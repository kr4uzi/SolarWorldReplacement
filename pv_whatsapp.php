<?php
/**
 * WhatsApp Cloud API transport.
 *
 * Two kinds of outbound message matter here:
 *
 *  - Template messages: required for anything the plant starts on its own
 *    (the midday alert, the monthly/yearly reports). Meta must approve the
 *    template first; this is the billable category.
 *  - Free-form text / interactive messages: allowed only inside the 24-hour
 *    service window that opens when the user messages us. These are free,
 *    and are what the menu uses.
 *
 * No external libraries: plain HTTPS via cURL, falling back to stream
 * wrappers on hosts without the cURL extension.
 */

require_once __DIR__ . '/pv_data.php';

function pvWaEndpoint(): string
{
    $version = (string)pvSetting('WHATSAPP_API_VERSION', 'v21.0');
    $phoneId = (string)pvSetting('WHATSAPP_PHONE_NUMBER_ID', '');
    return "https://graph.facebook.com/{$version}/{$phoneId}/messages";
}

/** Cloud API wants bare digits: '+41 79 123 45 67' -> '41791234567'. */
function pvWaNormalize(string $phone): string
{
    return preg_replace('/\D+/', '', $phone);
}

function pvWaConfigured(): bool
{
    return pvSetting('WHATSAPP_TOKEN', '') !== '' && pvSetting('WHATSAPP_PHONE_NUMBER_ID', '') !== '';
}

/**
 * POST a message payload to the Cloud API.
 * Returns ['ok' => bool, 'status' => int, 'body' => string].
 */
function pvWaPost(array $payload): array
{
    if (!pvWaConfigured()) {
        return ['ok' => false, 'status' => 0, 'body' => 'WhatsApp is not configured (WHATSAPP_TOKEN / WHATSAPP_PHONE_NUMBER_ID)'];
    }

    $url  = pvWaEndpoint();
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = [
        'Authorization: Bearer ' . pvSetting('WHATSAPP_TOKEN', ''),
        'Content-Type: application/json',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
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

        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'body' => $error];
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$body];
    }

    $context = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers),
        'content'       => $json,
        'timeout'       => 20,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return ['ok' => false, 'status' => 0, 'body' => 'request failed'];
    }
    $status = 0;
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m)) {
        $status = (int)$m[1];
    }
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$body];
}

/** Free-form text. Only delivered inside an open 24-hour service window. */
function pvWaSendText(string $to, string $text): array
{
    return pvWaPost([
        'messaging_product' => 'whatsapp',
        'to'                => pvWaNormalize($to),
        'type'              => 'text',
        'text'              => ['body' => $text, 'preview_url' => false],
    ]);
}

/**
 * Business-initiated message via an approved template.
 * Expects a template whose body contains a single {{1}} placeholder,
 * e.g. "PV Monitor update: {{1}}".
 */
function pvWaSendTemplate(string $to, string $text): array
{
    // Meta rejects newlines/tabs in template parameters, so flatten the body.
    $parameter = trim(preg_replace('/\s*\n\s*/', ' — ', $text));

    return pvWaPost([
        'messaging_product' => 'whatsapp',
        'to'                => pvWaNormalize($to),
        'type'              => 'template',
        'template'          => [
            'name'       => (string)pvSetting('WHATSAPP_TEMPLATE_NAME', 'pv_update'),
            'language'   => ['code' => (string)pvSetting('WHATSAPP_TEMPLATE_LANG', 'en')],
            'components' => [[
                'type'       => 'body',
                'parameters' => [['type' => 'text', 'text' => $parameter]],
            ]],
        ],
    ]);
}

/** The main menu as an interactive list. Free-form, so window must be open. */
function pvWaSendMenu(string $to, string $body = 'What would you like to see?'): array
{
    return pvWaPost([
        'messaging_product' => 'whatsapp',
        'to'                => pvWaNormalize($to),
        'type'              => 'interactive',
        'interactive'       => [
            'type'   => 'list',
            'header' => ['type' => 'text', 'text' => 'PV Monitor'],
            'body'   => ['text' => $body],
            'action' => [
                'button'   => 'Choose',
                'sections' => [[
                    'title' => 'Production',
                    'rows'  => [
                        ['id' => 'today', 'title' => 'Today',      'description' => 'Yield so far and current output'],
                        ['id' => 'week',  'title' => 'Last 7 days', 'description' => 'Rolling week total'],
                        ['id' => 'month', 'title' => 'This month',  'description' => 'Month to date'],
                        ['id' => 'year',  'title' => 'This year',   'description' => 'Year to date'],
                    ],
                ]],
            ],
        ],
    ]);
}

/** Quick follow-up buttons (max 3, enforced by the API). */
function pvWaSendButtons(string $to, string $body, array $buttons): array
{
    $rows = [];
    foreach (array_slice($buttons, 0, 3) as $id => $title) {
        $rows[] = ['type' => 'reply', 'reply' => ['id' => (string)$id, 'title' => mb_substr($title, 0, 20)]];
    }

    return pvWaPost([
        'messaging_product' => 'whatsapp',
        'to'                => pvWaNormalize($to),
        'type'              => 'interactive',
        'interactive'       => [
            'type'   => 'button',
            'body'   => ['text' => $body],
            'action' => ['buttons' => $rows],
        ],
    ]);
}
