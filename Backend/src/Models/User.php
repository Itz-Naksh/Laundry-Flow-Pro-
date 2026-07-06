<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Wraps the `users` table.
 *
 * Actual schema (existing DB):
 *   id, full_name, email, phone, address (combined), password,
 *   role_id (FK → roles: 1=Admin, 2=Staff, 3=Customer, 4=Driver),
 *   status, avatar_url, email_verified_at, remember_token, created_at, updated_at
 */
class User
{
    private PDO $db;

    // role_id → role string used by frontend auth guard (admin.js checks user.role === 'admin')
    private const ROLE_MAP = [1 => 'admin', 2 => 'admin', 3 => 'customer', 4 => 'driver'];

    public function __construct()
    {
        $this->db = Database::connect();
    }

    // ── Lookups ───────────────────────────────────────────────────────────────

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, full_name, email, phone, address, avatar_url,
                    role_id, status, created_at
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // ── Mutations ─────────────────────────────────────────────────────────────

    public function create(array $data): int
    {
        $this->db->prepare(
            'INSERT INTO users
               (full_name, email, phone, address, password, role_id, status, email_verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $data['full_name'],
            $data['email'],
            $data['phone']             ?? null,
            $data['address']           ?? '',
            $data['password'],                 // Argon2id hash
            $data['role_id']           ?? 3,   // 3 = Customer
            $data['status']            ?? 'active',
            $data['email_verified_at'] ?? date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function saveAddress(int $userId, array $addr): void
    {
        $this->db->prepare(
            'INSERT INTO user_addresses (user_id, street, city, postal_code, is_default)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([
            $userId,
            $addr['street']      ?? '',
            $addr['city']        ?? '',
            $addr['postal_code'] ?? '',
        ]);
    }

    public function updateProfile(int $id, array $data): void
    {
        $fields = [];
        $values = [];
        $allowed = ['full_name', 'phone', 'address', 'avatar_url'];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = ?";
                $values[] = $data[$col];
            }
        }
        if (!$fields) return;

        $values[] = $id;
        $this->db->prepare(
            'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?'
        )->execute($values);
    }

    // ── Password ──────────────────────────────────────────────────────────────

    public function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ]);
    }

    public function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    // ── Serialization ─────────────────────────────────────────────────────────

    /**
     * Returns the safe public representation.
     * role_id comes directly from the DB; role string is derived for the
     * admin.js guard which checks `user.role === 'admin'`.
     */
    public function toPublic(array $user): array
    {
        $roleId = (int) ($user['role_id'] ?? 3);

        return [
            'id'         => (int) $user['id'],
            'full_name'  => $user['full_name'] ?? '',
            'email'      => $user['email'],
            'phone'      => $user['phone'] ?? null,
            'role'       => self::ROLE_MAP[$roleId] ?? 'customer',
            'role_id'    => $roleId,
            'avatar_url' => $user['avatar_url'] ?? null,
            'status'     => $user['status'],
        ];
    }
}
