<?php
declare(strict_types=1);

namespace PV\Controller;

use PV\Auth;
use PV\Env;
use PV\Messages;
use PV\Messenger;
use PV\Router;
use PV\Transport\Telegram;

/**
 * Telegram's inbound endpoint, at /telegram-webhook.
 *
 * Two kinds of update matter:
 *
 *  - message       - typed text, including the /start that redeems an invite
 *  - callback_query - a tapped inline-keyboard button
 *
 * Authentication is the secret token Telegram echoes back on every delivery,
 * checked in Router before anything here runs.
 *
 * The users table is the whitelist, so a chat that belongs to no account gets
 * no data. It does get an answer to /start, though: that is the moment someone
 * is trying to get set up, and silence there is indistinguishable from a broken
 * webhook. There is nothing to conceal either - a Telegram bot is public by its
 * @username, unlike a phone number.
 */
final class TelegramWebhook implements Handler
{
    public function handle(): void
    {
        $update = json_decode(Router::rawBody(), true);

        if (Router::isDiagnostic()) {
            $this->report(is_array($update) ? $update : []);
            return;
        }

        // Answer at once; Telegram retries deliveries that are slow to return.
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'OK';
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        if (!is_array($update)) {
            return;
        }

        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    private function handleMessage(array $message): void
    {
        $chatId = (string)($message['chat']['id'] ?? '');
        $text   = trim((string)($message['text'] ?? ''));
        if ($chatId === '') {
            return;
        }

        // '/start <code>' is how an invite is redeemed. Handled before the
        // account lookup, since redeeming is precisely what a chat with no
        // account yet is allowed to do.
        if (str_starts_with($text, '/start')) {
            $code = trim(substr($text, strlen('/start')));
            if ($code !== '' && $this->redeem($code, $chatId, $message)) {
                return;
            }
        }

        $user = Auth::userByAddress($chatId);
        if ($user === null) {
            $this->log("message from unregistered chat {$chatId}: " . ($text === '' ? '(no text)' : $text));

            // Answer a bare /start rather than saying nothing. Silence here is
            // indistinguishable from a broken webhook, and this is exactly the
            // moment someone is trying to get set up. A Telegram bot is public
            // by its @username anyway, so there is nothing to conceal - unlike
            // a phone number, where staying quiet is worth something.
            if (str_starts_with($text, '/start')) {
                Telegram::call('sendMessage', [
                    'chat_id' => $chatId,
                    'text'    => Messages::notInvited($chatId),
                ]);
            }
            return;
        }

        $this->act($this->action($text), $chatId, $user);
    }

    private function handleCallback(array $callback): void
    {
        $chatId = (string)($callback['message']['chat']['id'] ?? $callback['from']['id'] ?? '');
        $data   = trim((string)($callback['data'] ?? ''));

        if (isset($callback['id'])) {
            Telegram::acknowledgeCallback((string)$callback['id']);
        }
        if ($chatId === '') {
            return;
        }

        $user = Auth::userByAddress($chatId);
        if ($user === null) {
            $this->log("ignored callback from unregistered chat {$chatId}");
            return;
        }

        $this->act($data === '' ? 'menu' : $data, $chatId, $user);
    }

    /** Bind this chat to the account the invite code belongs to. */
    private function redeem(string $code, string $chatId, array $message): bool
    {
        if (Auth::userByAddress($chatId) !== null) {
            return false; // already bound; fall through to the normal menu
        }

        $user = Auth::bindInvite($code, $chatId);
        if ($user === null) {
            $this->log("invalid invite code from chat {$chatId}");
            Telegram::call('sendMessage', [
                'chat_id' => $chatId,
                'text'    => 'Dieser Einladungslink ist ungültig oder wurde bereits verwendet.',
            ]);
            return true;
        }

        $this->log("chat {$chatId} bound to user {$user['id']} ({$user['name']})");
        Messenger::reply($chatId, Messages::welcome($user['name']));
        Messenger::menu($chatId);

        return true;
    }

    private function act(string $action, string $chatId, array $user): void
    {
        $this->log("{$chatId} -> {$action}");

        match ($action) {
            Telegram::MENU_PORTAL => $this->sendPortalLink($user, $chatId),
            Telegram::MENU_MONTH  => Messenger::reply($chatId, Messages::currentMonth()),
            Telegram::MENU_YEAR   => Messenger::reply($chatId, Messages::currentYear()),
            default               => Messenger::menu($chatId),
        };
    }

    /** Typed words work as well as buttons. */
    private function action(string $text): string
    {
        $text = ltrim(mb_strtolower(trim($text)), '/');

        foreach ([
            Telegram::MENU_PORTAL => ['portal', 'login', 'dashboard', 'zugang'],
            Telegram::MENU_MONTH  => ['monat', 'monatsertrag', 'month'],
            Telegram::MENU_YEAR   => ['jahr', 'jahresertrag', 'year'],
        ] as $action => $keywords) {
            if (in_array($text, $keywords, true)) {
                return $action;
            }
        }

        return 'menu';
    }

    private function sendPortalLink(array $user, string $chatId): void
    {
        $token = Auth::issueToken((int)$user['id']);
        $ttl   = max(1, (int)Env::get('LOGIN_TOKEN_TTL_MINUTES', 15));

        Messenger::reply($chatId, Messages::portalLink(
            Router::url('login') . '?t=' . urlencode($token),
            $ttl
        ));
    }

    private function report(array $update): void
    {
        $chatId = (string)($update['message']['chat']['id']
            ?? $update['callback_query']['message']['chat']['id'] ?? '');

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'reached'    => 'System.php -> Router -> TelegramWebhook',
            'update_kind' => isset($update['callback_query']) ? 'callback_query'
                : (isset($update['message']) ? 'message' : '(unrecognised)'),
            'chat_id'    => $chatId === '' ? null : $chatId,
            'registered' => $chatId !== '' && Auth::userByAddress($chatId) !== null,
            'secret_header_present' => isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']),
            'update'     => $update,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    }

    private function log(string $line): void
    {
        $path = (string)Env::get('PV_WEBHOOK_LOG', '');
        if ($path !== '') {
            @file_put_contents($path, date('c') . ' [telegram] ' . $line . "\n", FILE_APPEND);
        }
    }
}
