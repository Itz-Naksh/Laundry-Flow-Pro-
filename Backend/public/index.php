<?php
declare(strict_types=1);

// ── Silence PHP's HTML error output so it never corrupts the JSON response.
// Errors still go to the Apache/WAMP error log.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

define('ROOT_PATH', dirname(__DIR__));
define('SRC_PATH',  ROOT_PATH . '/src');

// ── PSR-4 autoloader for App\ namespace ──────────────────────────────────
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $relative = str_replace('\\', '/', substr($class, 4));
    $file = SRC_PATH . '/' . $relative . '.php';
    if (file_exists($file)) require $file;
});

// ── Composer autoloader (PHPMailer, etc.) ────────────────────────────────
$composerAutoload = ROOT_PATH . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
}

// ── Load environment variables from Backend/.env ─────────────────────────
\App\Core\Env::load(ROOT_PATH . '/.env');

// ── Global exception handler: always return JSON, never HTML ─────────────
// Without this, any uncaught exception (e.g. DB connection failure) will
// produce PHP's HTML error page, which the JS JSON.parse() then rejects.
set_exception_handler(function (Throwable $e): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    error_log('[LFP error] ' . get_class($e) . ': ' . $e->getMessage()
              . ' in ' . $e->getFile() . ':' . $e->getLine());

    $isDb = str_contains($e->getMessage(), 'Database connection') ||
            str_contains($e->getMessage(), 'SQLSTATE');

    echo json_encode([
        'success' => false,
        'message' => $isDb
            ? 'Database connection failed. Have you run Backend/setup.php?'
            : 'An unexpected server error occurred.',
    ]);
    exit;
});

(new \App\Core\App())->run();
