<?php
declare(strict_types=1);

namespace PV\Transport;

use PV\Env;
use PV\Messages;

/**
 * The only place that talks to Meta.
 *
 * Two categories of outbound message matter:
 *
 *  - Templates: required for anything we start ourselves (the monthly report,
 *    the no-production alert). Meta must approve the template first, and this
 *    is the billable category.
 *  - Free-form text and interactive messages: allowed only inside the 24-hour
 *    service window a user opens by messaging us. These are free, and are what
 *    the menu and its answers use.
 *
 * No external libraries: plain HTTPS via cURL, falling back to stream wrappers
 * where the cURL extension is unavailable.
 */
final class WhatsApp implements Transport
{
    use TextOnly;

    public const MENU_PORTAL = 'portal';
    public const MENU_MONTH  = 'month';
    public const MENU_YEAR   = 'year';

    public function name(): string
    {
        return 'whatsapp';
    }

    public function addressKind(): string
    {
        return 'phone';
    }

    public function sendReply(string $address, string $text): array
    {
        return self::sendText($address, $text);
    }

    public function sendNotification(string $address, string $text): array
    {
        // Business-initiated, so Meta requires an approved template.
        return self::sendTemplate($address, $text);
    }

    public function sendMenu(string $address): array
    {
        return self::sendList($address);
    }

    public function isConfigured(): bool
    {
        return self::hasCredentials();
    }

    /** Static form, so callers can ask before building an instance. */
    public static function hasCredentials(): bool
    {
        return (string)Env::get('META_TOKEN', '') !== ''
            && (string)Env::get('META_PHONE_NUMBER_ID', '') !== '';
    }

    private static function endpoint(): string
    {
        return sprintf(
            '%s/%s/%s/messages',
            rtrim((string)Env::get('META_API_BASE', 'https://graph.facebook.com'), '/'),
            (string)Env::get('META_API_VERSION', 'v21.0'),
            (string)Env::get('META_PHONE_NUMBER_ID', '')
        );
    }

    /** Meta wants bare digits: '+41 79 123 45 67' -> '41791234567'. */
    public static function normalize(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /** @return array{ok:bool,status:int,body:string} */
    private static function post(array $payload): array
    {
        if (!self::hasCredentials()) {
            return ['ok' => false, 'status' => 0, 'body' => 'WhatsApp is not configured (META_TOKEN / META_PHONE_NUMBER_ID)'];
        }

        $json    = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Authorization: Bearer ' . Env::get('META_TOKEN', ''),
            'Content-Type: application/json',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init(self::endpoint());
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
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
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $json,
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents(self::endpoint(), false, $context);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'body' => 'request failed'];
        }

        $status = 0;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m)) {
            $status = (int)$m[1];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$body];
    }

    /** Free-form text. Requires an open 24-hour service window. */
    public static function sendText(string $to, string $text): array
    {
        return self::post([
            'messaging_product' => 'whatsapp',
            'to'                => self::normalize($to),
            'type'              => 'text',
            'text'              => ['body' => $text, 'preview_url' => false],
        ]);
    }

    /**
     * Business-initiated message through an approved template whose body holds
     * a single {{1}} placeholder, e.g. 'PV Anlage: {{1}}'.
     */
    public static function sendTemplate(string $to, string $text): array
    {
        // Meta rejects newlines and tabs inside template parameters.
        $parameter = trim((string)preg_replace('/\s*\n\s*/', ' — ', $text));

        return self::post([
            'messaging_product' => 'whatsapp',
            'to'                => self::normalize($to),
            'type'              => 'template',
            'template'          => [
                'name'       => (string)Env::get('META_TEMPLATE_NAME', 'pv_update'),
                'language'   => ['code' => (string)Env::get('META_TEMPLATE_LANG', 'de')],
                'components' => [[
                    'type'       => 'body',
                    'parameters' => [['type' => 'text', 'text' => $parameter]],
                ]],
            ],
        ]);
    }

    /** The German menu, as an interactive list. */
    public static function sendList(string $to): array
    {
        return self::post([
            'messaging_product' => 'whatsapp',
            'to'                => self::normalize($to),
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'list',
                'header' => ['type' => 'text', 'text' => 'PV Anlage'],
                'body'   => ['text' => Messages::menuBody()],
                'action' => [
                    'button'   => 'Auswählen',
                    'sections' => [[
                        'title' => 'Übersicht',
                        'rows'  => [
                            ['id' => self::MENU_PORTAL, 'title' => 'Portal',       'description' => 'Zugang zum Dashboard'],
                            ['id' => self::MENU_MONTH,  'title' => 'Monatsertrag', 'description' => 'Ertrag im laufenden Monat'],
                            ['id' => self::MENU_YEAR,   'title' => 'Jahresertrag', 'description' => 'Ertrag im laufenden Jahr'],
                        ],
                    ]],
                ],
            ],
        ]);
    }
}
