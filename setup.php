<?php
declare(strict_types=1);

/**
 * Account management. CLI only.
 *
 *   php setup.php init                      create or update the database schema
 *   php setup.php "Markus" +4915112345678   add a user (schema is created if needed)
 *   php setup.php add "Markus" +49151...    same thing, explicit
 *   php setup.php list                      show all accounts
 *   php setup.php remove +49151...          delete an account and its tokens
 *   php setup.php check                     verify the whole deployment
 *
 * Adding a user sends them a welcome message over the configured transport.
 * Pass --no-message to skip it; it is skipped automatically while that
 * transport is unconfigured.
 *
 * A contact is a phone number, or an address (anything containing @) for
 * transports that do not use phone numbers.
 *
 * A user record is the only thing that grants access: the same row decides
 * who the bot answers and who can hold a portal session. There is no
 * self-registration and no password anywhere in the system.
 */

// Refuse to run over the web. .htaccess denies this file too, but a
// misconfigured vhost should not be the only thing standing between a
// visitor and the account table.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("setup.php is a command line tool.\n");
}

require __DIR__ . '/app/bootstrap.php';

use PV\Auth;
use PV\Data;
use PV\Db;
use PV\Messages;
use PV\Messenger;

/**
 * Bring the schema up to date before anything writes to it.
 *
 * Upgrading by pulling code leaves the old tables in place, so a version that
 * added a column would otherwise fail on the first insert with a bare SQL
 * error. Applying what is missing is idempotent and cheap.
 */
function ensureSchema(): void
{
    $applied = Db::migrate();
    foreach ($applied as $change) {
        echo "Schema: {$change}\n";
    }
}

/** How a user is displayed: their address, or their phone number. */
function contactOf(array $user): string
{
    $address = trim((string)($user['address'] ?? ''));

    return $address !== '' ? $address : '+' . $user['phone'];
}

/** @return never */
function fail(string $message, int $code = 1)
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

function usage(): void
{
    echo <<<TXT
    Usage:
      php setup.php init                       create the database schema
      php setup.php <name> <contact>           add a user (sends a welcome message)
      php setup.php add <name> <contact>       add a user
                    [--no-message]             ... without the welcome message
      php setup.php list                       list users
      php setup.php remove <contact>           delete a user
      php setup.php check                      verify the whole deployment
      php setup.php test <contact> [text]      send one message and show the result

    Telegram:
      php setup.php invite <name>              create a user and print their invite link
      php setup.php telegram-webhook           register this site's webhook with Telegram

    TXT;
}

/**
 * Greet a newly registered user.
 *
 * This is a notification rather than a reply: the person has never written to
 * us. On WhatsApp that means an approved template is mandatory, which makes an
 * unapproved template the likeliest reason for a failure there.
 *
 * A failure is reported but never fatal - the account exists either way, and
 * the user can simply message the bot to get going.
 */
function sendWelcome(array $user, bool $skip): void
{
    if ($skip) {
        echo "Welcome message skipped (--no-message).\n";
        return;
    }

    if (!Messenger::isConfigured()) {
        echo "No welcome message sent: the '" . Messenger::name()
           . "' transport is not configured yet.\n";
        return;
    }

    $result = Messenger::notify(Messenger::addressFor($user), Messages::welcome($user['name']));

    if ($result['ok']) {
        echo "Welcome message sent.\n";
        return;
    }

    echo "Could not send the welcome message (HTTP {$result['status']}): {$result['body']}\n";
    echo "The account works regardless.\n";
    if (Messenger::name() === 'whatsapp') {
        $template = (string)PV\Env::get('META_TEMPLATE_NAME', 'pv_update');
        echo "Check that the template '{$template}' is approved and that META_TOKEN "
           . "is a non-expiring System User token.\n";
    }
}

/**
 * Create a Telegram account and print the link that activates it.
 *
 * Telegram identifies people by numeric chat id, which nobody can look up or
 * type. So the account is created empty and the user's first message - sent by
 * tapping this link - is what supplies it.
 */
function runInvite(string $name): int
{
    if ($name === '') {
        fail('Usage: php setup.php invite <name>');
    }

    ensureSchema();

    [$user, $code] = Auth::createInvite($name);

    echo "Created {$user['name']} (id {$user['id']}), awaiting activation.\n\n";
    echo "  " . PV\Transport\Telegram::inviteLink($code) . "\n\n";
    echo "Send them that link. Opening it starts a chat with the bot and binds\n";
    echo "their account; the code stops working once used.\n";

    return 0;
}

