<?php
declare(strict_types=1);

/**
 * Front controller.
 *
 * .htaccess rewrites every web request here, so this file is the only entry
 * point into the application. Nothing under app/ or views/ is reachable
 * directly, which is what lets Router decide authentication centrally instead
 * of each script guarding itself.
 *
 * The CLI tools (setup.php, job.php) deliberately bypass this and boot on
 * their own - they have no request, session or user to speak of.
 */

require __DIR__ . '/app/bootstrap.php';

PV\Router::dispatch();
