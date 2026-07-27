<?php
declare(strict_types=1);

namespace PV\Transport;

use PV\Env;
use PV\HttpClient;

/**
 * BirdyChat connector.
 *
 * Outbound half of the integration; inbound lives in Controller\BirdyWebhook,
 * which BirdyChat needs its own endpoint for.
 *
 * The request shape is driven by configuration rather than hard-coded. That is
 * deliberate: the exact field names should be confirmed against
 * https://docs.birdy.chat/ and corrected in .env without touching this class,
 * and it means a change at their end does not require a code release. The
 * defaults below are a plausible REST shape, not a verified contract - check
 * them before trusting them.
 */
final class Birdy implements Transport
{
    use TextOnly;

    public function name(): string
    {
        return 'birdy';
    }

    public function isConfigured(): bool
    {
        return (string)Env::get('BIRDY_API_TOKEN', '') !== ''
            && $this->endpoint() !== '';
    }

    /** BirdyChat identifies people by work email. */
    public function addressKind(): string
    {
        return 'email';
    }

    private function endpoint(): string
    {
        $base = rtrim((string)Env::get('BIRDY_API_BASE', 'https://api.birdy.chat'), '/');
        $path = '/' . ltrim((string)Env::get('BIRDY_SEND_PATH', '/v1/messages'), '/');

        return $base === '' ? '' : $base . $path;
    }

    /** @return array<int,string> */
    private function headers(): array
    {
        $token  = (string)Env::get('BIRDY_API_TOKEN', '');
        $scheme = (string)Env::get('BIRDY_AUTH_HEADER', 'Authorization: Bearer {token}');

        return [
            str_replace('{token}', $token, $scheme),
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /**
     * Build the request body.
     *
     * Both substitutions are JSON-escaped, so quotes, newlines and emoji in a
     * report cannot break the payload or inject structure into it.
     */
    private function body(string $address, string $text): string
    {
        $template = (string)Env::get('BIRDY_SEND_BODY', '{"to":"{address}","text":"{text}"}');
        $escape   = static fn(string $v): string => trim(json_encode($v, JSON_UNESCAPED_UNICODE), '"');

        return strtr($template, [
            '{address}' => $escape($address),
            '{text}'    => $escape($text),
        ]);
    }

    public function sendReply(string $address, string $text): array
    {
        return $this->send($address, $text);
    }

    /**
     * Same call as a reply: unlike WhatsApp, there is no template approval or
     * 24-hour window making an unprompted message a different kind of thing.
     */
    public function sendNotification(string $address, string $text): array
    {
        return $this->send($address, $text);
    }

    public function sendMenu(string $address): array
    {
        return $this->send($address, \PV\Messages::menuText());
    }

    /** @return array{ok:bool,status:int,body:string} */
    private function send(string $address, string $text): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'body' => 'BirdyChat is not configured (BIRDY_API_TOKEN / BIRDY_API_BASE)'];
        }

        return HttpClient::request(
            (string)Env::get('BIRDY_SEND_METHOD', 'POST'),
            $this->endpoint(),
            $this->headers(),
            $this->body($address, $text),
            max(1, (int)Env::get('BIRDY_TIMEOUT', 20))
        );
    }
}
