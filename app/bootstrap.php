<?php
declare(strict_types=1);

/**
 * Shared bootstrap: autoloader, configuration and timezone.
 * Used by the front controller and by the CLI tools alike.
 */

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'PV\\')) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 3)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

try {
    PV\Env::load();
} catch (RuntimeException $e) {
    // On the CLI a stack trace helps nobody who is mid-deploy; on the web let
    // it bubble so it becomes a 500 and lands in the server's error log rather
    // than being shown to a visitor.
    if (PHP_SAPI !== 'cli') {
        throw $e;
    }
    fwrite(STDERR, 'Configuration error: ' . $e->getMessage() . "\n");
    exit(1);
}

// 12:15 must mean 12:15 where the plant is, not wherever the host clock sits.
date_default_timezone_set((string)PV\Env::get('PV_TIMEZONE', 'Europe/Berlin'));
