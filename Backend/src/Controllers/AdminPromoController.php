<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};

class AdminPromoController
{
    // ── GET /api/admin/promos ─────────────────────────────────────────────────
    public function index(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db     = Database::connect();
        $search = trim((string) ($req->query('search') ?? ''));
        $filter = trim((string) ($req->query('status') ?? 'all'));

        $conditions = [];
        $params     = [];

        if ($search !== '') {
            $conditions[] = "code LIKE ?";
            $params[]     = '%' . $search . '%';
        }

        if ($filter === 'active') {
            $conditions[] = "is_active = 1 AND (valid_until IS NULL OR valid_until >= NOW())";
        } elseif ($filter === 'inactive') {
            $conditions[] = "is_active = 0";
        } elseif ($filter === 'expired') {
            $conditions[] = "valid_until < NOW()";
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $stmt  = $db->prepare(
            "SELECT id, code, description, discount_type, discount_value, min_order_value,
                    max_uses, uses_count, valid_from, valid_until, is_active, created_at
             FROM promo_codes {$where}
             ORDER BY created_at DESC"
        );
        $stmt->execute($params);
        $promos = $stmt->fetchAll();

        foreach ($promos as &$p) {
            $p['id']             = (int) $p['id'];
            $p['discount_value'] = (float) $p['discount_value'];
            $p['min_order_value'] = (float) $p['min_order_value'];
            $p['max_uses']       = $p['max_uses'] !== null ? (int) $p['max_uses'] : null;
            $p['uses_count']     = (int) $p['uses_count'];
            $p['is_active']      = (bool) $p['is_active'];
            // Derived status
            if (!$p['is_active']) {
                $p['status'] = 'inactive';
            } elseif ($p['valid_until'] && strtotime($p['valid_until']) < time()) {
                $p['status'] = 'expired';
            } else {
                $p['status'] = 'active';
            }
        }
        unset($p);

        Response::success('Promos loaded.', ['promos' => $promos]);
    }

    // ── POST /api/admin/promos ────────────────────────────────────────────────
    public function create(Request $req): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db = Database::connect();

        $data = $this->extractFields($req);
        if (!$data['code']) Response::error('Promo code is required.', 422);
        if ($data['discount_value'] <= 0) Response::error('Discount value must be positive.', 422);

        // Duplicate check
        $dup = $db->prepare("SELECT id FROM promo_codes WHERE code = ? LIMIT 1");
        $dup->execute([$data['code']]);
        if ($dup->fetch()) Response::error('Promo code already exists.', 409);

        $db->prepare(
            "INSERT INTO promo_codes
             (code, description, discount_type, discount_value, min_order_value,
              max_uses, valid_from, valid_until, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $data['code'],
            $data['description'],
            $data['discount_type'],
            $data['discount_value'],
            $data['min_order_value'],
            $data['max_uses'],
            $data['valid_from'],
            $data['valid_until'],
            $data['is_active'] ? 1 : 0,
        ]);

        $id = (int) $db->lastInsertId();
        Response::success('Promo created.', ['id' => $id], 201);
    }

    // ── PUT /api/admin/promos/{id} ────────────────────────────────────────────
    public function update(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db = Database::connect();
        $id = (int) ($params['id'] ?? 0);

        $check = $db->prepare("SELECT id FROM promo_codes WHERE id = ? LIMIT 1");
        $check->execute([$id]);
        if (!$check->fetch()) Response::error('Promo not found.', 404);

        $data = $this->extractFields($req);

        $db->prepare(
            "UPDATE promo_codes SET
             description=?, discount_type=?, discount_value=?, min_order_value=?,
             max_uses=?, valid_from=?, valid_until=?, is_active=?
             WHERE id=?"
        )->execute([
            $data['description'],
            $data['discount_type'],
            $data['discount_value'],
            $data['min_order_value'],
            $data['max_uses'],
            $data['valid_from'],
            $data['valid_until'],
            $data['is_active'] ? 1 : 0,
            $id,
        ]);

        Response::success('Promo updated.');
    }

    // ── DELETE /api/admin/promos/{id} ─────────────────────────────────────────
    public function delete(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db = Database::connect();
        $id = (int) ($params['id'] ?? 0);

        $check = $db->prepare("SELECT id FROM promo_codes WHERE id = ? LIMIT 1");
        $check->execute([$id]);
        if (!$check->fetch()) Response::error('Promo not found.', 404);

        $db->prepare("DELETE FROM promo_codes WHERE id = ?")->execute([$id]);
        Response::success('Promo deleted.');
    }

    // ── Helper ────────────────────────────────────────────────────────────────
    private function extractFields(Request $req): array
    {
        $code  = strtoupper(trim((string) ($req->body('code') ?? '')));
        $type  = (string) ($req->body('discount_type') ?? 'percent');
        $type  = in_array($type, ['percent','fixed'], true) ? $type : 'percent';
        $until = trim((string) ($req->body('valid_until') ?? ''));
        $from  = trim((string) ($req->body('valid_from')  ?? ''));
        $max   = $req->body('max_uses');

        return [
            'code'            => $code,
            'description'     => trim((string) ($req->body('description') ?? '')),
            'discount_type'   => $type,
            'discount_value'  => (float) ($req->body('discount_value')  ?? 0),
            'min_order_value' => (float) ($req->body('min_order_value') ?? 0),
            'max_uses'        => ($max !== null && $max !== '') ? (int) $max : null,
            'valid_from'      => $from ? date('Y-m-d H:i:s', strtotime($from)) : null,
            'valid_until'     => $until ? date('Y-m-d H:i:s', strtotime($until)) : null,
            'is_active'       => filter_var($req->body('is_active', true), FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
