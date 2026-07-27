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

// Nothing below this is allowed to reach the visitor as a bare 500. An empty
// error page cannot be told apart from a broken link, and the commonest cause
// - a schema that was never upgraded after a pull - is both invisible and
// trivially fixable, so it is worth naming rather than logging silently.
try {
    PV\Router::dispatch();
} catch (Throwable $e) {
    PV\ErrorPage::render($e);
}
