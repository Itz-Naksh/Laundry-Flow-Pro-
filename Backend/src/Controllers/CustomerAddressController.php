<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};

/**
 * Customer saved-address book (user_addresses table).
 *
 * Powers the pickup/delivery address dropdowns on the booking flow:
 *   GET  /api/addresses   — list the logged-in customer's saved addresses
 *   POST /api/addresses   — save a new address and return it (with id)
 */
class CustomerAddressController
{
    // ── GET /api/addresses ────────────────────────────────────────────────────
    public function index(Request $req): never
    {
        $ctx    = Middleware::requireAuth($req);
        $db     = Database::connect();

        $stmt = $db->prepare(
            "SELECT id, label, street, city, state, postal_code, country, is_default, created_at
             FROM user_addresses
             WHERE user_id = ?
             ORDER BY is_default DESC, id DESC"
        );
        $stmt->execute([$ctx['user_id']]);
        $rows = $stmt->fetchAll();

        $addresses = array_map([self::class, 'shape'], $rows);

        Response::success('Addresses loaded.', ['addresses' => $addresses]);
    }

    // ── POST /api/addresses ───────────────────────────────────────────────────
    public function create(Request $req): never
    {
        $ctx = Middleware::requireAuth($req);
        Middleware::requireCsrf($req);
        $db  = Database::connect();

        $label      = trim((string) ($req->body('label')       ?? 'Home'));
        $street     = trim((string) ($req->body('street')      ?? ''));
        $city       = trim((string) ($req->body('city')        ?? ''));
        $state      = trim((string) ($req->body('state')       ?? ''));
        $postalCode = trim((string) ($req->body('postal_code') ?? ''));
        $country    = trim((string) ($req->body('country')     ?? 'MY'));

        if ($street === '') {
            Response::error('Street/address line is required.', 422);
        }

        $id = self::findOrCreate(
            $db,
            (int) $ctx['user_id'],
            $street,
            $city,
            $state,
            $postalCode,
            $country,
            $label ?: 'Home'
        );

        $stmt = $db->prepare(
            "SELECT id, label, street, city, state, postal_code, country, is_default, created_at
             FROM user_addresses WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        Response::success('Address saved.', ['address' => self::shape($row)], 201);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Shared helpers (also used by CustomerOrderController for FK resolution)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Find an existing address for the user that matches the given street line,
     * or insert a new one. Returns the address id. The first address a user
     * saves becomes their default.
     */
    public static function findOrCreate(
        \PDO   $db,
        int    $userId,
        string $street,
        string $city = '',
        string $state = '',
        string $postalCode = '',
        string $country = 'MY',
        string $label = 'Home'
    ): int {
        $street = trim($street);

        // Reuse an identical saved address to avoid duplicates
        $find = $db->prepare(
            "SELECT id FROM user_addresses
             WHERE user_id = ? AND street = ? AND COALESCE(city,'') = ?
             LIMIT 1"
        );
        $find->execute([$userId, $street, $city]);
        $existing = $find->fetch();
        if ($existing) {
            return (int) $existing['id'];
        }

        // First address for this user becomes the default
        $countStmt = $db->prepare("SELECT COUNT(*) FROM user_addresses WHERE user_id = ?");
        $countStmt->execute([$userId]);
        $isDefault = ((int) $countStmt->fetchColumn() === 0) ? 1 : 0;

        $db->prepare(
            "INSERT INTO user_addresses
               (user_id, label, street, city, state, postal_code, country, is_default, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $userId,
            $label ?: 'Home',
            $street,
            $city ?: '',
            $state ?: null,
            $postalCode ?: '',
            $country ?: 'MY',
            $isDefault,
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Return an address row owned by the user, or null.
     */
    public static function findOwned(\PDO $db, int $userId, int $addressId): ?array
    {
        $stmt = $db->prepare(
            "SELECT id, label, street, city, state, postal_code, country
             FROM user_addresses WHERE id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$addressId, $userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Collapse an address row into a single human-readable line.
     */
    public static function formatLine(array $row): string
    {
        $parts = array_filter([
            $row['street']      ?? '',
            $row['city']        ?? '',
            $row['state']       ?? '',
            $row['postal_code'] ?? '',
        ], static fn($p) => trim((string) $p) !== '');
        return implode(', ', $parts);
    }

    /**
     * Normalise a DB row for JSON output.
     */
    private static function shape(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'label'       => $row['label'] ?? 'Home',
            'street'      => $row['street'] ?? '',
            'city'        => $row['city'] ?? '',
            'state'       => $row['state'] ?? '',
            'postal_code' => $row['postal_code'] ?? '',
            'country'     => $row['country'] ?? 'MY',
            'is_default'  => (bool) ($row['is_default'] ?? false),
            'formatted'   => self::formatLine($row),
        ];
    }
}
