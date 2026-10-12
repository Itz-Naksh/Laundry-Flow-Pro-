<?php
declare(strict_types=1);

namespace App\Core;

use App\Controllers\AuthController;
use App\Controllers\ProfileController;
use App\Controllers\CustomerServiceController;
use App\Controllers\CustomerOrderController;
use App\Controllers\CustomerPromoController;
use App\Controllers\AdminDashboardController;
use App\Controllers\AdminOrderController;
use App\Controllers\AdminCustomerController;
use App\Controllers\AdminServiceController;
use App\Controllers\AdminPromoController;
use App\Controllers\AdminReviewController;
use App\Controllers\CustomerReviewController;
use App\Controllers\AdminAnalyticsController;
use App\Controllers\AdminNotificationController;
use App\Controllers\AnalyticsController;
use App\Controllers\ReportExportController;
use App\Controllers\DashboardFilterController;
use App\Controllers\CustomerNotificationController;
use App\Controllers\CustomerAddressController;

class App
{
    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
        $this->registerRoutes();
    }

    public function run(): void
    {
        $this->configureSession();
        $this->setResponseHeaders();

        $request = new Request();
        $this->router->dispatch($request);
    }

    private function registerRoutes(): void
    {
        // ── Auth ──────────────────────────────────────────────────────────────
        $auth = AuthController::class;
        $this->router->add('POST', '/api/auth/register',        [$auth, 'register']);
        $this->router->add('POST', '/api/auth/login',           [$auth, 'login']);
        $this->router->add('GET',  '/api/auth/profile',         [$auth, 'profile']);
        $this->router->add('POST', '/api/auth/logout',          [$auth, 'logout']);
        $this->router->add('POST', '/api/auth/forgot-password', [$auth, 'forgotPassword']);

        // ── Profile (customer + admin) ────────────────────────────────────────
        $profile = ProfileController::class;
        $this->router->add('GET',  '/api/profile',          [$profile, 'show']);
        $this->router->add('PUT',  '/api/profile',          [$profile, 'update']);
        $this->router->add('POST', '/api/profile/avatar',   [$profile, 'uploadAvatar']);
        $this->router->add('PUT',  '/api/profile/password', [$profile, 'changePassword']);

        // ── Customer Services (public) ────────────────────────────────────────
        $this->router->add('GET', '/api/services', [CustomerServiceController::class, 'index']);

        // ── Customer Orders ───────────────────────────────────────────────────
        $custOrd = CustomerOrderController::class;
        $this->router->add('POST', '/api/orders',                      [$custOrd, 'create']);
        $this->router->add('GET',  '/api/orders',                      [$custOrd, 'index']);
        $this->router->add('GET',  '/api/orders/{id}',                 [$custOrd, 'show']);
        $this->router->add('GET',  '/api/customer/orders/active',      [$custOrd, 'active']);
        $this->router->add('GET',  '/api/customer/orders/history',     [$custOrd, 'history']);

        // ── Customer Saved Addresses ──────────────────────────────────────────
        $addr = CustomerAddressController::class;
        $this->router->add('GET',  '/api/addresses', [$addr, 'index']);
        $this->router->add('POST', '/api/addresses', [$addr, 'create']);

        // ── Customer Promo Validation ─────────────────────────────────────────
        $this->router->add('GET', '/api/promo/validate', [CustomerPromoController::class, 'validate']);

        // ── Customer Reviews ──────────────────────────────────────────────────
        $custRev = CustomerReviewController::class;
        $this->router->add('GET',  '/api/reviews/eligible', [$custRev, 'eligible']);
        $this->router->add('POST', '/api/reviews',          [$custRev, 'submit']);

        // ── Admin Dashboard ───────────────────────────────────────────────────
        $dash = AdminDashboardController::class;
        $this->router->add('GET', '/api/admin/dashboard',         [$dash, 'index']);
        $this->router->add('GET', '/api/admin/dashboard/revenue', [$dash, 'revenue']);

        // ── Dashboard Filter APIs ─────────────────────────────────────────────
        $df = DashboardFilterController::class;
        $this->router->add('GET', '/api/dashboard/statistics',   [$df, 'statistics']);
        $this->router->add('GET', '/api/dashboard/revenue',      [$df, 'revenue']);
        $this->router->add('GET', '/api/dashboard/top-services', [$df, 'topServices']);
        $this->router->add('GET', '/api/dashboard/order-status', [$df, 'orderStatus']);

        // ── Admin Orders ──────────────────────────────────────────────────────
        $orders = AdminOrderController::class;
        $this->router->add('GET',    '/api/admin/orders',              [$orders, 'index']);
        $this->router->add('GET',    '/api/admin/orders/stats',        [$orders, 'stats']);
        $this->router->add('GET',    '/api/admin/orders/{id}',         [$orders, 'show']);
        $this->router->add('PUT',    '/api/admin/orders/{id}/status',  [$orders, 'updateStatus']);
        $this->router->add('DELETE', '/api/admin/orders/{id}',         [$orders, 'delete']);

        // ── Admin Customers ───────────────────────────────────────────────────
        $cust = AdminCustomerController::class;
        $this->router->add('GET',    '/api/admin/customers',              [$cust, 'index']);
        $this->router->add('GET',    '/api/admin/customers/stats',        [$cust, 'stats']);
        $this->router->add('GET',    '/api/admin/customers/{id}',         [$cust, 'show']);
        $this->router->add('POST',   '/api/admin/customers',              [$cust, 'create']);
        $this->router->add('PUT',    '/api/admin/customers/{id}',         [$cust, 'update']);
        $this->router->add('PUT',    '/api/admin/customers/{id}/status',  [$cust, 'updateStatus']);
        $this->router->add('DELETE', '/api/admin/customers/{id}',         [$cust, 'delete']);

        // ── Admin Services ────────────────────────────────────────────────────
        $svc = AdminServiceController::class;
        $this->router->add('GET',    '/api/admin/services',      [$svc, 'index']);
        $this->router->add('POST',   '/api/admin/services',      [$svc, 'create']);
        $this->router->add('PUT',    '/api/admin/services/{id}', [$svc, 'update']);
        $this->router->add('DELETE', '/api/admin/services/{id}', [$svc, 'delete']);

        // ── Admin Promos ──────────────────────────────────────────────────────
        $promo = AdminPromoController::class;
        $this->router->add('GET',    '/api/admin/promos',      [$promo, 'index']);
        $this->router->add('POST',   '/api/admin/promos',      [$promo, 'create']);
        $this->router->add('PUT',    '/api/admin/promos/{id}', [$promo, 'update']);
        $this->router->add('DELETE', '/api/admin/promos/{id}', [$promo, 'delete']);

        // ── Public Featured Reviews ───────────────────────────────────────────
        $this->router->add('GET', '/api/reviews/featured', [AdminReviewController::class, 'featured']);

        // ── Admin Reviews ─────────────────────────────────────────────────────
        $rev = AdminReviewController::class;
        $this->router->add('GET',    '/api/admin/reviews',                [$rev, 'index']);
        $this->router->add('GET',    '/api/admin/reviews/stats',           [$rev, 'stats']);
        $this->router->add('PUT',    '/api/admin/reviews/{id}/approve',    [$rev, 'approve']);
        $this->router->add('PUT',    '/api/admin/reviews/{id}/reject',     [$rev, 'reject']);
        $this->router->add('PUT',    '/api/admin/reviews/{id}/feature',    [$rev, 'toggleFeatured']);
        $this->router->add('DELETE', '/api/admin/reviews/{id}',            [$rev, 'delete']);

        // ── Admin Analytics & Reports (legacy) ───────────────────────────────
        $analytics = AdminAnalyticsController::class;
        $this->router->add('GET', '/api/admin/analytics',              [$analytics, 'index']);
        $this->router->add('GET', '/api/admin/analytics/services',     [$analytics, 'services']);
        $this->router->add('GET', '/api/admin/analytics/top-customers',[$analytics, 'topCustomers']);
        $this->router->add('GET', '/api/admin/analytics/report',       [$analytics, 'report']);

        // ── Analytics Microservice (period-aware, with % change) ─────────────
        $this->router->add('GET', '/api/analytics', [AnalyticsController::class, 'index']);

        // ── Report Export Microservice ────────────────────────────────────────
        $export = ReportExportController::class;
        $this->router->add('POST', '/api/reports/export/excel', [$export, 'excel']);
        $this->router->add('POST', '/api/reports/export/pdf',   [$export, 'pdf']);

        // ── Customer Notifications ────────────────────────────────────────────
        $custNotif = CustomerNotificationController::class;
        $this->router->add('GET',    '/api/customer/notifications',               [$custNotif, 'index']);
        $this->router->add('GET',    '/api/customer/notifications/unread-count',  [$custNotif, 'unreadCount']);
        $this->router->add('PUT',    '/api/customer/notifications/read-all',      [$custNotif, 'markAllRead']);
        $this->router->add('PUT',    '/api/customer/notifications/{id}/read',     [$custNotif, 'markRead']);
        $this->router->add('DELETE', '/api/customer/notifications/{id}',          [$custNotif, 'delete']);

        // ── Admin Notifications ───────────────────────────────────────────────
        $notif = AdminNotificationController::class;
        $this->router->add('GET',    '/api/admin/notifications',          [$notif, 'index']);
        $this->router->add('POST',   '/api/admin/notifications/send',     [$notif, 'send']);
        $this->router->add('PUT',    '/api/admin/notifications/read-all', [$notif, 'markAllRead']);
        $this->router->add('PUT',    '/api/admin/notifications/{id}/read',[$notif, 'markRead']);
        $this->router->add('DELETE', '/api/admin/notifications/{id}',     [$notif, 'delete']);

        // ── Admin Promotional Campaigns ───────────────────────────────────────
        $this->router->add('POST', '/api/admin/promotions/send', [$notif, 'sendPromotion']);
    }

    private function configureSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) return;

        session_name('lfp_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    private function setResponseHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin && $this->isAllowedOrigin($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
            header('Access-Control-Max-Age: 86400');
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    /**
     * Only this site's own origin, plus any listed in CORS_ALLOWED_ORIGINS
     * (comma-separated in .env), may make credentialed cross-origin calls.
     */
    private function isAllowedOrigin(string $origin): bool
    {
        $self = (self::isHttps() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        if (strcasecmp($origin, $self) === 0) return true;

        $allowed = array_filter(array_map('trim', explode(',', (string) Env::get('CORS_ALLOWED_ORIGINS', ''))));
        return in_array($origin, $allowed, true);
    }

    private static function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
}
