<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};
use App\Services\NotificationService;

class CustomerReviewController
{
    // ── GET /api/reviews/eligible ─────────────────────────────────────────────
    // Returns delivered orders that the authenticated customer has not reviewed.
    public function eligible(Request $req): never
    {
        $ctx        = Middleware::requireAuth($req);
        $db         = Database::connect();
        $customerId = $ctx['user_id'];

        $stmt = $db->prepare(
            "SELECT o.order_id, o.order_number, o.created_at,
                    st.name AS service_name, st.icon AS service_icon
             FROM orders o
             JOIN service_types st ON o.service_type_id = st.id
             LEFT JOIN reviews r ON r.order_id = o.order_id
             WHERE o.customer_id = ?
               AND o.status = 'delivered'
               AND r.id IS NULL
             ORDER BY o.created_at DESC"
        );
        $stmt->execute([$customerId]);
        $orders = $stmt->fetchAll();

        foreach ($orders as &$o) {
            $o['order_id'] = (int) $o['order_id'];
        }
        unset($o);

        Response::success('Eligible orders loaded.', ['orders' => $orders]);
    }

    // ── POST /api/reviews ─────────────────────────────────────────────────────
    // Submits a new review. Validates: delivered order, belongs to customer,
    // no duplicate review. Status defaults to 'pending' (awaiting admin approval).
    public function submit(Request $req): never
    {
        $ctx        = Middleware::requireAuth($req);
        Middleware::requireCsrf($req);
        $db         = Database::connect();
        $customerId = $ctx['user_id'];

        $body       = $req->body();
        $orderId    = (int)  ($body['order_id']    ?? 0);
        $rating     = (int)  ($body['rating']      ?? 0);
        $reviewText = trim((string) ($body['review_text'] ?? ''));
        $title      = trim((string) ($body['title']       ?? ''));

        if ($orderId <= 0)             Response::error('Order ID is required.',           422);
        if ($rating < 1 || $rating > 5) Response::error('Rating must be between 1 and 5.', 422);
        if ($reviewText === '')        Response::error('Review text is required.',         422);

        // Verify order belongs to customer and is delivered
        $orderStmt = $db->prepare(
            "SELECT order_id, service_type_id FROM orders
             WHERE order_id = ? AND customer_id = ? AND status = 'delivered' LIMIT 1"
        );
        $orderStmt->execute([$orderId, $customerId]);
        $order = $orderStmt->fetch();

        if (!$order) {
            Response::error('Order not found or not eligible for review.', 404);
        }

        // Prevent duplicate reviews
        $dupStmt = $db->prepare("SELECT id FROM reviews WHERE order_id = ? LIMIT 1");
        $dupStmt->execute([$orderId]);
        if ($dupStmt->fetch()) {
            Response::error('You have already reviewed this order.', 409);
        }

        $serviceId = (int) $order['service_type_id'];

        $ins = $db->prepare(
            "INSERT INTO reviews
               (order_id, user_id, service_id, rating, title, review_text, status, is_featured, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', 0, NOW())"
        );
        $ins->execute([$orderId, $customerId, $serviceId, $rating, $title ?: null, $reviewText]);

        // ── Notify admins of new review (fail-silent) ─────────────────────────
        $svcName = $db->prepare("SELECT name FROM service_types WHERE id = ? LIMIT 1");
        $svcName->execute([$serviceId]);
        $svcRow = $svcName->fetch();
        $customerRow = NotificationService::getCustomerInfo($customerId);
        NotificationService::onReviewSubmitted(
            $customerRow['full_name'] ?? 'A customer',
            $svcRow['name'] ?? 'a service',
            $orderId
        );

        Response::success(
            'Thank you for your feedback! Your review is pending approval.',
            ['review_id' => (int) $db->lastInsertId()],
            201
        );
    }
}
