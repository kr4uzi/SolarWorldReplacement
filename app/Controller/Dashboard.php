<?php
declare(strict_types=1);

namespace PV\Controller;

use PV\Auth;
use PV\Router;

final class Dashboard implements Handler
{
    public function handle(): void
    {
        header('Content-Type: text/html; charset=utf-8');

        // Consumed by views/dashboard.php.
        $user   = Auth::user();
        $apiUrl = Router::path('api');

        require dirname(__DIR__, 2) . '/views/dashboard.php';
    }
}
