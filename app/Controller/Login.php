<?php
declare(strict_types=1);

namespace PV\Controller;

use PV\Auth;
use PV\Router;

/**
 * Redeems a one-time login token.
 *
 * Deliberately two steps. Opening the link only *checks* the token and renders
 * a confirmation; the token is spent by the button's POST. That is because a
 * link sent into a chat gets fetched by things that are not its owner -
 * Telegram builds a preview card, scanners and antivirus proxies follow URLs -
 * and if a plain GET redeemed it, the link would already be dead when its owner
 * tapped it, reported as "expired" with nothing to show why.
 *
 * On success we redirect rather than render, which drops the token out of the
 * address bar, the browser history and any Referer sent onward. The token is
 * already spent by then, but a spent token in a URL is still worth not leaving
 * lying around.
 */
final class Login implements Handler
{
    public function handle(): void
    {
        $token = (string)($_POST['t'] ?? $_GET['t'] ?? '');

        if ($token === '') {
            $this->deny('Dieser Link ist unvollständig - bitte fordere einen neuen an.');
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->redeem($token);
            return;
        }

        $user = Auth::peekToken($token);
        if ($user === null) {
            $this->deny('Dieser Link ist abgelaufen oder wurde bereits verwendet.');
            return;
        }

        $this->confirm($user, $token);
    }

    private function redeem(string $token): void
    {
        $user = Auth::consumeToken($token);
        if ($user === null) {
            $this->deny('Dieser Link ist abgelaufen oder wurde bereits verwendet.');
            return;
        }

        Auth::login($user);
        header('Location: ' . Router::url(''), true, 302);
    }

    /** The one tap that actually spends the token. */
    private function confirm(array $user, string $token): void
    {
        header('Content-Type: text/html; charset=utf-8');
        // Nothing here should be cached or indexed - it holds a live token.
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');

        $action = Router::url('login');
        require dirname(__DIR__, 2) . '/views/login.php';
    }

    private function deny(string $reason): void
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__, 2) . '/views/denied.php';
    }
}
