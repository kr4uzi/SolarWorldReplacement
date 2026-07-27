<?php
declare(strict_types=1);

namespace PV\Controller;

use PV\Auth;
use PV\Chart;
use PV\Env;
use PV\ErrorPage;
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

        // Everything past this point runs after the response has been sent,
        // so an exception here reaches nobody: Telegram already has its 200,
        // the user sees silence, and only the server's error log knows. Catch
        // it and put it somewhere the operator actually looks.
        try {
            if (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
                return;
            }

            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            }
        } catch (\Throwable $e) {
            $this->log(sprintf('ERROR %s: %s (%s:%d)',
                get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine()));

            // Silence here is indistinguishable from a dead webhook, and the
            // user has no way to tell which they are looking at. Say that it
            // failed - and when the cause is a database that was never
            // upgraded, say that too, since it is one command to fix.
            $this->apologise($update);
        }
    }

    /**
     * Tell the chat that the request failed.
     *
     * Best-effort by nature: this runs because something already threw, so it
     * must not throw in turn - the response has long since been sent and a
     * second failure would go nowhere at all.
     */
    private function apologise(array $update): void
    {
        try {
            $chatId = (string)($update['message']['chat']['id']
                ?? $update['callback_query']['message']['chat']['id']
                ?? $update['callback_query']['from']['id'] ?? '');
            if ($chatId === '') {
                return;
            }

            // Stop the button's spinner first, if a button is what failed.
            $callbackId = (string)($update['callback_query']['id'] ?? '');
            if ($callbackId !== '') {
                Telegram::acknowledgeCallback($callbackId, Messages::failedShort());
            }

            Telegram::call('sendMessage', [
                'chat_id' => $chatId,
                'text'    => Messages::failed(ErrorPage::schemaIsStale()),
            ]);
        } catch (\Throwable $inner) {
            $this->log('ERROR while reporting the failure: ' . $inner->getMessage());
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
        $chatId    = (string)($callback['message']['chat']['id'] ?? $callback['from']['id'] ?? '');
        $data      = trim((string)($callback['data'] ?? ''));
        $callbackId = (string)($callback['id'] ?? '');

        if ($chatId === '') {
            if ($callbackId !== '') {
                Telegram::acknowledgeCallback($callbackId);
            }
            return;
        }

        $user = Auth::userByAddress($chatId);
        if ($user === null) {
            $this->log("ignored callback from unregistered chat {$chatId}");
            if ($callbackId !== '') {
                Telegram::acknowledgeCallback($callbackId);
            }
            return;
        }

        // A switch inside the settings card rewrites that card in place, so it
        // is handled here rather than in act(): it needs the id of the message
        // the button belongs to, which nothing else does.
        if (str_starts_with($data, Telegram::TOGGLE_PREFIX) && $data !== Telegram::NOTIFY_TIME) {
            $this->toggleSetting(
                substr($data, strlen(Telegram::TOGGLE_PREFIX)),
                $chatId,
                $user,
                $callbackId,
                (int)($callback['message']['message_id'] ?? 0)
            );
            return;
        }

        if ($callbackId !== '') {
            Telegram::acknowledgeCallback($callbackId);
        }

        $this->act($data === '' ? 'menu' : $data, $chatId, $user);
    }

    /** Flip one notification switch and rewrite the card it lives in. */
    private function toggleSetting(string $which, string $chatId, array $user, string $callbackId, int $messageId): void
    {
        $updated = Auth::toggleNotification((int)$user['id'], $which);
        if ($updated === null) {
            $this->log("unknown notification switch '{$which}' from chat {$chatId}");
            if ($callbackId !== '') {
                Telegram::acknowledgeCallback($callbackId);
            }
            return;
        }

        $settings = Auth::settings($updated);
        $this->log("{$chatId} -> notify:{$which} = " . ($settings[$which] ? 'on' : 'off'));

        if ($callbackId !== '') {
            Telegram::acknowledgeCallback(
                $callbackId,
                Messages::notificationToggled($which, (bool)$settings[$which])
            );
        }

        Telegram::sendSettings($chatId, $settings, $messageId > 0 ? $messageId : null);
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

        // The figures are the caption of their own chart: one message rather
        // than a picture and a wall of numbers arriving separately. Messenger
        // falls back to text wherever an image cannot be drawn or sent.
        match ($action) {
            Telegram::MENU_PORTAL => $this->sendPortalLink($user, $chatId),
            Telegram::MENU_NOTIFY => Telegram::sendSettings($chatId, Auth::settings($user)),
            Telegram::NOTIFY_TIME => $this->sendPortalLink($user, $chatId, 'settings'),
            Telegram::MENU_WEEK   => Messenger::image($chatId, Chart::lastDays(7), Messages::lastDays(7)),
            Telegram::MENU_MONTH  => Messenger::image(
                $chatId,
                Chart::month((int)date('n'), (int)date('Y')),
                Messages::currentMonth()
            ),
            Telegram::MENU_YEAR   => Messenger::image(
                $chatId,
                Chart::year((int)date('Y')),
                Messages::currentYear()
            ),
            default               => Messenger::menu($chatId),
        };
    }

    /** Typed words work as well as buttons. */
    private function action(string $text): string
    {
        $text = ltrim(mb_strtolower(trim($text)), '/');

        foreach ([
            Telegram::MENU_PORTAL => ['portal', 'login', 'dashboard', 'zugang'],
            Telegram::MENU_WEEK   => ['woche', '7 tage', 'grafik', 'chart', 'week'],
            Telegram::MENU_MONTH  => ['monat', 'monatsertrag', 'month'],
            Telegram::MENU_YEAR   => ['jahr', 'jahresertrag', 'year'],
            Telegram::MENU_NOTIFY => [
                'benachrichtigungen', 'benachrichtigung', 'einstellungen',
                'settings', 'notify', 'alarm',
            ],
        ] as $action => $keywords) {
            if (in_array($text, $keywords, true)) {
                return $action;
            }
        }

        return 'menu';
    }

    /**
     * A one-time login link.
     *
     * $route names where it should land - empty for the dashboard, 'settings'
     * for the notification page, where the browser's own time picker is a far
     * better way to choose an hour than a keyboard of 24 buttons would be.
     */
    private function sendPortalLink(array $user, string $chatId, string $route = ''): void
    {
        $token = Auth::issueToken((int)$user['id']);
        $ttl   = max(1, (int)Env::get('LOGIN_TOKEN_TTL_MINUTES', 15));
        $url   = Router::url('login') . '?t=' . urlencode($token)
               . ($route === '' ? '' : '&n=' . urlencode($route));

        Messenger::reply($chatId, $route === 'settings'
            ? Messages::notificationTimeLink($url, $ttl)
            : Messages::portalLink($url, $ttl));
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
