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

PV\Env::load();

// 12:15 must mean 12:15 where the plant is, not wherever the host clock sits.
date_default_timezone_set((string)PV\Env::get('PV_TIMEZONE', 'Europe/Berlin'));
