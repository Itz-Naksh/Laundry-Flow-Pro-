<?php
declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use App\Core\Database;

/**
 * Transactional mailer built on PHPMailer (SMTP).
 *
 * Configuration is read from config/email.php, which in turn pulls SMTP
 * credentials from Backend/.env. Every send is wrapped in try/catch and is
 * fail-silent (returns bool) so it can never break a controller's response.
 * Failures are logged to Backend/logs/email.log.
 *
 * Two delivery modes:
 *   queue() — enqueue into `email_queue` and return instantly (used in the
 *             request path; the CLI worker drains and sends asynchronously).
 *   send()  — send synchronously via SMTP (used by the worker).
 */
class EmailService
{
    private static array $cfg = [];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Enqueue an email for asynchronous delivery by the worker. Returns true
     * if the job was queued. Falls back to a synchronous send() if the queue
     * table is unavailable, so no email is silently lost.
     */
    public static function queue(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        string $eventType = ''
    ): bool {
        $cleanTo = filter_var($toEmail, FILTER_VALIDATE_EMAIL);
        if (!$cleanTo) {
            self::logError("Invalid recipient address (queue): {$toEmail}");
            return false;
        }

        $alt = $textBody !== '' ? $textBody : self::htmlToText($htmlBody);

        try {
            Database::connect()->prepare(
                "INSERT INTO email_queue
                   (to_email, to_name, subject, body, alt_body, event_type, status, available_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())"
            )->execute([$cleanTo, $toName, $subject, $htmlBody, $alt, $eventType ?: null]);
            return true;
        } catch (\Throwable $e) {
            // Queue not available (e.g. migration not run) — fall back to
            // a direct synchronous send so the message still goes out.
            self::logError('queue() fell back to direct send: ' . $e->getMessage());
            return self::send($toEmail, $toName, $subject, $htmlBody, $alt);
        }
    }

