<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

class AuthSession
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * Creates a new session record and returns the PLAIN token (never stored raw in DB).
     */
    public function create(int $userId, bool $rememberMe, string $ip, string $userAgent): string
    {
        $plain     = bin2hex(random_bytes(32));       // 64-char hex token
        $hashed    = hash('sha256', $plain);
        $lifetime  = $rememberMe ? 30 * 86400 : 86400; // 30 days or 24 hours
        $expiresAt = date('Y-m-d H:i:s', time() + $lifetime);

        $this->db->prepare(
            'INSERT INTO auth_sessions
               (user_id, token, ip_address, user_agent, remember_me, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $hashed, $ip, $userAgent, $rememberMe ? 1 : 0, $expiresAt]);

        return $plain;
    }

    /**
     * Validates a plain token, refreshes last_used_at, and returns the session row.
     */
    public function validate(string $plain): ?array
    {
        $hashed = hash('sha256', $plain);
        $stmt   = $this->db->prepare(
            'SELECT * FROM auth_sessions
             WHERE token = ? AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$hashed]);
        $session = $stmt->fetch() ?: null;

        if ($session) {
            $this->db->prepare(
                'UPDATE auth_sessions SET last_used_at = NOW() WHERE id = ?'
            )->execute([$session['id']]);
        }

        return $session;
    }

    public function deleteByToken(string $plain): void
    {
        $this->db->prepare(
            'DELETE FROM auth_sessions WHERE token = ?'
        )->execute([hash('sha256', $plain)]);
    }

    public function deleteAllForUser(int $userId): void
    {
        $this->db->prepare(
            'DELETE FROM auth_sessions WHERE user_id = ?'
        )->execute([$userId]);
    }

    /** Prune expired sessions from DB (can be called on a schedule). */
    public function pruneExpired(): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM auth_sessions WHERE expires_at < NOW()'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
