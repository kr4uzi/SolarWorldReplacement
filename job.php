<?php
declare(strict_types=1);

/**
 * Scheduled job. CLI only, intended to run every 15 minutes:
 *
 *     *\/15 * * * * php /path/to/pv/job.php
 *
 * What it sends is decided per user, in the portal's notification settings:
 *
 *   - a fault alert when the plant produces nothing, or the logger goes quiet
 *   - the day's figures, for those who want them
 *   - a report on the month that just ended, at the start of a month
 *
 * Each account also carries its own time of day, so the job cannot ask "is it
 * time yet" once for everybody - it asks per user.
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
use PV\Chart;
use PV\Data;
use PV\Db;
use PV\Env;
use PV\Messages;
use PV\Messenger;

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

if (!Db::isCurrent()) {
    fwrite(STDERR, "Schema is out of date (" . implode(', ', array_keys(Db::pending()))
        . "). Run: php setup.php init\n");
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
 * Send one message to one user, unless they have already had it.
 *
 * The job_runs key carries the user id, so a recipient who could not be
 * reached is retried on the next run without re-sending to everybody else.
 */
function deliverTo(array $user, string $baseKey, string $message, ?string $png = null): void
{
    global $dryRun;

    $key = $baseKey . '#' . $user['id'];
    if (alreadySent($key)) {
        return;
    }

    $address = Messenger::addressFor($user);
    if ($address === '') {
        say("skipped {$baseKey} for {$user['name']}: no address yet (invite not opened)");
        return;
    }

    if ($dryRun) {
        say("[dry-run] would send {$baseKey} to {$address} ({$user['name']})"
            . ($png !== null ? ' with a chart (' . strlen($png) . ' bytes)' : ''));
        return;
    }

    $result = $png === null
        ? Messenger::notify($address, $message)
        : Messenger::image($address, $png, $message);
    if ($result['ok']) {
        markSent($key);
        say("sent {$baseKey} to {$address} ({$user['name']})");
    } else {
        say("FAILED {$baseKey} to {$address} (HTTP {$result['status']}): {$result['body']}");
    }
}

// --- Per-user schedule ------------------------------------------------------

/**
 * Has this user's chosen time passed today?
 *
 * Each account carries its own time, so the job cannot ask the question once
 * for everybody. Anything from that moment until midnight counts as "due":
 * what has already been sent is recorded in job_runs, so a late run delivers
 * rather than skipping - which is the whole point of running every 15 minutes.
 */
function isDue(array $user): bool
{
    $due = strtotime('today ' . Auth::notifyTime($user));

    return $due !== false && time() >= $due;
}

$catchUpDays = max(1, (int)Env::get('JOB_MONTHLY_CATCHUP_DAYS', 3));
$threshold   = (float)Env::get('PV_MIN_MIDDAY_WH', 100);
$maxAgeMin   = (float)Env::get('PV_MAX_DATA_AGE_MINUTES', 60);
$dateKey     = date('Y-m-d');

// Read the plant once, not per user.
$today      = Data::today();
$ageMinutes = $today['ts'] > 0 ? (time() - $today['ts']) / 60 : INF;
$todayTotal = array_sum($today['wh']);

$loggerOffline = $ageMinutes > $maxAgeMin;
$noProduction  = !$loggerOffline && $todayTotal < $threshold;

$firstOfLastMonth = strtotime('first day of last month');
$lastMonth        = (int)date('n', $firstOfLastMonth);
$lastMonthYear    = (int)date('Y', $firstOfLastMonth);

// The chart that goes with the monthly report is drawn once, not per recipient.
$monthlyWindow = (int)date('j') <= $catchUpDays;
$monthChart    = $monthlyWindow ? Chart::month($lastMonth, $lastMonthYear) : null;

$users = Auth::activeUsers();
if ($users === []) {
    say('No users configured - nothing to send. Add one: php setup.php invite "Name"');
}

foreach ($users as $user) {
    if (!isDue($user)) {
        continue;
    }

    $settings = Auth::settings($user);

    // 1. Something is wrong with the plant.
    if ($settings['zero']) {
        if ($loggerOffline) {
            // With no fresh readings we cannot say whether the plant is
            // producing, so report the upload as the fault rather than
            // blaming the panels.
            $newest = $today['ts'] > 0 ? $today['ts'] : Data::newestDay();
            deliverTo($user, 'offline-' . $dateKey, Messages::loggerOffline($newest, $ageMinutes / 60));
        } elseif ($noProduction) {
            deliverTo($user, 'zeroday-' . $dateKey, Messages::noProduction($today, $threshold));
        }
    }

    // 2. The day's figures, for those who want them.
    if ($settings['daily']) {
        deliverTo($user, 'daily-' . $dateKey, Messages::today());
    }

    // 3. The month that just ended, with its daily figures as a chart. Both
    //    are rendered once and reused for every recipient.
    if ($settings['monthly'] && $monthlyWindow) {
        deliverTo(
            $user,
            sprintf('summary-%04d-%02d', $lastMonthYear, $lastMonth),
            Messages::monthlySummary($lastMonth, $lastMonthYear),
            $monthChart
        );
    }
}

if ($verbose && !$loggerOffline && !$noProduction) {
    say('Production OK: ' . Messages::kwh($todayTotal) . ' by ' . date('H:i'));
}

// --- Housekeeping -----------------------------------------------------------

$purged = Auth::purgeExpiredTokens();
if ($purged > 0 && $verbose) {
    say("purged {$purged} expired login token(s)");
}

if ($log !== [] && ($verbose || array_filter($log, static fn($l) => str_contains($l, 'FAILED')))) {
    echo implode("\n", $log) . "\n";
}
