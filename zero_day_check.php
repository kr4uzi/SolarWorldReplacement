<?php
/**
 * PV Zero-Day Detection & Alerting
 *
 * Detects "zero days" - days where the whole plant or a single inverter
 * reported (almost) no energy production, or where the data logger stopped
 * uploading data - and sends a warning via email and/or WhatsApp.
 *
 * No external libraries required: email uses PHP's built-in mail(),
 * WhatsApp uses a single HTTPS request to CallMeBot.
 *
 * Run once per day (after the day is complete), either via cron:
 *     15 6 * * * php /path/to/pv/zero_day_check.php
 * or via web-cron (set 'http_key' below first):
 *     https://example.com/pv/zero_day_check.php?key=YOUR_SECRET
 *
 * Alerts are deduplicated via a small state file, so running the script
 * more often than daily is safe and will not spam you.
 */

$config = [
    // Directory containing days.csv / base_vars.js (as used by api.php)
    'data_dir' => __DIR__ . '/data/',

    // A day with less total production than this (in Wh) counts as a "zero day".
    // Keep it > 0: even dark winter days produce a little, while a faulty
    // inverter typically reports exactly 0.
    'min_day_wh' => 100,

    // If the newest entry in days.csv is older than this many days,
    // the logger has probably stopped uploading -> alert.
    'max_data_age_days' => 2,

    // --- Notification: email (leave empty to disable) ---
    'notify_email' => '',           // e.g. 'me@example.com'
    'from_email'   => '',           // optional From: header, e.g. 'pv@example.com'

    // --- Notification: WhatsApp via CallMeBot (leave empty to disable) ---
    // Free for personal notifications to your own number. One-time setup:
    // 1. Save +34 644 20 47 56 in your phone's contacts (e.g. as "CallMeBot").
    // 2. Send that contact this exact WhatsApp message:
    //        I allow callmebot to send me messages
    // 3. The bot replies with your personal API key - paste it below.
    // Note: CallMeBot is a free third-party relay, so alert text passes through
    // their server. For an official (paid, business-verified) route see README.
    'whatsapp_phone'  => '',        // your number incl. country code, e.g. '+41791234567'
    'whatsapp_apikey' => '',        // the key CallMeBot replied with

    // Secret key required when the script is called via HTTP (web-cron).
    // Leave empty to allow CLI execution only.
    'http_key' => '',

    // Remembers which alerts were already sent (prevents duplicates).
    'state_file' => __DIR__ . '/data/zero_day_state.json',
];

// ---------------------------------------------------------------------------

// When called via web, require the configured key
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if ($config['http_key'] === '' || !hash_equals($config['http_key'], $_GET['key'] ?? '')) {
        http_response_code(403);
        exit("Forbidden\n");
    }
}

/** Parse a 'dd.mm.yy' date string to a timestamp (0 if invalid) */
function parseDate(string $date): int
{
    $dt = DateTime::createFromFormat('!d.m.y', $date);
    return $dt ? $dt->getTimestamp() : 0;
}

/** Parse days.csv: one row per inverter per day: date;inverter;Psum(Wh);Pmax(W) */
function readDays(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $days = []; // 'dd.mm.yy' => [inverterIndex => Psum in Wh]
    $handle = fopen($file, 'r');
    fgets($handle); // skip header
    while (($line = fgets($handle)) !== false) {
        $row = str_getcsv(trim($line), ';', '"', '\\');
        if (count($row) < 3) {
            continue;
        }
        $days[$row[0]][(int)$row[1]] = (float)$row[2];
    }
    fclose($handle);
    return $days;
}

/** Inverter display names from base_vars.js (falls back to "WR n") */
function inverterName(string $dataDir, int $index): string
{
    static $content = null;
    if ($content === null) {
        $file = $dataDir . 'base_vars.js';
        $content = file_exists($file) ? file_get_contents($file) : '';
    }
    if (preg_match('/WRInfo\[' . $index . '\]\s*=\s*new\s+Array\s*\((.*?)\)/s', $content, $m)
        && preg_match_all('/"([^"]*)"/', $m[1], $strings)
        && isset($strings[1][4]) && $strings[1][4] !== '') {
        return $strings[1][4];
    }
    return 'WR ' . ($index + 1);
}

