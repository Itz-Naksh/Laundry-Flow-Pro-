<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};

class CustomerNotificationController
{
    // ── GET /api/customer/notifications?filter=all|unread ─────────────────────
    public function index(Request $req): never
    {
        $ctx    = Middleware::requireAuth($req);
        $db     = Database::connect();
        $userId = $ctx['user_id'];
        $filter = trim((string) ($req->query('filter') ?? 'all'));

        $conditions = ["n.user_id = ?", "n.channel = 'in_app'"];
        $params     = [$userId];

        if ($filter === 'unread') {
            $conditions[] = "n.read_at IS NULL";
        } elseif (in_array($filter, ['order','promo','review','welcome','system'], true)) {
            $conditions[] = "n.type LIKE ?";
            $params[]     = $filter . '%';
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

        // Unread count
        $unreadStmt = $db->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE user_id = ? AND channel = 'in_app' AND read_at IS NULL"
        );
        $unreadStmt->execute([$userId]);
        $unread = (int) $unreadStmt->fetchColumn();

        Response::success('Notifications loaded.', [
            'notifications' => $notifications,
            'unread_count'  => $unread,
        ]);
    }

    // ── PUT /api/customer/notifications/{id}/read ─────────────────────────────
    public function markRead(Request $req, array $params): never
    {
        $ctx    = Middleware::requireAuth($req);
        Middleware::requireCsrf($req);
        $db     = Database::connect();
        $userId = $ctx['user_id'];
        $id     = (int) ($params['id'] ?? 0);

        $db->prepare(
            "UPDATE notifications SET read_at = NOW()
             WHERE notification_id = ? AND user_id = ? AND read_at IS NULL"
        )->execute([$id, $userId]);

        Response::success('Marked as read.');
    }

    // ── PUT /api/customer/notifications/read-all ──────────────────────────────
    public function markAllRead(Request $req): never
    {
        $ctx    = Middleware::requireAuth($req);
        Middleware::requireCsrf($req);
        $db     = Database::connect();
        $userId = $ctx['user_id'];

        $db->prepare(
            "UPDATE notifications SET read_at = NOW()
             WHERE user_id = ? AND channel = 'in_app' AND read_at IS NULL"
        )->execute([$userId]);

        Response::success('All notifications marked as read.');
    }

    // ── DELETE /api/customer/notifications/{id} ───────────────────────────────
    public function delete(Request $req, array $params): never
    {
        $ctx    = Middleware::requireAuth($req);
        Middleware::requireCsrf($req);
        $db     = Database::connect();
        $userId = $ctx['user_id'];
        $id     = (int) ($params['id'] ?? 0);

        $db->prepare(
            "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?"
        )->execute([$id, $userId]);

        Response::success('Notification deleted.');
    }

    // ── GET /api/customer/notifications/unread-count ──────────────────────────
    public function unreadCount(Request $req): never
    {
        $ctx    = Middleware::requireAuth($req);
        $db     = Database::connect();
        $userId = $ctx['user_id'];

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE user_id = ? AND channel = 'in_app' AND read_at IS NULL"
        );
        $stmt->execute([$userId]);
        $count = (int) $stmt->fetchColumn();

        Response::success('Unread count.', ['unread_count' => $count]);
    }
}
