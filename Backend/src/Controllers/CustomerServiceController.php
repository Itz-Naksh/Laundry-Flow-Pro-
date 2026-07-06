<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Database};

class CustomerServiceController
{
    // ── GET /api/services ─────────────────────────────────────────────────────
    public function index(Request $req): never
    {
        $db   = Database::connect();
        $stmt = $db->prepare(
            "SELECT st.id, st.slug, st.name, st.description, st.base_price,
                    st.unit, st.turnaround_h, st.is_express, st.icon,
                    COALESCE(ROUND(AVG(r.rating), 1), 0) AS avg_rating,
                    COUNT(r.id) AS review_count
             FROM service_types st
             LEFT JOIN reviews r ON r.service_id = st.id AND r.status = 'approved'
             WHERE st.is_active = 1
             GROUP BY st.id
             ORDER BY st.sort_order ASC, st.id ASC"
        );
        $stmt->execute();
        $services = $stmt->fetchAll();

        foreach ($services as &$s) {
            $s['id']           = (int)   $s['id'];
            $s['base_price']   = (float) $s['base_price'];
            $s['turnaround_h'] = (int)   $s['turnaround_h'];
            $s['is_express']   = (bool)  $s['is_express'];
            $s['avg_rating']   = (float) $s['avg_rating'];
            $s['review_count'] = (int)   $s['review_count'];
        }
        unset($s);

        Response::success('Services loaded.', ['services' => $services]);
    }
}
