<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};
use App\Services\NotificationService;

class AdminNotificationController
{
    // ── GET /api/admin/notifications?filter=all|unread|order|system|new.* ─────
    public function index(Request $req): never
    {
        $ctx    = Middleware::requireAdmin($req);
        $db     = Database::connect();
        $filter = trim((string) ($req->query('filter') ?? 'all'));

        $conditions = ["n.user_id = ?", "n.channel = 'in_app'"];
        $params     = [$ctx['user_id']];

        if ($filter === 'unread') {
            $conditions[] = "n.read_at IS NULL";
        } elseif ($filter === 'order') {
            $conditions[] = "n.type LIKE 'new.order%'";
        } elseif ($filter === 'customer') {
            $conditions[] = "n.type = 'new.customer'";
        } elseif ($filter === 'review') {
            $conditions[] = "n.type = 'new.review'";
        } elseif ($filter === 'system') {
            $conditions[] = "n.type = 'system'";
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $stmt = $db->prepare(
            "SELECT notification_id AS id, type, subject, message, read_at, created_at
             FROM notifications n
             {$where}
             ORDER BY created_at DESC
             LIMIT 100"
        );
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();

        foreach ($notifications as &$n) {
            $n['id']   = (int) $n['id'];
            $n['read'] = $n['read_at'] !== null;
        }
        unset($n);

        $unreadStmt = $db->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE user_id = ? AND channel = 'in_app' AND read_at IS NULL"
        );
        $unreadStmt->execute([$ctx['user_id']]);
        $unread = (int) $unreadStmt->fetchColumn();

        Response::success('Notifications loaded.', [
            'notifications' => $notifications,
            'unread_count'  => $unread,
        ]);
    }

    // ── POST /api/admin/notifications/send ────────────────────────────────────
    // Custom notification — website and/or email
    public function send(Request $req): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db = Database::connect();

        $subject    = trim((string) ($req->body('subject')     ?? ''));
        $message    = trim((string) ($req->body('message')     ?? ''));
        $type       = trim((string) ($req->body('type')        ?? 'custom'));
        $recipients = $req->body('recipients') ?? 'all';
        $sendInApp  = filter_var($req->body('send_in_app') ?? true,  FILTER_VALIDATE_BOOLEAN);
        $sendEmail  = filter_var($req->body('send_email')  ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$subject) Response::error('Subject is required.', 422);
        if (!$message) Response::error('Message is required.', 422);

        $type = in_array($type, ['custom','promo','system','announcement'], true) ? $type : 'custom';

        $userIds = $this->resolveRecipients($db, $recipients);
        if (empty($userIds)) Response::error('No recipients found.', 422);

        $sent = NotificationService::broadcast(
            $userIds,
            $type,
            $subject,
            $message,
            $sendInApp,
            $sendEmail
        );

        Response::success("Notification sent to {$sent} customer(s).", ['sent_to' => $sent]);
    }

    // ── POST /api/admin/promotions/send ───────────────────────────────────────
    // Promotional campaign — website + SMTP email
    public function sendPromotion(Request $req): never
    {
        $ctx = Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db  = Database::connect();

        $title      = trim((string) ($req->body('title')      ?? ''));
        $subject    = trim((string) ($req->body('subject')    ?? ''));
        $message    = trim((string) ($req->body('message')    ?? ''));
        $promoCode  = strtoupper(trim((string) ($req->body('promo_code')  ?? '')));
        $startDate  = trim((string) ($req->body('start_date') ?? ''));
        $endDate    = trim((string) ($req->body('end_date')   ?? ''));
        $recipients = $req->body('recipients') ?? 'all';
        $sendInApp  = filter_var($req->body('send_in_app') ?? true,  FILTER_VALIDATE_BOOLEAN);
        $sendEmail  = filter_var($req->body('send_email')  ?? true,  FILTER_VALIDATE_BOOLEAN);

        if (!$title)   Response::error('Promotion title is required.', 422);
        if (!$subject) Response::error('Subject is required.', 422);
        if (!$message) Response::error('Message is required.', 422);

        $userIds = $this->resolveRecipients($db, $recipients);
        if (empty($userIds)) Response::error('No recipients found.', 422);

        // Append dates to message if provided
        $fullMessage = $message;
        if ($startDate || $endDate) {
            $fullMessage .= "\n\nValid: " . ($startDate ?: '—') . ' to ' . ($endDate ?: '—');
        }
        if ($promoCode) {
            $fullMessage .= "\nUse code: {$promoCode}";
        }

        $sent = NotificationService::broadcast(
            $userIds,
            'promo',
            $subject,
            $fullMessage,
            $sendInApp,
            $sendEmail,
            $promoCode
        );

        // Log the campaign as a system notification for the admin who sent it
        NotificationService::notify(
            $ctx['user_id'],
            'system',
            "Campaign Sent: {$title}",
            "Promotional campaign \"{$title}\" sent to {$sent} customer(s).",
            ['sent_to' => $sent, 'promo_code' => $promoCode]
        );

        Response::success("Promotion sent to {$sent} customer(s).", ['sent_to' => $sent]);
    }

    // ── PUT /api/admin/notifications/{id}/read ────────────────────────────────
    public function markRead(Request $req, array $params): never
    {
        $ctx = Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db  = Database::connect();
        $id  = (int) ($params['id'] ?? 0);

        $db->prepare(
            "UPDATE notifications SET read_at = NOW()
             WHERE notification_id = ? AND user_id = ? AND read_at IS NULL"
        )->execute([$id, $ctx['user_id']]);

        Response::success('Marked as read.');
    }

    // ── PUT /api/admin/notifications/read-all ─────────────────────────────────
    public function markAllRead(Request $req): never
    {
        $ctx = Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db  = Database::connect();

        $db->prepare(
            "UPDATE notifications SET read_at = NOW()
             WHERE user_id = ? AND channel = 'in_app' AND read_at IS NULL"
        )->execute([$ctx['user_id']]);

        Response::success('All notifications marked as read.');
    }

    // ── DELETE /api/admin/notifications/{id} ──────────────────────────────────
    public function delete(Request $req, array $params): never
    {
        $ctx = Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db  = Database::connect();
        $id  = (int) ($params['id'] ?? 0);

        $db->prepare(
            "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?"
        )->execute([$id, $ctx['user_id']]);

        Response::success('Notification deleted.');
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function resolveRecipients(\PDO $db, mixed $recipients): array
    {
        // Array of specific IDs
        if (is_array($recipients)) {
            return array_map('intval', $recipients);
        }

        $str = trim((string) $recipients);

        if ($str === 'all') {
            $stmt = $db->query("SELECT id FROM users WHERE role_id = 3 AND status = 'active'");
            return array_column($stmt->fetchAll(), 'id');
        }

        // Comma-separated IDs
        if (preg_match('/^[\d,]+$/', $str)) {
            return array_unique(array_filter(array_map('intval', explode(',', $str))));
        }

        // Single numeric ID
        if (is_numeric($str)) {
            return [(int) $str];
        }

        return [];
    }
}
