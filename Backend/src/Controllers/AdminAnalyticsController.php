<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};

class AdminAnalyticsController
{
    // ── GET /api/admin/analytics?range=12m|6m|3m ──────────────────────────────
    public function index(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db    = Database::connect();
        $range = (string) ($req->query('range') ?? '12m');

        $months = match ($range) {
            '3m'  => 3,
            '6m'  => 6,
            default => 12,
        };

        // Monthly revenue
        $revRows = $db->query(
            "SELECT DATE_FORMAT(created_at,'%Y-%m') as month,
                    COALESCE(SUM(total_amount),0) as revenue
             FROM orders
             WHERE status='delivered'
               AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$months} MONTH)
             GROUP BY DATE_FORMAT(created_at,'%Y-%m')
             ORDER BY month ASC"
        )->fetchAll();

        // Monthly order count
        $ordRows = $db->query(
            "SELECT DATE_FORMAT(created_at,'%Y-%m') as month,
                    COUNT(*) as orders
             FROM orders
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL {$months} MONTH)
             GROUP BY DATE_FORMAT(created_at,'%Y-%m')
             ORDER BY month ASC"
        )->fetchAll();

        // Monthly new customers
        $custRows = $db->query(
            "SELECT DATE_FORMAT(created_at,'%Y-%m') as month,
                    COUNT(*) as customers
             FROM users
             WHERE role_id=3
               AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$months} MONTH)
             GROUP BY DATE_FORMAT(created_at,'%Y-%m')
             ORDER BY month ASC"
        )->fetchAll();

        // Fill all months in range
        $filled = $this->fillMonths($months);
        $revMap  = array_column($revRows,  'revenue',   'month');
        $ordMap  = array_column($ordRows,  'orders',    'month');
        $custMap = array_column($custRows, 'customers', 'month');

        $monthLabels = [];
        $revenueData = [];
        $orderData   = [];
        $customerData = [];

        foreach ($filled as $ym) {
            $monthLabels[]  = date('M Y', strtotime($ym . '-01'));
            $revenueData[]  = round((float) ($revMap[$ym]  ?? 0), 2);
            $orderData[]    = (int) ($ordMap[$ym]  ?? 0);
            $customerData[] = (int) ($custMap[$ym] ?? 0);
        }

        // Key metrics
        $metrics = $this->keyMetrics($db);

        Response::success('Analytics loaded.', [
            'labels'    => $monthLabels,
            'revenue'   => $revenueData,
            'orders'    => $orderData,
            'customers' => $customerData,
            'metrics'   => $metrics,
        ]);
    }

    // ── GET /api/admin/analytics/services ─────────────────────────────────────
    public function services(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db = Database::connect();

        $rows = $db->query(
            "SELECT st.name,
                    COUNT(o.order_id) as order_count,
                    COALESCE(SUM(CASE WHEN o.status='delivered' THEN o.total_amount ELSE 0 END), 0) as revenue
             FROM service_types st
             LEFT JOIN orders o ON o.service_type_id = st.id
             GROUP BY st.id, st.name
             ORDER BY revenue DESC"
        )->fetchAll();

        $totalRevenue = array_sum(array_column($rows, 'revenue'));
        $colors = ['var(--purple)','var(--primary)','var(--accent)','var(--green)','var(--orange)','var(--red)'];

        $services = [];
        foreach ($rows as $i => $r) {
            $rev = (float) $r['revenue'];
            $services[] = [
                'name'    => $r['name'],
                'orders'  => (int) $r['order_count'],
                'revenue' => round($rev, 2),
                'pct'     => $totalRevenue > 0 ? (int) round($rev / $totalRevenue * 100) : 0,
                'color'   => $colors[$i % count($colors)],
            ];
        }

        Response::success('Service analytics.', ['services' => $services]);
    }

    // ── GET /api/admin/analytics/top-customers ────────────────────────────────
    public function topCustomers(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db = Database::connect();

        $rows = $db->query(
            "SELECT u.id, u.full_name as name, u.email,
                    COUNT(o.order_id) as orders,
                    COALESCE(SUM(CASE WHEN o.status='delivered' THEN o.total_amount ELSE 0 END),0) as total_spent,
                    MAX(o.created_at) as last_order
             FROM users u
             LEFT JOIN orders o ON o.customer_id = u.id
             WHERE u.role_id = 3
             GROUP BY u.id, u.full_name, u.email
             ORDER BY total_spent DESC
             LIMIT 10"
        )->fetchAll();

        $rank = 1;
        foreach ($rows as &$r) {
            $r['rank']        = $rank++;
            $r['id']          = (int) $r['id'];
            $r['orders']      = (int) $r['orders'];
            $r['total_spent'] = round((float) $r['total_spent'], 2);
            $r['avg_order']   = $r['orders'] > 0 ? round($r['total_spent'] / $r['orders'], 2) : 0;
        }
        unset($r);

        Response::success('Top customers.', ['customers' => $rows]);
    }

    // ── GET /api/admin/analytics/report?type=revenue|orders|customers&from=&to= ─
    public function report(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db   = Database::connect();
        $type = (string) ($req->query('type') ?? 'revenue');
        $from = (string) ($req->query('from') ?? date('Y-m-01', strtotime('-3 months')));
        $to   = (string) ($req->query('to')   ?? date('Y-m-d'));

        // Sanitize dates
        $fromDate = date('Y-m-d', strtotime($from) ?: strtotime('-3 months'));
        $toDate   = date('Y-m-d', strtotime($to)   ?: time());

        if ($type === 'orders') {
            $rows = $db->prepare(
                "SELECT o.order_number, u.full_name as customer, st.name as service,
                        o.status, o.total_amount, o.payment_method, o.created_at
                 FROM orders o
                 JOIN users u ON o.customer_id = u.id
                 JOIN service_types st ON o.service_type_id = st.id
                 WHERE DATE(o.created_at) BETWEEN ? AND ?
                 ORDER BY o.created_at DESC"
            );
            $rows->execute([$fromDate, $toDate]);
            $data = $rows->fetchAll();
            foreach ($data as &$d) $d['total_amount'] = (float) $d['total_amount'];
            unset($d);

            $summary = [
                'total_orders'  => count($data),
                'total_revenue' => round(array_sum(array_column($data, 'total_amount')), 2),
                'delivered'     => count(array_filter($data, fn($d) => $d['status'] === 'delivered')),
                'cancelled'     => count(array_filter($data, fn($d) => $d['status'] === 'canceled')),
            ];
        } elseif ($type === 'customers') {
            $rows = $db->prepare(
                "SELECT u.full_name as name, u.email, u.phone, u.status, u.created_at,
                        COUNT(o.order_id) as order_count,
                        COALESCE(SUM(o.total_amount),0) as total_spent
                 FROM users u
                 LEFT JOIN orders o ON o.customer_id = u.id
                 WHERE u.role_id = 3 AND DATE(u.created_at) BETWEEN ? AND ?
                 GROUP BY u.id
                 ORDER BY u.created_at DESC"
            );
            $rows->execute([$fromDate, $toDate]);
            $data = $rows->fetchAll();
            foreach ($data as &$d) {
                $d['order_count'] = (int) $d['order_count'];
                $d['total_spent'] = round((float) $d['total_spent'], 2);
            }
            unset($d);

            $summary = [
                'new_customers' => count($data),
                'active'        => count(array_filter($data, fn($d) => $d['status'] === 'active')),
                'total_orders'  => array_sum(array_column($data, 'order_count')),
            ];
        } else {
            // Revenue report (default)
            $rows = $db->prepare(
                "SELECT DATE(o.created_at) as date,
                        COUNT(*) as orders,
                        COALESCE(SUM(o.total_amount),0) as revenue,
                        COALESCE(SUM(o.discount),0) as discounts
                 FROM orders o
                 WHERE o.status='delivered' AND DATE(o.created_at) BETWEEN ? AND ?
                 GROUP BY DATE(o.created_at)
                 ORDER BY date ASC"
            );
            $rows->execute([$fromDate, $toDate]);
            $data = $rows->fetchAll();
            foreach ($data as &$d) {
                $d['orders']    = (int) $d['orders'];
                $d['revenue']   = round((float) $d['revenue'], 2);
                $d['discounts'] = round((float) $d['discounts'], 2);
            }
            unset($d);

            $summary = [
                'total_revenue'  => round(array_sum(array_column($data, 'revenue')), 2),
                'total_orders'   => array_sum(array_column($data, 'orders')),
                'total_discount' => round(array_sum(array_column($data, 'discounts')), 2),
                'days'           => count($data),
            ];
        }

        Response::success('Report generated.', [
            'type'    => $type,
            'from'    => $fromDate,
            'to'      => $toDate,
            'summary' => $summary,
            'rows'    => $data,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function fillMonths(int $months): array
    {
        $list = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $list[] = date('Y-m', strtotime("-{$i} months"));
        }
        return $list;
    }

    private function keyMetrics(\PDO $db): array
    {
        $total      = (int) $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $delivered  = (int) $db->query("SELECT COUNT(*) FROM orders WHERE status='delivered'")->fetchColumn();
        $cancelled  = (int) $db->query("SELECT COUNT(*) FROM orders WHERE status='canceled'")->fetchColumn();
        $avgRating  = (float) $db->query("SELECT COALESCE(AVG(rating),0) FROM reviews WHERE status='approved'")->fetchColumn();
        $totalRev   = (float) $db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='delivered'")->fetchColumn();
        $custCount  = (int) $db->query("SELECT COUNT(*) FROM users WHERE role_id=3 AND status='active'")->fetchColumn();

        return [
            'completion_rate' => $total > 0 ? round($delivered / $total * 100, 1) : 0,
            'cancellation_rate' => $total > 0 ? round($cancelled / $total * 100, 1) : 0,
            'avg_rating'  => round($avgRating, 1),
            'revenue_per_customer' => $custCount > 0 ? round($totalRev / $custCount, 2) : 0,
        ];
    }
}
