<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Central notification service.
 * All public methods are static and fail-silent — they must never break the
 * calling controller's response flow.
 */
class NotificationService
{
    // ── Status display labels ─────────────────────────────────────────────────
    private const STATUS_LABELS = [
        'schedule'         => 'Scheduled for Pickup',
        'confirm'          => 'Confirmed',
        'collected'        => 'Laundry Collected',
        'processing'       => 'Processing',
        'ready'            => 'Ready',
        'out_for_delivery' => 'Out for Delivery',
        'delivered'        => 'Delivered',
        'canceled'         => 'Canceled',
    ];

    // ═════════════════════════════════════════════════════════════════════════
    // Event Handlers — called from controllers
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Triggered after a new customer registers.
     */
    public static function onCustomerRegistered(
        int    $userId,
        string $fullName,
        string $email
    ): void {
        // Welcome notification → customer (in-app)
        self::notify(
            $userId,
            'welcome',
            'Welcome to Laundry Flow Pro!',
            'Your account has been successfully created. Book your first laundry service now!'
        );

        // Welcome email → customer (PHPMailer/SMTP)
        self::sendEmail(
            $email,
            $fullName,
            'Welcome to Laundry Flow Pro!',
            EmailService::welcomeTemplate($fullName, $email),
            'welcome'
        );

        // Alert → all admins (in-app)
        self::notifyAdmins(
            'new.customer',
            'New Customer Registered',
            "New customer registered: {$fullName}",
            ['customer_name' => $fullName, 'customer_email' => $email]
        );
    }

    /**
     * Triggered after a customer places an order.
     */
    public static function onOrderPlaced(
        int    $customerId,
        string $customerEmail,
        string $customerName,
        array  $order
    ): void {
        $num = $order['order_number'] ?? "#{$order['order_id']}";

        // In-app → customer
        self::notify(
            $customerId,
            'order.placed',
            'Order Placed Successfully',
            "Your order {$num} has been successfully placed.",
            ['order_id' => $order['order_id'] ?? null, 'order_number' => $num]
        );

        // Email → customer
        self::sendEmail(
            $customerEmail,
            $customerName,
            "Order Confirmed — {$num}",
            EmailService::orderConfirmTemplate($customerName, $order),
            'order.placed'
        );

        // In-app → admins
        self::notifyAdmins(
            'new.order',
            'New Order Received',
            "New order received {$num} from {$customerName}",
            ['order_id' => $order['order_id'] ?? null, 'order_number' => $num]
        );
    }

    /**
     * Triggered after admin updates an order's status.
     */
    public static function onOrderStatusChanged(
        int    $customerId,
        string $customerEmail,
        string $customerName,
        array  $order,
        string $newStatus
    ): void {
        $num   = $order['order_number'] ?? "#{$order['order_id']}";
        $label = self::STATUS_LABELS[$newStatus] ?? ucfirst($newStatus);
        $note  = trim((string) ($order['note'] ?? ''));

        // Optional admin remark appended to the customer message
        $message = "Your order {$num} is now {$label}.";
        if ($note !== '') {
            $message .= " Note: {$note}";
        }

        // In-app → customer
        self::notify(
            $customerId,
            'order.' . $newStatus,
            'Order Status Updated',
            $message,
            ['order_id' => $order['order_id'] ?? null, 'order_number' => $num, 'status' => $newStatus]
        );

        // Email → customer
        self::sendEmail(
            $customerEmail,
            $customerName,
            "Order Status Updated — {$num}",
            EmailService::statusUpdateTemplate($customerName, $order, $newStatus),
            'order.' . $newStatus
        );

        // If delivered: also send review request
        if ($newStatus === 'delivered') {
            self::notify(
                $customerId,
                'review.request',
                'How was your experience?',
                "Your order {$num} has been delivered. Please leave a review!",
                ['order_id' => $order['order_id'] ?? null, 'order_number' => $num]
            );

            self::sendEmail(
                $customerEmail,
                $customerName,
                "Share your experience — {$num}",
                EmailService::reviewRequestTemplate($customerName, $order),
                'review.request'
            );
        }
    }

