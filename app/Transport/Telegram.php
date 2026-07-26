<?php
declare(strict_types=1);

namespace PV\Transport;

use PV\Env;
use PV\HttpClient;
use PV\Messages;

/**
 * Telegram Bot API connector.
 *
 * Users are addressed by numeric chat id, which is not something anyone can
 * type from memory - so accounts are created with an invite link instead, and
 * the chat id is captured when the user taps it. See Auth::createInvite() and
 * Controller\TelegramWebhook.
 *
 * There is no distinction between a reply and an unprompted message here:
 * Telegram has no template approval and no service window, so a bot may write
 * to anyone who has ever started a conversation with it.
 */
final class Telegram implements Transport
{
    public const MENU_PORTAL = 'portal';
    public const MENU_MONTH  = 'month';
    public const MENU_YEAR   = 'year';

    public function name(): string
    {
        return 'telegram';
    }

    public function isConfigured(): bool
    {
        return self::token() !== '';
    }

    /** A numeric chat id, captured from the invite link rather than typed. */
    public function addressKind(): string
    {
        return 'chat_id';
    }

    public static function token(): string
    {
        return trim((string)Env::get('TELEGRAM_BOT_TOKEN', ''));
    }

    /** Deep link that starts a conversation and carries the invite code. */
    public static function inviteLink(string $code): string
    {
        $username = ltrim(trim((string)Env::get('TELEGRAM_BOT_USERNAME', '')), '@');

        return $username === ''
            ? '(set TELEGRAM_BOT_USERNAME to build the invite link)'
            : "https://t.me/{$username}?start={$code}";
    }

    /** @return array{ok:bool,status:int,body:string} */
    public static function call(string $method, array $params): array
    {
        $token = self::token();
        if ($token === '') {
            return ['ok' => false, 'status' => 0, 'body' => 'TELEGRAM_BOT_TOKEN is not set'];
        }

        $base = rtrim((string)Env::get('TELEGRAM_API_BASE', 'https://api.telegram.org'), '/');

        return HttpClient::request(
            'POST',
            "{$base}/bot{$token}/{$method}",
            ['Content-Type: application/json'],
            json_encode($params, JSON_UNESCAPED_UNICODE),
            max(1, (int)Env::get('TELEGRAM_TIMEOUT', 20))
        );
    }

    public function sendReply(string $address, string $text): array
    {
        return self::call('sendMessage', ['chat_id' => $address, 'text' => $text]);
    }

    public function sendNotification(string $address, string $text): array
    {
        return $this->sendReply($address, $text);
    }

    /** The menu as tappable buttons rather than keywords to remember. */
    public function sendMenu(string $address): array
    {
        return self::call('sendMessage', [
            'chat_id'      => $address,
            'text'         => Messages::menuBody(),
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '🔑 Portal', 'callback_data' => self::MENU_PORTAL]],
                    [
                        ['text' => '📅 Monatsertrag', 'callback_data' => self::MENU_MONTH],
                        ['text' => '📈 Jahresertrag', 'callback_data' => self::MENU_YEAR],
                    ],
                ],
            ],
        ]);
    }

    /** Stops the button's spinner; Telegram expects this for every callback. */
    public static function acknowledgeCallback(string $callbackId): void
    {
        self::call('answerCallbackQuery', ['callback_query_id' => $callbackId]);
    }
}
