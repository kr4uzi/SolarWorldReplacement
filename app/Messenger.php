<?php
declare(strict_types=1);

namespace PV;

use PV\Transport\Birdy;
use PV\Transport\Http;
use PV\Transport\LogFile;
use PV\Transport\Telegram;
use PV\Transport\Transport;
use PV\Transport\WhatsApp;

/**
 * Resolves the configured messaging transport.
 *
 * Everything that sends goes through here, so the rest of the application
 * never names a provider. Adding one means writing a Transport implementation
 * and listing it below - no caller changes.
 */
final class Messenger
{
    private static ?Transport $transport = null;

    /** @return array<string,class-string<Transport>> */
    public static function available(): array
    {
        return [
            'whatsapp' => WhatsApp::class,
            'birdy'    => Birdy::class,
            'telegram' => Telegram::class,
            'http'     => Http::class,
            'log'      => LogFile::class,
        ];
    }

    public static function transport(): Transport
    {
        if (self::$transport instanceof Transport) {
            return self::$transport;
        }

        $name  = strtolower(trim((string)Env::get('MESSAGING_TRANSPORT', 'log')));
        $known = self::available();

        if (!isset($known[$name])) {
            throw new \RuntimeException(sprintf(
                "Unknown MESSAGING_TRANSPORT '%s'. Available: %s",
                $name,
                implode(', ', array_keys($known))
            ));
        }

        return self::$transport = new $known[$name]();
    }

    public static function name(): string
    {
        return self::transport()->name();
    }

    public static function isConfigured(): bool
    {
        return self::transport()->isConfigured();
    }

    /** The address to reach a user on with the current transport. */
    public static function addressFor(array $user): string
    {
        $address = trim((string)($user['address'] ?? ''));

        return $address !== '' ? $address : (string)($user['phone'] ?? '');
    }

    /** @return array{ok:bool,status:int,body:string} */
    public static function reply(string $address, string $text): array
    {
        return self::transport()->sendReply($address, $text);
    }

    /** @return array{ok:bool,status:int,body:string} */
    public static function notify(string $address, string $text): array
    {
        return self::transport()->sendNotification($address, $text);
    }

    /** @return array{ok:bool,status:int,body:string} */
    public static function menu(string $address): array
    {
        return self::transport()->sendMenu($address);
    }

    /**
     * Send a chart with its figures as the caption.
     *
     * A null image means there was none to draw - GD missing, or no data for
     * the period - and the text goes out on its own, so callers can always
     * ask for the picture without checking first.
     *
     * @return array{ok:bool,status:int,body:string}
     */
    public static function image(string $address, ?string $png, string $caption): array
    {
        return $png === null || $png === ''
            ? self::notify($address, $caption)
            : self::transport()->sendImage($address, $png, $caption);
    }

    /** Only used by tests, which switch transports between cases. */
    public static function reset(): void
    {
        self::$transport = null;
    }
}
