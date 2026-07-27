<?php
declare(strict_types=1);

namespace PV\Controller;

use PV\Auth;
use PV\Router;

final class Logout implements Handler
{
    public function handle(): void
    {
        Auth::logout();

        header('Location: ' . Router::path(), true, 302);
    }
}
