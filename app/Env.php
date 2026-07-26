<?php
declare(strict_types=1);

namespace PV;

/**
 * Configuration loader.
 *
 * Reads .env with PHP's built-in parse_ini_file - no library required.
 * Real environment variables take precedence, so a hosting panel or a
 * systemd unit can override the file without editing it.
 */
final class Env
{
    private static ?array $values = null;

    public static function load(?string $path = null): void
    {
        if (self::$values !== null) {
            return;
        }
        $path ??= (getenv('PV_ENV_PATH') ?: dirname(__DIR__) . '/.env');
        self::$values = is_readable($path) ? (parse_ini_file($path) ?: []) : [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        $fromEnv = getenv($key);
        if ($fromEnv !== false && $fromEnv !== '') {
            return $fromEnv;
        }
        $value = self::$values[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /** Same as get(), but fails loudly when a required setting is missing. */
    public static function must(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Missing required setting {$key} in .env");
        }
        return (string)$value;
    }

    /** Comma-separated setting -> array of trimmed, non-empty values. */
    public static function list(string $key): array
    {
        $raw = (string)self::get($key, '');
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn($v) => $v !== ''));
    }

    /** Only used by tests to load an alternative configuration. */
    public static function reset(): void
    {
        self::$values = null;
    }
}
