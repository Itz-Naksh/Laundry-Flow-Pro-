<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};

class AnalyticsController
{
    // ── GET /api/analytics?period=30days|3months|6months|12months ─────────────
    public function index(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db = Database::connect();

        $raw    = (string) ($req->query('period') ?? '12months');
        $period = in_array($raw, ['30days','3months','6months','12months'], true) ? $raw : '12months';

        [$curFrom, $curTo, $prevFrom, $prevTo, $chartGroup, $chartSteps] = $this->resolvePeriod($period);

        $summary = [
            'revenue'         => $this->stat(
                $this->sumRevenue($db, $curFrom, $curTo),
                $this->sumRevenue($db, $prevFrom, $prevTo)
            ),
            'orders'          => $this->stat(
                $this->countOrders($db, $curFrom, $curTo),
                $this->countOrders($db, $prevFrom, $prevTo)
            ),
            'newCustomers'    => $this->stat(
                $this->countNewCustomers($db, $curFrom, $curTo),
                $this->countNewCustomers($db, $prevFrom, $prevTo)
            ),
            'activeCustomers' => $this->stat(
                $this->countActiveCustomers($db, $curFrom, $curTo),
                $this->countActiveCustomers($db, $prevFrom, $prevTo)
            ),
            'avgOrderValue'   => $this->stat(
                $this->avgOrderValue($db, $curFrom, $curTo),
                $this->avgOrderValue($db, $prevFrom, $prevTo)
            ),
            'repeatRate'      => $this->stat(
                $this->repeatRate($db, $curFrom, $curTo),
                $this->repeatRate($db, $prevFrom, $prevTo)
            ),
        ];

        $charts      = $this->buildCharts($db, $curFrom, $curTo, $chartGroup, $chartSteps);
        $services    = $this->serviceBreakdown($db, $curFrom, $curTo);
        $topCustomers = $this->topCustomers($db, $curFrom, $curTo);
        $metrics     = $this->keyMetrics($db, $curFrom, $curTo);

        Response::success('Analytics loaded.', [
            'period'       => $period,
            'dateRange'    => ['from' => $curFrom, 'to' => $curTo],
            'summary'      => $summary,
            'charts'       => $charts,
            'services'     => $services,
            'topCustomers' => $topCustomers,
            'metrics'      => $metrics,
        ]);
    }

    // ── Period resolution ─────────────────────────────────────────────────────

    private function resolvePeriod(string $period): array
    {
        $now    = new \DateTime('today');
        $curTo  = $now->format('Y-m-d');

        switch ($period) {
            case '30days':
                $curFrom  = (clone $now)->modify('-30 days')->format('Y-m-d');
                $prevTo   = (clone $now)->modify('-31 days')->format('Y-m-d');
                $prevFrom = (clone $now)->modify('-61 days')->format('Y-m-d');
                return [$curFrom, $curTo, $prevFrom, $prevTo, 'daily', 30];

            case '3months':
                $curFrom  = (clone $now)->modify('-3 months')->format('Y-m-d');
                $prevTo   = (clone $now)->modify('-3 months')->modify('-1 day')->format('Y-m-d');
                $prevFrom = (clone $now)->modify('-6 months')->format('Y-m-d');
                return [$curFrom, $curTo, $prevFrom, $prevTo, 'weekly', 13];

            case '6months':
                $curFrom  = (clone $now)->modify('-6 months')->format('Y-m-d');
                $prevTo   = (clone $now)->modify('-6 months')->modify('-1 day')->format('Y-m-d');
                $prevFrom = (clone $now)->modify('-12 months')->format('Y-m-d');
                return [$curFrom, $curTo, $prevFrom, $prevTo, 'monthly', 6];

            default: // 12months
                $curFrom  = (clone $now)->modify('-12 months')->format('Y-m-d');
                $prevTo   = (clone $now)->modify('-12 months')->modify('-1 day')->format('Y-m-d');
                $prevFrom = (clone $now)->modify('-24 months')->format('Y-m-d');
                return [$curFrom, $curTo, $prevFrom, $prevTo, 'monthly', 12];
        }
    }

    // ── Stat builder (current vs previous + % change) ─────────────────────────

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

    // ── Scalar queries ────────────────────────────────────────────────────────

