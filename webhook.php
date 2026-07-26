<?php
/**
 * WhatsApp Cloud API webhook.
 *
 * Meta calls this URL when someone messages the business number:
 *   GET  - one-time verification handshake when you register the webhook
 *   POST - inbound messages and delivery status events
 *
 * Replies are free-form, which is allowed (and free) because the user
 * started the conversation, opening a 24-hour service window.
 *
 * This endpoint is public, so it is locked down two ways: Meta's payload
 * signature is verified against the app secret, and only phone numbers in
 * WHATSAPP_ALLOWED_SENDERS get answered. Without the whitelist anyone who
 * finds the number could read the plant's production data.
 */

require_once __DIR__ . '/pv_data.php';
require_once __DIR__ . '/pv_messages.php';
require_once __DIR__ . '/pv_whatsapp.php';

pvInitTimezone();

function pvWebhookLog(string $line): void
{
    $path = (string)pvSetting('PV_WEBHOOK_LOG', '');
    if ($path !== '') {
        @file_put_contents($path, date('c') . ' ' . $line . "\n", FILE_APPEND);
    }
}

// --- Verification handshake -------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $verifyToken = (string)pvSetting('WHATSAPP_VERIFY_TOKEN', '');
    $mode      = $_GET['hub_mode']         ?? $_GET['hub.mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? $_GET['hub.challenge']    ?? '';

    if ($mode === 'subscribe' && $verifyToken !== '' && hash_equals($verifyToken, (string)$token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }

    http_response_code(403);
    exit('Forbidden');
}

// --- Inbound events ---------------------------------------------------------

$raw = file_get_contents('php://input') ?: '';

// Verify the payload really came from Meta.
$appSecret = (string)pvSetting('WHATSAPP_APP_SECRET', '');
if ($appSecret !== '') {
    $provided = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $appSecret);
    if (!hash_equals($expected, $provided)) {
        pvWebhookLog('rejected: bad signature');
        http_response_code(403);
        exit('Forbidden');
    }
}

// Acknowledge immediately - Meta retries (and eventually disables) the webhook
// if we are slow or return an error, so the reply is sent after this point.
http_response_code(200);
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    exit;
}

/** Pull the inbound messages out of Meta's nested envelope. */
function pvWebhookMessages(array $payload): array
{
    $messages = [];
    foreach ($payload['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            // Delivery receipts arrive here too, under 'statuses' - ignore them.
            foreach ($change['value']['messages'] ?? [] as $message) {
                $messages[] = $message;
            }
        }
    }
    return $messages;
}

/** Map an inbound message to a menu action. */
function pvWebhookAction(array $message): string
{
    if (($message['type'] ?? '') === 'interactive') {
        $interactive = $message['interactive'] ?? [];
        $id = $interactive['list_reply']['id'] ?? $interactive['button_reply']['id'] ?? '';
        return strtolower(trim((string)$id));
    }

    $text = strtolower(trim((string)($message['text']['body'] ?? '')));
    $text = ltrim($text, '/');

    foreach ([
        'today' => ['today', 'heute', 'now', 'jetzt'],
        'week'  => ['week', 'woche', '7', '7 days'],
        'month' => ['month', 'monat'],
        'year'  => ['year', 'jahr'],
    ] as $action => $keywords) {
        foreach ($keywords as $keyword) {
            if ($text === $keyword || str_starts_with($text, $keyword . ' ')) {
                return $action;
            }
        }
    }

    return 'menu';
}

$allowed = array_map('pvWaNormalize', pvSettingList('WHATSAPP_ALLOWED_SENDERS'));

foreach (pvWebhookMessages($payload) as $message) {
    $from = pvWaNormalize((string)($message['from'] ?? ''));
    if ($from === '') {
        continue;
    }

    if (!in_array($from, $allowed, true)) {
        // Silence rather than "access denied": do not confirm the number exists.
        pvWebhookLog("ignored message from unlisted sender {$from}");
        continue;
    }

    $action = pvWebhookAction($message);
    pvWebhookLog("{$from} -> {$action}");

    switch ($action) {
        case 'today':
            pvWaSendText($from, pvMessageToday());
            pvWaSendButtons($from, 'Anything else?', ['week' => 'Last 7 days', 'month' => 'This month', 'year' => 'This year']);
            break;

        case 'week':
            pvWaSendText($from, pvMessageWeek());
            pvWaSendButtons($from, 'Anything else?', ['today' => 'Today', 'month' => 'This month', 'year' => 'This year']);
            break;

        case 'month':
            pvWaSendText($from, pvMessageMonth());
            pvWaSendButtons($from, 'Anything else?', ['today' => 'Today', 'week' => 'Last 7 days', 'year' => 'This year']);
            break;

        case 'year':
            pvWaSendText($from, pvMessageYear());
            pvWaSendButtons($from, 'Anything else?', ['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'This month']);
            break;

        default:
            pvWaSendMenu($from);
            break;
    }
}
