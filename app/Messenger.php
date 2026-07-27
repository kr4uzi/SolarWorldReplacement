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

    /**
     * A specific transport by name, for talking on a channel that names one.
     *
     * Distinct from transport(), which answers "what does this installation
     * use by default" - the setting that decides where new invites are sent.
     * Delivery cannot use that: a user's channels each name their own.
     */
    public static function via(string $name): ?Transport
    {
        $known = self::available()[strtolower(trim($name))] ?? null;

        return $known === null ? null : new $known();
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

    /**
     * The address a user is reached at first.
     *
     * Delivery goes through deliver(), which walks every channel - this is for
     * the places that need something to show an operator, like a listing or a
     * one-off test send.
     */
    public static function addressFor(array $user): string
    {
        $channels = Channel::forUser((int)($user['id'] ?? 0));
        if ($channels !== []) {
            return (string)$channels[0]['address'];
        }

        // Pre-channels installations, between pulling the code and running
        // setup.php init.
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

    /**
     * Send to a user over whichever of their channels works.
     *
     * The channels are tried in priority order and the first success ends it,
     * which is what makes a costly last resort safe to configure: SMS is only
     * reached for when everything above it could not deliver, so it bills
     * nothing on a normal day.
     *
     * A failure is only a failure once every channel has refused. That is
     * deliberately different from "the first one failed": a plant alert that
     * stops at a transport having a bad afternoon is the one message you
     * cannot afford to lose.
     *
     * @param string|null $png a chart to attach, if the channel can carry one
     * @return array{ok:bool,status:int,body:string,transport:string,tried:array<int,string>}
     */
    public static function deliver(array $user, string $text, ?string $png = null): array
    {
        $channels = Channel::forUser((int)$user['id']);
        $tried    = [];
        $last     = ['ok' => false, 'status' => 0, 'body' => 'no channel configured'];

        foreach ($channels as $channel) {
            $name      = (string)$channel['transport'];
            $transport = self::via($name);
            $address   = (string)$channel['address'];

            if ($transport === null) {
                $tried[] = "{$name}: unknown transport";
                continue;
            }
            if (!$transport->isConfigured()) {
                $tried[] = "{$name}: not configured";
                continue;
            }

            $last = $png === null || $png === ''
                ? $transport->sendNotification($address, $text)
                : $transport->sendImage($address, $png, $text);

            if ($last['ok']) {
                return $last + ['transport' => $name, 'tried' => $tried];
            }

            $tried[] = "{$name}: HTTP {$last['status']} {$last['body']}";
        }

        return $last + ['transport' => '', 'tried' => $tried];
    }

    /** Only used by tests, which switch transports between cases. */
    public static function reset(): void
    {
        self::$transport = null;
    }
}
