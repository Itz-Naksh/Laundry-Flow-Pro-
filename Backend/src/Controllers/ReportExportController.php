<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};
use App\Helpers\SimplePdf;

class ReportExportController
{
    // ── POST /api/reports/export/excel ────────────────────────────────────────
    public function excel(Request $req): never
    {
        Middleware::requireAdmin($req);

        $body   = $req->body() ?? [];
        $period = $this->safePeriod($body['period'] ?? $req->query('period') ?? '12months');

        $data = $this->fetchReportData($period);
        $xlsx = $this->buildXlsx($data, $period);

        $filename = 'analytics-report-' . $period . '-' . date('Y-m-d') . '.xlsx';

        $this->logExport('excel', $period);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($xlsx));
        header('Cache-Control: private, no-cache');
        echo $xlsx;
        exit;
    }

    // ── POST /api/reports/export/pdf ─────────────────────────────────────────
    public function pdf(Request $req): never
    {
        Middleware::requireAdmin($req);

        $body   = $req->body() ?? [];
        $period = $this->safePeriod($body['period'] ?? $req->query('period') ?? '12months');

        $data = $this->fetchReportData($period);
        $pdf  = $this->buildPdf($data, $period);

        $filename = 'analytics-report-' . $period . '-' . date('Y-m-d') . '.pdf';

        $this->logExport('pdf', $period);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, no-cache');
        echo $pdf;
        exit;
    }

    // ── Data fetching (mirrors AnalyticsController logic) ─────────────────────

    private function fetchReportData(string $period): array
    {
        $db = Database::connect();
        [$curFrom, $curTo, $prevFrom, $prevTo] = $this->resolvePeriod($period);

        $ctrl = new AnalyticsController();
        // Re-use its private methods via reflection would couple tightly;
        // instead we call the same queries directly here.

        $revCur  = $this->qScalar($db, "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='delivered' AND DATE(created_at) BETWEEN ? AND ?", [$curFrom, $curTo]);
        $revPrev = $this->qScalar($db, "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='delivered' AND DATE(created_at) BETWEEN ? AND ?", [$prevFrom, $prevTo]);

        $ordCur  = $this->qScalar($db, "SELECT COUNT(*) FROM orders WHERE DATE(created_at) BETWEEN ? AND ?", [$curFrom, $curTo]);
        $ordPrev = $this->qScalar($db, "SELECT COUNT(*) FROM orders WHERE DATE(created_at) BETWEEN ? AND ?", [$prevFrom, $prevTo]);

        $newCustCur  = $this->qScalar($db, "SELECT COUNT(*) FROM users WHERE role_id=3 AND DATE(created_at) BETWEEN ? AND ?", [$curFrom, $curTo]);
        $newCustPrev = $this->qScalar($db, "SELECT COUNT(*) FROM users WHERE role_id=3 AND DATE(created_at) BETWEEN ? AND ?", [$prevFrom, $prevTo]);

        $actCustCur  = $this->qScalar($db, "SELECT COUNT(DISTINCT customer_id) FROM orders WHERE DATE(created_at) BETWEEN ? AND ?", [$curFrom, $curTo]);
        $actCustPrev = $this->qScalar($db, "SELECT COUNT(DISTINCT customer_id) FROM orders WHERE DATE(created_at) BETWEEN ? AND ?", [$prevFrom, $prevTo]);

        $avgCur  = $this->qScalar($db, "SELECT COALESCE(AVG(total_amount),0) FROM orders WHERE status='delivered' AND DATE(created_at) BETWEEN ? AND ?", [$curFrom, $curTo]);
        $avgPrev = $this->qScalar($db, "SELECT COALESCE(AVG(total_amount),0) FROM orders WHERE status='delivered' AND DATE(created_at) BETWEEN ? AND ?", [$prevFrom, $prevTo]);

        $delivCur  = $this->qScalar($db, "SELECT COUNT(*) FROM orders WHERE status='delivered' AND DATE(created_at) BETWEEN ? AND ?", [$curFrom, $curTo]);
        $cancCur   = $this->qScalar($db, "SELECT COUNT(*) FROM orders WHERE status='canceled' AND DATE(created_at) BETWEEN ? AND ?", [$curFrom, $curTo]);

        // Top customers
        $topSt = $db->prepare(
            "SELECT u.full_name AS name,
                    COUNT(o.order_id) AS orders,
                    COALESCE(SUM(CASE WHEN o.status='delivered' THEN o.total_amount ELSE 0 END),0) AS total_spent,
                    MAX(o.created_at) AS last_order
             FROM users u
             INNER JOIN orders o ON o.customer_id = u.id AND DATE(o.created_at) BETWEEN ? AND ?
             WHERE u.role_id=3
             GROUP BY u.id, u.full_name
             ORDER BY total_spent DESC
             LIMIT 10"
        );
        $topSt->execute([$curFrom, $curTo]);
        $topCustomers = $topSt->fetchAll();

        // Service breakdown
        $svcSt = $db->prepare(
            "SELECT st.name,
                    COUNT(o.order_id) AS order_count,
                    COALESCE(SUM(CASE WHEN o.status='delivered' THEN o.total_amount ELSE 0 END),0) AS revenue
             FROM service_types st
             LEFT JOIN orders o ON o.service_type_id=st.id AND DATE(o.created_at) BETWEEN ? AND ?
             GROUP BY st.id, st.name
             ORDER BY revenue DESC"
        );
        $svcSt->execute([$curFrom, $curTo]);
        $services = $svcSt->fetchAll();

        return [
            'period'     => $period,
            'dateRange'  => ['from' => $curFrom, 'to' => $curTo],
            'generatedAt'=> date('Y-m-d H:i:s'),
            'summary'    => [
                'revenue'         => $this->stat((float)$revCur, (float)$revPrev),
                'orders'          => $this->stat((float)$ordCur, (float)$ordPrev),
                'newCustomers'    => $this->stat((float)$newCustCur, (float)$newCustPrev),
                'activeCustomers' => $this->stat((float)$actCustCur, (float)$actCustPrev),
                'avgOrderValue'   => $this->stat((float)$avgCur, (float)$avgPrev),
                'delivered'       => (int)$delivCur,
                'cancelled'       => (int)$cancCur,
            ],
            'services'     => $services,
            'topCustomers' => $topCustomers,
        ];
    }

    // ── XLSX builder ──────────────────────────────────────────────────────────

    private function buildXlsx(array $data, string $period): string
    {
        $periodLabel = $this->periodLabel($period);
        $rows        = [];

        // ── Title block ───────────────────────────────────────────────────────
        $rows[] = ['Laundry Flow Pro — Analytics Report'];
        $rows[] = ["Period: {$periodLabel}"];
        $rows[] = ["Date Range: {$data['dateRange']['from']} to {$data['dateRange']['to']}"];
        $rows[] = ["Generated: {$data['generatedAt']}"];
        $rows[] = [];

        // ── Revenue Summary ───────────────────────────────────────────────────
        $rows[] = ['REVENUE SUMMARY'];
        $rows[] = ['Metric', 'Current Period', 'Previous Period', 'Change %', 'Trend'];
        $s = $data['summary'];
        $rows[] = ['Total Revenue (LKR)',    $s['revenue']['current'],   $s['revenue']['previous'],   $s['revenue']['changePercentage'],         $s['revenue']['trend']];
        $rows[] = ['Avg Order Value (LKR)',  $s['avgOrderValue']['current'], $s['avgOrderValue']['previous'], $s['avgOrderValue']['changePercentage'], $s['avgOrderValue']['trend']];
        $rows[] = [];

        // ── Orders Summary ────────────────────────────────────────────────────
        $rows[] = ['ORDERS SUMMARY'];
        $rows[] = ['Metric', 'Value'];
        $rows[] = ['Total Orders',     $s['orders']['current']];
        $rows[] = ['Previous Period',  $s['orders']['previous']];
        $rows[] = ['Change %',         $s['orders']['changePercentage'] . '% ' . ($s['orders']['trend'] === 'up' ? 'UP' : 'DOWN')];
        $rows[] = ['Delivered Orders', $s['delivered']];
        $rows[] = ['Cancelled Orders', $s['cancelled']];
        $rows[] = [];

        // ── Customer Summary ──────────────────────────────────────────────────
        $rows[] = ['CUSTOMER SUMMARY'];
        $rows[] = ['Metric', 'Current Period', 'Previous Period', 'Change %', 'Trend'];
        $rows[] = ['New Customers',    $s['newCustomers']['current'],    $s['newCustomers']['previous'],    $s['newCustomers']['changePercentage'],    $s['newCustomers']['trend']];
        $rows[] = ['Active Customers', $s['activeCustomers']['current'], $s['activeCustomers']['previous'], $s['activeCustomers']['changePercentage'], $s['activeCustomers']['trend']];
        $rows[] = [];

        // ── Service Performance ────────────────────────────────────────────────
        $rows[] = ['SERVICE PERFORMANCE'];
        $rows[] = ['Service', 'Orders', 'Revenue (LKR)'];
        $totalSvcRev = array_sum(array_column($data['services'], 'revenue'));
        foreach ($data['services'] as $svc) {
            $pct    = $totalSvcRev > 0 ? round((float)$svc['revenue'] / $totalSvcRev * 100, 1) : 0;
            $rows[] = [$svc['name'], (int)$svc['order_count'], round((float)$svc['revenue'], 2), $pct . '%'];
        }
        $rows[] = [];

        // ── Top Customers ─────────────────────────────────────────────────────
        $rows[] = ['TOP CUSTOMERS'];
        $rows[] = ['Rank', 'Customer', 'Orders', 'Total Spent (LKR)', 'Last Order'];
        foreach ($data['topCustomers'] as $rank => $c) {
            $rows[] = [
                $rank + 1,
                $c['name'],
                (int) $c['orders'],
                round((float) $c['total_spent'], 2),
                $c['last_order'] ? substr($c['last_order'], 0, 10) : '-',
            ];
        }

        return $this->xlsxFromRows($rows);
    }

    private function xlsxFromRows(array $rows): string
    {
        // Collect all string values for sharedStrings
        $strings     = [];
        $stringIndex = [];

        $addString = function (string $s) use (&$strings, &$stringIndex): int {
            if (!isset($stringIndex[$s])) {
                $stringIndex[$s] = count($strings);
                $strings[]       = $s;
            }
            return $stringIndex[$s];
        };

        // Build sheet XML rows
        $sheetRows = '';
        foreach ($rows as $ri => $row) {
            if (empty($row)) { $sheetRows .= '<row r="' . ($ri + 1) . '"/>'; continue; }
            $sheetRows .= '<row r="' . ($ri + 1) . '">';
            foreach ($row as $ci => $cell) {
                $colLetter = $this->xlsxCol($ci);
                $cellRef   = $colLetter . ($ri + 1);
                if (is_numeric($cell) && $cell !== '') {
                    $sheetRows .= '<c r="' . $cellRef . '"><v>' . $cell . '</v></c>';
                } else {
                    $idx        = $addString((string) $cell);
                    $sheetRows .= '<c r="' . $cellRef . '" t="s"><v>' . $idx . '</v></c>';
                }
            }
            $sheetRows .= '</row>';
        }

        // Shared strings XML
        $ssCount = count($strings);
        $ssXml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $ssCount . '" uniqueCount="' . $ssCount . '">';
        foreach ($strings as $s) {
            $ssXml .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</t></si>';
        }
        $ssXml .= '</sst>';

        // Sheet XML
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetRows . '</sheetData>'
            . '</worksheet>';

        // Workbook XML
        $wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Analytics Report" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        // Content Types
        $ctXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';

        // Root .rels
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" '
            . 'Target="xl/workbook.xml"/>'
            . '</Relationships>';

        // Workbook .rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
            . 'Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" '
            . 'Target="sharedStrings.xml"/>'
            . '</Relationships>';

        // Assemble ZIP
        $tmp = tempnam(sys_get_temp_dir(), 'lfp_xlsx_');
        @unlink($tmp); // ZipArchive creates the file itself

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            Response::error('Unable to create Excel file.', 500);
        }

        $zip->addFromString('[Content_Types].xml',        $ctXml);
        $zip->addFromString('_rels/.rels',                $rootRels);
        $zip->addFromString('xl/workbook.xml',            $wbXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/worksheets/sheet1.xml',   $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml',       $ssXml);
        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return $bytes !== false ? $bytes : '';
    }

    private function xlsxCol(int $index): string
    {
        $col = '';
        $index++;
        while ($index > 0) {
            $mod  = ($index - 1) % 26;
            $col  = chr(65 + $mod) . $col;
            $index = (int) (($index - $mod - 1) / 26);
        }
        return $col;
    }

    // ── PDF builder ───────────────────────────────────────────────────────────

    private function buildPdf(array $data, string $period): string
    {
        $pdf         = new SimplePdf();
        $periodLabel = $this->periodLabel($period);
        $cw          = $pdf->contentWidth();
        $s           = $data['summary'];

        // ── Cover / header ────────────────────────────────────────────────────
        $pdf->setY(760);
        $pdf->writeLine('ANALYTICS REPORT', 20, true);
        $pdf->writeLine('Laundry Flow Pro', 13, false);
        $pdf->moveDown(4);
        $pdf->writeLine("Period: {$periodLabel}", 10);
        $pdf->writeLine("Date Range: {$data['dateRange']['from']}  to  {$data['dateRange']['to']}", 10);
        $pdf->writeLine("Generated: {$data['generatedAt']}", 9);
        $pdf->moveDown(6);
        $pdf->hRule(1.0);
        $pdf->moveDown(4);

        // ── Summary stats table ───────────────────────────────────────────────
        $pdf->sectionHead('REVENUE & ORDER SUMMARY');
        $pdf->moveDown(4);

        $colW = [$cw * 0.40, $cw * 0.20, $cw * 0.20, $cw * 0.20];
        $pdf->table(
            ['Metric', 'Current', 'Previous', 'Change'],
            [
                ['Total Revenue (LKR)',   $this->fmt($s['revenue']['current']),       $this->fmt($s['revenue']['previous']),       $this->changeTxt($s['revenue'])],
                ['Avg Order Value (LKR)', $this->fmt($s['avgOrderValue']['current']), $this->fmt($s['avgOrderValue']['previous']), $this->changeTxt($s['avgOrderValue'])],
                ['Total Orders',          $s['orders']['current'],                    $s['orders']['previous'],                    $this->changeTxt($s['orders'])],
                ['Delivered Orders',      $s['delivered'],                            '-',                                         '-'],
                ['Cancelled Orders',      $s['cancelled'],                            '-',                                         '-'],
            ],
            $colW
        );

        // ── Customer summary ──────────────────────────────────────────────────
        $pdf->sectionHead('CUSTOMER SUMMARY');
        $pdf->moveDown(4);
        $pdf->table(
            ['Metric', 'Current', 'Previous', 'Change'],
            [
                ['New Customers',    $s['newCustomers']['current'],    $s['newCustomers']['previous'],    $this->changeTxt($s['newCustomers'])],
                ['Active Customers', $s['activeCustomers']['current'], $s['activeCustomers']['previous'], $this->changeTxt($s['activeCustomers'])],
            ],
            $colW
        );

        // ── Service performance ───────────────────────────────────────────────
        $pdf->sectionHead('SERVICE PERFORMANCE');
        $pdf->moveDown(4);

        $totalSvcRev = array_sum(array_column($data['services'], 'revenue'));
        $svcRows     = [];
        foreach ($data['services'] as $svc) {
            $pct       = $totalSvcRev > 0 ? round((float)$svc['revenue'] / $totalSvcRev * 100, 1) : 0;
            $svcRows[] = [$svc['name'], (int)$svc['order_count'], $this->fmt((float)$svc['revenue']), $pct . '%'];
        }
        $svcColW = [$cw * 0.40, $cw * 0.15, $cw * 0.27, $cw * 0.18];
        $pdf->table(['Service', 'Orders', 'Revenue (LKR)', 'Share'], $svcRows, $svcColW);

        // ── Top customers ─────────────────────────────────────────────────────
        $pdf->sectionHead('TOP CUSTOMERS');
        $pdf->moveDown(4);

        $custRows = [];
        foreach ($data['topCustomers'] as $rank => $c) {
            $custRows[] = [
                (string)($rank + 1),
                $c['name'],
                (string)(int)$c['orders'],
                $this->fmt((float)$c['total_spent']),
                $c['last_order'] ? substr($c['last_order'], 0, 10) : '-',
            ];
        }
        $custColW = [$cw * 0.07, $cw * 0.33, $cw * 0.12, $cw * 0.25, $cw * 0.23];
        $pdf->table(['#', 'Customer', 'Orders', 'Total Spent (LKR)', 'Last Order'], $custRows, $custColW);

        return $pdf->render();
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function resolvePeriod(string $period): array
    {
        $now   = new \DateTime('today');
        $curTo = $now->format('Y-m-d');

        switch ($period) {
            case '30days':
                return [
                    (clone $now)->modify('-30 days')->format('Y-m-d'), $curTo,
                    (clone $now)->modify('-61 days')->format('Y-m-d'),
                    (clone $now)->modify('-31 days')->format('Y-m-d'),
                ];
            case '3months':
                $cf = (clone $now)->modify('-3 months')->format('Y-m-d');
                return [$cf, $curTo, (clone $now)->modify('-6 months')->format('Y-m-d'),
                        (clone $now)->modify('-3 months')->modify('-1 day')->format('Y-m-d')];
            case '6months':
                $cf = (clone $now)->modify('-6 months')->format('Y-m-d');
                return [$cf, $curTo, (clone $now)->modify('-12 months')->format('Y-m-d'),
                        (clone $now)->modify('-6 months')->modify('-1 day')->format('Y-m-d')];
            default: // 12months
                $cf = (clone $now)->modify('-12 months')->format('Y-m-d');
                return [$cf, $curTo, (clone $now)->modify('-24 months')->format('Y-m-d'),
                        (clone $now)->modify('-12 months')->modify('-1 day')->format('Y-m-d')];
        }
    }

    private function qScalar(\PDO $db, string $sql, array $params): mixed
    {
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    private function stat(float $current, float $previous): array
    {
        $pct = $previous > 0
            ? (($current - $previous) / $previous * 100)
            : ($current > 0 ? 100.0 : 0.0);

        return [
            'current'          => round($current, 2),
            'previous'         => round($previous, 2),
            'changePercentage' => round(abs($pct), 1),
            'trend'            => $pct >= 0 ? 'up' : 'down',
        ];
    }

    private function safePeriod(mixed $v): string
    {
        $s = (string) $v;
        return in_array($s, ['30days','3months','6months','12months'], true) ? $s : '12months';
    }

    private function periodLabel(string $period): string
    {
        return match ($period) {
            '30days'   => 'Last 30 Days',
            '3months'  => 'Last 3 Months',
            '6months'  => 'Last 6 Months',
            default    => 'Last 12 Months',
        };
    }

    private function fmt(float $v): string
    {
        return number_format($v, 2);
    }

    private function changeTxt(array $stat): string
    {
        $arrow = $stat['trend'] === 'up' ? '+' : '-';
        return $arrow . $stat['changePercentage'] . '%';
    }

    private function logExport(string $format, string $period): void
    {
        $logDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);

        $line = '[' . date('Y-m-d H:i:s') . '] '
            . 'format=' . $format . ' period=' . $period . PHP_EOL;

        @file_put_contents($logDir . '/reports.log', $line, FILE_APPEND | LOCK_EX);
    }
}
