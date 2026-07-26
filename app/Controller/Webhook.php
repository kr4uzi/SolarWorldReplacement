<?php
declare(strict_types=1);

namespace PV\Controller;

use PV\Auth;
use PV\Env;
use PV\Messages;
use PV\Router;
use PV\Messenger;
use PV\Transport\WhatsApp;

/**
 * Meta's webhook endpoint.
 *
 * Meta proves itself two different ways, and both are needed:
 *
 *  - GET  - the one-time registration handshake. Meta sends hub.mode,
 *           hub.verify_token and hub.challenge as query parameters; we compare
 *           the token and echo the challenge back verbatim.
 *  - POST - every real event, signed with the app secret and delivered in the
 *           X-Hub-Signature-256 header. That check happens in Router before we
 *           get here, so anything reaching handle() is genuine.
 *
 * Who is allowed to use the bot is decided by the users table: an inbound
 * number that does not match a registered account is ignored.
 */
final class Webhook implements Handler
{
    public function handle(): void
    {
        if (Router::isDiagnostic()) {
            $this->reportDiagnostics();
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $this->verifySubscription();
            return;
        }

        $this->acknowledge();

        $payload = json_decode(Router::rawBody(), true);
        if (!is_array($payload)) {
            return;
        }

        foreach ($this->inboundMessages($payload) as $message) {
            $this->respondTo($message);
        }
    }

    /**
     * Report what the server actually received, instead of processing it.
     *
     * Reaching this at all already proves a great deal: the request got past
     * the web server, mod_rewrite resolved the route, and PHP ran. What it adds
     * is the part Meta will never tell you - whether the query parameters
     * survived the trip, and whether the two tokens genuinely match.
     *
     * Secrets are compared by hash prefix; none of them are printed.
     */
    private function reportDiagnostics(): void
    {
        $fingerprint = static fn(string $v): array => $v === ''
            ? ['configured' => false]
            : ['configured' => true, 'length' => strlen($v), 'fingerprint' => substr(hash('sha256', $v), 0, 8)];

        $method    = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $mode      = (string)($_GET['hub_mode']         ?? $_GET['hub.mode']         ?? '');
        $sent      = (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
        $challenge = (string)($_GET['hub_challenge']    ?? $_GET['hub.challenge']    ?? '');
        $expected  = (string)Env::get('META_VERIFY_TOKEN', '');
        $secret    = (string)Env::get('META_APP_SECRET', '');

        $report = [
            'reached'  => 'System.php -> Router -> Webhook controller',
            'request'  => [
                'method'       => $method,
                'uri'          => (string)($_SERVER['REQUEST_URI'] ?? ''),
                'https'        => Auth::isHttps(),
                'base_path'    => Router::basePath(),
                'route'        => Router::currentPath(),
                'query_keys'   => array_values(array_diff(array_keys($_GET), ['diag'])),
            ],
            'handshake' => [
                'hub_mode'           => $mode === '' ? null : $mode,
                'hub_challenge'      => $challenge === '' ? null : 'present',
                'token_from_request' => $fingerprint($sent),
                'token_on_server'    => $fingerprint($expected),
                'tokens_match'       => $sent !== '' && $expected !== '' && hash_equals($expected, $sent),
            ],
            'signature' => [
                'app_secret'     => $fingerprint($secret),
                'header_present' => isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']),
                'body_bytes'     => strlen(Router::rawBody()),
            ],
        ];

        if ($method === 'POST' && $secret !== '') {
            $expectedSig = 'sha256=' . hash_hmac('sha256', Router::rawBody(), $secret);
            $report['signature']['matches'] =
                hash_equals($expectedSig, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
        }

        $report['verdict'] = $this->verdict($method, $mode, $challenge, $sent, $expected);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    }

    /** Plain-language conclusion, so the report answers rather than describes. */
    private function verdict(string $method, string $mode, string $challenge, string $sent, string $expected): string
    {
        if ($method !== 'GET') {
            return $expected === ''
                ? 'META_APP_SECRET / META_VERIFY_TOKEN not fully configured - see the fields above.'
                : 'POST probe: check signature.matches above. Meta signs every real event.';
        }
        if ($expected === '') {
            return 'META_VERIFY_TOKEN is not set on the server, so every handshake is rejected. Set it in .env.';
        }
        if ($sent === '') {
            return 'No hub.verify_token arrived. Meta always sends one - if you are testing by hand, '
                 . 'quote the URL: an unquoted & ends the command at the first parameter.';
        }
        if ($mode !== 'subscribe') {
            return "hub.mode was '" . ($mode === '' ? '(absent)' : $mode) . "', expected 'subscribe'.";
        }
        if (!hash_equals($expected, $sent)) {
            return 'The token arrived intact but differs from META_VERIFY_TOKEN. Compare the two '
                 . 'fingerprints above; note that a ; in an unquoted .env value truncates it.';
        }
        if ($challenge === '') {
            return 'Token matches, but no hub.challenge arrived - Meta always sends one.';
        }

        return 'Handshake would succeed. If Meta still refuses, it is not reaching this endpoint '
             . '(certificate, firewall, or a redirect - Meta does not follow redirects).';
    }

    /** PHP rewrites dots in query keys to underscores; accept either spelling. */
    private function verifySubscription(): void
    {
        $expected  = (string)Env::get('META_VERIFY_TOKEN', '');
        $mode      = (string)($_GET['hub_mode']         ?? $_GET['hub.mode']         ?? '');
        $token     = (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
        $challenge = (string)($_GET['hub_challenge']    ?? $_GET['hub.challenge']    ?? '');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            $this->log('handshake accepted');
            header('Content-Type: text/plain; charset=utf-8');
            echo $challenge;
            return;
        }

        // Meta reports every failure as the same opaque message, so record why
        // it was rejected here. The tokens themselves are never written out.
        $this->log(sprintf(
            'handshake REJECTED: mode=%s, %s, challenge=%s',
            $mode === '' ? '(none)' : $mode,
            $expected === ''
                ? 'META_VERIFY_TOKEN is not set'
                : ($token === '' ? 'no token supplied' : 'token does not match META_VERIFY_TOKEN'),
            $challenge === '' ? 'missing' : 'present'
        ));

        http_response_code(403);
        echo 'Forbidden';
    }

    /**
     * Answer Meta immediately. It retries, and eventually disables, a webhook
     * that is slow or errors - so the replies are sent after the response is
     * already on its way.
     */
    private function acknowledge(): void
    {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'OK';

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /** Pull messages out of Meta's envelope, ignoring delivery-status events. */
    private function inboundMessages(array $payload): array
    {
        $messages = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $message) {
                    $messages[] = $message;
                }
            }
        }

        return $messages;
    }

