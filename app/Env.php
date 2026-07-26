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
    private static string $path = '';

    public static function load(?string $path = null): void
    {
        if (self::$values !== null) {
            return;
        }
        self::$path = $path ?? (getenv('PV_ENV_PATH') ?: dirname(__DIR__) . '/.env');

        // A file that exists but cannot be read is always a misconfiguration,
        // and a silent fallback to defaults hides it in the worst way: the CLI
        // tools keep working as root while the web server quietly gets nothing.
        if (file_exists(self::$path) && !is_readable(self::$path)) {
            throw new \RuntimeException(sprintf(
                'Configuration at %s exists but is not readable by %s. Check file permissions.',
                self::$path,
                function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? 'this user')
                    : 'this user'
            ));
        }

        // A missing file is allowed: everything can come from real env vars.
        self::$values = is_readable(self::$path) ? (parse_ini_file(self::$path) ?: []) : [];
    }

    /** Where configuration was read from - used to make errors actionable. */
    public static function path(): string
    {
        self::load();

        return self::$path;
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
            throw new \RuntimeException(sprintf(
                'Missing required setting %s (looked in %s%s)',
                $key,
                self::$path,
                file_exists(self::$path) ? '' : ' - which does not exist'
            ));
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
