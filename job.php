<?php
declare(strict_types=1);

/**
 * Scheduled job. CLI only, intended to run every 15 minutes:
 *
 *     *\/15 * * * * php /path/to/pv/job.php
 *
 * Flags: -v prints what happened, --dry-run sends nothing, --force sends even
 * what has already gone out today (for trying settings without waiting for
 * tomorrow).
 *
 * Two different things happen here, and they are decided differently.
 *
 * The fault alert - nothing produced, or the logger gone quiet - is about the
 * plant, so it is judged once, at PV_ALERT_TIME, for everybody. Each account
 * only decides whether it wants to hear about it.
 *
 * The reports - the day, the week, the month - are about a person, so each
 * account chooses which ones it gets and at what time of day.
 *
 * Everything else is a no-op, so most of the 96 daily runs do nothing but
 * check the clock. The frequent cadence buys resilience rather than freshness:
 * a message missed because the host was down, or because the tick before the
 * user's time was the last one to run, still goes out on a later run. What has
 * already been sent is recorded in job_runs rather than inferred from the
 * clock, so nothing is sent twice and nothing is lost by being late.
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

// Send again something this user has already had today. For trying settings
// out: the daily report goes once a day by design, so without this the only
// way to see a change take effect is to wait until tomorrow.
$force   = in_array('--force', $_SERVER['argv'], true);
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

// Record that the scheduler fired, before anything else can go wrong.
//
// This answers one question only - "is something actually calling this script"
// - so it is written as early as it can be, rather than at the end of a
// successful run. A job that starts and then exits on the schema check is a
// working cron entry with a fixable problem behind it; reporting that as "has
// never run" sends you looking at the scheduler, which is the wrong place.
//
// Not on a dry run: those are typed by hand, and letting one count would make
// a dead scheduler look alive for the next hour.
if (!$dryRun) {
    markSent('heartbeat');
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
    global $dryRun, $force, $verbose;

    $key = $baseKey . '#' . $user['id'];
    if (alreadySent($key) && !$force) {
        // Silence here reads as "the job ignored me", which is the wrong
        // conclusion to draw while somebody is changing settings and running
        // it again to see what happens.
        if ($verbose || $dryRun) {
            say("skipped {$baseKey} for {$user['name']}: already sent (use --force to send it again)");
        }
        return;
    }

    $channels = PV\Channel::forUser((int)$user['id']);
    if ($channels === []) {
        say("skipped {$baseKey} for {$user['name']}: no channel yet (invite not opened)");
        return;
    }

    if ($dryRun) {
        $route = implode(' then ', array_map(
            static fn($c) => $c['transport'] . ' ' . $c['address'],
            $channels
        ));
        say("[dry-run] would send {$baseKey} to {$user['name']} via {$route}"
            . ($png !== null ? ' with a chart (' . strlen($png) . ' bytes)' : ''));
        return;
    }

    // Walks the user's channels and stops at the first that delivers, so a
    // costly last resort only bills when the ones above it are down.
    $result = Messenger::deliver($user, $message, $png);

    if ($result['ok']) {
        markSent($key);
        $note = $result['tried'] === [] ? '' : ' (after ' . implode('; ', $result['tried']) . ')';
        say("sent {$baseKey} to {$user['name']} via {$result['transport']}{$note}");
    } else {
        say("FAILED {$baseKey} to {$user['name']}: " . implode('; ', $result['tried'] ?: [$result['body']]));
    }
}

/**
 * Has this user's chosen time passed today?
 *
 * Anything from that moment until midnight counts as due: what has already
 * been sent is recorded in job_runs, so a late run delivers rather than
 * skipping - which is the whole point of running often.
 */
function isDue(array $user): bool
{
    $due = strtotime('today ' . Auth::notifyTime($user));

    return $due !== false && time() >= $due;
}

// --- What is owed, and to whom ----------------------------------------------

/**
 * Is the plant in trouble, judged once for everybody?
 *
 * Deliberately not per user. "Nothing produced yet" is a statement about the
 * plant, and PV_MIN_MIDDAY_WH is a midday threshold: asking it at 07:00 would
 * report every winter morning as a fault, and asking it at 20:00 would find
 * yesterday's problem after dark. So the check has one time of its own, and
 * the per-user setting decides only whether somebody hears about it.
 */
