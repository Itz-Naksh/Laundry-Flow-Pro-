<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};

class DashboardFilterController
{
    // ── GET /api/dashboard/statistics ─────────────────────────────────────────
    public function statistics(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db = Database::connect();

        $totalCustomers = (int) $db->query(
            "SELECT COUNT(*) FROM users WHERE role_id = 3"
        )->fetchColumn();

        $activeStatuses = "'schedule','confirm','collected','processing','ready','out_for_delivery'";
        $activeOrders   = (int) $db->query(
            "SELECT COUNT(*) FROM orders WHERE status IN ({$activeStatuses})"
        )->fetchColumn();

        $todayCollections = (int) $db->query(
            "SELECT COUNT(*) FROM orders WHERE status='collected' AND DATE(updated_at)=CURDATE()"
        )->fetchColumn();

        $todayDeliveries = (int) $db->query(
            "SELECT COUNT(*) FROM orders WHERE status='delivered' AND DATE(updated_at)=CURDATE()"
        )->fetchColumn();

        $todayPickups = (int) $db->query(
            "SELECT COUNT(*) FROM orders WHERE DATE(pickup_date)=CURDATE()"
        )->fetchColumn();

        $newOrdersToday = (int) $db->query(
            "SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()"
        )->fetchColumn();

        $newCustomersToday = (int) $db->query(
            "SELECT COUNT(*) FROM users WHERE role_id=3 AND DATE(created_at)=CURDATE()"
        )->fetchColumn();

        $monthlyRevenue = (float) $db->query(
            "SELECT COALESCE(SUM(total_amount),0) FROM orders
             WHERE status='delivered'
               AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())"
        )->fetchColumn();

        $totalRevenue = (float) $db->query(
            "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='delivered'"
        )->fetchColumn();

