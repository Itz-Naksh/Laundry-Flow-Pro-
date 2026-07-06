<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};

class AdminDashboardController
{
    // ── GET /api/admin/dashboard ──────────────────────────────────────────────
    public function index(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db = Database::connect();

        // Stat cards (new format — also served by /api/dashboard/statistics)
        $revenueMonth = (float) $db->query(
            "SELECT COALESCE(SUM(total_amount),0) FROM orders
             WHERE status='delivered'
               AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())"
        )->fetchColumn();

        $ordersMonth = (int) $db->query(
            "SELECT COUNT(*) FROM orders
             WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())"
        )->fetchColumn();

        $todayDelivery = (int) $db->query(
            "SELECT COUNT(*) FROM orders
             WHERE status='out_for_delivery' AND DATE(updated_at)=CURDATE()"
        )->fetchColumn();

        $avgRating = (float) $db->query(
            "SELECT COALESCE(AVG(rating),0) FROM reviews WHERE status='approved'"
        )->fetchColumn();

        // Revenue chart – last 7 days (default)
        $revenueChart = $this->revenueChartData($db, '7d');

        // Order status distribution (all 8 statuses)
        $statusRows = $db->query(
            "SELECT status, COUNT(*) as cnt FROM orders GROUP BY status"
        )->fetchAll();
        $byStatus = [];
        foreach ($statusRows as $r) $byStatus[$r['status']] = (int) $r['cnt'];

        $statusMeta = [
            'schedule'         => ['label' => 'Schedule',         'color' => '#D97706'],
            'confirm'          => ['label' => 'Confirm',          'color' => '#2563EB'],
            'collected'        => ['label' => 'Collected',        'color' => '#7C3AED'],
            'processing'       => ['label' => 'Processing',       'color' => '#C2410C'],
            'ready'            => ['label' => 'Ready',            'color' => '#065F46'],
            'out_for_delivery' => ['label' => 'Out for Delivery', 'color' => '#0E7490'],
            'delivered'        => ['label' => 'Delivered',        'color' => '#059669'],
            'canceled'         => ['label' => 'Canceled',         'color' => '#DC2626'],
        ];
        $orderStatus = [];
        foreach ($statusMeta as $key => $m) {
            $orderStatus[] = [
                'label' => $m['label'],
                'value' => $byStatus[$key] ?? 0,
                'color' => $m['color'],
            ];
        }

        // Recent orders (last 6)
        $recentOrders = $db->query(
            "SELECT o.order_number as id, u.full_name as customer,
                    st.name as service, o.status, o.total_amount as total
             FROM orders o
             JOIN users u ON o.customer_id = u.id
             JOIN service_types st ON o.service_type_id = st.id
             ORDER BY o.created_at DESC LIMIT 6"
        )->fetchAll();

        // Top services by order count
        $topServices = $db->query(
            "SELECT st.name, COUNT(o.order_id) as count
             FROM orders o
             JOIN service_types st ON o.service_type_id = st.id
             GROUP BY st.id, st.name
             ORDER BY count DESC LIMIT 5"
        )->fetchAll();
        $maxCount = max(1, (int) ($topServices[0]['count'] ?? 1));
        foreach ($topServices as &$s) {
            $s['count'] = (int) $s['count'];
            $s['pct']   = (int) round($s['count'] / $maxCount * 100);
        }
        unset($s);

        // New customers this week
        $newCustomers = $db->query(
            "SELECT full_name as name, email, created_at as joined
             FROM users
             WHERE role_id = 3 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY created_at DESC LIMIT 5"
        )->fetchAll();

        // Recent reviews (last 4, public)
        $recentReviews = $db->query(
            "SELECT u.full_name as name, r.rating, r.review_text as text
             FROM reviews r
             JOIN users u ON r.user_id = u.id
             WHERE r.status = 'approved'
             ORDER BY r.created_at DESC LIMIT 4"
        )->fetchAll();
        foreach ($recentReviews as &$rv) $rv['rating'] = (int) $rv['rating'];
        unset($rv);

        Response::success('Dashboard loaded.', [
            'stats' => [
                'revenue_month'          => round($revenueMonth, 2),
                'orders_month'           => $ordersMonth,
                'today_out_for_delivery' => $todayDelivery,
                'avg_rating'             => round($avgRating, 1),
            ],
            'revenue_chart'  => $revenueChart,
            'order_status'   => $orderStatus,
            'recent_orders'  => $recentOrders,
            'top_services'   => $topServices,
            'new_customers'  => $newCustomers,
            'recent_reviews' => $recentReviews,
        ]);
    }

    // ── GET /api/admin/dashboard/revenue?range=7d|30d|3m ─────────────────────
    public function revenue(Request $req): never
    {
        Middleware::requireAdmin($req);
        $range = (string) ($req->query('range') ?? '7d');
        $data  = $this->revenueChartData(Database::connect(), $range);
        Response::success('Revenue data.', $data);
    }

    // ── Helper: build revenue chart arrays ────────────────────────────────────
    private function revenueChartData(\PDO $db, string $range): array
    {
        switch ($range) {
            case '30d':
                $sql    = "SELECT DATE(created_at) as label, COALESCE(SUM(total_amount),0) as revenue
                           FROM orders
                           WHERE status='delivered' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                           GROUP BY DATE(created_at) ORDER BY label ASC";
                $format = 'M j';
                break;
            case '3m':
                $sql    = "SELECT DATE_FORMAT(created_at,'%Y-%u') as label,
                                  MIN(DATE(created_at)) as week_start,
                                  COALESCE(SUM(total_amount),0) as revenue
                           FROM orders
                           WHERE status='delivered' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                           GROUP BY DATE_FORMAT(created_at,'%Y-%u') ORDER BY label ASC";
                $format = 'M j';
                break;
            default: // 7d
                $sql    = "SELECT DATE(created_at) as label, COALESCE(SUM(total_amount),0) as revenue
                           FROM orders
                           WHERE status='delivered' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                           GROUP BY DATE(created_at) ORDER BY label ASC";
                $format = 'D';
        }

        $rows   = $db->query($sql)->fetchAll();
        $values = [];
        $labels = [];
        foreach ($rows as $r) {
            $dateStr  = $r['week_start'] ?? $r['label'];
            $labels[] = date($format, strtotime($dateStr));
            $values[] = (float) $r['revenue'];
        }

        if ($range === '7d' && count($values) < 7) {
            $filled  = [];
            $filledL = [];
            for ($i = 6; $i >= 0; $i--) {
                $d   = date('Y-m-d', strtotime("-{$i} days"));
                $lbl = date('D', strtotime($d));
                $idx = array_search($lbl, $labels);
                $filledL[] = $lbl;
                $filled[]  = ($idx !== false) ? $values[$idx] : 0.0;
            }
            $values = $filled;
            $labels = $filledL;
        }

        return ['values' => $values, 'labels' => $labels];
    }
}
