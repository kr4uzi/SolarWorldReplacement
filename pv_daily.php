<?php
/**
 * PV daily job - run once per day at 12:15 local time.
 *
 *   * no power at midday          -> alert
 *   * 1st of a month              -> last month's total
 *   * 1 January                   -> last year's total (plus December's)
 *   * any other day               -> nothing
 *
 * Midday is deliberate: by 12:15 a working plant has banked real energy on
 * any day of the year, so "still at zero" is a reliable fault signal and you
 * hear about it the same day instead of the next morning.
 *
 * These messages are business-initiated, so WhatsApp requires an approved
 * template (see README). Replies you send back to the bot are handled by
 * webhook.php and are free.
 *
 * Cron (note: 12:15 in PV_TIMEZONE, which may differ from the host clock):
 *     15 12 * * * php /path/to/pv/pv_daily.php
 * Or via web-cron once PV_CRON_KEY is set:
 *     https://example.com/pv/pv_daily.php?key=YOUR_SECRET
 *
 * Alerts are deduplicated per recipient, so running more often than daily is
 * safe and a recipient who could not be reached is retried on the next run.
 */

require_once __DIR__ . '/pv_data.php';
require_once __DIR__ . '/pv_messages.php';
require_once __DIR__ . '/pv_whatsapp.php';

pvInitTimezone();

// When called over HTTP, require the shared secret.
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $key = (string)pvSetting('PV_CRON_KEY', '');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Forbidden\n");
    }
}

$stateFile = pvDataDir() . 'pv_state.json';
$state = is_readable($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];

/** Everyone who should receive alerts and reports. */
$recipients = pvSettingList('WHATSAPP_RECIPIENTS');
$log = [];

/**
 * Deliver a message to every recipient who has not received it yet.
 * Recipients are tracked individually so a transient failure for one person
 * does not permanently suppress the message for them, and does not re-send
 * to everyone who already got it.
 */
function pvDeliver(string $key, string $message, array $recipients, array &$state, array &$log): void
{
    if (empty($recipients)) {
        $log[] = "No recipients configured (WHATSAPP_RECIPIENTS) - would have sent: {$message}";
        return;
    }

    $already = $state[$key] ?? [];
    $pending = array_values(array_diff($recipients, array_keys($already)));

    if (empty($pending)) {
        $log[] = "Already delivered to all recipients, skipping: {$key}";
        return;
    }

    foreach ($pending as $recipient) {
        $result = pvWaSendTemplate($recipient, $message);
        if ($result['ok']) {
            $already[$recipient] = date('c');
            $log[] = "Sent {$key} to {$recipient}";
        } else {
            $log[] = "FAILED {$key} to {$recipient} (HTTP {$result['status']}): {$result['body']}";
        }
    }
    $state[$key] = $already;

    // Optional email copy, useful as a fallback while WhatsApp is being set up.
    $email = (string)pvSetting('PV_NOTIFY_EMAIL', '');
    if ($email !== '') {
        $from = (string)pvSetting('PV_FROM_EMAIL', '');
        @mail($email, 'PV Monitor', $message, $from !== '' ? 'From: ' . $from : '');
    }
}

// --- 1. Is the plant producing? ---------------------------------------------

$today      = pvToday();
$threshold  = (float)pvSetting('PV_MIN_MIDDAY_WH', 100);
$maxAgeMin  = (float)pvSetting('PV_MAX_DATA_AGE_MINUTES', 60);
$ageMinutes = $today['ts'] > 0 ? (time() - $today['ts']) / 60 : INF;
$todayTotal = array_sum($today['wh']);
$dateKey    = date('Y-m-d');

if ($ageMinutes > $maxAgeMin) {
    // Nothing fresh to judge production by - the logger itself is the problem.
    $newest = $today['ts'] > 0 ? $today['ts'] : pvNewestDay();
    pvDeliver(
        'stale-' . $dateKey,
        pvMessageStale($newest, $ageMinutes / 60),
        $recipients, $state, $log
    );
} elseif ($todayTotal < $threshold) {
    pvDeliver(
        'nopower-' . $dateKey,
        pvMessageNoPower($today, $threshold),
        $recipients, $state, $log
    );
} else {
    // Plant is alive overall - flag any single inverter sitting at zero.
    foreach ($today['wh'] as $inverter => $wh) {
        if ($wh <= 0) {
            pvDeliver(
                'inv' . $inverter . '-' . $dateKey,
                sprintf(
                    "⚠️ Inverter offline\n   %s produced nothing today, while the plant is at %s.",
                    pvInverterName($inverter),
                    pvFormatKwh($todayTotal)
                ),
                $recipients, $state, $log
            );
        }
    }
    $log[] = 'Production OK: ' . pvFormatKwh($todayTotal) . ' by ' . date('H:i');
}

// --- 2. Period reports ------------------------------------------------------

if ((int)date('j') === 1) {
    $lastMonth     = (int)date('n', strtotime('first day of last month'));
    $lastMonthYear = (int)date('Y', strtotime('first day of last month'));

    pvDeliver(
        sprintf('month-%04d-%02d', $lastMonthYear, $lastMonth),
        pvMessageMonthlyReport($lastMonth, $lastMonthYear),
        $recipients, $state, $log
    );

    // 1 January closes the year as well as December.
    if ((int)date('n') === 1) {
        $lastYear = (int)date('Y') - 1;
        pvDeliver('year-' . $lastYear, pvMessageYearlyReport($lastYear), $recipients, $state, $log);
    }
}

// --- 3. Persist state -------------------------------------------------------

// Drop entries older than 60 days so the file stays small.
foreach ($state as $key => $entries) {
    $newest = 0;
    foreach ((array)$entries as $timestamp) {
        $newest = max($newest, strtotime((string)$timestamp) ?: 0);
    }
    if ($newest > 0 && $newest < strtotime('-60 days')) {
        unset($state[$key]);
    }
}

if (!is_dir(pvDataDir())) {
    @mkdir(pvDataDir(), 0775, true);
}
file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo implode("\n", $log ?: ['Nothing to do.']) . "\n";
