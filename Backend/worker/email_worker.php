<?php
declare(strict_types=1);

/*
 * ───────────────────────────────────────────────────────────────
 * Laundry Flow Pro — Email Microservice Worker (CLI)
 *
 * Drains the `email_queue` table and sends pending emails via PHPMailer.
 * Failed sends are retried with exponential backoff; after max_attempts a
 * job is dead-lettered (status='dead'). Every outcome is logged to
 * Backend/logs/email.log.
 *
 * Usage:
 *   "C:\xampp\php\php.exe" "C:\xampp\htdocs\Laundry Flow Pro\Backend\worker\email_worker.php"            # process due jobs once, then exit
 *   "C:\xampp\php\php.exe" "C:\xampp\htdocs\Laundry Flow Pro\Backend\worker\email_worker.php" --loop     # keep running, poll every 5s
 *   "C:\xampp\php\php.exe" "C:\xampp\htdocs\Laundry Flow Pro\Backend\worker\email_worker.php" --limit=50 # max jobs per pass (default 25)
 *
 * ───────────────────────────────────────────────────────────────
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

define('ROOT_PATH', dirname(__DIR__));
define('SRC_PATH', ROOT_PATH . '/src');

// ── App\ autoloader (mirrors public/index.php) ──────────────────
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = SRC_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

$composer = ROOT_PATH . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
}

\App\Core\Env::load(ROOT_PATH . '/.env');

use App\Core\Database;
use App\Services\EmailService;

// ── Parse args ──────────────────────────────────────────────────
$loop  = in_array('--loop', $argv, true);
$limit = 25;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int) $m[1]);
    }
}

// Retry backoff per attempt number (seconds): 1m, 5m, 15m, 1h, 6h
const BACKOFF = [60, 300, 900, 3600, 21600];

function worker_log(string $message): void
{
    $logDir = ROOT_PATH . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] [worker] ' . $message . PHP_EOL;
    @file_put_contents($logDir . '/email.log', $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

/**
 * Process one batch of due jobs. Returns the number of jobs handled.
 */
function process_batch(int $limit): int
{
    $db = Database::connect();

    // Claim due jobs (pending or failed-but-eligible, under the attempt cap)
    $select = $db->prepare(
        "SELECT id, to_email, to_name, subject, body, alt_body, attempts, max_attempts
         FROM email_queue
         WHERE status IN ('pending','failed')
           AND available_at <= NOW()
           AND attempts < max_attempts
         ORDER BY id ASC
         LIMIT {$limit}"
    );
    $select->execute();
    $jobs = $select->fetchAll();

    if (!$jobs) {
        return 0;
    }

    $markProcessing = $db->prepare(
        "UPDATE email_queue SET status = 'processing', updated_at = NOW()
         WHERE id = ? AND status IN ('pending','failed')"
    );
    $markSent = $db->prepare(
        "UPDATE email_queue
         SET status = 'sent', attempts = attempts + 1, sent_at = NOW(),
             last_error = NULL, updated_at = NOW()
         WHERE id = ?"
    );
    $markRetry = $db->prepare(
        "UPDATE email_queue
         SET status = ?, attempts = attempts + 1, last_error = ?,
             available_at = ?, updated_at = NOW()
         WHERE id = ?"
    );

    $handled = 0;

    foreach ($jobs as $job) {
        $id = (int) $job['id'];

        // Claim atomically; skip if another worker grabbed it first
        if ($markProcessing->execute([$id]) && $markProcessing->rowCount() === 0) {
            continue;
        }

        $ok = EmailService::send(
            (string) $job['to_email'],
            (string) $job['to_name'],
            (string) $job['subject'],
            (string) $job['body'],
            (string) ($job['alt_body'] ?? '')
        );

        $handled++;

        if ($ok) {
            $markSent->execute([$id]);
            worker_log("SENT  #{$id} → {$job['to_email']} \"{$job['subject']}\"");
            continue;
        }

        // Failure → schedule retry or dead-letter
        $nextAttempt = (int) $job['attempts'] + 1;
        $maxAttempts = (int) $job['max_attempts'];

        if ($nextAttempt >= $maxAttempts) {
            $markRetry->execute(['dead', 'Max attempts reached', date('Y-m-d H:i:s'), $id]);
            worker_log("DEAD  #{$id} → {$job['to_email']} (gave up after {$nextAttempt} attempts)");
        } else {
            $delay   = BACKOFF[min($nextAttempt - 1, count(BACKOFF) - 1)];
            $nextDue = date('Y-m-d H:i:s', time() + $delay);
            $markRetry->execute(['failed', 'SMTP send failed', $nextDue, $id]);
            worker_log("RETRY #{$id} → {$job['to_email']} (attempt {$nextAttempt}, next try in {$delay}s)");
        }
    }

    return $handled;
}

// ── Main ────────────────────────────────────────────────────────
try {
    if ($loop) {
        worker_log('Worker started in --loop mode.');
        while (true) {
            $n = process_batch($limit);
            if ($n === 0) {
                sleep(5);
            }
        }
    } else {
        $n = process_batch($limit);
        if ($n > 0) {
            worker_log("Batch complete — {$n} job(s) processed.");
        }
    }
} catch (\Throwable $e) {
    worker_log('FATAL: ' . $e->getMessage());
    exit(1);
}