    private function sumRevenue(\PDO $db, string $from, string $to): float
    {
        $st = $db->prepare(
            "SELECT COALESCE(SUM(total_amount), 0)
             FROM orders
             WHERE status = 'delivered'
               AND DATE(created_at) BETWEEN ? AND ?"
        );
        $st->execute([$from, $to]);
        return (float) $st->fetchColumn();
    }

    private function countOrders(\PDO $db, string $from, string $to): int
    {
        $st = $db->prepare(
            "SELECT COUNT(*)
             FROM orders
             WHERE DATE(created_at) BETWEEN ? AND ?"
        );
        $st->execute([$from, $to]);
        return (int) $st->fetchColumn();
    }

    private function countNewCustomers(\PDO $db, string $from, string $to): int
    {
        $st = $db->prepare(
            "SELECT COUNT(*)
             FROM users
             WHERE role_id = 3
               AND DATE(created_at) BETWEEN ? AND ?"
        );
        $st->execute([$from, $to]);
        return (int) $st->fetchColumn();
    }

    private function countActiveCustomers(\PDO $db, string $from, string $to): int
    {
        $st = $db->prepare(
            "SELECT COUNT(DISTINCT customer_id)
             FROM orders
             WHERE DATE(created_at) BETWEEN ? AND ?"
        );
        $st->execute([$from, $to]);
        return (int) $st->fetchColumn();
    }

    private function avgOrderValue(\PDO $db, string $from, string $to): float
    {
        $st = $db->prepare(
            "SELECT COALESCE(AVG(total_amount), 0)
             FROM orders
             WHERE status = 'delivered'
               AND DATE(created_at) BETWEEN ? AND ?"
        );
        $st->execute([$from, $to]);
        return (float) $st->fetchColumn();
    }

    private function repeatRate(\PDO $db, string $from, string $to): float
    {
        $st = $db->prepare(
            "SELECT
               COUNT(*) AS total_with_orders,
               SUM(CASE WHEN order_count > 1 THEN 1 ELSE 0 END) AS repeat_customers
             FROM (
               SELECT customer_id, COUNT(*) AS order_count
               FROM orders
               WHERE DATE(created_at) BETWEEN ? AND ?
               GROUP BY customer_id
             ) t"
        );
        $st->execute([$from, $to]);
        $row = $st->fetch();
        if (!$row || (int) $row['total_with_orders'] === 0) return 0.0;
        return round((float) $row['repeat_customers'] / (float) $row['total_with_orders'] * 100, 1);
    }

    // ── Chart builders ────────────────────────────────────────────────────────

    private function buildCharts(\PDO $db, string $from, string $to, string $group, int $steps): array
    {
        return match ($group) {
            'daily'  => $this->dailyCharts($db, $from, $to, $steps),
            'weekly' => $this->weeklyCharts($db, $from, $to, $steps),
            default  => $this->monthlyCharts($db, $from, $to, $steps),
        };
    }

    private function dailyCharts(\PDO $db, string $from, string $to, int $days): array
    {
        $revSt = $db->prepare(
            "SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount),0) AS v
             FROM orders WHERE status='delivered' AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE(created_at)"
        );
        $revSt->execute([$from, $to]);
        $revMap = array_column($revSt->fetchAll(), 'v', 'd');

        $ordSt = $db->prepare(
            "SELECT DATE(created_at) AS d, COUNT(*) AS v
             FROM orders WHERE DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE(created_at)"
        );
        $ordSt->execute([$from, $to]);
        $ordMap = array_column($ordSt->fetchAll(), 'v', 'd');

        $custSt = $db->prepare(
            "SELECT DATE(created_at) AS d, COUNT(*) AS v
             FROM users WHERE role_id=3 AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE(created_at)"
        );
        $custSt->execute([$from, $to]);
        $custMap = array_column($custSt->fetchAll(), 'v', 'd');

        $labels = $revenue = $orders = $customers = [];
        $dt  = new \DateTime($from);
        $end = new \DateTime($to);
        while ($dt <= $end) {
            $d           = $dt->format('Y-m-d');
            $labels[]    = $dt->format('d M');
            $revenue[]   = round((float) ($revMap[$d]  ?? 0), 2);
            $orders[]    = (int) ($ordMap[$d]  ?? 0);
            $customers[] = (int) ($custMap[$d] ?? 0);
            $dt->modify('+1 day');
        }
        return compact('labels', 'revenue', 'orders', 'customers');
    }

