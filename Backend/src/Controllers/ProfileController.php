<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};
use App\Models\User;
use App\Helpers\Validator;

class ProfileController
{
    // ══════════════════════════════════════════════════════════════════════════
    // GET /api/profile
    // Returns user info + structured default address.
    // ══════════════════════════════════════════════════════════════════════════
    public function show(Request $req): never
    {
        $ctx       = Middleware::requireAuth($req);
        $userModel = new User();
        $user      = $userModel->findById($ctx['user_id']);

        if (!$user) Response::error('User not found.', 404);

        // Fetch structured address from user_addresses table
        $addrStmt = Database::connect()->prepare(
            'SELECT street, city, state, postal_code, country
             FROM user_addresses
             WHERE user_id = ? AND is_default = 1
             LIMIT 1'
        );
        $addrStmt->execute([$ctx['user_id']]);
        $address = $addrStmt->fetch() ?: null;

        Response::success('Profile loaded.', [
            'user'    => $userModel->toPublic($user),
            'address' => $address,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PUT /api/profile
    // Accepts first_name + last_name (combined → full_name), phone, address parts.
    // ══════════════════════════════════════════════════════════════════════════
    public function update(Request $req): never
    {
        $ctx = Middleware::requireAuth($req);
        Middleware::requireCsrf($req);

        $firstName  = trim((string) $req->body('first_name',  ''));
        $lastName   = trim((string) $req->body('last_name',   ''));
        $phone      = trim((string) $req->body('phone',       ''));
        $street     = trim((string) $req->body('street',      ''));
        $city       = trim((string) $req->body('city',        ''));
        $postalCode = trim((string) $req->body('postal_code', ''));

        // ── Validate ──────────────────────────────────────────────────────────
        $v = new Validator();
        $v->required('first_name', $firstName, 'First name')
          ->minLength('first_name', $firstName, 2, 'First name')
          ->maxLength('first_name', $firstName, 60, 'First name')
          ->required('last_name',  $lastName,  'Last name')
          ->minLength('last_name',  $lastName,  1, 'Last name');

        if ($phone) {
            $v->regex('phone', $phone, '/^\+?[\d\s\-().]{7,20}$/', 'Enter a valid phone number');
        }

        if (!$v->passes()) {
            Response::error('Validation failed', 422, $v->errors());
        }

        $fullName    = trim($firstName . ' ' . $lastName);
        $userModel   = new User();
        $db          = Database::connect();

        // ── Update users row ──────────────────────────────────────────────────
        $userFields = ['full_name' => $fullName];
        if ($phone) $userFields['phone'] = $phone;

        if ($street) {
            $userFields['address'] = $street
                . ($city       ? ", {$city}"       : '')
                . ($postalCode ? " {$postalCode}"  : '');
        }
        $userModel->updateProfile($ctx['user_id'], $userFields);

        // ── Upsert default address row ────────────────────────────────────────
        if ($street) {
            $existing = $db->prepare(
                'SELECT id FROM user_addresses WHERE user_id = ? AND is_default = 1 LIMIT 1'
            );
            $existing->execute([$ctx['user_id']]);
            $row = $existing->fetch();

            if ($row) {
                $db->prepare(
                    'UPDATE user_addresses SET street = ?, city = ?, postal_code = ? WHERE id = ?'
                )->execute([$street, $city, $postalCode, $row['id']]);
            } else {
                $userModel->saveAddress($ctx['user_id'], [
                    'street'      => $street,
                    'city'        => $city,
                    'postal_code' => $postalCode,
                ]);
            }
        }

        $updated = $userModel->findById($ctx['user_id']);
        Response::success('Profile updated successfully.', [
            'user' => $userModel->toPublic($updated),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /api/profile/avatar
    // Multipart upload — field name: "avatar"
    // ══════════════════════════════════════════════════════════════════════════
    public function uploadAvatar(Request $req): never
    {
        $ctx = Middleware::requireAuth($req);
        Middleware::requireCsrf($req);

        if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            Response::error('No valid file uploaded.', 400);
        }

        $file = $_FILES['avatar'];

        if ($file['size'] > 5 * 1024 * 1024) {
            Response::error('File must be under 5 MB.', 400);
        }

        // Validate MIME type from actual file content
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mimeType, $allowed, true)) {
            Response::error('Only JPG, PNG, or WebP images are allowed.', 400);
        }

        $ext = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        };

        $uploadDir = ROOT_PATH . '/uploads/avatars/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $filename = 'user_' . $ctx['user_id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            Response::error('Failed to save the file. Check server write permissions.', 500);
        }

        // Build public URL
        $scriptBase = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
        $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $avatarUrl  = $protocol . '://' . $_SERVER['HTTP_HOST']
                    . $scriptBase . '/uploads/avatars/' . $filename;

        // Delete previous avatar file if hosted locally
        $userModel = new User();
        $user      = $userModel->findById($ctx['user_id']);
        if ($user && $user['avatar_url'] && str_contains((string) $user['avatar_url'], '/uploads/avatars/')) {
            $oldFile = $uploadDir . basename((string) $user['avatar_url']);
            if (is_file($oldFile)) @unlink($oldFile);
        }

        $userModel->updateProfile($ctx['user_id'], ['avatar_url' => $avatarUrl]);

        Response::success('Avatar updated.', ['avatar_url' => $avatarUrl]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PUT /api/profile/password
    // ══════════════════════════════════════════════════════════════════════════
    public function changePassword(Request $req): never
    {
        $ctx = Middleware::requireAuth($req);
        Middleware::requireCsrf($req);

        $currentPw = (string) $req->body('current_password',        '');
        $newPw     = (string) $req->body('new_password',             '');
        $confirmPw = (string) $req->body('new_password_confirmation','');

        $v = new Validator();
        $v->required('current_password', $currentPw, 'Current password')
          ->required('new_password',     $newPw,     'New password')
          ->minPasswordStrength('new_password', $newPw)
          ->confirmed('new_password', $newPw, $confirmPw, 'New password');

        if (!$v->passes()) {
            Response::error('Validation failed', 422, $v->errors());
        }

        $userModel = new User();

        // Fetch the full row so we get the password column
        $stmt = Database::connect()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$ctx['user_id']]);
        $user = $stmt->fetch() ?: null;

        if (!$user || !$userModel->verifyPassword($currentPw, (string) $user['password'])) {
            Response::error('Validation failed', 422, [
                'current_password' => 'Current password is incorrect.',
            ]);
        }

        Database::connect()->prepare(
            'UPDATE users SET password = ? WHERE id = ?'
        )->execute([$userModel->hashPassword($newPw), $ctx['user_id']]);

        Response::success('Password changed successfully.');
    }
}
