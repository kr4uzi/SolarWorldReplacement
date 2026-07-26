<?php
declare(strict_types=1);

/**
 * Scheduled job. CLI only, intended to run every 15 minutes:
 *
 *     *\/15 * * * * php /path/to/pv/job.php
 *
 * It acts on two rules, both anchored at 12:15 local time:
 *
 *   1. Beginning of the month - send a report on the month that just ended.
 *   2. Every day - if the plant has produced nothing by 12:15, raise an alert.
 *
 * Everything else is a no-op, so 94 of the 96 daily runs do nothing but check
 * the clock. The frequent cadence buys resilience rather than freshness: if
 * the host is down or the network is out at 12:15, a later run still delivers,
 * because what has already been sent is recorded in job_runs rather than
 * inferred from the current time.
 *
 * Delivery is tracked per user, so someone unreachable is retried on the next
 * run without re-sending to everyone who already received the message.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("job.php is a command line tool.\n");
}

require __DIR__ . '/app/bootstrap.php';

use PV\Auth;
use PV\Data;
use PV\Db;
use PV\Env;
use PV\Messages;
use PV\WhatsApp;

$verbose = in_array('-v', $_SERVER['argv'], true) || in_array('--verbose', $_SERVER['argv'], true);
$dryRun  = in_array('--dry-run', $_SERVER['argv'], true);
$log     = [];

function say(string $line): void
{
    global $log;
    $log[] = $line;
}

try {
    Db::conn();
} catch (Throwable $e) {
    fwrite(STDERR, "Database unavailable: {$e->getMessage()}\n");
    exit(1);
}

if (!Db::isInstalled()) {
    fwrite(STDERR, "Schema is missing. Run: php setup.php init\n");
    exit(1);
}

/** Has this exact message already gone to this user? */
function alreadySent(string $key): bool
{
    $statement = Db::conn()->prepare('SELECT 1 FROM job_runs WHERE job_key = ? LIMIT 1');
    $statement->execute([$key]);

    return $statement->fetchColumn() !== false;
}

function markSent(string $key): void
{
    $statement = Db::conn()->prepare(
        'INSERT INTO job_runs (job_key, ran_at) VALUES (?, NOW())
         ON DUPLICATE KEY UPDATE ran_at = VALUES(ran_at)'
    );
    $statement->execute([$key]);
}

/**
 * Send one message to every active user that has not had it yet.
 * These are business-initiated, so they go out as an approved template.
 */
function deliver(string $baseKey, string $message): void
{
    global $dryRun;

    $users = Auth::activeUsers();
    if ($users === []) {
        say("No users configured - nothing to send. Add one: php setup.php \"Name\" +49...");
        return;
    }

    foreach ($users as $user) {
        $key = $baseKey . '#' . $user['id'];
        if (alreadySent($key)) {
            continue;
        }

        if ($dryRun) {
            say("[dry-run] would send {$baseKey} to +{$user['phone']}");
            continue;
        }

        $result = WhatsApp::sendTemplate($user['phone'], $message);
        if ($result['ok']) {
            markSent($key);
            say("sent {$baseKey} to +{$user['phone']}");
        } else {
            say("FAILED {$baseKey} to +{$user['phone']} (HTTP {$result['status']}): {$result['body']}");
        }
    }
}

// --- Is it time yet? --------------------------------------------------------

$triggerTime = (string)Env::get('JOB_TRIGGER_TIME', '12:15');
$triggerTs   = strtotime('today ' . $triggerTime);
if ($triggerTs === false) {
    fwrite(STDERR, "Invalid JOB_TRIGGER_TIME '{$triggerTime}', expected HH:MM\n");
    exit(1);
}

if (time() < $triggerTs) {
    if ($verbose) {
        echo "Before {$triggerTime}, nothing to do.\n";
    }
    exit(0);
}

// --- 1. Monthly report ------------------------------------------------------

// Normally this fires on the 1st. The window exists so a host that was down
// on the 1st still delivers the report a day or two later instead of losing
// the month entirely - the job_runs key stops it going out twice.
$catchUpDays = max(1, (int)Env::get('JOB_MONTHLY_CATCHUP_DAYS', 3));

if ((int)date('j') <= $catchUpDays) {
    $firstOfLastMonth = strtotime('first day of last month');
    $month = (int)date('n', $firstOfLastMonth);
    $year  = (int)date('Y', $firstOfLastMonth);

    deliver(sprintf('summary-%04d-%02d', $year, $month), Messages::monthlySummary($month, $year));
}

// --- 2. No production today -------------------------------------------------

$today      = Data::today();
$threshold  = (float)Env::get('PV_MIN_MIDDAY_WH', 100);
$maxAgeMin  = (float)Env::get('PV_MAX_DATA_AGE_MINUTES', 60);
$ageMinutes = $today['ts'] > 0 ? (time() - $today['ts']) / 60 : INF;
$dateKey    = date('Y-m-d');

if ($ageMinutes > $maxAgeMin) {
    // With no fresh readings we cannot say whether the plant is producing, so
    // report the upload as the fault rather than blaming the panels.
    $newest = $today['ts'] > 0 ? $today['ts'] : Data::newestDay();
    deliver('offline-' . $dateKey, Messages::loggerOffline($newest, $ageMinutes / 60));
} elseif (array_sum($today['wh']) < $threshold) {
    deliver('zeroday-' . $dateKey, Messages::noProduction($today, $threshold));
} elseif ($verbose) {
    say('Production OK: ' . Messages::kwh(array_sum($today['wh'])) . ' by ' . date('H:i'));
}

// --- Housekeeping -----------------------------------------------------------

$purged = Auth::purgeExpiredTokens();
if ($purged > 0 && $verbose) {
    say("purged {$purged} expired login token(s)");
}

if ($log !== [] && ($verbose || array_filter($log, static fn($l) => str_contains($l, 'FAILED')))) {
    echo implode("\n", $log) . "\n";
}