    private function weeklyCharts(\PDO $db, string $from, string $to, int $maxWeeks): array
    {
        // Use Monday of each week as the key
        $sql = "DATE_FORMAT(DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY), '%Y-%m-%d')";

        $revSt = $db->prepare(
            "SELECT {$sql} AS wk, COALESCE(SUM(total_amount),0) AS v
             FROM orders WHERE status='delivered' AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY wk"
        );
        $revSt->execute([$from, $to]);
        $revMap = array_column($revSt->fetchAll(), 'v', 'wk');

        $ordSt = $db->prepare(
            "SELECT {$sql} AS wk, COUNT(*) AS v
             FROM orders WHERE DATE(created_at) BETWEEN ? AND ?
             GROUP BY wk"
        );
        $ordSt->execute([$from, $to]);
        $ordMap = array_column($ordSt->fetchAll(), 'v', 'wk');

        $custSt = $db->prepare(
            "SELECT {$sql} AS wk, COUNT(*) AS v
             FROM users WHERE role_id=3 AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY wk"
        );
        $custSt->execute([$from, $to]);
        $custMap = array_column($custSt->fetchAll(), 'v', 'wk');

        $labels = $revenue = $orders = $customers = [];
        // Start from Monday on or before $from
        $dt  = new \DateTime($from);
        $dow = (int) $dt->format('N'); // 1=Mon … 7=Sun
        if ($dow > 1) $dt->modify('-' . ($dow - 1) . ' days');
        $end   = new \DateTime($to);
        $count = 0;

        while ($dt <= $end && $count < $maxWeeks) {
            $wk          = $dt->format('Y-m-d');
            $labels[]    = $dt->format('d M');
            $revenue[]   = round((float) ($revMap[$wk]  ?? 0), 2);
            $orders[]    = (int) ($ordMap[$wk]  ?? 0);
            $customers[] = (int) ($custMap[$wk] ?? 0);
            $dt->modify('+1 week');
            $count++;
        }
        return compact('labels', 'revenue', 'orders', 'customers');
    }

    private function monthlyCharts(\PDO $db, string $from, string $to, int $months): array
    {
        $revSt = $db->prepare(
            "SELECT DATE_FORMAT(created_at,'%Y-%m') AS mo, COALESCE(SUM(total_amount),0) AS v
             FROM orders WHERE status='delivered' AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(created_at,'%Y-%m')"
        );
        $revSt->execute([$from, $to]);
        $revMap = array_column($revSt->fetchAll(), 'v', 'mo');

        $ordSt = $db->prepare(
            "SELECT DATE_FORMAT(created_at,'%Y-%m') AS mo, COUNT(*) AS v
             FROM orders WHERE DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(created_at,'%Y-%m')"
        );
        $ordSt->execute([$from, $to]);
        $ordMap = array_column($ordSt->fetchAll(), 'v', 'mo');

        $custSt = $db->prepare(
            "SELECT DATE_FORMAT(created_at,'%Y-%m') AS mo, COUNT(*) AS v
             FROM users WHERE role_id=3 AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(created_at,'%Y-%m')"
        );
        $custSt->execute([$from, $to]);
        $custMap = array_column($custSt->fetchAll(), 'v', 'mo');

        $labels = $revenue = $orders = $customers = [];
        $dt    = new \DateTime($from);
        $dt->modify('first day of this month');
        $end   = new \DateTime($to);
        $count = 0;

        while ($dt <= $end && $count < $months) {
            $mo          = $dt->format('Y-m');
            $labels[]    = $dt->format('M Y');
            $revenue[]   = round((float) ($revMap[$mo]  ?? 0), 2);
            $orders[]    = (int) ($ordMap[$mo]  ?? 0);
            $customers[] = (int) ($custMap[$mo] ?? 0);
            $dt->modify('+1 month');
            $count++;
        }
        return compact('labels', 'revenue', 'orders', 'customers');
    }

    // ── Service breakdown ─────────────────────────────────────────────────────