    private function respondTo(array $message): void
    {
        $from = WhatsApp::normalize((string)($message['from'] ?? ''));
        if ($from === '') {
            return;
        }

        $user = Auth::userByPhone($from);
        if ($user === null) {
            // Stay silent rather than replying "not authorised": that would
            // confirm the number is live to anyone probing it.
            $this->log("ignored message from unregistered number {$from}");
            return;
        }

        $action = $this->action($message);
        $this->log("{$from} -> {$action}");

        match ($action) {
            WhatsApp::MENU_PORTAL => $this->sendPortalLink($user),
            WhatsApp::MENU_MONTH  => Messenger::reply($from, Messages::currentMonth()),
            WhatsApp::MENU_YEAR   => Messenger::reply($from, Messages::currentYear()),
            default               => Messenger::menu($from),
        };
    }

    /** Map a tapped list entry or a typed word onto a menu action. */
    private function action(array $message): string
    {
        if (($message['type'] ?? '') === 'interactive') {
            $interactive = $message['interactive'] ?? [];
            $id = $interactive['list_reply']['id'] ?? $interactive['button_reply']['id'] ?? '';

            return strtolower(trim((string)$id));
        }

        $text = ltrim(mb_strtolower(trim((string)($message['text']['body'] ?? ''))), '/');

        foreach ([
            WhatsApp::MENU_PORTAL => ['portal', 'login', 'dashboard', 'zugang'],
            WhatsApp::MENU_MONTH  => ['monat', 'monatsertrag', 'month'],
            WhatsApp::MENU_YEAR   => ['jahr', 'jahresertrag', 'year'],
        ] as $action => $keywords) {
            if (in_array($text, $keywords, true)) {
                return $action;
            }
        }

        return 'menu';
    }

    private function sendPortalLink(array $user): void
    {
        $token = Auth::issueToken((int)$user['id']);
        $ttl   = max(1, (int)Env::get('LOGIN_TOKEN_TTL_MINUTES', 15));
        $url   = Router::url('login') . '?t=' . urlencode($token);

        Messenger::reply(Messenger::addressFor($user), Messages::portalLink($url, $ttl));
    }

    private function log(string $line): void
    {
        $path = (string)Env::get('PV_WEBHOOK_LOG', '');
        if ($path !== '') {
            @file_put_contents($path, date('c') . ' ' . $line . "\n", FILE_APPEND);
        }
    }
}
