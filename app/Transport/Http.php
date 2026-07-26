<?php
declare(strict_types=1);

namespace PV\Transport;

use PV\Env;

/**
 * Sends messages by calling an arbitrary HTTP endpoint described in .env.
 *
 * This exists so a provider can be adopted without waiting for a bespoke
 * class: give it a URL, whatever headers the service wants, and a request body
 * with {address} and {text} placeholders, and it will post to anything that
 * accepts a plain HTTP request - BirdyChat, ntfy, Gotify, a Slack or Discord
 * webhook, or an internal relay.
 *
 * Placeholders are escaped for the body's content type, so message text
 * carrying quotes, newlines or emoji cannot break the payload or inject
 * structure into it.
 */
final class Http implements Transport
{
    public function name(): string
    {
        return 'http';
    }

    public function isConfigured(): bool
    {
        return $this->url() !== '';
    }

    /** Whatever the endpoint expects; it is substituted verbatim. */
    public function addressKind(): string
    {
        return 'any';
    }

    private function url(): string
    {
        return trim((string)Env::get('HTTP_TRANSPORT_URL', ''));
    }

    /** Headers are configured as "Name: value" joined by | characters. */
    private function headers(): array
    {
        $raw = (string)Env::get('HTTP_TRANSPORT_HEADERS', 'Content-Type: application/json');

        return array_values(array_filter(array_map('trim', explode('|', $raw)), static fn($h) => $h !== ''));
    }

    private function isJson(): bool
    {
        foreach ($this->headers() as $header) {
            if (stripos($header, 'content-type:') === 0 && stripos($header, 'json') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fill {address} and {text} into the configured body.
     *
     * Escaping is the point here: the reports contain quotes, newlines and
     * emoji, all of which would otherwise produce an invalid payload - or, with
     * a hostile enough message, alter its structure.
     */
    private function body(string $address, string $text): string
    {
        $template = (string)Env::get('HTTP_TRANSPORT_BODY', '{"to":"{address}","text":"{text}"}');

        $escape = $this->isJson()
            // trim() removes the quotes json_encode adds; the template supplies them.
            ? static fn(string $v): string => trim(json_encode($v, JSON_UNESCAPED_UNICODE), '"')
            : static fn(string $v): string => rawurlencode($v);

        return strtr($template, [
            '{address}' => $escape($address),
            '{text}'    => $escape($text),
        ]);
    }

    public function sendReply(string $address, string $text): array
    {
        return $this->send($address, $text);
    }

    /** No distinction: endpoints of this kind have no template or window rules. */
    public function sendNotification(string $address, string $text): array
    {
        return $this->send($address, $text);
    }

    public function sendMenu(string $address): array
    {
        return $this->send($address, "Menü\n· Portal\n· Monatsertrag\n· Jahresertrag");
    }

    /** @return array{ok:bool,status:int,body:string} */
    private function send(string $address, string $text): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'body' => 'HTTP_TRANSPORT_URL is not set'];
        }

        $method  = strtoupper((string)Env::get('HTTP_TRANSPORT_METHOD', 'POST'));
        $payload = $this->body($address, $text);
        $timeout = max(1, (int)Env::get('HTTP_TRANSPORT_TIMEOUT', 20));

        if (function_exists('curl_init')) {
            $ch = curl_init($this->url());
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => $this->headers(),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
            ]);
            $body   = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error  = curl_error($ch);
            curl_close($ch);

            return $body === false
                ? ['ok' => false, 'status' => 0, 'body' => $error]
                : ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$body];
        }

        $context = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $this->headers()),
            'content'       => $payload,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($this->url(), false, $context);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'body' => 'request failed'];
        }

        $status = 0;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m)) {
            $status = (int)$m[1];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$body];
    }
}
