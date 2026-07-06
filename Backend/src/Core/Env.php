<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal dependency-free .env loader.
 *
 * Parses a simple KEY=VALUE file once and exposes the values through
 * Env::get(). Values are also pushed into getenv()/$_ENV so any other
 * code (or libraries) can read them the conventional way.
 *
 * Supported syntax:
 *   # comment lines and blank lines are ignored
 *   KEY=value
 *   KEY="quoted value with spaces"
 *   KEY='single quoted'
 */
class Env
{
    private static bool  $loaded = false;
    private static array $vars   = [];

    /**
     * Load and parse the given .env file. Safe to call multiple times —
     * only the first call does any work.
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip matching surrounding quotes
            $len = strlen($value);
            if ($len >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[$len - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            if ($key === '') {
                continue;
            }

            self::$vars[$key] = $value;
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
            }
            $_ENV[$key] = $value;
        }
    }

    /**
     * Read a configuration value with an optional fallback default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }
        $env = getenv($key);
        return $env !== false ? $env : $default;
    }
}
