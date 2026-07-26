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
    public const AUTH_BIRDY     = 'birdy';      // shared secret or HMAC from BirdyChat

    private static ?string $rawBody = null;

    /** @return array<string,array{0:class-string,1:string}> */
    private static function routes(): array
    {
        return [
            ''        => [Controller\Dashboard::class, self::AUTH_SESSION],
            'api'     => [Controller\Api::class,       self::AUTH_SESSION],
            'login'   => [Controller\Login::class,     self::AUTH_PUBLIC],
            'logout'  => [Controller\Logout::class,    self::AUTH_PUBLIC],
            'webhook'       => [Controller\Webhook::class,      self::AUTH_SIGNATURE],
            'birdy-webhook' => [Controller\BirdyWebhook::class, self::AUTH_BIRDY],
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

    /**
     * Is this a diagnostic probe of the webhook?
     *
     * Meta reports every verification failure with one opaque message, which
     * leaves nothing to debug against. When WEBHOOK_DIAG_KEY is set, a request
     * carrying it reports what the server actually received instead of being
     * processed. It is off unless that key is configured, and the report never
     * contains a secret - only lengths and hash prefixes.
     */
    public static function isDiagnostic(): bool
    {
        $key = (string)Env::get('WEBHOOK_DIAG_KEY', '');

        return $key !== '' && hash_equals($key, (string)($_GET['diag'] ?? ''));
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

        // A diagnostic probe reports on the signature rather than being blocked
        // by it - otherwise the check you most need to debug is the one that
        // refuses to tell you anything.
        if (self::isDiagnostic()) {
            return true;
        }

        if ($policy === self::AUTH_BIRDY) {
            return self::authorizeBirdy();
        }

        // AUTH_SIGNATURE. Meta's one-time verification handshake arrives as a
        // GET with no body to sign, so the controller validates that itself
        // against the verify token; only real events are signed.
        //
        // HEAD is treated the same. Meta never sends one, but uptime probes
        // and `curl -I` do, and answering those with a signature failure looks
        // like a broken endpoint when nothing is wrong.
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
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

    /**
     * BirdyChat's endpoint, verified by shared secret or HMAC.
     *
     * Which of the two is used is configuration rather than a guess: set
     * BIRDY_WEBHOOK_SIGNATURE_MODE to 'plain' when the provider sends the
     * secret verbatim in a header, or 'hmac-sha256' when it signs the body.
     * Refusing to run unconfigured is deliberate - an unauthenticated inbound
     * endpoint would let anyone drive the bot.
     */
    private static function authorizeBirdy(): bool
    {
        if (self::isDiagnostic()) {
            return true;
        }

        $secret = (string)Env::get('BIRDY_WEBHOOK_SECRET', '');
        if ($secret === '') {
            self::fail(500, 'Webhook rejected', 'BIRDY_WEBHOOK_SECRET is not configured');
            return false;
        }

        $header   = (string)Env::get('BIRDY_WEBHOOK_SECRET_HEADER', 'X-Birdy-Signature');
        $key      = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        $provided = (string)($_SERVER[$key] ?? '');

        $expected = strtolower((string)Env::get('BIRDY_WEBHOOK_SIGNATURE_MODE', 'plain')) === 'hmac-sha256'
            ? hash_hmac('sha256', self::rawBody(), $secret)
            : $secret;

        // Tolerate providers that prefix the digest, e.g. 'sha256=<hex>'.
        $provided = preg_replace('/^sha256=/i', '', $provided) ?? $provided;

        if (!hash_equals($expected, $provided)) {
            self::fail(403, 'Forbidden', 'webhook secret mismatch');
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
