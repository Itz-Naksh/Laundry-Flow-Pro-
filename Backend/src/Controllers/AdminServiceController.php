<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};

class AdminServiceController
{
    // ── GET /api/admin/services ───────────────────────────────────────────────
    public function index(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db = Database::connect();

        $services = $db->query(
            "SELECT st.id, st.slug, st.name, st.description, st.base_price, st.unit,
                    st.turnaround_h, st.is_express, st.icon, st.is_active, st.sort_order,
                    COUNT(o.order_id) as order_count,
                    COALESCE(SUM(CASE WHEN o.status='delivered' THEN o.total_amount ELSE 0 END), 0) as revenue,
                    COALESCE(AVG(r.rating), 0) as avg_rating
             FROM service_types st
             LEFT JOIN orders o ON o.service_type_id = st.id
             LEFT JOIN reviews r ON r.order_id = o.order_id
             GROUP BY st.id
             ORDER BY st.sort_order ASC, st.id ASC"
        )->fetchAll();

        foreach ($services as &$s) {
            $s['id']          = (int) $s['id'];
            $s['base_price']  = (float) $s['base_price'];
            $s['turnaround_h'] = (int) $s['turnaround_h'];
            $s['is_express']  = (bool) $s['is_express'];
            $s['is_active']   = (bool) $s['is_active'];
            $s['sort_order']  = (int) $s['sort_order'];
            $s['order_count'] = (int) $s['order_count'];
            $s['revenue']     = round((float) $s['revenue'], 2);
            $s['avg_rating']  = round((float) $s['avg_rating'], 1);
        }
        unset($s);

        Response::success('Services loaded.', ['services' => $services]);
    }

    // ── POST /api/admin/services ──────────────────────────────────────────────
    public function create(Request $req): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db = Database::connect();

        $data = $this->extractFields($req);
        if (!$data['name']) Response::error('Service name is required.', 422);
        if ($data['base_price'] <= 0) Response::error('Valid price is required.', 422);

        $slug = $this->makeSlug($data['name'], $db);

        $db->prepare(
            "INSERT INTO service_types
             (slug, name, description, base_price, unit, turnaround_h, is_express, icon, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $slug,
            $data['name'],
            $data['description'],
            $data['base_price'],
            $data['unit'],
            $data['turnaround_h'],
            $data['is_express'] ? 1 : 0,
            $data['icon'],
            $data['is_active'] ? 1 : 0,
            $data['sort_order'],
        ]);

        $id = (int) $db->lastInsertId();
        Response::success('Service created.', ['id' => $id], 201);
    }

    // ── PUT /api/admin/services/{id} ──────────────────────────────────────────
    public function update(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db  = Database::connect();
        $id  = (int) ($params['id'] ?? 0);

        $check = $db->prepare("SELECT id FROM service_types WHERE id = ? LIMIT 1");
        $check->execute([$id]);
        if (!$check->fetch()) Response::error('Service not found.', 404);

        $data = $this->extractFields($req);
        if (!$data['name']) Response::error('Service name is required.', 422);

        $db->prepare(
            "UPDATE service_types SET
             name=?, description=?, base_price=?, unit=?, turnaround_h=?,
             is_express=?, icon=?, is_active=?, sort_order=?
             WHERE id=?"
        )->execute([
            $data['name'],
            $data['description'],
            $data['base_price'],
            $data['unit'],
            $data['turnaround_h'],
            $data['is_express'] ? 1 : 0,
            $data['icon'],
            $data['is_active'] ? 1 : 0,
            $data['sort_order'],
            $id,
        ]);

        Response::success('Service updated.');
    }

    // ── DELETE /api/admin/services/{id} ──────────────────────────────────────
    public function delete(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db = Database::connect();
        $id = (int) ($params['id'] ?? 0);

        $check = $db->prepare("SELECT id FROM service_types WHERE id = ? LIMIT 1");
        $check->execute([$id]);
        if (!$check->fetch()) Response::error('Service not found.', 404);

        // Check if service has orders
        $used = $db->prepare("SELECT COUNT(*) FROM orders WHERE service_type_id = ? LIMIT 1");
        $used->execute([$id]);
        if ((int) $used->fetchColumn() > 0) {
            // Soft-delete: set inactive
            $db->prepare("UPDATE service_types SET is_active = 0 WHERE id = ?")->execute([$id]);
            Response::success('Service deactivated (has existing orders).');
        }

        $db->prepare("DELETE FROM service_types WHERE id = ?")->execute([$id]);
        Response::success('Service deleted.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function extractFields(Request $req): array
    {
        return [
            'name'        => trim((string) ($req->body('name')        ?? '')),
            'description' => trim((string) ($req->body('description') ?? '')),
            'base_price'  => (float) ($req->body('base_price')  ?? 0),
            'unit'        => in_array($req->body('unit'), ['lb','item','bag'], true)
                                ? (string) $req->body('unit') : 'lb',
            'turnaround_h' => max(1, (int) ($req->body('turnaround_h') ?? 24)),
            'is_express'  => (bool) ($req->body('is_express') ?? false),
            'icon'        => trim((string) ($req->body('icon') ?? '🧺')),
            'is_active'   => (bool) ($req->body('is_active') ?? true),
            'sort_order'  => (int) ($req->body('sort_order') ?? 0),
        ];
    }

    private function makeSlug(string $name, \PDO $db): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim($slug, '-');
        $orig = $slug;
        $i    = 1;
        while (true) {
            $s = $db->prepare("SELECT id FROM service_types WHERE slug = ? LIMIT 1");
            $s->execute([$slug]);
            if (!$s->fetch()) break;
            $slug = $orig . '-' . $i++;
        }
        return $slug;
    }
}