/**
 * Point Telegram at this installation's webhook.
 *
 * Saves hand-rolling the setWebhook call, and passes the secret token that the
 * endpoint checks on every delivery.
 */
function runTelegramWebhook(): int
{
    $secret = (string)PV\Env::get('TELEGRAM_WEBHOOK_SECRET', '');
    if ($secret === '') {
        fail('Set TELEGRAM_WEBHOOK_SECRET in .env first - the endpoint refuses deliveries without it.');
    }

    $url = PV\Router::url('telegram-webhook');
    if (!str_starts_with($url, 'https://')) {
        fail("Telegram only accepts an https webhook. PORTAL_URL currently gives: {$url}");
    }

    echo "Registering {$url}\n\n";

    $result = PV\Transport\Telegram::call('setWebhook', [
        'url'             => $url,
        'secret_token'    => $secret,
        'allowed_updates' => ['message', 'callback_query'],
    ]);

    printf("HTTP %d\n%s\n\n", $result['status'], $result['body']);

    if (!$result['ok']) {
        echo "Not registered - the response above is Telegram's own error.\n";
        return 1;
    }

    echo "Registered. Users can now message the bot.\n";

    return 0;
}

/**
 * Send a single message and report exactly what came back.
 *
 * Exists so a provider's request shape can be settled by trial against the
 * real API: edit the transport settings in .env, run this, read the response,
 * adjust. No user record and no scheduled run required.
 */
function runTest(string $contact, ?string $text): int
{
    if ($contact === '') {
        fail("Usage: php setup.php test <contact> [text]");
    }

    try {
        $transport = Messenger::transport();
    } catch (Throwable $e) {
        fail($e->getMessage());
    }

    if (!$transport->isConfigured()) {
        fail("The '{$transport->name()}' transport is not configured - see php setup.php check");
    }

    $text ??= 'PV Anlage: Testnachricht. Wenn du das liest, funktioniert der Versand.';

    echo "transport: {$transport->name()}\n";
    echo "to:        {$contact}\n\n";

    $result = Messenger::notify($contact, $text);

    printf("HTTP %d\n%s\n\n", $result['status'], $result['body'] === '' ? '(empty response)' : $result['body']);

    if ($result['ok']) {
        echo "Sent. Check the recipient's app.\n";
        return 0;
    }

    echo "Not sent. The response above is the provider's own error - adjust the\n";
    echo "transport settings in .env to match its documentation and run this again.\n";

    return 1;
}

/**
 * Walk the whole deployment and report what is and is not ready.
 * Returns a shell exit code: 0 when nothing is broken.
 */
