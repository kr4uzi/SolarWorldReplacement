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
    public const MENU_WEEK   = 'week';

    /** Telegram truncates a photo caption past this many characters. */
    private const CAPTION_LIMIT = 1024;

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
        return self::call('sendMessage', [
            'chat_id' => $address,
            'text'    => $text,
            // Telegram fetches links to build a preview card. A login link is
            // single-use, so that fetch would spend it before its owner ever
            // tapped it - and the message looks tidier without the card anyway.
            'link_preview_options' => ['is_disabled' => true],
        ]);
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
                        ['text' => '📊 7 Tage', 'callback_data' => self::MENU_WEEK],
                    ],
                    [
                        ['text' => '📅 Monatsertrag', 'callback_data' => self::MENU_MONTH],
                        ['text' => '📈 Jahresertrag', 'callback_data' => self::MENU_YEAR],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Upload a chart.
     *
     * The picture is posted as multipart rather than referenced by URL: the
     * data would otherwise have to be reachable from the internet, which is
     * the opposite of what a private plant's figures want, and it would mean
     * an unauthenticated route serving them.
     */
    public function sendImage(string $address, string $png, string $caption): array
    {
        $token = self::token();
        if ($token === '') {
            return ['ok' => false, 'status' => 0, 'body' => 'TELEGRAM_BOT_TOKEN is not set'];
        }

        // A caption past the limit is truncated by Telegram, silently losing
        // the end of the report. Send the picture bare and the text after it.
        $overlong = mb_strlen($caption) > self::CAPTION_LIMIT;

        [$contentType, $body] = HttpClient::multipart(
            ['chat_id' => $address] + ($overlong ? [] : ['caption' => $caption]),
            ['photo' => ['filename' => 'chart.png', 'type' => 'image/png', 'content' => $png]]
        );

        $base   = rtrim((string)Env::get('TELEGRAM_API_BASE', 'https://api.telegram.org'), '/');
        $result = HttpClient::request(
            'POST',
            "{$base}/bot{$token}/sendPhoto",
            ['Content-Type: ' . $contentType],
            $body,
            max(1, (int)Env::get('TELEGRAM_TIMEOUT', 20))
        );

        // Fall back to the text when the upload fails, so a picture that will
        // not go through does not cost the user the report itself.
        if (!$result['ok']) {
            return $this->sendReply($address, $caption);
        }

        return $overlong ? $this->sendReply($address, $caption) : $result;
    }

    /** Stops the button's spinner; Telegram expects this for every callback. */
    public static function acknowledgeCallback(string $callbackId): void
    {
        self::call('answerCallbackQuery', ['callback_query_id' => $callbackId]);
    }
}
