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
 * Each account has one time. When it passes, everything that account is owed
 * is worked out and sent: the fault alert if the plant produced nothing, and
 * whichever of the day, week and month reports it asked for.
 *
 * The alert tests for exactly zero rather than for a threshold, because the
 * fault it is looking for is an inverter that has stopped - and that reports
 * nothing at all. A threshold would need an hour of its own to be meaningful
 * at, which is the only reason this ever needed two schedules.
 *
 * Everything else is a no-op, so most of the 96 daily runs do nothing but
 * check the clock. The frequent cadence buys resilience: a message missed
 * because the host was down, or because the tick before the user's time was
 * the last one to run, still goes out on a later run the same day. What has
 * already been sent is recorded in job_runs rather than inferred from the
 * clock, so nothing is sent twice - and nothing outlives the day it was for.
 *
 * Delivery is tracked per user, so someone unreachable is retried on the next
 * run without re-sending to everyone who already received the message. The
 * record also carries the time the report was due, so changing that time is a
 * new appointment rather than one already kept.
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
 * Is the plant in trouble?
 *
 * One question only: has it produced anything today by the time the reader
 * asked to be told. Not how much - a threshold needs an hour to be meaningful
 * at, and needing an hour is what would force a second, plant-wide schedule.
 *
 * And deliberately not "are the readings fresh". Inverters stop uploading when
 * they stop producing, so after sunset the newest reading is always hours old:
 * a staleness check reports every evening as a fault. The energy accumulated
 * today is the honest measure, and it stays correct all night - a plant that
 * worked has a day's total, whatever time anybody reads it.
 *
 * @return array{0:string,1:string}|null [key, message]
 */
function plantAlert(array $today): ?array
{
    // Judged on the best figure available for today, not on min_day.js alone.
    // Loggers differ in when they roll that file over, and one that has
    // already cleared it for the evening would otherwise read as a plant that
    // produced nothing - the same "no data means no production" mistake this
    // alert has made once already. sumRange() prefers days.csv where the
    // logger has written today's row and falls back to the live figures.
    $range   = Data::sumRange((int)strtotime('today'), (int)strtotime('today 23:59:59'));
    $perUnit = $range['per_inverter'];
    $total   = $range['total'];

    if ($total <= 0) {
        return ['zeroday', Messages::noProduction($today)];
    }

    // One inverter dead among several: the total stays healthy and nothing
    // looks wrong, while that string earns nothing until somebody notices.
    $dead = array_filter($perUnit, static fn($wh) => $wh <= 0);
    if ($dead !== [] && count($dead) < count($perUnit)) {
        return ['inverter-' . implode('-', array_keys($dead)), Messages::inverterDown($dead, $total)];
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

    $due[] = ['daily', 'daily-' . date('Y-m-d'), static fn() => Messages::daily(), null];

    // Sunday closes the week, so the report covers the seven days ending today.
    if ((int)date('N') === 7) {
        $due[] = [
            'weekly',
            'weekly-' . date('o-\WW'),
            static fn() => Messages::lastDays(7),
            static fn() => Chart::lastDays(7),
        ];
    }

    // The month that just ended, on the first of the new one.
    if ((int)date('j') === 1) {
        $first = strtotime('first day of last month');
        $month = (int)date('n', $first);
        $year  = (int)date('Y', $first);

        $due[] = [
            'monthly',
            sprintf('summary-%04d-%02d', $year, $month),
            static fn() => Messages::monthlySummary($month, $year),
            static fn() => Chart::month($month, $year),
        ];
    }

    return $due;
}

// --- The run ----------------------------------------------------------------

$dateKey = date('Y-m-d');

// Read the plant once, not per user.
$today = Data::today();
$alert = plantAlert($today);

$reports = reportsDue();
if ($verbose) {
    $absent = [];
    if ((int)date('N') !== 7) {
        $absent[] = 'weekly is Sundays only';
    }
    if ((int)date('j') !== 1) {
        $absent[] = 'monthly is the 1st only';
    }

    say('Reports due today: ' . implode(', ', array_column($reports, 0))
        . ($absent === [] ? '' : ' (' . implode('; ', $absent) . ')'));
}
$charts  = [];   // drawn at most once per run, on the first user who is owed one

$users = Auth::activeUsers();
if ($users === []) {
    say('No users configured - nothing to send. Add one: php setup.php invite "Name"');
}

foreach ($users as $user) {
    $settings = Auth::settings($user);

    // One time per account decides everything it gets. Every branch below
    // explains itself under -v: a message that does not arrive is otherwise
    // indistinguishable from a job that never considered it, and there are
    // five separate reasons it might not - the switch, the time, the day, an
    // empty account, and having gone already.
    if (!isDue($user)) {
        if ($verbose) {
            say("skipped everything for {$user['name']}: their time "
                . $settings['time'] . ' has not passed yet (now ' . date('H:i') . ')');
        }
        continue;
    }

    // 1. Something is wrong with the plant.
    if ($alert !== null) {
        if ($settings['zero']) {
            deliverTo($user, $alert[0] . '-' . $dateKey . '@' . $settings['time'], $alert[1]);
        } elseif ($verbose) {
            say("skipped the alert for {$user['name']}: Störungsmeldung is switched off");
        }
    }

    // 2. The reports.
    foreach ($reports as [$setting, $key, $text, $chart]) {
        if (!$settings[$setting]) {
            if ($verbose) {
                say("skipped {$key} for {$user['name']}: the {$setting} report is switched off");
            }
            continue;
        }

        if ($chart !== null && !array_key_exists($key, $charts)) {
            $charts[$key] = $chart();
        }

        // The time the user asked for is part of what "already sent" means.
        // Without it the key says "once today", so moving the time forward
        // after a report has gone out does nothing until tomorrow - and from
        // the outside that is indistinguishable from the setting being
        // ignored. With it, changing the time is a different appointment, and
        // an unchanged one still goes exactly once.
        deliverTo($user, $key . '@' . $settings['time'], $text(), $charts[$key] ?? null);
    }
}

if ($verbose) {
    $known = Data::sumRange((int)strtotime('today'), (int)strtotime('today 23:59:59'))['total'];
    say($alert === null
        ? 'Plant OK: ' . Messages::kwh($known) . ' by ' . date('H:i')
        : 'Plant fault: ' . $alert[0]);
}

// --- Housekeeping -----------------------------------------------------------

$purged = Auth::purgeExpiredTokens();
if ($purged > 0 && $verbose) {
    say("purged {$purged} expired login token(s)");
}

if ($log !== [] && ($verbose || array_filter($log, static fn($l) => str_contains($l, 'FAILED')))) {
    echo implode("\n", $log) . "\n";
}
