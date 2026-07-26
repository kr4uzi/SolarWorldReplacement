<?php
declare(strict_types=1);

namespace PV;

/**
 * Front controller routing.
 *
 * Every web request is rewritten to System.php, so this table is the single
 * place where "what is reachable" and "what does it take to reach it" are
 * decided.
 *
 * Each route carries its own auth policy, because one global gate would not
 * work: Meta is not a logged-in user and can never hold a session, so the
 * webhook authenticates by payload signature instead. Getting that wrong in
 * either direction is bad - a session check on the webhook breaks the bot,
 * and no check at all leaves an open endpoint.
 */
final class Router
{
    public const AUTH_PUBLIC    = 'public';     // no credentials needed
    public const AUTH_SESSION   = 'session';    // signed-in portal user
    public const AUTH_SIGNATURE = 'signature';  // signed by Meta

    private static ?string $rawBody = null;

    /** @return array<string,array{0:class-string,1:string}> */
    private static function routes(): array
    {
        return [
            ''        => [Controller\Dashboard::class, self::AUTH_SESSION],
            'api'     => [Controller\Api::class,       self::AUTH_SESSION],
            'login'   => [Controller\Login::class,     self::AUTH_PUBLIC],
            'logout'  => [Controller\Logout::class,    self::AUTH_PUBLIC],
            'webhook' => [Controller\Webhook::class,   self::AUTH_SIGNATURE],
        ];
    }

    /** Directory the app is mounted in, e.g. '' at the root or '/pv'. */
    public static function basePath(): string
    {
        $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));

        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }

    /** Route key for the current request, e.g. '' or 'api'. */
    public static function currentPath(): string
    {
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = self::basePath();

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return trim($path, '/');
    }

    /** Build a link to a route. Absolute when PORTAL_URL is configured. */
    public static function url(string $path = ''): string
    {
        $portal = rtrim((string)Env::get('PORTAL_URL', ''), '/');
        if ($portal !== '') {
            return $path === '' ? $portal : $portal . '/' . ltrim($path, '/');
        }

        return (self::basePath() ?: '') . '/' . ltrim($path, '/');
    }

    /** Raw request body, read once so the signature check and the controller agree. */
    public static function rawBody(): string
    {
        return self::$rawBody ??= (string)file_get_contents('php://input');
    }

    public static function dispatch(): void
    {
        $path  = self::currentPath();
        $route = self::routes()[$path] ?? null;

        if ($route === null) {
            self::fail(404, 'Not found', $path);
            return;
        }

        [$controller, $policy] = $route;

        if (!self::authorize($policy, $path)) {
            return; // authorize() has already produced the response
        }

        (new $controller())->handle();
    }

    private static function authorize(string $policy, string $path): bool
    {
        if ($policy === self::AUTH_PUBLIC) {
            return true;
        }

        if ($policy === self::AUTH_SESSION) {
            if (Auth::user() !== null) {
                return true;
            }
            self::denyUnauthenticated($path);
            return false;
        }

        // AUTH_SIGNATURE. Meta's one-time verification handshake arrives as a
        // GET with no body to sign, so the controller validates that itself
        // against the verify token; only real events are signed.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            return true;
        }

        $secret = (string)Env::get('META_APP_SECRET', '');
        if ($secret === '') {
            self::fail(500, 'Webhook rejected', 'META_APP_SECRET is not configured');
            return false;
        }

        $provided = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        $expected = 'sha256=' . hash_hmac('sha256', self::rawBody(), $secret);

        if (!hash_equals($expected, $provided)) {
            self::fail(403, 'Forbidden', 'signature mismatch');
            return false;
        }

        return true;
    }

    /** API callers get JSON; humans get a page telling them how to get in. */
    private static function denyUnauthenticated(string $path): void
    {
        if ($path === 'api') {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Nicht angemeldet']);
            return;
        }

        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__) . '/views/denied.php';
    }

    private static function fail(int $status, string $message, string $detail = ''): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message . ($detail !== '' ? ": {$detail}" : '') . "\n";
    }
}