    /**
     * Triggered after a customer submits a review.
     */
    public static function onReviewSubmitted(
        string $customerName,
        string $serviceName,
        int    $orderId
    ): void {
        self::notifyAdmins(
            'new.review',
            'New Review Submitted',
            "New review submitted by {$customerName} for {$serviceName}",
            ['order_id' => $orderId, 'service_name' => $serviceName]
        );
    }

    /**
     * Admin broadcasts a custom or promotional notification.
     *
     * @param int[]  $userIds     Target customer IDs
     * @param string $type        Notification type (e.g. 'promo', 'custom')
     * @param string $subject
     * @param string $message
     * @param bool   $sendInApp   Whether to create in-app records
     * @param bool   $sendEmail   Whether to send SMTP emails
     * @param string $promoCode   Optional promo code for promotion emails
     * @return int Number of users notified
     */
    public static function broadcast(
        array  $userIds,
        string $type,
        string $subject,
        string $message,
        bool   $sendInApp = true,
        bool   $sendEmail = false,
        string $promoCode = ''
    ): int {
        if (empty($userIds)) return 0;

        $db = Database::connect();
        $count = 0;

        foreach ($userIds as $uid) {
            // Fetch customer info once per user (needed for email)
            $user = null;
            if ($sendEmail) {
                $stmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$uid]);
                $user = $stmt->fetch();
            }

            if ($sendInApp) {
                self::notify((int) $uid, $type, $subject, $message);
            }

            if ($sendEmail && $user) {
                $html = EmailService::promotionTemplate(
                    $user['full_name'],
                    $subject,
                    nl2br(htmlspecialchars($message, ENT_QUOTES)),
                    $promoCode
                );
                self::sendEmail($user['email'], $user['full_name'], $subject, $html, $type);
            }

            $count++;
        }

        return $count;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Primitives
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Insert one in-app notification for a single user.
     */
    public static function notify(
        int    $userId,
        string $type,
        string $subject,
        string $message,
        array  $metadata = []
    ): void {
        try {
            $db = Database::connect();

            // Try with metadata column first (requires migrate_notifications.sql to have been run)
            try {
                $meta = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;
                $db->prepare(
                    "INSERT INTO notifications
                       (user_id, type, channel, subject, message, metadata, sent_at, created_at)
                     VALUES (?, ?, 'in_app', ?, ?, ?, NOW(), NOW())"
                )->execute([$userId, $type, $subject, $message, $meta]);
            } catch (\PDOException $e) {
                // metadata column not yet added — insert without it
                $db->prepare(
                    "INSERT INTO notifications
                       (user_id, type, channel, subject, message, sent_at, created_at)
                     VALUES (?, ?, 'in_app', ?, ?, NOW(), NOW())"
                )->execute([$userId, $type, $subject, $message]);
            }
        } catch (\Throwable $e) {
            error_log('[NotificationService] notify() failed: ' . $e->getMessage());
        }
    }

    /**
     * Insert an in-app notification for every admin / staff user.
     */
    public static function notifyAdmins(
        string $type,
        string $subject,
        string $message,
        array  $metadata = []
    ): void {
        try {
            $db     = Database::connect();
            $admins = $db->query(
                "SELECT id FROM users WHERE role_id IN (1,2) AND status = 'active'"
            )->fetchAll();

            foreach ($admins as $a) {
                self::notify((int) $a['id'], $type, $subject, $message, $metadata);
            }
        } catch (\Throwable $e) {
            error_log('[NotificationService] notifyAdmins() failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetch customer info (name, email) needed for email sending.
     * Returns null silently if not found.
     */
    public static function getCustomerInfo(int $userId): ?array
    {
        try {
            $db   = Database::connect();
            $stmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Enqueue a customer email for asynchronous delivery (fail-silent).
     * The request returns immediately; the worker sends the email in the
     * background. Admins never receive email — only customers.
     */
    public static function sendEmail(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $eventType = ''
    ): void {
        try {
            EmailService::queue($toEmail, $toName, $subject, $htmlBody, '', $eventType);
        } catch (\Throwable $e) {
            error_log('[NotificationService] sendEmail() failed: ' . $e->getMessage());
        }
    }

    /**
     * Count unread in-app notifications for a user.
     */
    public static function unreadCount(int $userId): int
    {
        try {
            $db   = Database::connect();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM notifications
                 WHERE user_id = ? AND channel = 'in_app' AND read_at IS NULL"
            );
            $stmt->execute([$userId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }
}
