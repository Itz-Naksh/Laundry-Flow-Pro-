<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};
use App\Services\NotificationService;

class AdminOrderController
{
    private const PER_PAGE = 10;

    // ── GET /api/admin/orders/stats ───────────────────────────────────────────
    public function stats(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db = Database::connect();

        $rows = $db->query(
            "SELECT status, COUNT(*) as cnt FROM orders GROUP BY status"
        )->fetchAll();
        $by = [];
        foreach ($rows as $r) $by[$r['status']] = (int) $r['cnt'];

        $processing = 0;
        foreach (['confirm','collected','processing','ready'] as $s) $processing += $by[$s] ?? 0;

        Response::success('Order stats.', [
            'schedule'         => $by['schedule']         ?? 0,
            'processing'       => $processing,
            'out_for_delivery' => $by['out_for_delivery'] ?? 0,
            'delivered'        => $by['delivered']        ?? 0,
        ]);
    }

    // ── GET /api/admin/orders ─────────────────────────────────────────────────
    public function index(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db = Database::connect();

        $page      = max(1, (int) ($req->query('page') ?? 1));
        $perPage   = (int) ($req->query('per_page') ?? self::PER_PAGE);
        $search    = trim((string) ($req->query('search') ?? ''));
        $status    = trim((string) ($req->query('status') ?? ''));
        $svcId     = (int) ($req->query('service_id') ?? 0);
        $dateFrom  = trim((string) ($req->query('date_from') ?? ''));
        $dateTo    = trim((string) ($req->query('date_to') ?? ''));
        $offset    = ($page - 1) * $perPage;

        [$where, $params] = $this->buildWhereClause($search, $status, $svcId, $dateFrom, $dateTo);

        $total = (int) $db->prepare(
            "SELECT COUNT(*) FROM orders o
             JOIN users u ON o.customer_id = u.id
             JOIN service_types st ON o.service_type_id = st.id
             {$where}"
        )->execute($params) ? $db->prepare(
            "SELECT COUNT(*) FROM orders o
             JOIN users u ON o.customer_id = u.id
             JOIN service_types st ON o.service_type_id = st.id
             {$where}"
        )->execute($params) : 0;

        // Re-run with correct binding
        $countStmt = $db->prepare(
            "SELECT COUNT(*) FROM orders o
             JOIN users u ON o.customer_id = u.id
             JOIN service_types st ON o.service_type_id = st.id
             {$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listStmt = $db->prepare(
            "SELECT o.order_id, o.order_number, o.status, o.payment_method,
                    o.weight_lbs, o.item_count, o.subtotal, o.discount, o.tax,
                    o.total_amount, o.pickup_scheduled_at, o.created_at,
                    u.full_name as customer_name, u.email as customer_email, u.phone as customer_phone,
                    st.name as service_name, st.id as service_id
             FROM orders o
             JOIN users u ON o.customer_id = u.id
             JOIN service_types st ON o.service_type_id = st.id
             {$where}
             ORDER BY o.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $listStmt->execute($params);
        $orders = $listStmt->fetchAll();

        foreach ($orders as &$o) {
            $o['order_id']    = (int) $o['order_id'];
            $o['total_amount'] = (float) $o['total_amount'];
            $o['weight_lbs']  = $o['weight_lbs'] !== null ? (float) $o['weight_lbs'] : null;
        }
        unset($o);

        Response::success('Orders loaded.', [
            'orders'       => $orders,
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'total_pages'  => (int) ceil($total / $perPage),
        ]);
    }

    // ── GET /api/admin/orders/{id} ────────────────────────────────────────────
    public function show(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        $db      = Database::connect();
        $orderId = (int) ($params['id'] ?? 0);

        $stmt = $db->prepare(
            "SELECT o.*, u.full_name as customer_name, u.email as customer_email,
                    u.phone as customer_phone, u.avatar_url as customer_avatar,
                    st.name as service_name, st.unit as service_unit,
                    pc.code as promo_code
             FROM orders o
             JOIN users u ON o.customer_id = u.id
             JOIN service_types st ON o.service_type_id = st.id
             LEFT JOIN promo_codes pc ON o.promo_code_id = pc.id
             WHERE o.order_id = ? LIMIT 1"
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) Response::error('Order not found.', 404);

        // Timeline (status history)
        $histStmt = $db->prepare(
            "SELECT status, note, created_at
             FROM order_status_history
             WHERE order_id = ?
             ORDER BY created_at ASC"
        );
        $histStmt->execute([$orderId]);
        $timeline = $histStmt->fetchAll();

        $order['total_amount'] = (float) $order['total_amount'];
        $order['subtotal']     = (float) $order['subtotal'];
        $order['discount']     = (float) $order['discount'];
        $order['tax']          = (float) $order['tax'];
        $order['weight_lbs']   = $order['weight_lbs'] !== null ? (float) $order['weight_lbs'] : null;

        Response::success('Order loaded.', [
            'order'    => $order,
            'timeline' => $timeline,
        ]);
    }

    // Valid forward transitions per status
    private const VALID_TRANSITIONS = [
        'schedule'         => ['confirm',          'canceled'],
        'confirm'          => ['collected',         'canceled'],
        'collected'        => ['processing',        'canceled'],
        'processing'       => ['ready',             'canceled'],
        'ready'            => ['out_for_delivery',  'canceled'],
        'out_for_delivery' => ['delivered',         'canceled'],
        'delivered'        => [],
        'canceled'         => [],
    ];

    // ── PUT /api/admin/orders/{id}/status ─────────────────────────────────────
    public function updateStatus(Request $req, array $params): never
    {
        $ctx     = Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db      = Database::connect();
        $orderId = (int) ($params['id'] ?? 0);
        $status  = trim((string) ($req->body('status') ?? ''));
        $note    = trim((string) ($req->body('note')   ?? ''));

        $allowed = ['schedule','confirm','collected','processing','ready','out_for_delivery','delivered','canceled'];
        if (!in_array($status, $allowed, true)) {
            Response::error('Invalid status value.', 422);
        }

        // Fetch current order status
        $checkStmt = $db->prepare("SELECT order_id, status FROM orders WHERE order_id = ? LIMIT 1");
        $checkStmt->execute([$orderId]);
        $order = $checkStmt->fetch();
        if (!$order) Response::error('Order not found.', 404);

        $currentStatus = $order['status'];

        // Block permanently locked statuses
        if ($currentStatus === 'delivered') {
            Response::error('Delivered orders cannot be modified.', 422);
        }
        if ($currentStatus === 'canceled') {
            Response::error('Canceled orders cannot be modified.', 422);
        }

        // Enforce forward-only workflow
        $validNext = self::VALID_TRANSITIONS[$currentStatus] ?? [];
        if (!in_array($status, $validNext, true)) {
            Response::error("Invalid transition: {$currentStatus} → {$status}.", 422);
        }

        // Update status + delivered_at if applicable
        if ($status === 'delivered') {
            $db->prepare(
                "UPDATE orders SET status = ?, delivered_at = NOW(), updated_at = NOW() WHERE order_id = ?"
            )->execute([$status, $orderId]);
        } else {
            $db->prepare(
                "UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?"
            )->execute([$status, $orderId]);
        }

        // Append to history
        $db->prepare(
            "INSERT INTO order_status_history (order_id, status, note, changed_by, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        )->execute([$orderId, $status, $note ?: null, $ctx['user_id']]);

        // ── Notify customer of status change (fail-silent) ────────────────────
        $fullOrder = $db->prepare(
            "SELECT o.order_id, o.order_number, o.delivery_date,
                    u.id AS customer_id, u.full_name, u.email,
                    st.name AS service_name
             FROM orders o
             JOIN users u ON o.customer_id = u.id
             JOIN service_types st ON o.service_type_id = st.id
             WHERE o.order_id = ? LIMIT 1"
        );
        $fullOrder->execute([$orderId]);
        $row = $fullOrder->fetch();
        if ($row) {
            NotificationService::onOrderStatusChanged(
                (int) $row['customer_id'],
                $row['email'],
                $row['full_name'],
                [
                    'order_id'      => (int) $row['order_id'],
                    'order_number'  => $row['order_number'],
                    'service_name'  => $row['service_name'],
                    'delivery_date' => $row['delivery_date'],
                    'note'          => $note ?: null,
                ],
                $status
            );
        }

        Response::success('Order status updated.', ['status' => $status]);
    }

    // ── DELETE /api/admin/orders/{id} ─────────────────────────────────────────
    public function delete(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db      = Database::connect();
        $orderId = (int) ($params['id'] ?? 0);

        $check = $db->prepare("SELECT order_id FROM orders WHERE order_id = ? LIMIT 1");
        $check->execute([$orderId]);
        if (!$check->fetch()) Response::error('Order not found.', 404);

        $db->prepare("DELETE FROM orders WHERE order_id = ?")->execute([$orderId]);
        Response::success('Order deleted.');
    }

    // ── Helper ────────────────────────────────────────────────────────────────
    private function buildWhereClause(
        string $search, string $status, int $svcId,
        string $dateFrom = '', string $dateTo = ''
    ): array {
        $conditions = [];
        $params     = [];

        if ($search !== '') {
            $conditions[] = "(o.order_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
            $like         = '%' . $search . '%';
            $params[]     = $like;
            $params[]     = $like;
            $params[]     = $like;
        }

        $allowed = ['schedule','confirm','collected','processing','ready','out_for_delivery','delivered','canceled'];
        if ($status !== '' && in_array($status, $allowed, true)) {
            $conditions[] = "o.status = ?";
            $params[]     = $status;
        }

        if ($svcId > 0) {
            $conditions[] = "o.service_type_id = ?";
            $params[]     = $svcId;
        }

        if ($dateFrom !== '') {
            $conditions[] = "DATE(o.created_at) >= ?";
            $params[]     = $dateFrom;
        }

        if ($dateTo !== '') {
            $conditions[] = "DATE(o.created_at) <= ?";
            $params[]     = $dateTo;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        return [$where, $params];
    }
}
