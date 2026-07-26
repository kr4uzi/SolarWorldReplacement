<?php
declare(strict_types=1);

namespace PV\Controller;

use PV\Auth;
use PV\Router;

/**
 * Redeems a one-time login token from a WhatsApp link.
 *
 * On success we redirect straight to the dashboard rather than rendering it
 * here. That is deliberate: the redirect drops the token out of the address
 * bar, the browser history and any Referer header sent to third parties. The
 * token is already dead by then - Auth::consumeToken deletes it on sight -
 * but a spent token in a URL is still worth not leaving lying around.
 */
final class Login implements Handler
{
    public function handle(): void
    {
        $token = (string)($_GET['t'] ?? '');
        $user  = $token === '' ? null : Auth::consumeToken($token);

        if ($user === null) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            $reason = 'Dieser Link ist abgelaufen oder wurde bereits verwendet.';
            require dirname(__DIR__, 2) . '/views/denied.php';
            return;
        }

        Auth::login($user);

        header('Location: ' . Router::url(''), true, 302);
    }
}
