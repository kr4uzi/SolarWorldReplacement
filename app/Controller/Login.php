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

        if (Router::isDiagnostic()) {
            $this->report($token);
            return;
        }

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
        header('Location: ' . Router::path(), true, 302);
    }

    /** The one tap that actually spends the token. */
    private function confirm(array $user, string $token): void
    {
        header('Content-Type: text/html; charset=utf-8');
        // Nothing here should be cached or indexed - it holds a live token.
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');

        $action = Router::path('login');
        require dirname(__DIR__, 2) . '/views/login.php';
    }

    /**
     * Report what this request looks like from the server's side.
     *
     * "The link does not work" covers several unrelated failures that produce
     * the same page: the token never arrived, it arrived but no longer exists,
     * it exists but has expired, or it redeems fine and the session is what
     * fails afterwards. Each needs a different fix, so name them apart.
     */
    private function report(string $token): void
    {
        $savePath = session_save_path() ?: sys_get_temp_dir();
        $row      = $token === '' ? null : Auth::peekToken($token);

        $found = false;
        $expiry = null;
        if ($token !== '') {
            $statement = \PV\Db::conn()->prepare(
                'SELECT expires_at FROM login_tokens WHERE token_hash = ? LIMIT 1'
            );
            $statement->execute([hash('sha256', trim($token))]);
            $expiry = $statement->fetchColumn();
            $found  = $expiry !== false;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'request' => [
                'method'        => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
                'host'          => (string)($_SERVER['HTTP_HOST'] ?? ''),
                'https_seen'    => Auth::isHttps(),
                'base_path'     => Router::basePath(),
                'redirects_to'  => Router::path(),
                'portal_url'    => (string)\PV\Env::get('PORTAL_URL', ''),
            ],
            'token' => [
                'supplied'      => $token !== '',
                'source'        => isset($_POST['t']) ? 'POST body' : (isset($_GET['t']) ? 'query string' : 'none'),
                'length'        => strlen($token),
                'found_in_db'   => $found,
                'expires_at'    => $found ? (string)$expiry : null,
                'server_time'   => date('Y-m-d H:i:s'),
                'still_valid'   => $row !== null,
            ],
            'session' => [
                'save_path'     => $savePath,
                'path_writable' => is_writable($savePath),
                'cookie_sent'   => isset($_COOKIE[session_name()]),
            ],
            'verdict' => $this->reportVerdict($token, $found, $row, $savePath),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    }

    private function reportVerdict(string $token, bool $found, ?array $user, string $savePath): string
    {
        if ($token === '') {
            return 'No token in this request. On a POST that means the form body did not arrive - '
                 . 'check that the rewrite is internal and not a redirect, which would drop it.';
        }
        if (!$found) {
            return 'That token is not in the database. Either it was already redeemed - something '
                 . 'fetched the link before you did - or a newer link was requested, which deletes '
                 . 'the previous one.';
        }
        if ($user === null) {
            return 'The token exists but is past its expiry. Compare expires_at with server_time '
                 . 'above: if they disagree by hours, the database and PHP are on different clocks.';
        }
        if (!is_writable($savePath)) {
            return "The token is valid, so login will succeed - but PHP cannot write sessions to "
                 . "{$savePath}, so the session will not survive the redirect and the dashboard "
                 . 'will refuse you. Point session.save_path somewhere writable.';
        }

        return 'The token is valid and sessions are writable - redeeming this link should work.';
    }

    private function deny(string $reason): void
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__, 2) . '/views/denied.php';
    }
}