    private function serviceBreakdown(\PDO $db, string $from, string $to): array
    {
        $st = $db->prepare(
            "SELECT st.name,
                    COUNT(o.order_id) AS order_count,
                    COALESCE(SUM(CASE WHEN o.status='delivered' THEN o.total_amount ELSE 0 END), 0) AS revenue
             FROM service_types st
             LEFT JOIN orders o ON o.service_type_id = st.id
                                AND DATE(o.created_at) BETWEEN ? AND ?
             GROUP BY st.id, st.name
             ORDER BY revenue DESC"
        );
        $st->execute([$from, $to]);
        $rows = $st->fetchAll();

        $total  = array_sum(array_column($rows, 'revenue'));
        $colors = ['#6366f1','#2563eb','#06b6d4','#10b981','#f59e0b','#ef4444'];

        $services = [];
        foreach ($rows as $i => $r) {
            $rev        = (float) $r['revenue'];
            $services[] = [
                'name'    => $r['name'],
                'orders'  => (int) $r['order_count'],
                'revenue' => round($rev, 2),
                'pct'     => $total > 0 ? round($rev / $total * 100, 1) : 0,
                'color'   => $colors[$i % count($colors)],
            ];
        }
        return $services;
    }

    // ── Top customers ─────────────────────────────────────────────────────────

    private function topCustomers(\PDO $db, string $from, string $to): array
    {
        $st = $db->prepare(
            "SELECT u.id, u.full_name AS name, u.email,
                    COUNT(o.order_id) AS orders,
                    COALESCE(SUM(CASE WHEN o.status='delivered' THEN o.total_amount ELSE 0 END), 0) AS total_spent,
                    MAX(o.created_at) AS last_order
             FROM users u
             INNER JOIN orders o ON o.customer_id = u.id
                                 AND DATE(o.created_at) BETWEEN ? AND ?
             WHERE u.role_id = 3
             GROUP BY u.id, u.full_name, u.email
             ORDER BY total_spent DESC
             LIMIT 10"
        );
        $st->execute([$from, $to]);
        $rows = $st->fetchAll();

        $rank = 1;
        foreach ($rows as &$r) {
            $r['rank']        = $rank++;
            $r['id']          = (int) $r['id'];
            $r['orders']      = (int) $r['orders'];
            $r['total_spent'] = round((float) $r['total_spent'], 2);
            $r['avg_order']   = $r['orders'] > 0 ? round($r['total_spent'] / $r['orders'], 2) : 0;
        }
        unset($r);
        return $rows;
    }

    // ── Key metrics ───────────────────────────────────────────────────────────

    private function keyMetrics(\PDO $db, string $from, string $to): array
    {
        $total = (function () use ($db, $from, $to) {
            $s = $db->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at) BETWEEN ? AND ?");
            $s->execute([$from, $to]);
            return (int) $s->fetchColumn();
        })();

        $delivered = (function () use ($db, $from, $to) {
            $s = $db->prepare("SELECT COUNT(*) FROM orders WHERE status='delivered' AND DATE(created_at) BETWEEN ? AND ?");
            $s->execute([$from, $to]);
            return (int) $s->fetchColumn();
        })();

        $cancelled = (function () use ($db, $from, $to) {
            $s = $db->prepare("SELECT COUNT(*) FROM orders WHERE status='canceled' AND DATE(created_at) BETWEEN ? AND ?");
            $s->execute([$from, $to]);
            return (int) $s->fetchColumn();
        })();

        $avgRating = (function () use ($db, $from, $to) {
            $s = $db->prepare(
                "SELECT COALESCE(AVG(r.rating), 0)
                 FROM reviews r
                 JOIN orders o ON r.order_id = o.order_id
                 WHERE r.status = 'approved' AND DATE(o.created_at) BETWEEN ? AND ?"
            );
            $s->execute([$from, $to]);
            return (float) $s->fetchColumn();
        })();

        $totalRev = (function () use ($db, $from, $to) {
            $s = $db->prepare(
                "SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status='delivered' AND DATE(created_at) BETWEEN ? AND ?"
            );
            $s->execute([$from, $to]);
            return (float) $s->fetchColumn();
        })();

        $custCount = (function () use ($db, $from, $to) {
            $s = $db->prepare(
                "SELECT COUNT(DISTINCT customer_id) FROM orders WHERE DATE(created_at) BETWEEN ? AND ?"
            );
            $s->execute([$from, $to]);
            return (int) $s->fetchColumn();
        })();

        return [
            'completion_rate'      => $total > 0 ? round($delivered / $total * 100, 1) : 0,
            'cancellation_rate'    => $total > 0 ? round($cancelled / $total * 100, 1) : 0,
            'avg_rating'           => round($avgRating, 1),
            'revenue_per_customer' => $custCount > 0 ? round($totalRev / $custCount, 2) : 0,
        ];
    }
}