function runCheck(): int
{
    $problems = 0;
    $warnings = 0;

    $ok   = static function (string $label, string $detail = ''): void {
        printf("  [ ok ] %-26s %s\n", $label, $detail);
    };
    $bad  = static function (string $label, string $detail) use (&$problems): void {
        $problems++;
        printf("  [FAIL] %-26s %s\n", $label, $detail);
    };
    $warn = static function (string $label, string $detail) use (&$warnings): void {
        $warnings++;
        printf("  [warn] %-26s %s\n", $label, $detail);
    };

    echo "\nConfiguration\n";
    $envPath = PV\Env::path();
    file_exists($envPath)
        ? $ok('.env', $envPath)
        : $bad('.env', "not found at {$envPath} - copy .env.example there");

    $tz = (string)PV\Env::get('PV_TIMEZONE', 'Europe/Berlin');
    in_array($tz, DateTimeZone::listIdentifiers(), true)
        ? $ok('timezone', $tz . ' (now ' . date('H:i') . ')')
        : $bad('timezone', "'{$tz}' is not a valid identifier");

    echo "\nDatabase\n";
    try {
        Db::conn();
        $ok('connection', (string)PV\Env::get('DB_NAME', '(via DB_DSN)'));

        if (Db::isInstalled()) {
            $pending = Db::pending();
            $pending === []
                ? $ok('schema', 'up to date')
                : $bad('schema', 'out of date (' . implode(', ', array_keys($pending))
                     . ') - run: php setup.php init');

            $users = Auth::activeUsers();
            $users === []
                ? $warn('users', 'none yet - add one: php setup.php "Name" +49...')
                : $ok('users', count($users) . ' registered');
        } else {
            $bad('schema', "missing - run: php setup.php init");
        }
    } catch (Throwable $e) {
        $bad('connection', $e->getMessage());
    }

    echo "\nLogger data\n";
    $dir = Data::dir();
    if (!is_dir($dir)) {
        $bad('data directory', "{$dir} does not exist");
    } else {
        $ok('data directory', $dir);

        is_readable($dir . 'days.csv')
            ? $ok('days.csv', count(Data::days()) . ' days on record')
            : $bad('days.csv', 'missing - the dashboard and reports need it');

        $today = Data::today();
        if ($today['ts'] === 0) {
            $warn('min_day.js', 'no live readings - fine at night, otherwise check the upload');
        } else {
            $age = (time() - $today['ts']) / 60;
            $age > (float)PV\Env::get('PV_MAX_DATA_AGE_MINUTES', 60)
                ? $warn('min_day.js', sprintf('last reading %s (%.0f min old)', date('H:i', $today['ts']), $age))
                : $ok('min_day.js', 'last reading ' . date('H:i', $today['ts']));
        }

        $config = Data::inverterConfig();
        $names  = implode(', ', $config['names']);
        str_starts_with($names, 'WR 1')
            ? $warn('inverters', "{$config['count']} found, names not resolved ({$names}) - check base_vars.js")
            : $ok('inverters', "{$config['count']}: {$names}");
    }

    echo "\nPortal\n";
    $portal = (string)PV\Env::get('PORTAL_URL', '');
    if ($portal === '') {
        $bad('PORTAL_URL', 'not set - login links cannot be built');
    } elseif (!str_starts_with($portal, 'https://')) {
        $warn('PORTAL_URL', "{$portal} - should be https, the login token travels in the URL");
    } else {
        $ok('PORTAL_URL', $portal);
        $ok('webhook URL', rtrim($portal, '/') . '/webhook');
    }

    echo "\nMessaging\n";
    try {
        $transport = Messenger::transport();
        $transport->isConfigured()
            ? $ok('transport', $transport->name() . ' (ready)')
            : $bad('transport', $transport->name() . ' is selected but not configured');
    } catch (Throwable $e) {
        $bad('transport', $e->getMessage());
        $transport = null;
    }

    if ($transport !== null && $transport->name() === 'telegram') {
        $username = ltrim(trim((string)PV\Env::get('TELEGRAM_BOT_USERNAME', '')), '@');
        $username === ''
            ? $warn('TELEGRAM_BOT_USERNAME', 'not set - invite links cannot be built')
            : $ok('bot', '@' . $username);

        (string)PV\Env::get('TELEGRAM_WEBHOOK_SECRET', '') === ''
            ? $bad('TELEGRAM_WEBHOOK_SECRET', 'not set - the webhook refuses every delivery')
            : $ok('webhook URL', PV\Router::url('telegram-webhook'));

        $pending = Db::conn()->query('SELECT COUNT(*) FROM users WHERE invite_code IS NOT NULL')->fetchColumn();
        if ((int)$pending > 0) {
            $warn('invites', $pending . ' user(s) have not opened their invite link yet');
        }
    }

    if ($transport !== null && $transport->name() === 'http') {
        $url = (string)PV\Env::get('HTTP_TRANSPORT_URL', '');
        $url === ''
            ? $bad('HTTP_TRANSPORT_URL', 'not set - the transport has nowhere to post')
            : $ok('endpoint', $url);
    }

    if ($transport === null || $transport->name() !== 'whatsapp') {
        $rate = (float)PV\Env::get('PV_EUR_PER_KWH', 0);
        $rate > 0
            ? $ok('tariff', $rate . ' per kWh')
            : $warn('tariff', 'PV_EUR_PER_KWH not set - money figures will be omitted');

        printf(
            "\n%s  (%d problem%s, %d warning%s)\n\n",
            $problems === 0 ? 'Ready.' : 'Not ready yet.',
            $problems, $problems === 1 ? '' : 's',
            $warnings, $warnings === 1 ? '' : 's'
        );

        return $problems === 0 ? 0 : 1;
    }

    echo "\nWhatsApp\n";
    foreach ([
        'META_TOKEN'           => 'App > WhatsApp > API Setup (use a System User token)',
        'META_PHONE_NUMBER_ID' => 'App > WhatsApp > API Setup',
        'META_VERIFY_TOKEN'    => 'any string you choose; must match Meta webhook setup',
        'META_APP_SECRET'      => 'App > Settings > Basic - signs every incoming event',
    ] as $key => $where) {
        $value = (string)PV\Env::get($key, '');
        $value === ''
            ? $bad($key, "not set - {$where}")
            : $ok($key, sprintf('set (%d chars, fp %s)', strlen($value), substr(hash('sha256', $value), 0, 8)));
    }
    $ok('template', (string)PV\Env::get('META_TEMPLATE_NAME', 'pv_update')
        . ' / ' . (string)PV\Env::get('META_TEMPLATE_LANG', 'de'));

    $rate = (float)PV\Env::get('PV_EUR_PER_KWH', 0);
    $rate > 0
        ? $ok('tariff', $rate . ' per kWh')
        : $warn('tariff', 'PV_EUR_PER_KWH not set - money figures will be omitted');

    printf(
        "\n%s  (%d problem%s, %d warning%s)\n\n",
        $problems === 0 ? 'Ready.' : 'Not ready yet.',
        $problems, $problems === 1 ? '' : 's',
        $warnings, $warnings === 1 ? '' : 's'
    );

    return $problems === 0 ? 0 : 1;
}

