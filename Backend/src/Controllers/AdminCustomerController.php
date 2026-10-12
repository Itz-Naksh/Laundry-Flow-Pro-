<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};
use App\Models\User;

class AdminCustomerController
{
    private const PER_PAGE = 10;

    // ── GET /api/admin/customers/stats ────────────────────────────────────────
    public function stats(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db = Database::connect();

        $total    = (int) $db->query("SELECT COUNT(*) FROM users WHERE role_id = 3")->fetchColumn();
        $active   = (int) $db->query("SELECT COUNT(*) FROM users WHERE role_id = 3 AND status = 'active'")->fetchColumn();
        $newMonth = (int) $db->query(
            "SELECT COUNT(*) FROM users WHERE role_id = 3
             AND MONTH(created_at) = MONTH(CURDATE())
             AND YEAR(created_at) = YEAR(CURDATE())"
        )->fetchColumn();

        Response::success('Customer stats.', [
            'total'     => $total,
            'active'    => $active,
            'new_month' => $newMonth,
        ]);
    }

    // ── GET /api/admin/customers ──────────────────────────────────────────────
    public function index(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db      = Database::connect();
        $page    = max(1, (int) ($req->query('page')    ?? 1));
        $perPage = min(100, max(1, (int) ($req->query('per_page') ?? self::PER_PAGE)));
        $search  = trim((string) ($req->query('search') ?? ''));
        $role    = trim((string) ($req->query('role')   ?? ''));
        $status  = trim((string) ($req->query('status') ?? ''));
        $offset  = ($page - 1) * $perPage;

        [$where, $params] = $this->buildWhereClause($search, $role, $status);

        $countStmt = $db->prepare(
            "SELECT COUNT(*) FROM users u
             JOIN roles r ON u.role_id = r.id
             {$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listStmt = $db->prepare(
            "SELECT u.id, u.full_name, u.email, u.phone, u.status, u.avatar_url,
                    u.created_at, r.role_name as role,
                    (SELECT COUNT(*) FROM orders WHERE customer_id = u.id) as orders_count
             FROM users u
             JOIN roles r ON u.role_id = r.id
             {$where}
             ORDER BY u.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $listStmt->execute($params);
        $customers = $listStmt->fetchAll();

        foreach ($customers as &$c) {
            $c['id']           = (int) $c['id'];
            $c['orders_count'] = (int) $c['orders_count'];
            $c['role']         = strtolower($c['role']);
        }
        unset($c);

        Response::success('Customers loaded.', [
            'customers'   => $customers,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    }

    // ── GET /api/admin/customers/{id} ─────────────────────────────────────────
    public function show(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        $db  = Database::connect();
        $uid = (int) ($params['id'] ?? 0);

        $stmt = $db->prepare(
            "SELECT u.id, u.full_name, u.email, u.phone, u.address, u.status,
                    u.avatar_url, u.created_at, r.role_name as role
             FROM users u JOIN roles r ON u.role_id = r.id
             WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([$uid]);
        $user = $stmt->fetch();
        if (!$user) Response::error('Customer not found.', 404);

        // Recent orders
        $ordStmt = $db->prepare(
            "SELECT o.order_number, o.status, o.total_amount, o.created_at,
                    st.name as service_name
             FROM orders o
             JOIN service_types st ON o.service_type_id = st.id
             WHERE o.customer_id = ?
             ORDER BY o.created_at DESC LIMIT 5"
        );
        $ordStmt->execute([$uid]);
        $orders = $ordStmt->fetchAll();
        foreach ($orders as &$o) $o['total_amount'] = (float) $o['total_amount'];
        unset($o);

        // Stats
        $orderCount  = (int) $db->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ?")->execute([$uid]) ? 0 : 0;
        $cs = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as total FROM orders WHERE customer_id = ? AND status='delivered'");
        $cs->execute([$uid]);
        $csr = $cs->fetch();

        $user['id']          = (int) $user['id'];
        $user['role']        = strtolower($user['role']);
        $user['total_spent'] = (float) $csr['total'];
        $user['order_count'] = (int) $csr['cnt'];

        Response::success('Customer loaded.', [
            'customer' => $user,
            'orders'   => $orders,
        ]);
    }

    // ── POST /api/admin/customers ─────────────────────────────────────────────
    public function create(Request $req): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db = Database::connect();

        $firstName = trim((string) ($req->body('first_name') ?? ''));
        $lastName  = trim((string) ($req->body('last_name')  ?? ''));
        $email     = trim((string) ($req->body('email')      ?? ''));
        $phone     = trim((string) ($req->body('phone')      ?? ''));
        $password  = (string) ($req->body('password') ?? '');
        $roleStr   = trim((string) ($req->body('role') ?? 'customer'));

        if (!$firstName || !$lastName) Response::error('Name is required.', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Valid email required.', 422);
        if (strlen($password) < 8) Response::error('Password must be at least 8 characters.', 422);

        $existing = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $existing->execute([$email]);
        if ($existing->fetch()) Response::error('Email already registered.', 409);

        $roleMap = ['admin' => 1, 'staff' => 2, 'customer' => 3, 'driver' => 4];
        $roleId  = $roleMap[$roleStr] ?? 3;

        $hash = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]);
        $db->prepare(
            "INSERT INTO users (full_name, email, phone, address, password, role_id, status, email_verified_at)
             VALUES (?, ?, ?, '', ?, ?, 'active', NOW())"
        )->execute(["{$firstName} {$lastName}", $email, $phone, $hash, $roleId]);

        $id = (int) $db->lastInsertId();
        Response::success('Customer created.', ['id' => $id], 201);
    }

    // ── PUT /api/admin/customers/{id} ─────────────────────────────────────────
    public function update(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db  = Database::connect();
        $uid = (int) ($params['id'] ?? 0);

        $check = $db->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        $check->execute([$uid]);
        if (!$check->fetch()) Response::error('Customer not found.', 404);

        $fullName = trim((string) ($req->body('full_name') ?? ''));
        $phone    = trim((string) ($req->body('phone')     ?? ''));
        $address  = trim((string) ($req->body('address')   ?? ''));

        if (!$fullName) Response::error('Name is required.', 422);

        $db->prepare(
            "UPDATE users SET full_name = ?, phone = ?, address = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$fullName, $phone, $address, $uid]);

        Response::success('Customer updated.');
    }

    // ── PUT /api/admin/customers/{id}/status ──────────────────────────────────
    public function updateStatus(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db     = Database::connect();
        $uid    = (int) ($params['id'] ?? 0);
        $status = trim((string) ($req->body('status') ?? ''));

        if (!in_array($status, ['active', 'suspended', 'pending'], true)) {
            Response::error('Invalid status.', 422);
        }

        $check = $db->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        $check->execute([$uid]);
        if (!$check->fetch()) Response::error('Customer not found.', 404);

        $db->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $uid]);
        Response::success('Status updated.', ['status' => $status]);
    }

    // ── DELETE /api/admin/customers/{id} ──────────────────────────────────────
    public function delete(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db  = Database::connect();
        $uid = (int) ($params['id'] ?? 0);

        // Prevent deleting admin accounts
        $check = $db->prepare("SELECT id, role_id FROM users WHERE id = ? LIMIT 1");
        $check->execute([$uid]);
        $user = $check->fetch();
        if (!$user) Response::error('User not found.', 404);
        if ((int) $user['role_id'] === 1) Response::error('Cannot delete admin accounts.', 403);

        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
        Response::success('Customer deleted.');
    }

    // ── Helper ────────────────────────────────────────────────────────────────
    private function buildWhereClause(string $search, string $role, string $status): array
    {
        $conditions = [];
        $params     = [];

        $roleMap = ['customer' => 3, 'admin' => 1, 'staff' => 2, 'driver' => 4];
        if ($role !== '' && isset($roleMap[$role])) {
            $conditions[] = "u.role_id = ?";
            $params[]     = $roleMap[$role];
        } else {
            // Default: exclude no filter applied (show all roles)
        }

        if ($status !== '' && in_array($status, ['active','suspended','pending'], true)) {
            $conditions[] = "u.status = ?";
            $params[]     = $status;
        }

        if ($search !== '') {
            $conditions[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        return [$where, $params];
    }
}
