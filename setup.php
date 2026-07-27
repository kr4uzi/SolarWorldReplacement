<?php
declare(strict_types=1);

/**
 * Account management. CLI only.
 *
 *   php setup.php init                      create or update the database schema
 *   php setup.php invite "Markus"           create a user and print their invite link
 *   php setup.php "Markus" +4915112345678   add a user directly, where the transport
 *                                           addresses people by phone number
 *   php setup.php list                      show all accounts
 *   php setup.php remove +49151...          delete an account and its tokens
 *   php setup.php check                     verify the whole deployment
 *   php setup.php telegram-status           ask Telegram about the webhook
 *   php setup.php login <who>               mint a portal link on the terminal
 *
 * Adding a user sends them a welcome message over the configured transport.
 * Pass --no-message to skip it; it is skipped automatically while that
 * transport is unconfigured.
 *
 * A contact is a phone number, or an address for transports that do not use
 * phone numbers - an email, or a numeric chat id. Which one applies is decided
 * by the configured transport, not guessed from what was typed: on Telegram a
 * phone number identifies nobody, so it is refused rather than stored in an
 * account that could never be reached.
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

/** How a user is displayed: their address, their phone number, or neither yet. */
function contactOf(array $user): string
{
    $address = trim((string)($user['address'] ?? ''));
    if ($address !== '') {
        return $address;
    }

    $phone = trim((string)($user['phone'] ?? ''));
    if ($phone !== '') {
        return '+' . $phone;
    }

    // An invited account that has not been opened yet. Showing a bare '+'
    // here read as a broken record rather than one still waiting.
    return trim((string)($user['invite_code'] ?? '')) !== ''
        ? '(invite pending)'
        : '(no contact)';
}

/**
 * How the configured transport addresses people: 'phone', 'chat_id', 'any'.
 *
 * Answers 'any' when the transport cannot be resolved at all - an unconfigured
 * installation should still be able to add users, and 'check' is the command
 * that complains about configuration.
 */
function addressKind(): string
{
    try {
        return PV\Messenger::transport()->addressKind();
    } catch (Throwable) {
        return 'any';
    }
}

/** @return never */
function fail(string $message, int $code = 1)
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

