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
use PV\Channel;
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

/**
 * How a user is displayed: where they are reached, best channel first.
 *
 * A pending invite says so rather than showing nothing, which read as a
 * broken record rather than one still waiting to be opened.
 */
function contactOf(array $user): string
{
    $channels = Channel::forUser((int)$user['id']);
    if ($channels !== []) {
        $parts = [];
        foreach ($channels as $channel) {
            $parts[] = $channel['transport'] . ' ' . $channel['address'];
        }

        return implode(' → ', $parts);
    }

    return Channel::forUser((int)$user['id'], false) !== []
        ? '(invite pending)'
        : '(no channel)';
}

/** Everything a user can be reached on, including invites not yet opened. */
/**
 * Fold duplicate accounts into one.
 *
 * Duplicates are a leftover from the versions where `invite` always inserted
 * and a phone number could be stored where nothing could reach it. They are
 * quiet: every copy looks correct in a listing, and whichever one is not bound
 * to a chat simply never hears anything.
 *
 * Merging keeps one account and moves everything that can be reached onto it,
 * rather than deleting the spares outright - a duplicate may be the one
 * carrying the channel that actually works.
 *
 * @param array<int,int> $otherIds accounts to fold in and then delete
 */
function mergeUsers(int $keepId, array $otherIds, bool $apply): int
{
    $keep = Auth::userById($keepId);
    if ($keep === null) {
        fail("No account with id {$keepId}.");
    }

    $otherIds = array_values(array_filter($otherIds, static fn($id) => $id !== $keepId));
    if ($otherIds === []) {
        echo "Nothing to merge into {$keep['name']} (id {$keepId}).\n";
        return 0;
    }

    $keepSettings = Auth::settings($keep);
    $moved = $dropped = $tokens = $runs = 0;
    $adoptTime = null;

    echo "Keeping {$keep['name']} (id {$keepId}, " . contactOf($keep) . ")\n";

    foreach ($otherIds as $id) {
        $other = Auth::userById($id);
        if ($other === null) {
            echo "  id {$id}: no such account, skipped\n";
            continue;
        }

        echo "  folding in id {$id} (" . contactOf($other) . ")\n";

        foreach (Channel::forUser($id, false) as $channel) {
            $address = (string)($channel['address'] ?? '');

            // A pending invite is worth nothing once the account it would have
            // activated is gone, and two pending channels on one transport
            // just confuse the next invite.
            if ($address === '') {
                echo "    drop  pending {$channel['transport']} invite (channel {$channel['id']})\n";
                $dropped++;
                continue;
            }

            echo "    move  {$channel['transport']} {$address} (channel {$channel['id']})\n";
            $moved++;
        }

        // A time that was actually chosen beats one that was never set.
        $otherSettings = Auth::settings($other);
        if (trim((string)($keep['notify_time'] ?? '')) === ''
            && trim((string)($other['notify_time'] ?? '')) !== '') {
            $adoptTime = $otherSettings['time'];
        }

        // Only the switches are worth reporting. The time is handled above,
        // and comparing it here would flag every account that simply never
        // chose one against the installation default - a difference nobody
        // made and nobody loses.
        foreach (['zero', 'daily', 'monthly'] as $switch) {
            if ($otherSettings[$switch] !== $keepSettings[$switch]) {
                echo "    note  its notification settings differ and are discarded"
                   . " (keeping id {$keepId}'s)\n";
                break;
            }
        }
    }

    if ($adoptTime !== null) {
        echo "  adopting {$adoptTime} as the notification time - id {$keepId} had none set\n";
    }

    if (!$apply) {
        echo "\nDry run. Nothing has changed - add --apply to do it.\n";
        return 0;
    }

    $pdo = Db::conn();
    $pdo->beginTransaction();

    try {
        foreach ($otherIds as $id) {
            // Channels carrying an address move across; the unique key is on
            // (transport, address), so nothing can collide by moving.
            $move = $pdo->prepare(
                "UPDATE notification_channels SET user_id = ?
                 WHERE user_id = ? AND address IS NOT NULL AND address <> ''"
            );
            $move->execute([$keepId, $id]);

            // Login tokens are single-use and short-lived; a link minted for
            // an account that is about to stop existing should stop working.
            $drop = $pdo->prepare('DELETE FROM login_tokens WHERE user_id = ?');
            $drop->execute([$id]);
            $tokens += $drop->rowCount();

            // Delivery records are keyed by user id. Left behind they are
            // inert, but they would never be cleaned up either.
            $stale = $pdo->prepare('DELETE FROM job_runs WHERE job_key LIKE ?');
            $stale->execute(['%#' . $id]);
            $runs += $stale->rowCount();

            // Takes the remaining pending channels with it, by foreign key.
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }

        if ($adoptTime !== null) {
            Auth::saveSettings(
                $keepId,
                $keepSettings['zero'],
                $keepSettings['daily'],
                $keepSettings['monthly'],
                $adoptTime
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail('Merge failed, nothing was changed: ' . $e->getMessage());
    }

    echo "\nMerged " . count($otherIds) . " account(s) into id {$keepId}:"
       . " {$moved} channel(s) moved, {$dropped} pending invite(s) dropped,"
       . " {$tokens} login token(s) and {$runs} delivery record(s) cleared.\n";

    return 0;
}

/**
 * Find accounts that are the same person and merge them.
 *
 * Grouped by name, compared without case or surrounding space, since that is
 * what the duplicates actually look like - the same name typed twice.
 *
 * The survivor is the account that can already be reached, and the oldest of
 * those, so ids and notification settings stay where they have been.
 */
function runMergeDuplicates(bool $apply): int
{
    if (!Db::isInstalled() || !Db::isCurrent()) {
        fail("Schema is not current. Run: php setup.php init");
    }

    $groups = [];
    foreach (Auth::activeUsers() as $user) {
        $groups[mb_strtolower(trim((string)$user['name']))][] = $user;
    }

    $duplicates = array_filter($groups, static fn($group) => count($group) > 1);

    if ($duplicates === []) {
        echo "No duplicates: every account has a name of its own.\n";
        return 0;
    }

    foreach ($duplicates as $name => $group) {
        echo "\n" . count($group) . " accounts named '{$group[0]['name']}':\n";

        // Reachable first, then oldest. An account with a working channel is
        // the one worth keeping; among equals, the one that has been there
        // longest, so nothing else has to be renumbered.
        usort($group, static function (array $a, array $b): int {
            $aReach = Channel::forUser((int)$a['id']) !== [];
            $bReach = Channel::forUser((int)$b['id']) !== [];

            return $aReach === $bReach
                ? (int)$a['id'] <=> (int)$b['id']
                : ($aReach ? -1 : 1);
        });

        $keep  = array_shift($group);
        $folds = array_map(static fn($u) => (int)$u['id'], $group);

        mergeUsers((int)$keep['id'], $folds, $apply);
    }

    return 0;
}

function runChannels(string $who): int
{
    if (!Db::isInstalled()) {
        fail('Schema is missing. Run: php setup.php init');
    }

    $users = $who === '' ? Auth::activeUsers() : array_filter(
        Auth::activeUsers(),
        static fn($u) => (string)$u['id'] === $who || strcasecmp($u['name'], $who) === 0
    );

    if ($users === []) {
        fail($who === '' ? 'No users yet.' : "No user matches '{$who}'.");
    }

    printf("%-4s %-20s %-4s %-10s %-24s %s\n", 'ID', 'USER', 'PRIO', 'TRANSPORT', 'ADDRESS', 'STATE');
    foreach ($users as $user) {
        foreach (Channel::forUser((int)$user['id'], false) as $channel) {
            printf(
                "%-4s %-20s %-4s %-10s %-24s %s\n",
                $channel['id'],
                mb_substr((string)$user['name'], 0, 20),
                $channel['priority'],
                $channel['transport'],
                (string)($channel['address'] ?? '') === '' ? '-' : $channel['address'],
                $channel['verified_at'] !== null ? 'active' : 'invite pending'
            );
        }
    }

    echo "\nLower priority is tried first; delivery stops at the first channel that works.\n";
    echo "Reorder with: php setup.php channel-priority <channel id> <number>\n";

    return 0;
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

/**
 * '7 minutes', '2 hours', '3 days'.
 *
 * Minutes are kept well past the hour: at a 15-minute cadence "94 minutes"
 * says something "1 hours" does not, and rounding an hour and a half down to
 * a bare "1" hides exactly the gap worth noticing.
 */
function ageInWords(int $seconds): string
{
    foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$unit, $name]) {
        if ($seconds >= $unit * ($unit === 3600 ? 3 : 1)) {
            $count = intdiv($seconds, $unit);

            return $count . ' ' . $name . ($count === 1 ? '' : 's');
        }
    }

    return intdiv($seconds, 60) . ' minutes';
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
      php setup.php channels [name|id]         list where users are reached
      php setup.php channel-priority <id> <n>  reorder a channel (lower goes first)
      php setup.php channel-remove <id>        drop one way of reaching someone
      php setup.php merge-duplicates           fold same-named accounts into one
                    [--apply]                  ... for real; without it, a dry run
      php setup.php merge <keep> <id>...       merge specific accounts
                    [--apply]
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

    // The invite opens a channel on whatever this installation sends over.
    $transport = Messenger::name();

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
            $reachable = false;
            foreach (Channel::forUser((int)$candidate['id']) as $channel) {
                $reachable = $reachable || $channel['transport'] === $transport;
            }
            if (!$reachable) {
                $user = $candidate;
                break;
            }
        }

        if (count($existing) > 1) {
            echo "Note: " . count($existing) . " accounts are named {$name}. Using id {$user['id']}.\n";
            echo "      Run 'php setup.php list' to see them, and 'remove' to clear out any spares.\n\n";
        }

        $address = '';
        foreach (Channel::forUser((int)$user['id']) as $channel) {
            if ($channel['transport'] === $transport) {
                $address = (string)$channel['address'];
                break;
            }
        }

        if ($address !== '') {
            echo "{$user['name']} (id {$user['id']}) is already activated on {$transport} {$address}.\n\n";
            echo "Nothing to do - they can message the bot right now. To move them to a\n";
            echo "different chat, remove and re-invite:\n\n";
            echo "  php setup.php remove {$address}\n";
            echo "  php setup.php invite \"{$user['name']}\"\n\n";
            echo "To add a second, different person of the same name: php setup.php invite \"{$name}\" --new\n";

            return 1;
        }

        $code = Auth::reissueInvite((int)$user['id'], $transport);

        echo "Re-issued the invite for {$user['name']} (id {$user['id']}), still awaiting activation.\n";
        echo "Any earlier link for them has stopped working.\n\n";
        echo "  " . PV\Transport\Telegram::inviteLink($code) . "\n";

        return 0;
    }

    [$user, $code] = Auth::createInvite($name, $transport);

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
            // Advisory only, and never a problem: a human is reading this and
            // knows whether the sun is up. The alert itself does not judge
            // freshness at all - see plantAlert() in job.php.
            $age = (time() - $today['ts']) / 60;
            $age > 60
                ? $warn('min_day.js', sprintf('last reading %s (%.0f min old - normal after sunset)',
                    date('H:i', $today['ts']), $age))
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

        $pending = Db::conn()->query(
            'SELECT COUNT(*) FROM notification_channels WHERE invite_code IS NOT NULL'
        )->fetchColumn();
        if ((int)$pending > 0) {
            $warn('invites', $pending . ' invite link(s) have not been opened yet');
        }
    }

    // Nothing sends unless job.php is actually being run, and a scheduler that
    // was never set up looks exactly like a quiet day from in here.
    //
    // Deliberately not gated on the schema being current: job_runs is old
    // enough to read either way, and "is cron firing" is most worth answering
    // when something else is also wrong. Withholding it there would send
    // somebody to look at their scheduler for a fault that is not in it.
    if (Db::isInstalled()) {
        $lastRun = Db::conn()->query(
            "SELECT ran_at FROM job_runs WHERE job_key = 'heartbeat'"
        )->fetchColumn();

        if ($lastRun === false) {
            $bad('job.php', 'has never run - the scheduled messages need a cron entry:'
                . ' */15 * * * * ' . PHP_BINARY . ' ' . __DIR__ . '/job.php');
        } else {
            $age = time() - (int)strtotime((string)$lastRun);
            $ago = ageInWords($age) . ' ago';

            // Deliberately loose about the interval. Every 15 minutes is what
            // this is built for, but shared hosts often only offer hourly, and
            // that works too - a message stays owed until it has been sent, so
            // a longer interval delivers late rather than not at all. Warning
            // at an hour would cry wolf on exactly those hosts, so the line is
            // drawn where no reasonable schedule can still be running.
            $age > 5400
                ? $warn('job.php', "last ran {$ago} ({$lastRun}) - longer than any"
                    . ' schedule should leave it; check the cron entry is still firing')
                : $ok('job.php', "last ran {$ago}");
        }
    }

    // An account with nowhere to send is silently skipped by the job, which is
    // the correct behaviour and completely invisible - so say it here.
    if (Db::isInstalled() && Db::isCurrent()) {
        $unreachable = Db::conn()->query(
            "SELECT COUNT(*) FROM users u WHERE u.is_active = 1 AND NOT EXISTS (
                 SELECT 1 FROM notification_channels c
                 WHERE c.user_id = u.id AND c.address IS NOT NULL AND c.address <> ''
             )"
        )->fetchColumn();
        if ((int)$unreachable > 0) {
            $warn('channels', $unreachable . ' user(s) have no way to be reached'
                . ' - see: php setup.php channels');
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

    case 'merge-duplicates':
        exit(runMergeDuplicates(in_array('--apply', $argv, true)));

    case 'merge':
        if (!isset($argv[2], $argv[3]) || !ctype_digit((string)$argv[2])) {
            fail('Usage: php setup.php merge <keep id> <id to fold in>... [--apply]');
        }
        $folds = [];
        foreach (array_slice($argv, 3) as $arg) {
            if (ctype_digit((string)$arg)) {
                $folds[] = (int)$arg;
            }
        }
        exit(mergeUsers((int)$argv[2], $folds, in_array('--apply', $argv, true)));

    case 'channels':
        exit(runChannels($argv[2] ?? ''));

    case 'channel-priority':
        if (!isset($argv[2], $argv[3]) || !ctype_digit($argv[2])) {
            fail('Usage: php setup.php channel-priority <channel id> <number>');
        }
        Channel::setPriority((int)$argv[2], (int)$argv[3]);
        echo "Channel {$argv[2]} now has priority {$argv[3]} (lower is tried first).\n";
        break;

    case 'channel-remove':
        if (!isset($argv[2]) || !ctype_digit($argv[2])) {
            fail('Usage: php setup.php channel-remove <channel id>');
        }
        echo Channel::remove((int)$argv[2])
            ? "Channel {$argv[2]} removed.\n"
            : "No channel with id {$argv[2]}.\n";
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
            $user = Auth::addUser($name, Messenger::name(), $phone);
        } catch (Throwable $e) {
            fail($e->getMessage());
        }

        echo "Added {$user['name']} (" . contactOf($user) . ").\n";
        echo "They can now message the bot and request a portal link.\n";

        sendWelcome($user, in_array('--no-message', $argv, true));
        break;
}
