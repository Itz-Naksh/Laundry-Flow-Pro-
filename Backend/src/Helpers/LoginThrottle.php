<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * File-based limiter for failed login attempts (no DB table needed).
 *
 * Counts failures per (email + IP) and per IP within a sliding window.
 * Once either limit is reached, further attempts are refused until the
 * oldest failure falls outside the window.
 */
class LoginThrottle
{
    private const WINDOW_SECONDS  = 900; // 15 minutes
    private const MAX_PER_ACCOUNT = 5;   // same email from same IP
    private const MAX_PER_IP      = 20;  // any email from same IP

    private string $dir;

    public function __construct()
    {
        $this->dir = ROOT_PATH . '/storage/throttle';
        if (!is_dir($this->dir)) mkdir($this->dir, 0700, true);
    }

    /** Seconds the caller must wait before trying again, or 0 if allowed. */
    public function retryAfter(string $email, string $ip): int
    {
        return max(
            $this->waitFor('acct|' . $email . '|' . $ip, self::MAX_PER_ACCOUNT),
            $this->waitFor('ip|' . $ip, self::MAX_PER_IP)
        );
    }

    public function recordFailure(string $email, string $ip): void
    {
        $this->append('acct|' . $email . '|' . $ip);
        $this->append('ip|' . $ip);
    }

    public function clear(string $email, string $ip): void
    {
        $file = $this->path('acct|' . $email . '|' . $ip);
        if (is_file($file)) @unlink($file);
    }

    private function waitFor(string $key, int $max): int
    {
        $times = $this->read($this->path($key));
        if (count($times) < $max) return 0;
        return max(1, $times[count($times) - $max] + self::WINDOW_SECONDS - time());
    }

    private function append(string $key): void
    {
        $file = $this->path($key);
        $fh   = fopen($file, 'c+');
        if (!$fh) return;
        flock($fh, LOCK_EX);
        $times   = $this->filter(json_decode((string) stream_get_contents($fh), true));
        $times[] = time();
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($times));
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    private function read(string $file): array
    {
        if (!is_file($file)) return [];
        return $this->filter(json_decode((string) @file_get_contents($file), true));
    }

    /** Keep only timestamps still inside the window, oldest first. */
    private function filter(mixed $times): array
    {
        if (!is_array($times)) return [];
        $cutoff = time() - self::WINDOW_SECONDS;
        $times  = array_values(array_filter($times, fn($t) => is_int($t) && $t > $cutoff));
        sort($times);
        return $times;
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }
}