    /**
     * Send a single HTML email via SMTP. Returns true on success, false on
     * failure (failures are logged, never thrown).
     */
    public static function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = ''
    ): bool {
        $cfg = self::config();

        $cleanTo = filter_var($toEmail, FILTER_VALIDATE_EMAIL);
        if (!$cleanTo) {
            self::logError("Invalid recipient address: {$toEmail}");
            return false;
        }

        if (!class_exists(PHPMailer::class)) {
            self::logError('PHPMailer is not installed — run "composer install" in Backend/.');
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            // ── Transport ──────────────────────────────────────────────────────
            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->Port       = (int) $cfg['port'];
            $mail->SMTPAuth   = $cfg['username'] !== '';
            $mail->Username   = $cfg['username'];
            $mail->Password   = $cfg['password'];
            $mail->CharSet    = PHPMailer::CHARSET_UTF8;
            $mail->Timeout    = 15;

            switch ($cfg['encryption']) {
                case 'ssl':
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    break;
                case 'none':
                    $mail->SMTPSecure  = '';
                    $mail->SMTPAutoTLS = false;
                    break;
                case 'tls':
                default:
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    break;
            }

            if (!empty($cfg['debug'])) {
                $mail->SMTPDebug   = SMTP::DEBUG_CONNECTION;
                $mail->Debugoutput = static fn($str, $level) => self::logError("SMTP[{$level}] {$str}");
            }

            // ── Headers ────────────────────────────────────────────────────────
            $mail->setFrom($cfg['from_email'], $cfg['from_name']);
            $mail->addAddress($cleanTo, $toName);
            if (!empty($cfg['reply_to'])) {
                $mail->addReplyTo($cfg['reply_to'], $cfg['from_name']);
            }

            // ── Content ────────────────────────────────────────────────────────
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody !== '' ? $textBody : self::htmlToText($htmlBody);

            $mail->send();
            return true;

        } catch (PHPMailerException $e) {
            self::logError("Send to {$cleanTo} failed: " . ($mail->ErrorInfo ?: $e->getMessage()));
            return false;
        } catch (\Throwable $e) {
            self::logError("Unexpected error sending to {$cleanTo}: " . $e->getMessage());
            return false;
        }
    }

    // ── Email Templates ───────────────────────────────────────────────────────

    public static function welcomeTemplate(string $name, string $email): string
    {
        $name  = self::e($name);
        $email = self::e($email);
        return self::wrap("Welcome to Laundry Flow Pro, {$name}!", "
            <p style='font-size:16px;color:#374151;'>Hi <strong>{$name}</strong>,</p>
            <p style='color:#6b7280;'>Welcome to <strong>Laundry Flow Pro</strong>! Your account has been successfully created.</p>
            <table width='100%' cellpadding='0' cellspacing='0' style='margin:24px 0;background:#f9fafb;border-radius:8px;padding:16px;'>
              <tr><td style='color:#6b7280;font-size:13px;'>Account Email</td><td style='text-align:right;font-weight:600;color:#111827;'>{$email}</td></tr>
            </table>
            <p style='color:#6b7280;'>You can now book laundry services, track orders, and manage your account from your dashboard.</p>
            <div style='text-align:center;margin:28px 0;'>
              <a href='http://localhost/Laundry%20Flow%20Pro/Frontend/Customer/index.html'
                 style='background:#2563eb;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>
                 Get Started
              </a>
            </div>
        ");
    }

    public static function orderConfirmTemplate(string $name, array $order): string
    {
        $name  = self::e($name);
        $order = array_map(fn($v) => is_scalar($v) ? self::e($v) : $v, $order);
        $amount = number_format((float)($order['total_amount'] ?? 0), 2);
        $pickup = $order['pickup_date'] ?? '—';
        $delivery = $order['delivery_date'] ?? '—';
        return self::wrap("Order Confirmed — {$order['order_number']}", "
            <p style='font-size:16px;color:#374151;'>Hi <strong>{$name}</strong>,</p>
            <p style='color:#6b7280;'>Your laundry order has been successfully placed. Here are the details:</p>
            <table width='100%' cellpadding='0' cellspacing='0' style='margin:20px 0;border-collapse:collapse;'>
              <tr style='background:#f9fafb;'>
                <td style='padding:10px 14px;color:#6b7280;font-size:13px;border-bottom:1px solid #e5e7eb;'>Order Number</td>
                <td style='padding:10px 14px;font-weight:700;color:#2563eb;border-bottom:1px solid #e5e7eb;'>{$order['order_number']}</td>
              </tr>
              <tr>
                <td style='padding:10px 14px;color:#6b7280;font-size:13px;border-bottom:1px solid #e5e7eb;'>Service</td>
                <td style='padding:10px 14px;font-weight:600;color:#111827;border-bottom:1px solid #e5e7eb;'>{$order['service_name']}</td>
              </tr>
              <tr style='background:#f9fafb;'>
                <td style='padding:10px 14px;color:#6b7280;font-size:13px;border-bottom:1px solid #e5e7eb;'>Pickup Date</td>
                <td style='padding:10px 14px;color:#111827;border-bottom:1px solid #e5e7eb;'>{$pickup}</td>
              </tr>
              <tr>
                <td style='padding:10px 14px;color:#6b7280;font-size:13px;border-bottom:1px solid #e5e7eb;'>Estimated Delivery</td>
                <td style='padding:10px 14px;color:#111827;border-bottom:1px solid #e5e7eb;'>{$delivery}</td>
              </tr>
              <tr style='background:#eff6ff;'>
                <td style='padding:12px 14px;color:#374151;font-weight:700;'>Total Amount</td>
                <td style='padding:12px 14px;font-weight:700;color:#2563eb;font-size:18px;'>LKR {$amount}</td>
              </tr>
            </table>
            <p style='color:#6b7280;'>We will notify you as your order progresses through each stage.</p>
        ");
    }

    public static function statusUpdateTemplate(string $name, array $order, string $status): string
    {
        $name  = self::e($name);
        $order = array_map(fn($v) => is_scalar($v) ? self::e($v) : $v, $order);
        $labels = [
            'schedule'         => 'Scheduled for Pickup',
            'confirm'          => 'Confirmed',
            'collected'        => 'Laundry Collected',
            'processing'       => 'Processing',
            'ready'            => 'Ready',
            'out_for_delivery' => 'Out for Delivery',
            'delivered'        => 'Delivered',
            'canceled'         => 'Canceled',
        ];
        $label   = $labels[$status] ?? ucfirst($status);
        $colors  = ['delivered' => '#16a34a', 'canceled' => '#dc2626'];
        $color   = $colors[$status] ?? '#2563eb';
        $delivery = $order['delivery_date'] ?? '—';

        $note      = trim((string) ($order['note'] ?? ''));
        $noteBlock = '';
        if ($note !== '') {
            $safeNote  = $note; // already escaped with the rest of $order above
            $noteBlock = "
              <tr>
                <td style='padding:10px 14px;color:#6b7280;font-size:13px;border-top:1px solid #e5e7eb;'>Remarks</td>
                <td style='padding:10px 14px;color:#111827;border-top:1px solid #e5e7eb;'>{$safeNote}</td>
              </tr>";
        }

        return self::wrap("Order Status Updated — {$order['order_number']}", "
            <p style='font-size:16px;color:#374151;'>Hi <strong>{$name}</strong>,</p>
            <p style='color:#6b7280;'>Your order status has been updated.</p>
            <div style='background:#f9fafb;border-radius:12px;padding:20px;margin:20px 0;text-align:center;'>
              <div style='font-size:13px;color:#6b7280;margin-bottom:6px;'>Order {$order['order_number']}</div>
              <div style='font-size:22px;font-weight:700;color:{$color};'>{$label}</div>
            </div>
            <table width='100%' cellpadding='0' cellspacing='0' style='margin:16px 0;border-collapse:collapse;'>
              <tr style='background:#f9fafb;'>
                <td style='padding:10px 14px;color:#6b7280;font-size:13px;border-bottom:1px solid #e5e7eb;'>Current Status</td>
                <td style='padding:10px 14px;font-weight:700;color:{$color};border-bottom:1px solid #e5e7eb;'>{$label}</td>
              </tr>
              <tr>
                <td style='padding:10px 14px;color:#6b7280;font-size:13px;'>Estimated Delivery</td>
                <td style='padding:10px 14px;color:#111827;'>{$delivery}</td>
              </tr>
              {$noteBlock}
            </table>
        ");
    }

    public static function reviewRequestTemplate(string $name, array $order): string
    {
        $name  = self::e($name);
        $order = array_map(fn($v) => is_scalar($v) ? self::e($v) : $v, $order);
        return self::wrap("How was your experience? — {$order['order_number']}", "
            <p style='font-size:16px;color:#374151;'>Hi <strong>{$name}</strong>,</p>
            <p style='color:#6b7280;'>Your order <strong>{$order['order_number']}</strong> for <strong>{$order['service_name']}</strong> has been delivered. We hope you love it!</p>
            <p style='color:#6b7280;'>Could you take a moment to share your experience? Your feedback helps us serve you better.</p>
            <div style='text-align:center;margin:28px 0;'>
              <a href='http://localhost/Laundry%20Flow%20Pro/Frontend/Customer/profile.html'
                 style='background:#2563eb;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>
                 Leave a Review ⭐
              </a>
            </div>
            <div style='background:#f9fafb;border-radius:8px;padding:14px;margin-top:16px;'>
              <div style='font-size:12px;color:#9ca3af;'>Order Details</div>
              <div style='font-weight:600;color:#111827;'>{$order['order_number']} &mdash; {$order['service_name']}</div>
            </div>
        ");
    }

    public static function promotionTemplate(string $name, string $subject, string $body, string $promoCode = ''): string
    {
        // $body is pre-escaped by the caller (NotificationService)
        $name      = self::e($name);
        $subject   = self::e($subject);
        $promoCode = self::e($promoCode);
        $promoBlock = '';
        if ($promoCode) {
            $promoBlock = "
              <div style='background:#eff6ff;border:2px dashed #93c5fd;border-radius:12px;padding:20px;text-align:center;margin:20px 0;'>
                <div style='font-size:12px;color:#6b7280;margin-bottom:8px;'>USE PROMO CODE</div>
                <div style='font-size:28px;font-weight:800;letter-spacing:4px;color:#2563eb;'>{$promoCode}</div>
              </div>
            ";
        }
        return self::wrap($subject, "
            <p style='font-size:16px;color:#374151;'>Hi <strong>{$name}</strong>,</p>
            <div style='color:#374151;line-height:1.7;'>{$body}</div>
            {$promoBlock}
        ");
    }

    public static function passwordResetTemplate(string $name, string $resetLink): string
    {
        $name      = self::e($name);
        $resetLink = self::e($resetLink);
        return self::wrap('Reset Your Password', "
            <p style='font-size:16px;color:#374151;'>Hi <strong>{$name}</strong>,</p>
            <p style='color:#6b7280;'>We received a request to reset your password. Click the button below — this link expires in 1 hour.</p>
            <div style='text-align:center;margin:28px 0;'>
              <a href='{$resetLink}'
                 style='background:#2563eb;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>
                 Reset Password
              </a>
            </div>
            <p style='color:#9ca3af;font-size:13px;'>If you did not request a password reset, please ignore this email.</p>
        ");
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private static function config(): array
    {
        if (empty(self::$cfg)) {
            self::$cfg = require dirname(__DIR__, 2) . '/config/email.php';
        }
        return self::$cfg;
    }

    /**
     * Derive a plain-text fallback body from an HTML email.
     */
    private static function htmlToText(string $html): string
    {
        return trim(strip_tags(
            str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $html)
        ));
    }

    /**
     * Append a failure message to Backend/logs/email.log and the PHP error log.
     */
    private static function logError(string $message): void
    {
        error_log('[Email] ' . $message);

        try {
            $logDir = dirname(__DIR__, 2) . '/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0775, true);
            }
            $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
            @file_put_contents($logDir . '/email.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Logging must never throw.
        }
    }

    /** Escape user-controlled text before inserting it into email HTML. */
    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function wrap(string $title, string $content): string
    {
        $year = date('Y');
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,.06);">
        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#1d4ed8,#2563eb);padding:28px 32px;text-align:center;">
            <div style="font-size:24px;font-weight:800;color:#fff;letter-spacing:-0.5px;">🧺 Laundry Flow Pro</div>
            <div style="font-size:14px;color:#bfdbfe;margin-top:4px;">{$title}</div>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:32px;">
            {$content}
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#f9fafb;padding:20px 32px;text-align:center;border-top:1px solid #e5e7eb;">
            <p style="margin:0;font-size:12px;color:#9ca3af;">
              &copy; {$year} Laundry Flow Pro. All rights reserved.<br>
              Questions? Reply to this email or contact us through the app.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