function usage(): void
{
    // The way to add a user depends on the transport, so lead with whichever
    // one actually works here. Telling somebody to type a phone number into a
    // Telegram installation produces an account that can never be reached.
    $adding = addressKind() === 'chat_id'
        ? <<<TXT
        Adding users (this installation uses chat ids):
          php setup.php invite <name>              create a user and print their invite link
                        [--new]                    ... even if that name already exists
          php setup.php add <name> <chat id>       bind an account to a chat id directly

        TXT
        : <<<TXT
        Adding users:
          php setup.php <name> <contact>           add a user (sends a welcome message)
          php setup.php add <name> <contact>       add a user
                        [--no-message]             ... without the welcome message

        TXT;

    echo <<<TXT
    Usage:
      php setup.php init                       create the database schema

    {$adding}
      php setup.php list                       list users
      php setup.php remove <contact>           delete a user
      php setup.php check                      verify the whole deployment
      php setup.php test <contact> [text]      send one message and show the result
      php setup.php login <name|id|contact>    mint a portal link on the terminal

    Telegram:
      php setup.php telegram-webhook           register this site's webhook with Telegram
      php setup.php telegram-status            ask Telegram what it thinks the webhook is

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
 * Mint a portal link and print it here instead of sending it.
 *
 * Issuing a token is the only menu action that writes to the database, so when
 * the bot answers for month and year but not for Portal, this separates the
 * two possibilities: a database problem, or a messaging one. It also gets an
 * operator into the portal when the bot is down.
 */
function runLogin(string $who): int
{
    if ($who === '') {
        fail('Usage: php setup.php login <name|id|contact>');
    }

    $user = ctype_digit($who) ? Auth::userById((int)$who) : null;
    $user ??= Auth::userByAddress($who) ?? Auth::userByPhone($who);

    if ($user === null) {
        foreach (Auth::activeUsers() as $candidate) {
            if (strcasecmp($candidate['name'], $who) === 0) {
                $user = $candidate;
                break;
            }
        }
    }

    if ($user === null) {
        fail("No user matches '{$who}'. See: php setup.php list");
    }

    try {
        $token = Auth::issueToken((int)$user['id']);
    } catch (Throwable $e) {
        echo "Token creation FAILED: {$e->getMessage()}\n\n";
        echo "This is the same write the Portal menu item performs, so the bot\n";
        echo "cannot issue login links either. Check that the database user may\n";
        echo "INSERT into login_tokens, and that the schema is current:\n";
        echo "  php setup.php init\n";
        return 1;
    }

    $ttl = max(1, (int)PV\Env::get('LOGIN_TOKEN_TTL_MINUTES', 15));

    echo "Portal link for {$user['name']} (valid {$ttl} minutes, single use):\n\n";
    echo '  ' . PV\Router::url('login') . '?t=' . urlencode($token) . "\n\n";
    echo "Token stored as a hash - this is the only time the link exists.\n";

    return 0;
}

/**
 * Create a Telegram account and print the link that activates it.
 *
 * Telegram identifies people by numeric chat id, which nobody can look up or
 * type. So the account is created empty and the user's first message - sent by
 * tapping this link - is what supplies it.
 */
function runInvite(string $name, bool $forceNew = false): int
{
    if ($name === '') {
        fail('Usage: php setup.php invite <name> [--new]');
    }

    ensureSchema();

    // Inviting somebody who is already on the list means "send them another
    // link", not "create a second account for the same person". Getting this
    // wrong is quiet and expensive: the duplicate looks fine in `list`, and
    // whichever row does not end up bound to a chat simply never hears
    // anything, with nothing to show why.
    $existing = $forceNew ? [] : Auth::usersByName($name);

    if ($existing !== []) {
        // Among duplicates, prefer one that is still waiting to be activated:
        // inviting is about getting somebody connected, and an account already
        // bound to a chat is the one case where there is nothing to do.
        $user = $existing[0];
        foreach ($existing as $candidate) {
            if (trim((string)($candidate['address'] ?? '')) === '') {
                $user = $candidate;
                break;
            }
        }

        if (count($existing) > 1) {
            echo "Note: " . count($existing) . " accounts are named {$name}. Using id {$user['id']}.\n";
            echo "      Run 'php setup.php list' to see them, and 'remove' to clear out any spares.\n\n";
        }

        $address = trim((string)($user['address'] ?? ''));

        if ($address !== '') {
            echo "{$user['name']} (id {$user['id']}) is already activated on chat {$address}.\n\n";
            echo "Nothing to do - they can message the bot right now. To move them to a\n";
            echo "different chat, remove and re-invite:\n\n";
            echo "  php setup.php remove {$address}\n";
            echo "  php setup.php invite \"{$user['name']}\"\n\n";
            echo "To add a second, different person of the same name: php setup.php invite \"{$name}\" --new\n";

            return 1;
        }

        $code = Auth::reissueInvite((int)$user['id']);

        echo "Re-issued the invite for {$user['name']} (id {$user['id']}), still awaiting activation.\n";
        echo "Any earlier link for them has stopped working.\n\n";
        echo "  " . PV\Transport\Telegram::inviteLink($code) . "\n";

        return 0;
    }

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
 * Ask Telegram what it currently knows about the webhook.
 *
 * This is the only view of the half of the round trip that happens outside
 * this server: Telegram records why its last delivery failed, which is the
 * difference between "never registered", "registered at the wrong URL" and
 * "registered but rejected".
 */
function runTelegramStatus(): int
{
    $result = PV\Transport\Telegram::call('getWebhookInfo', []);
    if (!$result['ok']) {
        echo "Could not ask Telegram (HTTP {$result['status']}): {$result['body']}\n";
        return 1;
    }

    $info     = json_decode($result['body'], true)['result'] ?? [];
    $expected = PV\Router::url('telegram-webhook');
    $current  = (string)($info['url'] ?? '');

    // Which bot the token actually belongs to. Registering the webhook with
    // one bot's token and then chatting with a different bot looks exactly
    // like a dead webhook, and nothing else here would reveal it.
    $me = PV\Transport\Telegram::call('getMe', []);
    if ($me['ok']) {
        $bot = json_decode($me['body'], true)['result'] ?? [];
        printf("  %-22s @%s (%s)\n", 'token belongs to',
            (string)($bot['username'] ?? '?'), (string)($bot['first_name'] ?? ''));

        $configured = ltrim(trim((string)PV\Env::get('TELEGRAM_BOT_USERNAME', '')), '@');
        if ($configured !== '' && strcasecmp($configured, (string)($bot['username'] ?? '')) !== 0) {
            printf("  %-22s @%s - invite links point at a DIFFERENT bot\n",
                'TELEGRAM_BOT_USERNAME', $configured);
        }
    } else {
        printf("  %-22s token rejected by Telegram (HTTP %d)\n", 'token belongs to', $me['status']);
    }

    printf("  %-22s %s\n", 'expected URL', $expected);
    printf("  %-22s %s\n", 'registered URL', $current === '' ? '(none)' : $current);
    printf("  %-22s %s\n", 'pending updates', (string)($info['pending_update_count'] ?? 0));

    if (isset($info['last_error_message'])) {
        printf("  %-22s %s\n", 'last error',
            date('d.m.y H:i', (int)($info['last_error_date'] ?? 0)) . ' - ' . $info['last_error_message']);
    }
    echo "\n";

    if ($current === '') {
        echo "No webhook registered, so Telegram has nowhere to deliver and the bot\n";
        echo "will never answer. Register it: php setup.php telegram-webhook\n";
        return 1;
    }

    if ($current !== $expected) {
        echo "Telegram is delivering somewhere else. Either PORTAL_URL changed or the\n";
        echo "webhook was registered from a different installation - re-register:\n";
        echo "  php setup.php telegram-webhook\n";
        return 1;
    }

    if (isset($info['last_error_message'])) {
        echo "Telegram is reaching out but the delivery failed - see the error above.\n";
        echo "  403  the secret token does not match TELEGRAM_WEBHOOK_SECRET\n";
        echo "  404  mod_rewrite is not routing /telegram-webhook into System.php\n";
        echo "  500  a PHP error - check the server's error log\n";
        echo "  SSL  the certificate is not one Telegram accepts\n";
        return 1;
    }

    echo (int)($info['pending_update_count'] ?? 0) > 0
        ? "Registered and reachable, with updates queued - they should arrive shortly.\n"
        : "Registered, reachable, and nothing queued. The bot is live.\n";

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

if ($command === 'login') {
    exit(runLogin($argv[2] ?? ''));
}

if ($command === 'invite') {
    exit(runInvite($argv[2] ?? '', in_array('--new', $argv, true)));
}

if ($command === 'telegram-webhook') {
    exit(runTelegramWebhook());
}

if ($command === 'telegram-status') {
    exit(runTelegramStatus());
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
            echo addressKind() === 'chat_id'
                ? "No users yet. Add one: php setup.php invite \"Name\"\n"
                : "No users yet. Add one: php setup.php \"Name\" +49151...\n";
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

        // Under a transport that identifies people by chat id, a phone number
        // creates an account nothing can ever reach: the bot matches incoming
        // chats by id, so that row is never bound, never answered, and the job
        // keeps trying to send to a number that is not an address. Refuse it
        // and name the command that does work.
        $kind = addressKind();
        if ($kind === 'chat_id') {
            if (preg_match('/^-?\d+$/', $phone) !== 1) {
                fail(
                    "This installation talks to people over " . PV\Messenger::name() . ", which identifies\n"
                    . "them by numeric chat id - '{$phone}' cannot be reached and the account would\n"
                    . "never receive anything.\n\n"
                    . "Invite them instead, which creates the account and the link that binds it:\n\n"
                    . "  php setup.php invite \"{$name}\"\n\n"
                    . "A bare chat id is still accepted here, for the case where somebody has\n"
                    . "already messaged the bot and it told them their id."
                );
            }
        }

        if ($existing = Auth::usersByName($name)) {
            echo "Note: an account named {$name} already exists (id {$existing[0]['id']}).\n";
            echo "      Adding another one. To send that person a new link instead:"
                . " php setup.php invite \"{$name}\"\n\n";
        }

        try {
            $user = Auth::addUser($name, $phone, $kind === 'chat_id' ? 'address' : 'auto');
        } catch (Throwable $e) {
            fail($e->getMessage());
        }

        echo "Added {$user['name']} (" . contactOf($user) . ").\n";
        echo "They can now message the bot and request a portal link.\n";

        sendWelcome($user, in_array('--no-message', $argv, true));
        break;
}
