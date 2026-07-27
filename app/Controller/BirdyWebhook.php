<?php
declare(strict_types=1);

namespace PV\Controller;

use PV\Auth;
use PV\Env;
use PV\HttpClient;
use PV\Messages;
use PV\Messenger;
use PV\Router;

/**
 * BirdyChat's inbound endpoint, at /birdy-webhook.
 *
 * Separate from the Meta webhook on purpose: the two authenticate differently
 * and their payloads share no structure, so folding them together would mean a
 * single handler guessing which provider it is talking to.
 *
 * Because the exact payload shape is not something this project can pin down,
 * the two fields that matter - who sent the message and what they wrote - are
 * located by configurable dotted paths (BIRDY_INBOUND_SENDER_PATH,
 * BIRDY_INBOUND_TEXT_PATH). Point them at the right keys once you have seen a
 * real delivery; the diagnostic below prints the payload so you can.
 *
 * Authentication happens in Router before anything here runs.
 */
final class BirdyWebhook implements Handler
{
    public function handle(): void
    {
        $payload = json_decode(Router::rawBody(), true);

        // With a diagnostic key set, report the delivery instead of acting on
        // it - the fastest way to learn the real payload shape.
        if (Router::isDiagnostic()) {
            $this->report(is_array($payload) ? $payload : []);
            return;
        }

        // Answer immediately; providers retry endpoints that are slow to reply.
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'OK';
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        if (!is_array($payload)) {
            return;
        }

        // Runs after the response has been flushed, so an exception would be
        // invisible - record it where the operator can find it.
        try {
            foreach ($this->messages($payload) as $message) {
                $this->respondTo($message);
            }
        } catch (\Throwable $e) {
            $this->log(sprintf('ERROR %s: %s (%s:%d)',
                get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine()));
        }
    }

    /**
     * Deliveries may carry one message or a batch; BIRDY_INBOUND_LIST_PATH
     * names the array when they are batched.
     *
     * @return array<int,array>
     */
    private function messages(array $payload): array
    {
        $listPath = (string)Env::get('BIRDY_INBOUND_LIST_PATH', '');
        if ($listPath === '') {
            return [$payload];
        }

        $list = HttpClient::pluck($payload, $listPath, []);

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    private function respondTo(array $message): void
    {
        $sender = trim((string)HttpClient::pluck(
            $message,
            (string)Env::get('BIRDY_INBOUND_SENDER_PATH', 'sender.email'),
            ''
        ));
        $text = trim((string)HttpClient::pluck(
            $message,
            (string)Env::get('BIRDY_INBOUND_TEXT_PATH', 'text'),
            ''
        ));

        if ($sender === '') {
            $this->log('ignored delivery: no sender at the configured path');
            return;
        }

        $user = Auth::userByAddress($sender) ?? Auth::userByPhone($sender);
        if ($user === null) {
            // Silence rather than "not authorised", which would confirm the
            // account exists to anyone probing.
            $this->log("ignored message from unregistered sender {$sender}");
            return;
        }

        $action = $this->action($text);
        $this->log("{$sender} -> {$action}");

        match ($action) {
            'portal' => $this->sendPortalLink($user),
            'month'  => Messenger::reply($sender, Messages::currentMonth()),
            'year'   => Messenger::reply($sender, Messages::currentYear()),
            default  => Messenger::menu($sender),
        };
    }

    /** Keyword matching, since this transport has no tappable menu. */
    private function action(string $text): string
    {
        $text = ltrim(mb_strtolower($text), '/');

        foreach ([
            'portal' => ['portal', 'login', 'dashboard', 'zugang'],
            'month'  => ['monat', 'monatsertrag', 'month'],
            'year'   => ['jahr', 'jahresertrag', 'year'],
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

    /**
     * Echo the delivery back so the configured paths can be checked against a
     * real payload. Guarded by WEBHOOK_DIAG_KEY, and it resolves the paths so
     * a mismatch is obvious rather than inferred.
     */
    private function report(array $payload): void
    {
        $senderPath = (string)Env::get('BIRDY_INBOUND_SENDER_PATH', 'sender.email');
        $textPath   = (string)Env::get('BIRDY_INBOUND_TEXT_PATH', 'text');
        $listPath   = (string)Env::get('BIRDY_INBOUND_LIST_PATH', '');

        $messages = $this->messages($payload);
        $first    = $messages[0] ?? [];

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'reached'        => 'System.php -> Router -> BirdyWebhook',
            'method'         => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
            'body_bytes'     => strlen(Router::rawBody()),
            'payload'        => $payload,
            'configured'     => [
                'list_path'   => $listPath === '' ? '(none - payload is one message)' : $listPath,
                'sender_path' => $senderPath,
                'text_path'   => $textPath,
            ],
            'resolved'       => [
                'messages_found' => count($messages),
                'sender'         => HttpClient::pluck($first, $senderPath),
                'text'           => HttpClient::pluck($first, $textPath),
            ],
            'verdict'        => $this->verdict($first, $senderPath, $textPath),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    }

    private function verdict(array $message, string $senderPath, string $textPath): string
    {
        $sender = HttpClient::pluck($message, $senderPath);
        $text   = HttpClient::pluck($message, $textPath);

        if ($sender === null && $text === null) {
            return 'Neither path resolved. Compare "payload" above with the configured paths '
                 . 'and set BIRDY_INBOUND_SENDER_PATH / BIRDY_INBOUND_TEXT_PATH to match. '
                 . 'If the delivery batches messages, set BIRDY_INBOUND_LIST_PATH too.';
        }
        if ($sender === null) {
            return "BIRDY_INBOUND_SENDER_PATH ('{$senderPath}') did not resolve - see \"payload\".";
        }
        if ($text === null) {
            return "BIRDY_INBOUND_TEXT_PATH ('{$textPath}') did not resolve - see \"payload\".";
        }

        return Auth::userByAddress((string)$sender) === null
            ? "Paths resolve, but '{$sender}' is not a registered user. Add them: php setup.php \"Name\" {$sender}"
            : 'Paths resolve and the sender is registered - this delivery would be handled.';
    }

    private function log(string $line): void
    {
        $path = (string)Env::get('PV_WEBHOOK_LOG', '');
        if ($path !== '') {
            @file_put_contents($path, date('c') . ' [birdy] ' . $line . "\n", FILE_APPEND);
        }
    }
}