function plantAlert(array $today, float $threshold, float $ageMinutes, float $maxAgeMinutes): ?array
{
    if ($ageMinutes > $maxAgeMinutes) {
        // With no fresh readings we cannot say whether the plant is producing,
        // so report the upload as the fault rather than blaming the panels.
        $newest = $today['ts'] > 0 ? $today['ts'] : Data::newestDay();

        return ['offline', Messages::loggerOffline($newest, $ageMinutes / 60)];
    }

    if (array_sum($today['wh']) < $threshold) {
        return ['zeroday', Messages::noProduction($today, $threshold)];
    }

    return null;
}

/**
 * The reports a user is owed, as [setting, job key, text, chart].
 *
 * Daily, weekly and monthly are the same thing over different periods, so they
 * are described rather than special-cased. The period is part of the job key,
 * which is what makes "send this once" mean once per day, per week, or per
 * month without any further bookkeeping.
 *
 * A report is owed from the user's chosen time until it has actually been
 * sent - not only on the run that first crosses that time. That is what
 * carries a message over a missed tick, a failed delivery or a host that was
 * down all afternoon, and it is why nothing here consults the clock beyond
 * asking whether the time has passed.
 */
function reportsDue(): array
{
    $due = [];

    $due[] = ['daily', 'daily-' . date('Y-m-d'), static fn() => Messages::today(), null];

    // Sunday closes the week, so the report covers the seven days ending today.
    if ((int)date('N') === 7) {
        $due[] = [
            'weekly',
            'weekly-' . date('o-\WW'),
            static fn() => Messages::lastDays(7),
            static fn() => Chart::lastDays(7),
        ];
    }

    // The month that just ended. Owed for the whole of the new month rather
    // than its first days: job_runs records it once it has gone out, so there
    // is nothing to bound, and a window would only decide how long an outage
    // may last before the report is lost.
    $first = strtotime('first day of last month');
    $month = (int)date('n', $first);
    $year  = (int)date('Y', $first);

    $due[] = [
        'monthly',
        sprintf('summary-%04d-%02d', $year, $month),
        static fn() => Messages::monthlySummary($month, $year),
        static fn() => Chart::month($month, $year),
    ];

    return $due;
}

// --- The run ----------------------------------------------------------------

$threshold = (float)Env::get('PV_MIN_MIDDAY_WH', 100);
$maxAgeMin = (float)Env::get('PV_MAX_DATA_AGE_MINUTES', 60);
$alertTime = (string)Env::get('PV_ALERT_TIME', '12:00');
$dateKey   = date('Y-m-d');

// Read the plant once, not per user.
$today      = Data::today();
$ageMinutes = $today['ts'] > 0 ? (time() - $today['ts']) / 60 : INF;

$alert = strtotime('today ' . $alertTime) <= time()
    ? plantAlert($today, $threshold, $ageMinutes, $maxAgeMin)
    : null;

$reports = reportsDue();
$charts  = [];   // drawn at most once per run, on the first user who is owed one

$users = Auth::activeUsers();
if ($users === []) {
    say('No users configured - nothing to send. Add one: php setup.php invite "Name"');
}

foreach ($users as $user) {
    $settings = Auth::settings($user);

    // 1. Something is wrong with the plant. Judged plant-wide, delivered to
    //    whoever asked to hear about it.
    if ($alert !== null && $settings['zero']) {
        deliverTo($user, $alert[0] . '-' . $dateKey, $alert[1]);
    }

    // 2. The reports, once this user's own time has passed.
    if (!isDue($user)) {
        continue;
    }

    foreach ($reports as [$setting, $key, $text, $chart]) {
        if (!$settings[$setting]) {
            continue;
        }

        if ($chart !== null && !array_key_exists($key, $charts)) {
            $charts[$key] = $chart();
        }

        deliverTo($user, $key, $text(), $charts[$key] ?? null);
    }
}

if ($verbose) {
    say(strtotime('today ' . $alertTime) > time()
        ? 'Plant check is not due until ' . $alertTime . '.'
        : ($alert === null
            ? 'Production OK: ' . Messages::kwh(array_sum($today['wh'])) . ' by ' . date('H:i')
            : 'Plant fault: ' . $alert[0]));
}

// --- Housekeeping -----------------------------------------------------------

$purged = Auth::purgeExpiredTokens();
if ($purged > 0 && $verbose) {
    say("purged {$purged} expired login token(s)");
}

if ($log !== [] && ($verbose || array_filter($log, static fn($l) => str_contains($l, 'FAILED')))) {
    echo implode("\n", $log) . "\n";
}