$argv    = $_SERVER['argv'];
$command = $argv[1] ?? '';

// 'check' has to run before anything that could fail hard, since diagnosing a
// broken configuration is precisely its job.
if ($command === 'check') {
    exit(runCheck());
}

if ($command === 'test') {
    exit(runTest($argv[2] ?? '', $argv[3] ?? null));
}

if ($command === 'invite') {
    exit(runInvite($argv[2] ?? ''));
}

if ($command === 'telegram-webhook') {
    exit(runTelegramWebhook());
}

if (in_array($command, ['', '-h', '--help', 'help'], true)) {
    usage();
    exit(0);
}

try {
    Db::conn();
} catch (Throwable $e) {
    fail("Cannot connect to the database: {$e->getMessage()}\nCheck DB_* in .env.\nRun 'php setup.php check' for a full report.");
}

switch ($command) {
    case '':
    case '-h':
    case '--help':
    case 'help':
        usage();
        break;

    case 'init':
        $applied = Db::migrate();
        echo $applied === []
            ? "Schema already up to date.\n"
            : "Schema updated:\n  " . implode("\n  ", $applied) . "\n";
        break;

    case 'list':
        if (!Db::isInstalled()) {
            fail("Schema is missing. Run: php setup.php init");
        }
        $users = Auth::activeUsers();
        if ($users === []) {
            echo "No users yet. Add one: php setup.php \"Name\" +49151...\n";
            break;
        }
        printf("%-4s %-24s %-26s %s\n", 'ID', 'NAME', 'CONTACT', 'CREATED');
        foreach ($users as $user) {
            printf("%-4d %-24s %-26s %s\n", $user['id'], $user['name'], contactOf($user), $user['created_at']);
        }
        break;

    case 'remove':
        $phone = $argv[2] ?? '';
        if ($phone === '') {
            fail("Usage: php setup.php remove <phone>");
        }
        // Tokens are removed with the user by the foreign key's ON DELETE CASCADE.
        echo Auth::removeUser($phone)
            ? "Removed {$phone}.\n"
            : "No user with phone {$phone}.\n";
        break;

    default:
        // 'add name phone', or the shorthand 'name phone'.
        [$name, $phone] = $command === 'add'
            ? [$argv[2] ?? '', $argv[3] ?? '']
            : [$command, $argv[2] ?? ''];

        if ($name === '' || $phone === '') {
            usage();
            fail("Both a name and a phone number are required.");
        }

        // Creating or updating the schema on first use means a fresh install
        // is one command, and an upgraded one does not need a separate step.
        ensureSchema();

        try {
            $user = Auth::addUser($name, $phone);
        } catch (Throwable $e) {
            fail($e->getMessage());
        }

        echo "Added {$user['name']} (" . contactOf($user) . ").\n";
        echo "They can now message the bot and request a portal link.\n";

        sendWelcome($user, in_array('--no-message', $argv, true));
        break;
}
