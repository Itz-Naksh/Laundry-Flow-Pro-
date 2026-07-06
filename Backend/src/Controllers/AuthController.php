<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Request, Response, Middleware, Database};
use App\Models\{User, AuthSession};
use App\Helpers\Validator;
use App\Services\NotificationService;

class AuthController
{
    // ══════════════════════════════════════════════════════════════════════════
    // POST /api/auth/register
    //
    // signup.js payload:
    //   { full_name, email, phone, address ("Street, City ZIP"), password, password_confirmation }
    // ══════════════════════════════════════════════════════════════════════════
    public function register(Request $req): never
    {
        $fullName  = trim((string) $req->body('full_name',            ''));
        $email     = strtolower(trim((string) $req->body('email',     '')));
        $phone     = trim((string) $req->body('phone',                ''));
        $address   = trim((string) $req->body('address',              ''));
        $password  = (string) $req->body('password',                  '');
        $pwConfirm = (string) $req->body('password_confirmation',     '');

        // ── Validate ──────────────────────────────────────────────────────────
        $v = new Validator();
        $v->required('full_name', $fullName,  'Full name')
          ->minLength('full_name', $fullName,  2, 'Full name')
          ->maxLength('full_name', $fullName, 100, 'Full name')
          ->required('email',      $email,    'Email')
          ->email('email',         $email)
          ->required('phone',      $phone,    'Phone')
          ->regex('phone',         $phone,    '/^\+?[\d\s\-().]{7,20}$/', 'Enter a valid phone number')
          ->required('address',    $address,  'Address')
          ->minLength('address',   $address,   5, 'Address')
          ->required('password',   $password, 'Password')
          ->minPasswordStrength('password', $password)
          ->confirmed('password',  $password, $pwConfirm, 'Password');

        if (!$v->passes()) {
            Response::error('Validation failed', 422, $v->errors());
        }

        $userModel = new User();

        // ── Duplicate email check ─────────────────────────────────────────────
        if ($userModel->findByEmail($email)) {
            Response::error('An account with this email already exists.', 409);
        }

        // ── Parse address into components for user_addresses table ────────────
        // Format from signup.js: "Street, City ZIP"
        $addrParts  = array_map('trim', explode(',', $address, 2));
        $street     = $addrParts[0];
        $cityZip    = preg_split('/\s+/', $addrParts[1] ?? '', 2);
        $city       = $cityZip[0] ?? '';
        $postalCode = $cityZip[1] ?? '';

        // ── Persist user ──────────────────────────────────────────────────────
        $userId = $userModel->create([
            'full_name'         => $fullName,
            'email'             => $email,
            'phone'             => $phone,
            'address'           => $address,     // store combined address
            'password'          => $userModel->hashPassword($password),
            'role_id'           => 3,            // Customer
            'status'            => 'active',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);

        // ── Persist structured address ────────────────────────────────────────
        $userModel->saveAddress($userId, [
            'street'      => $street,
            'city'        => $city,
            'postal_code' => $postalCode,
        ]);

        // ── Fire notification events (fail-silent) ────────────────────────────
        NotificationService::onCustomerRegistered($userId, $fullName, $email);

        Response::success('Account created successfully.', [], 201);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /api/auth/login
    // ══════════════════════════════════════════════════════════════════════════
    public function login(Request $req): never
    {
        $email    = strtolower(trim((string) $req->body('email',    '')));
        $password = (string) $req->body('password', '');

        if (!$email || !$password) {
            Response::error('Email and password are required.', 400);
        }

        $userModel = new User();
        $user      = $userModel->findByEmail($email);

        // Intentionally vague — don't reveal which part is wrong
        if (!$user || !$userModel->verifyPassword($password, (string) $user['password'])) {
            Response::error('Invalid email or password.', 401);
        }

        if ($user['status'] === 'suspended') {
            Response::error('Your account has been suspended. Please contact support.', 403);
        }

        if ($user['status'] === 'pending') {
            Response::error('Your account is pending activation.', 403);
        }

        // ── Create DB session token ───────────────────────────────────────────
        $sessionModel = new AuthSession();
        $authToken    = $sessionModel->create(
            (int) $user['id'],
            false,
            $req->ip(),
            $req->userAgent()
        );

        // ── Generate CSRF token ───────────────────────────────────────────────
        $csrfToken = bin2hex(random_bytes(32));

        // ── Persist in PHP session ────────────────────────────────────────────
        $_SESSION['user_id']    = (int) $user['id'];
        $_SESSION['role_id']    = (int) $user['role_id'];
        $_SESSION['csrf_token'] = $csrfToken;
        $_SESSION['auth_token'] = $authToken;

        session_regenerate_id(true);

        Response::success('Login successful.', [
            'user'       => $userModel->toPublic($user),
            'csrf_token' => $csrfToken,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /api/auth/profile  — session heartbeat / validation
    // ══════════════════════════════════════════════════════════════════════════
    public function profile(Request $req): never
    {
        $ctx = Middleware::requireAuth($req);

        $userModel = new User();
        $user      = $userModel->findById($ctx['user_id']);

        if (!$user || $user['status'] === 'suspended') {
            session_unset();
            Response::error('Unauthenticated.', 401);
        }

        Response::success('Profile loaded.', [
            'user' => $userModel->toPublic($user),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /api/auth/logout
    // ══════════════════════════════════════════════════════════════════════════
    public function logout(Request $req): never
    {
        if (!empty($_SESSION['user_id'])) {
            Middleware::requireCsrf($req);

            if (!empty($_SESSION['auth_token'])) {
                (new AuthSession())->deleteByToken($_SESSION['auth_token']);
            }
        }

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600,
                      $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        session_unset();
        session_destroy();

        Response::success('Logged out successfully.');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /api/auth/forgot-password
    // Always HTTP 200 — never reveals whether the email is registered.
    // ══════════════════════════════════════════════════════════════════════════
    public function forgotPassword(Request $req): never
    {
        $email = strtolower(trim((string) $req->body('email', '')));

        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $userModel = new User();
            $user      = $userModel->findByEmail($email);

            if ($user) {
                $plain     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                Database::connect()->prepare(
                    'INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)'
                )->execute([(int) $user['id'], hash('sha256', $plain), $expiresAt]);

                // TODO: send email with: "…/reset-password?token={$plain}"
                error_log("[LFP] Password reset for {$email} — token: {$plain}");
            }
        }

        Response::success('If that email is registered, a reset link has been sent.');
    }
}
