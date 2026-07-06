<?php
declare(strict_types=1);

namespace App\Core;

class Middleware
{
    /**
     * Require an active PHP session with user_id set.
     * Returns session context array on success, or exits with 401.
     */
    private const ROLE_MAP = [1 => 'admin', 2 => 'admin', 3 => 'customer', 4 => 'driver'];

    public static function requireAuth(Request $request): array
    {
        if (empty($_SESSION['user_id'])) {
            Response::error('Unauthenticated. Please log in.', 401);
        }
        $roleId = (int) ($_SESSION['role_id'] ?? 3);
        return [
            'user_id'    => (int) $_SESSION['user_id'],
            'role'       => self::ROLE_MAP[$roleId] ?? 'customer',
            'csrf_token' => (string) ($_SESSION['csrf_token'] ?? ''),
        ];
    }

    /**
     * Require a matching X-CSRF-Token header.
     * Call this on any state-mutating authenticated endpoint.
     */
    public static function requireCsrf(Request $request): void
    {
        $header       = $request->header('x-csrf-token') ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? null;

        if (!$sessionToken || !hash_equals($sessionToken, $header)) {
            Response::error('Invalid or missing CSRF token.', 403);
        }
    }

    /**
     * Require the authenticated user to have the 'admin' role.
     */
    public static function requireAdmin(Request $request): array
    {
        $ctx = self::requireAuth($request);
        if ($ctx['role'] !== 'admin') {
            Response::error('Forbidden. Admin access required.', 403);
        }
        return $ctx;
    }
}
