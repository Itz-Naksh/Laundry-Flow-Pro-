<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};
use App\Services\NotificationService;
use App\Controllers\CustomerAddressController;

class CustomerOrderController
{
    private const TAX_RATE = 0.08; // 8%

    // ── POST /api/orders ──────────────────────────────────────────────────────
    public function create(Request $req): never
    {
        $ctx = Middleware::requireAuth($req);
        Middleware::requireCsrf($req);
        $db         = Database::connect();
        $customerId = $ctx['user_id'];

        // ── Inputs ────────────────────────────────────────────────────────────
        $serviceId   = (int)    ($req->body('service_id')    ?? 0);
        $pickupDate  = trim((string) ($req->body('pickup_date')  ?? ''));
        $pickupTime  = trim((string) ($req->body('pickup_time')  ?? ''));
        $pickupAddr  = trim((string) ($req->body('pickup_address') ?? ''));
        $pickupAddrId = (int) ($req->body('pickup_address_id') ?? 0);
        $deliveryDate = trim((string) ($req->body('delivery_date') ?? ''));
        $deliveryTime = trim((string) ($req->body('delivery_time') ?? ''));
        $deliveryAddr = trim((string) ($req->body('delivery_address') ?? ''));
        $deliveryAddrId = (int) ($req->body('delivery_address_id') ?? 0);
        $weightLbs   = $req->body('weight_lbs')  !== null ? (float) $req->body('weight_lbs')  : null;
        $itemCount   = $req->body('item_count')  !== null ? (int)   $req->body('item_count')  : null;
        $bagCount    = $req->body('bag_count')   !== null ? (int)   $req->body('bag_count')   : null;
        $promoCode   = strtoupper(trim((string) ($req->body('promo_code') ?? '')));
        $payMethod   = trim((string) ($req->body('payment_method') ?? 'cod'));
        $notes       = trim((string) ($req->body('notes')              ?? ''));
        $specialInst = trim((string) ($req->body('special_instructions') ?? ''));

        // ── Validate required fields ──────────────────────────────────────────
        if ($serviceId <= 0)  Response::error('Service is required.', 422);
        if (!$pickupDate)     Response::error('Pickup date is required.', 422);
        if (!$pickupTime)     Response::error('Pickup time is required.', 422);
        if (!$pickupAddr)     Response::error('Pickup address is required.', 422);
        if (!$deliveryAddr)   Response::error('Delivery address is required.', 422);
        if (!in_array($payMethod, ['card', 'cod'], true))
            Response::error('Invalid payment method. Use "card" or "cod".', 422);

        // ── Validate date is not in the past ──────────────────────────────────
        if (strtotime($pickupDate) < strtotime('today')) {
            Response::error('Pickup date cannot be in the past.', 422);
        }

        // ── Load service ──────────────────────────────────────────────────────
        $svcStmt = $db->prepare(
            "SELECT id, name, base_price, unit FROM service_types WHERE id = ? AND is_active = 1 LIMIT 1"
        );
        $svcStmt->execute([$serviceId]);
        $service = $svcStmt->fetch();
        if (!$service) Response::error('Selected service not found or inactive.', 404);

        // ── Validate measurement for unit type ────────────────────────────────
        $unit = $service['unit'];
        if ($unit === 'lb') {
            if ($weightLbs === null || $weightLbs <= 0) Response::error('Weight (lbs) is required.', 422);
        } elseif ($unit === 'item') {
            if ($itemCount === null || $itemCount <= 0) Response::error('Number of items is required.', 422);
        } elseif ($unit === 'bag') {
            if ($bagCount === null || $bagCount <= 0) Response::error('Number of bags is required.', 422);
        }

        // ── Calculate pricing ─────────────────────────────────────────────────
        $basePrice = (float) $service['base_price'];
        $quantity  = match ($unit) {
            'lb'   => $weightLbs,
            'item' => (float) $itemCount,
            'bag'  => (float) $bagCount,
            default => 0.0,
        };
        $subtotal = round($basePrice * $quantity, 2);

        // ── Apply promo code ──────────────────────────────────────────────────
        $discount    = 0.0;
        $promoCodeId = null;

        if ($promoCode !== '') {
            $promoStmt = $db->prepare(
                "SELECT id, discount_type, discount_value, min_order_value, max_uses, uses_count, valid_until
                 FROM promo_codes WHERE code = ? AND is_active = 1 LIMIT 1"
            );
            $promoStmt->execute([$promoCode]);
            $promo = $promoStmt->fetch();

            if ($promo) {
                $validUntil = $promo['valid_until'];
                $maxUses    = $promo['max_uses'];
                $usesCount  = (int) $promo['uses_count'];
                $isExpired  = $validUntil && strtotime($validUntil) < time();
                $isExhausted = $maxUses !== null && $usesCount >= (int) $maxUses;

                if (!$isExpired && !$isExhausted && $subtotal >= (float) $promo['min_order_value']) {
                    $promoCodeId = (int) $promo['id'];
                    if ($promo['discount_type'] === 'percent') {
                        $discount = round($subtotal * ((float) $promo['discount_value'] / 100), 2);
                    } else {
                        $discount = min((float) $promo['discount_value'], $subtotal);
                    }
                }
            }
        }

        $taxable     = max(0, $subtotal - $discount);
        $tax         = round($taxable * self::TAX_RATE, 2);
        $totalAmount = round($taxable + $tax, 2);

        // ── Generate order number ─────────────────────────────────────────────
        $year  = date('Y');
        // order_number = LFP-YYYY####  → pos 9 is where the 4-digit seq begins
        $last  = (int) $db->query(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(order_number,9) AS UNSIGNED)),0)
             FROM orders WHERE order_number LIKE 'LFP-{$year}%'"
        )->fetchColumn();
        $orderNumber = 'LFP-' . $year . str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);

        // ── Insert order (atomic: order + history + promo use all-or-nothing) ──
        $pickupScheduledAt = $pickupDate . ' ' . $pickupTime . ':00';

        try {
            $db->beginTransaction();

            // ── Resolve address FK ids (select saved OR create-from-text) ──────
            // A selected saved address wins; otherwise the typed address is
            // saved to the customer's address book and reused. Either way the
            // order stores both the FK id and a denormalised text copy.
            [$pickupAddrIdResolved, $pickupAddr] =
                $this->resolveAddress($db, $customerId, $pickupAddrId, $pickupAddr);
            [$deliveryAddrIdResolved, $deliveryAddr] =
                $this->resolveAddress($db, $customerId, $deliveryAddrId, $deliveryAddr);

            $ins = $db->prepare(
                "INSERT INTO orders (
                   order_number, customer_id, service_type_id, promo_code_id, status,
                   pickup_address_id, delivery_address_id,
                   pickup_scheduled_at, pickup_date, pickup_time,
                   pickup_address, delivery_date, delivery_time, delivery_address,
                   weight_lbs, item_count, bag_count,
                   subtotal, discount, tax, total_amount,
                   payment_method, notes, special_instructions,
                   tracking_token, created_at
                 ) VALUES (
                   ?, ?, ?, ?, 'schedule',
                   ?, ?,
                   ?, ?, ?,
                   ?, ?, ?, ?,
                   ?, ?, ?,
                   ?, ?, ?, ?,
                   ?, ?, ?,
                   ?, NOW()
                 )"
            );

            $ins->execute([
                $orderNumber, $customerId, $serviceId, $promoCodeId,
                $pickupAddrIdResolved, $deliveryAddrIdResolved,
                $pickupScheduledAt, $pickupDate ?: null, $pickupTime ?: null,
                $pickupAddr, $deliveryDate ?: null, $deliveryTime ?: null, $deliveryAddr,
                $weightLbs, $itemCount, $bagCount,
                $subtotal, $discount, $tax, $totalAmount,
                $payMethod,
                $notes       ?: null,
                $specialInst ?: null,
                bin2hex(random_bytes(32)),
            ]);

            $orderId = (int) $db->lastInsertId();

            // ── Log initial status history ────────────────────────────────────
            $db->prepare(
                "INSERT INTO order_status_history (order_id, status, note, changed_by, created_at)
                 VALUES (?, 'schedule', 'Order placed by customer.', ?, NOW())"
            )->execute([$orderId, $customerId]);

            // ── Increment promo uses ──────────────────────────────────────────
            if ($promoCodeId) {
                $db->prepare("UPDATE promo_codes SET uses_count = uses_count + 1 WHERE id = ?")
                   ->execute([$promoCodeId]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[CustomerOrderController] order create failed: ' . $e->getMessage());
            Response::error('Failed to place order. Please try again.', 500);
        }

        // ── Fire notification events (fail-silent) ────────────────────────────
        $customerInfo = NotificationService::getCustomerInfo($customerId);
        if ($customerInfo) {
            NotificationService::onOrderPlaced(
                $customerId,
                $customerInfo['email'],
                $customerInfo['full_name'],
                [
                    'order_id'      => $orderId,
                    'order_number'  => $orderNumber,
                    'service_name'  => $service['name'],
                    'pickup_date'   => $pickupDate,
                    'delivery_date' => $deliveryDate,
                    'total_amount'  => $totalAmount,
                ]
            );
        }

        Response::success('Order created successfully.', [
            'order_id'     => $orderId,
            'order_number' => $orderNumber,
            'status'       => 'schedule',
            'subtotal'     => $subtotal,
            'discount'     => $discount,
            'tax'          => $tax,
            'total_amount' => $totalAmount,
        ], 201);
    }

    /**
     * Resolve an order address to a user_addresses FK id plus a denormalised
     * text line. If a valid saved address id is given it is used (and its
     * canonical line returned); otherwise the typed text is saved to the
     * customer's address book (deduped) and reused for this and future orders.
     *
     * @return array{0:?int,1:string} [address_id, address_text]
     */
    private function resolveAddress(\PDO $db, int $userId, int $addressId, string $text): array
    {
        // 1) Customer picked a saved address from the dropdown
        if ($addressId > 0) {
            $row = CustomerAddressController::findOwned($db, $userId, $addressId);
            if ($row) {
                $line = CustomerAddressController::formatLine($row);
                return [(int) $row['id'], $line !== '' ? $line : $text];
            }
        }

        // 2) Customer typed a new address — save it to the address book
        $text = trim($text);
        if ($text === '') {
            return [null, ''];
        }
        $newId = CustomerAddressController::findOrCreate($db, $userId, $text, '', '', '', 'MY', 'Order');
        return [$newId, $text];
    }

    // ── GET /api/customer/orders/active ──────────────────────────────────────
    public function active(Request $req): never
    {
        $ctx        = Middleware::requireAuth($req);
        $db         = Database::connect();
        $customerId = $ctx['user_id'];

        $stmt = $db->prepare(
            "SELECT o.order_id, o.order_number, o.status, o.payment_method,
                    o.pickup_date, o.pickup_time, o.pickup_address,
                    o.delivery_date, o.delivery_time, o.delivery_address,
                    o.subtotal, o.discount, o.tax, o.total_amount, o.created_at,
                    st.name AS service_name, st.unit AS service_unit, st.icon AS service_icon
             FROM orders o
             JOIN service_types st ON o.service_type_id = st.id
             WHERE o.customer_id = ?
               AND o.status IN ('schedule','confirm','collected','processing','ready','out_for_delivery')
             ORDER BY o.created_at DESC"
        );
        $stmt->execute([$customerId]);
        $orders = $this->castOrders($stmt->fetchAll());
        Response::success('Active orders loaded.', ['orders' => $orders]);
    }

    // ── GET /api/customer/orders/history ──────────────────────────────────────
    public function history(Request $req): never
    {
        $ctx        = Middleware::requireAuth($req);
        $db         = Database::connect();
        $customerId = $ctx['user_id'];

        $stmt = $db->prepare(
            "SELECT o.order_id, o.order_number, o.status, o.payment_method,
                    o.pickup_date, o.pickup_time, o.pickup_address,
                    o.delivery_date, o.delivery_time, o.delivery_address,
                    o.subtotal, o.discount, o.tax, o.total_amount, o.created_at,
                    o.service_type_id,
                    st.name AS service_name, st.unit AS service_unit, st.icon AS service_icon,
                    CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END AS has_review
             FROM orders o
             JOIN service_types st ON o.service_type_id = st.id
             LEFT JOIN reviews r ON r.order_id = o.order_id
             WHERE o.customer_id = ?
               AND o.status IN ('delivered','canceled')
             ORDER BY o.created_at DESC"
        );
        $stmt->execute([$customerId]);
        $orders = $this->castOrders($stmt->fetchAll());
        Response::success('Order history loaded.', ['orders' => $orders]);
    }

    private function castOrders(array $rows): array
    {
        foreach ($rows as &$o) {
            $o['order_id']     = (int)   $o['order_id'];
            $o['subtotal']     = (float) $o['subtotal'];
            $o['discount']     = (float) $o['discount'];
            $o['tax']          = (float) $o['tax'];
            $o['total_amount'] = (float) $o['total_amount'];
            if (isset($o['has_review'])) {
                $o['has_review'] = (bool) $o['has_review'];
            }
            if (isset($o['service_type_id'])) {
                $o['service_type_id'] = (int) $o['service_type_id'];
            }
        }
        unset($o);
        return $rows;
    }

    // ── GET /api/orders ───────────────────────────────────────────────────────
    public function index(Request $req): never
    {
        $ctx        = Middleware::requireAuth($req);
        $db         = Database::connect();
        $customerId = $ctx['user_id'];

        $stmt = $db->prepare(
            "SELECT o.order_id, o.order_number, o.status, o.payment_method,
                    o.pickup_date, o.pickup_time, o.pickup_address,
                    o.delivery_date, o.delivery_address,
                    o.subtotal, o.discount, o.tax, o.total_amount,
                    o.created_at,
                    st.name as service_name, st.unit as service_unit, st.icon as service_icon
             FROM orders o
             JOIN service_types st ON o.service_type_id = st.id
             WHERE o.customer_id = ?
             ORDER BY o.created_at DESC"
        );
        $stmt->execute([$customerId]);
        $orders = $stmt->fetchAll();

        foreach ($orders as &$o) {
            $o['order_id']    = (int)   $o['order_id'];
            $o['subtotal']    = (float) $o['subtotal'];
            $o['discount']    = (float) $o['discount'];
            $o['tax']         = (float) $o['tax'];
            $o['total_amount'] = (float) $o['total_amount'];
        }
        unset($o);

        Response::success('Orders loaded.', ['orders' => $orders]);
    }

    // ── GET /api/orders/{id} ──────────────────────────────────────────────────
    public function show(Request $req, array $params): never
    {
        $ctx        = Middleware::requireAuth($req);
        $db         = Database::connect();
        $customerId = $ctx['user_id'];
        $orderId    = (int) ($params['id'] ?? 0);

        $stmt = $db->prepare(
            "SELECT o.*,
                    st.name as service_name, st.unit as service_unit,
                    st.base_price as service_price, st.icon as service_icon,
                    pc.code as promo_code_label
             FROM orders o
             JOIN service_types st ON o.service_type_id = st.id
             LEFT JOIN promo_codes pc ON o.promo_code_id = pc.id
             WHERE o.order_id = ? AND o.customer_id = ? LIMIT 1"
        );
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();
        if (!$order) Response::error('Order not found.', 404);

        $histStmt = $db->prepare(
            "SELECT status, note, created_at
             FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC"
        );
        $histStmt->execute([$orderId]);
        $timeline = $histStmt->fetchAll();

        $order['subtotal']     = (float) $order['subtotal'];
        $order['discount']     = (float) $order['discount'];
        $order['tax']          = (float) $order['tax'];
        $order['total_amount'] = (float) $order['total_amount'];
        $order['weight_lbs']   = $order['weight_lbs'] !== null ? (float) $order['weight_lbs'] : null;
        $order['item_count']   = $order['item_count']  !== null ? (int)   $order['item_count']  : null;
        $order['bag_count']    = $order['bag_count']   !== null ? (int)   $order['bag_count']   : null;

        Response::success('Order loaded.', [
            'order'    => $order,
            'timeline' => $timeline,
        ]);
    }
}
