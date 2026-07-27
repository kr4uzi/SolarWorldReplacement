<?php
declare(strict_types=1);

namespace PV\Transport;

use PV\Env;

/**
 * Writes messages to a file instead of sending them.
 *
 * This exists so the system can be run and validated without any messaging
 * provider at all - the scheduled job, the monthly report, the fault alert and
 * the portal links can all be exercised end to end, and you read what would
 * have been sent. Useful while a provider is undecided or its onboarding is
 * stuck, and useful afterwards for reproducing a problem without messaging
 * real people.
 *
 * Addresses are recorded as given; the transport never contacts anyone.
 */
final class LogFile implements Transport
{
    public function name(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return $this->path() !== '';
    }

    /** Accepts whatever the operator entered - it is never dialled. */
    public function addressKind(): string
    {
        return 'any';
    }

    private function path(): string
    {
        return (string)Env::get('MESSAGE_LOG', dirname(__DIR__, 2) . '/data/messages.log');
    }

    public function sendReply(string $address, string $text): array
    {
        return $this->write('reply', $address, $text);
    }

    public function sendNotification(string $address, string $text): array
    {
        return $this->write('notification', $address, $text);
    }

    public function sendMenu(string $address): array
    {
        return $this->write('menu', $address, "Menü: Portal · Woche · Monatsertrag · Jahresertrag · Einstellungen");
    }

    /**
     * Writes the picture next to the log rather than dropping it.
     *
     * The point of this transport is seeing what would have been sent, and
     * "a chart was attached" is not something you can check by reading.
     */
    public function sendImage(string $address, string $png, string $caption): array
    {
        $file = dirname($this->path()) . '/message-' . date('Ymd-His') . '-' . substr(md5($png), 0, 6) . '.png';
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        $saved = @file_put_contents($file, $png) !== false;

        return $this->write(
            'image',
            $address,
            $caption . "\n\n[" . ($saved ? $file : 'image could not be written') . ']'
        );
    }

    private function write(string $kind, string $address, string $text): array
    {
        $entry = sprintf(
            "%s  [%s] to %s\n%s\n%s\n",
            date('c'),
            $kind,
            $address,
            preg_replace('/^/m', '    ', $text),
            str_repeat('-', 60)
        );

        $path = $this->path();
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        $written = @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);

        return $written === false
            ? ['ok' => false, 'status' => 0, 'body' => "cannot write to {$path}"]
            : ['ok' => true, 'status' => 200, 'body' => $path];
    }
}
