<?php
declare(strict_types=1);

namespace PV\Controller;

use PV\Auth;
use PV\Env;
use PV\Messages;
use PV\Router;
use PV\WhatsApp;

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
            WhatsApp::MENU_MONTH  => WhatsApp::sendText($from, Messages::currentMonth()),
            WhatsApp::MENU_YEAR   => WhatsApp::sendText($from, Messages::currentYear()),
            default               => WhatsApp::sendMenu($from),
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

        WhatsApp::sendText($user['phone'], Messages::portalLink($url, $ttl));
    }

    private function log(string $line): void
    {
        $path = (string)Env::get('PV_WEBHOOK_LOG', '');
        if ($path !== '') {
            @file_put_contents($path, date('c') . ' ' . $line . "\n", FILE_APPEND);
        }
    }
}