        Response::success('Statistics loaded.', [
            'total_customers'    => $totalCustomers,
            'active_orders'      => $activeOrders,
            'today_collections'  => $todayCollections,
            'today_deliveries'   => $todayDeliveries,
            'today_pickups'      => $todayPickups,
            'new_orders_today'   => $newOrdersToday,
            'new_customers_today'=> $newCustomersToday,
            'monthly_revenue'    => round($monthlyRevenue, 2),
            'total_revenue'      => round($totalRevenue, 2),
        ]);
    }

    // ── GET /api/dashboard/revenue?period=week|month|year|all ─────────────────
    public function revenue(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db     = Database::connect();
        $period = (string) ($req->query('period') ?? 'week');

        [$labels, $values] = match ($period) {
            'week'  => $this->revenueWeek($db),
            'month' => $this->revenueMonth($db),
            'year'  => $this->revenueYear($db),
            default => $this->revenueAll($db),
        };

        Response::success('Revenue data.', ['values' => $values, 'labels' => $labels]);
    }

    // Mon–Sun, real data up to today, null for future days
    private function revenueWeek(\PDO $db): array
    {
        $monday = date('Y-m-d', strtotime('monday this week'));
        $today  = date('Y-m-d');

        $rows = $db->prepare(
            "SELECT DATE(created_at) as day, COALESCE(SUM(total_amount),0) as revenue
             FROM orders
             WHERE status='delivered' AND DATE(created_at) >= ? AND DATE(created_at) <= ?
             GROUP BY DATE(created_at)"
        );
        $rows->execute([$monday, $today]);
        $byDay = [];
        foreach ($rows->fetchAll() as $r) $byDay[$r['day']] = (float) $r['revenue'];

        $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $labels   = [];
        $values   = [];
        for ($i = 0; $i < 7; $i++) {
            $d         = date('Y-m-d', strtotime($monday . " +{$i} days"));
            $labels[]  = $dayNames[$i];
            $values[]  = $d <= $today ? ($byDay[$d] ?? 0.0) : null;
        }
        return [$labels, $values];
    }

    // All days of current month, real data up to today, null for future dates
    private function revenueMonth(\PDO $db): array
    {
        $year        = (int) date('Y');
        $month       = (int) date('n');
        $todayDay    = (int) date('j');
        $daysInMonth = (int) date('t');

        $rows = $db->query(
            "SELECT DAY(created_at) as day, COALESCE(SUM(total_amount),0) as revenue
             FROM orders
             WHERE status='delivered'
               AND MONTH(created_at)={$month} AND YEAR(created_at)={$year}
             GROUP BY DAY(created_at)"
        )->fetchAll();
        $byDay = [];
        foreach ($rows as $r) $byDay[(int) $r['day']] = (float) $r['revenue'];

        $labels = [];
        $values = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $labels[] = (string) $d;
            $values[] = $d <= $todayDay ? ($byDay[$d] ?? 0.0) : null;
        }
        return [$labels, $values];
    }

    // Jan–Dec, real data up to current month, null for future months
    private function revenueYear(\PDO $db): array
    {
        $currentMonth = (int) date('n');
        $year         = (int) date('Y');
        $monthNames   = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

        $rows = $db->query(
            "SELECT MONTH(created_at) as month, COALESCE(SUM(total_amount),0) as revenue
             FROM orders
             WHERE status='delivered' AND YEAR(created_at)={$year}
             GROUP BY MONTH(created_at)"
        )->fetchAll();
        $byMonth = [];
        foreach ($rows as $r) $byMonth[(int) $r['month']] = (float) $r['revenue'];

        $labels = [];
        $values = [];
        for ($m = 1; $m <= 12; $m++) {
            $labels[] = $monthNames[$m - 1];
            $values[] = $m <= $currentMonth ? ($byMonth[$m] ?? 0.0) : null;
        }
        return [$labels, $values];
    }

    // Historical data grouped by month (<=24 months) or year (>24 months)
    private function revenueAll(\PDO $db): array
    {
        $span = (int) $db->query(
            "SELECT TIMESTAMPDIFF(MONTH, MIN(created_at), MAX(created_at)) FROM orders"
        )->fetchColumn();

        if ($span <= 24) {
            $rows   = $db->query(
                "SELECT DATE_FORMAT(created_at,'%Y-%m') as lbl,
                        COALESCE(SUM(total_amount),0) as revenue
                 FROM orders WHERE status='delivered'
                 GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY lbl ASC"
            )->fetchAll();
            $labels = array_map(fn($r) => date('M y', strtotime($r['lbl'].'-01')), $rows);
        } else {
            $rows   = $db->query(
                "SELECT YEAR(created_at) as lbl, COALESCE(SUM(total_amount),0) as revenue
                 FROM orders WHERE status='delivered'
                 GROUP BY YEAR(created_at) ORDER BY lbl ASC"
            )->fetchAll();
            $labels = array_map(fn($r) => (string) $r['lbl'], $rows);
        }
        $values = array_map(fn($r) => (float) $r['revenue'], $rows);
        return [$labels, $values];
    }

    // ── GET /api/dashboard/top-services?period=week|month|year|all ───────────
    public function topServices(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db     = Database::connect();
        $period = (string) ($req->query('period') ?? 'week');
        $where  = $this->periodWhere($period);

        $rows = $db->query(
            "SELECT st.name, COUNT(o.order_id) as count
             FROM orders o
             JOIN service_types st ON o.service_type_id = st.id
             WHERE 1=1 {$where}
             GROUP BY st.id, st.name
             ORDER BY count DESC LIMIT 5"
        )->fetchAll();

        $maxCount = max(1, (int) ($rows[0]['count'] ?? 1));
        $services = [];
        foreach ($rows as $r) {
            $services[] = [
                'name'  => $r['name'],
                'count' => (int) $r['count'],
                'pct'   => (int) round((int) $r['count'] / $maxCount * 100),
            ];
        }
        Response::success('Top services loaded.', $services);
    }

    // ── GET /api/dashboard/order-status?period=week|month|year|all ───────────
    public function orderStatus(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db     = Database::connect();
        $period = (string) ($req->query('period') ?? 'week');
        $where  = $this->periodWhere($period);

        $rows = $db->query(
            "SELECT status, COUNT(*) as cnt FROM orders WHERE 1=1 {$where} GROUP BY status"
        )->fetchAll();

        $byStatus = [];
        foreach ($rows as $r) $byStatus[$r['status']] = (int) $r['cnt'];

        $meta = [
            'schedule'         => ['label' => 'Schedule',         'color' => '#D97706'],
            'confirm'          => ['label' => 'Confirm',          'color' => '#2563EB'],
            'collected'        => ['label' => 'Collected',        'color' => '#7C3AED'],
            'processing'       => ['label' => 'Processing',       'color' => '#C2410C'],
            'ready'            => ['label' => 'Ready',            'color' => '#065F46'],
            'out_for_delivery' => ['label' => 'Out for Delivery', 'color' => '#0E7490'],
            'delivered'        => ['label' => 'Delivered',        'color' => '#059669'],
            'canceled'         => ['label' => 'Canceled',         'color' => '#DC2626'],
        ];

        $result = [];
        foreach ($meta as $key => $m) {
            $result[] = [
                'label' => $m['label'],
                'value' => $byStatus[$key] ?? 0,
                'color' => $m['color'],
            ];
        }
        Response::success('Order status loaded.', $result);
    }

    private function periodWhere(string $period): string
    {
        return match ($period) {
            'week'  => "AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
            'month' => "AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())",
            'year'  => "AND YEAR(created_at)=YEAR(CURDATE())",
            default => '',
        };
    }
}
