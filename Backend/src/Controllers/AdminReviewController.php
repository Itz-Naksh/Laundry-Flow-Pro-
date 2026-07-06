<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};

class AdminReviewController
{
    // ── GET /api/admin/reviews/stats ──────────────────────────────────────────
    public function stats(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db = Database::connect();

        $total    = (int) $db->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
        $pending  = (int) $db->query("SELECT COUNT(*) FROM reviews WHERE status='pending'")->fetchColumn();
        $approved = (int) $db->query("SELECT COUNT(*) FROM reviews WHERE status='approved'")->fetchColumn();
        $featured = (int) $db->query("SELECT COUNT(*) FROM reviews WHERE is_featured=1 AND status='approved'")->fetchColumn();

        $avgRow = $db->query(
            "SELECT COALESCE(AVG(rating),0) as avg FROM reviews WHERE status='approved'"
        )->fetch();

        $distRows = $db->query(
            "SELECT rating, COUNT(*) as cnt FROM reviews WHERE status='approved'
             GROUP BY rating ORDER BY rating DESC"
        )->fetchAll();

        $dist = [];
        foreach ($distRows as $r) $dist[(int) $r['rating']] = (int) $r['cnt'];

        Response::success('Review stats.', [
            'total'        => $total,
            'pending'      => $pending,
            'approved'     => $approved,
            'featured'     => $featured,
            'average'      => round((float) $avgRow['avg'], 1),
            'distribution' => $dist,
        ]);
    }

    // ── GET /api/admin/reviews ────────────────────────────────────────────────
    public function index(Request $req): never
    {
        Middleware::requireAdmin($req);
        $db     = Database::connect();
        $search = trim((string) ($req->query('search')     ?? ''));
        $rating = trim((string) ($req->query('rating')     ?? ''));
        $status = trim((string) ($req->query('status')     ?? 'all'));

        $conditions = [];
        $params     = [];

        if ($search !== '') {
            $like         = '%' . $search . '%';
            $conditions[] = "(u.full_name LIKE ? OR r.review_text LIKE ? OR r.title LIKE ? OR o.order_number LIKE ?)";
            array_push($params, $like, $like, $like, $like);
        }

        if ($rating !== '' && is_numeric($rating)) {
            $conditions[] = "r.rating = ?";
            $params[]     = (int) $rating;
        }

        if ($status === 'featured') {
            $conditions[] = "r.status = 'approved' AND r.is_featured = 1";
        } elseif (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $conditions[] = "r.status = ?";
            $params[]     = $status;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $stmt = $db->prepare(
            "SELECT r.id, r.rating, r.title, r.review_text, r.status, r.is_featured,
                    r.created_at, r.order_id,
                    u.full_name AS customer_name,
                    o.order_number,
                    COALESCE(st.name, 'Unknown Service') AS service_name
             FROM reviews r
             JOIN users u  ON r.user_id  = u.id
             JOIN orders o ON r.order_id = o.order_id
             LEFT JOIN service_types st ON r.service_id = st.id
             {$where}
             ORDER BY
               CASE r.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
               r.created_at DESC"
        );
        $stmt->execute($params);
        $reviews = $stmt->fetchAll();

        foreach ($reviews as &$rv) {
            $rv['id']          = (int)  $rv['id'];
            $rv['order_id']    = (int)  $rv['order_id'];
            $rv['rating']      = (int)  $rv['rating'];
            $rv['is_featured'] = (bool) $rv['is_featured'];
        }
        unset($rv);

        Response::success('Reviews loaded.', ['reviews' => $reviews]);
    }

    // ── PUT /api/admin/reviews/{id}/approve ───────────────────────────────────
    public function approve(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db = Database::connect();
        $id = (int) ($params['id'] ?? 0);

        $row = $db->prepare("SELECT id FROM reviews WHERE id = ? LIMIT 1");
        $row->execute([$id]);
        if (!$row->fetch()) Response::error('Review not found.', 404);

        $db->prepare("UPDATE reviews SET status='approved' WHERE id=?")->execute([$id]);
        Response::success('Review approved.');
    }

    // ── PUT /api/admin/reviews/{id}/reject ────────────────────────────────────
    public function reject(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db = Database::connect();
        $id = (int) ($params['id'] ?? 0);

        $row = $db->prepare("SELECT id FROM reviews WHERE id = ? LIMIT 1");
        $row->execute([$id]);
        if (!$row->fetch()) Response::error('Review not found.', 404);

        $db->prepare("UPDATE reviews SET status='rejected', is_featured=0 WHERE id=?")->execute([$id]);
        Response::success('Review rejected.');
    }

    // ── PUT /api/admin/reviews/{id}/feature ───────────────────────────────────
    public function toggleFeatured(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db = Database::connect();
        $id = (int) ($params['id'] ?? 0);

        $row = $db->prepare("SELECT id, status, is_featured FROM reviews WHERE id = ? LIMIT 1");
        $row->execute([$id]);
        $review = $row->fetch();
        if (!$review) Response::error('Review not found.', 404);

        if ($review['status'] !== 'approved') {
            Response::error('Only approved reviews can be featured.', 422);
        }

        $newVal = $review['is_featured'] ? 0 : 1;
        $db->prepare("UPDATE reviews SET is_featured=? WHERE id=?")->execute([$newVal, $id]);

        Response::success(
            $newVal ? 'Review featured on homepage.' : 'Review removed from homepage.',
            ['is_featured' => (bool) $newVal]
        );
    }

    // ── DELETE /api/admin/reviews/{id} ────────────────────────────────────────
    public function delete(Request $req, array $params): never
    {
        Middleware::requireAdmin($req);
        Middleware::requireCsrf($req);
        $db = Database::connect();
        $id = (int) ($params['id'] ?? 0);

        $check = $db->prepare("SELECT id FROM reviews WHERE id = ? LIMIT 1");
        $check->execute([$id]);
        if (!$check->fetch()) Response::error('Review not found.', 404);

        $db->prepare("DELETE FROM reviews WHERE id=?")->execute([$id]);
        Response::success('Review deleted.');
    }

    // ── GET /api/reviews/featured  (public — no auth required) ───────────────
    public function featured(Request $req): never
    {
        $db   = Database::connect();
        $stmt = $db->prepare(
            "SELECT r.id, r.rating, r.review_text, r.created_at,
                    u.full_name AS customer_name,
                    COALESCE(st.name, '') AS service_name
             FROM reviews r
             JOIN users u ON r.user_id = u.id
             LEFT JOIN service_types st ON r.service_id = st.id
             WHERE r.status = 'approved' AND r.is_featured = 1
             ORDER BY r.created_at DESC
             LIMIT 10"
        );
        $stmt->execute();
        $reviews = $stmt->fetchAll();
        foreach ($reviews as &$rv) {
            $rv['id']     = (int)   $rv['id'];
            $rv['rating'] = (float) $rv['rating'];
        }
        unset($rv);

        Response::success('Featured reviews loaded.', ['reviews' => $reviews]);
    }
}
