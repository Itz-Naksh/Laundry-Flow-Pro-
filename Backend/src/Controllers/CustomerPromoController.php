<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};

class CustomerPromoController
{
    // ── GET /api/promo/validate?code=XXXX ─────────────────────────────────────
    public function validate(Request $req): never
    {
        Middleware::requireAuth($req);
        $db   = Database::connect();
        $code = strtoupper(trim((string) ($req->query('code') ?? '')));

        if ($code === '') Response::error('Promo code is required.', 422);

        $stmt = $db->prepare(
            "SELECT id, code, description, discount_type, discount_value,
                    min_order_value, max_uses, uses_count, valid_until
             FROM promo_codes
             WHERE code = ? AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$code]);
        $promo = $stmt->fetch();

        if (!$promo) Response::error('Promo code not found or inactive.', 404);

        if ($promo['valid_until'] && strtotime($promo['valid_until']) < time()) {
            Response::error('Promo code has expired.', 422);
        }

        if ($promo['max_uses'] !== null && (int) $promo['uses_count'] >= (int) $promo['max_uses']) {
            Response::error('Promo code has reached its usage limit.', 422);
        }

        Response::success('Promo code is valid.', [
            'id'              => (int)   $promo['id'],
            'code'            => $promo['code'],
            'description'     => $promo['description'],
            'discount_type'   => $promo['discount_type'],
            'discount_value'  => (float) $promo['discount_value'],
            'min_order_value' => (float) $promo['min_order_value'],
        ]);
    }
}