/** Send a WhatsApp message via CallMeBot. Uses cURL, falls back to streams. */
function sendWhatsApp(array $config, string $text): bool
{
    $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
        'phone'  => $config['whatsapp_phone'],
        'text'   => $text,
        'apikey' => $config['whatsapp_apikey'],
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $body !== false && $status >= 200 && $status < 300;
    }

    // allow_url_fopen fallback for hosts without the cURL extension
    $context = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    if (@file_get_contents($url, false, $context) === false) {
        return false;
    }
    return (bool)preg_match('#^HTTP/\S+\s+2\d\d#', $http_response_header[0] ?? '');
}

/**
 * Send the alert through every configured channel.
 * Returns [channel => delivered?] so the caller can report partial failures.
 */
function sendAlert(array $config, string $subject, string $message): array
{
    $results = [];

    if ($config['notify_email'] !== '') {
        $headers = $config['from_email'] !== '' ? 'From: ' . $config['from_email'] : '';
        $results['email'] = mail($config['notify_email'], $subject, $message, $headers);
    }

    if ($config['whatsapp_phone'] !== '' && $config['whatsapp_apikey'] !== '') {
        $results['whatsapp'] = sendWhatsApp($config, $subject . ': ' . $message);
    }

    return $results;
}

// ---------------------------------------------------------------------------

$days = readDays($config['data_dir'] . 'days.csv');

$alerts = []; // key (for dedup) => message
$checkDate = date('d.m.y', strtotime('-1 day'));

if (empty($days)) {
    $alerts['no-data'] = 'days.csv is missing or empty - the data logger is not uploading any data.';
} else {
    // 1. Logger stale? Newest date in days.csv too old -> uploads stopped.
    $newest = 0;
    foreach (array_keys($days) as $date) {
        $newest = max($newest, parseDate($date));
    }
    $ageDays = (time() - $newest) / 86400;
    if ($ageDays > $config['max_data_age_days']) {
        $alerts['stale-' . date('d.m.y', $newest)] = sprintf(
            'No new data since %s (%d days). The data logger has probably stopped uploading.',
            date('d.m.y', $newest), floor($ageDays)
        );
    } elseif (!isset($days[$checkDate])) {
        // 2. Yesterday completely missing (but other data is recent)
        $alerts['missing-' . $checkDate] = "No production data for $checkDate.";
    } else {
        $production = $days[$checkDate];
        $total = array_sum($production);
        if ($total < $config['min_day_wh']) {
            // 3. Whole plant produced (almost) nothing
            $alerts['zero-' . $checkDate] = sprintf(
                'Zero day detected: total production on %s was only %.0f Wh (threshold: %d Wh).',
                $checkDate, $total, $config['min_day_wh']
            );
        } else {
            // 4. Plant OK, but a single inverter produced nothing
            foreach ($production as $inverter => $wh) {
                if ($wh <= 0) {
                    $alerts['inv' . $inverter . '-' . $checkDate] = sprintf(
                        'Inverter "%s" produced 0 Wh on %s while the plant total was %.1f kWh - possible inverter fault.',
                        inverterName($config['data_dir'], $inverter), $checkDate, $total / 1000
                    );
                }
            }
        }
    }
}

// Deduplicate: only send alerts we have not sent before
$state = file_exists($config['state_file'])
    ? (json_decode(file_get_contents($config['state_file']), true) ?: [])
    : [];

$log = [];
foreach ($alerts as $key => $message) {
    if (isset($state[$key])) {
        $log[] = "Already alerted, skipping: $message";
        continue;
    }
    $results = sendAlert($config, 'PV Warning', $message);
    $ok     = array_keys(array_filter($results));
    $failed = array_keys(array_filter($results, fn($delivered) => !$delivered));

    if (empty($results)) {
        $log[] = "No notification channel configured (set notify_email and/or "
               . "whatsapp_phone + whatsapp_apikey): $message";
    } elseif (!empty($ok)) {
        // Delivered on at least one channel - record it so we do not repeat.
        $state[$key] = date('c');
        $log[] = 'Alert sent via ' . implode(', ', $ok)
               . (empty($failed) ? '' : ' (FAILED: ' . implode(', ', $failed) . ')')
               . ": $message";
    } else {
        $log[] = 'FAILED to send alert via ' . implode(', ', $failed) . ": $message";
    }
}

// Keep state small: drop entries older than 30 days
$state = array_filter($state, fn($ts) => strtotime($ts) > strtotime('-30 days'));
file_put_contents($config['state_file'], json_encode($state, JSON_PRETTY_PRINT));

if (empty($alerts)) {
    $log[] = "OK: production on $checkDate looks normal.";
}
echo implode("\n", $log) . "\n";
