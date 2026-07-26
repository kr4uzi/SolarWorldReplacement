<?php
declare(strict_types=1);

/**
 * Account management. CLI only.
 *
 *   php setup.php init                      create the database schema
 *   php setup.php "Markus" +4915112345678   add a user (schema is created if needed)
 *   php setup.php add "Markus" +49151...    same thing, explicit
 *   php setup.php list                      show all accounts
 *   php setup.php remove +49151...          delete an account and its tokens
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
use PV\Db;

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
      php setup.php <name> <phone>             add a user
      php setup.php add <name> <phone>         add a user
      php setup.php list                       list users
      php setup.php remove <phone>             delete a user

    TXT;
}

$argv    = $_SERVER['argv'];
$command = $argv[1] ?? '';

try {
    Db::conn();
} catch (Throwable $e) {
    fail("Cannot connect to the database: {$e->getMessage()}\nCheck DB_* in .env.");
}

switch ($command) {
    case '':
    case '-h':
    case '--help':
    case 'help':
        usage();
        break;

    case 'init':
        $tables = Db::migrate();
        echo "Schema ready: " . implode(', ', $tables) . "\n";
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
        printf("%-4s %-24s %-18s %s\n", 'ID', 'NAME', 'PHONE', 'CREATED');
        foreach ($users as $user) {
            printf("%-4d %-24s %-18s %s\n", $user['id'], $user['name'], '+' . $user['phone'], $user['created_at']);
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

        // Creating the schema on first use means a fresh install is one command.
        if (!Db::isInstalled()) {
            Db::migrate();
            echo "Schema created.\n";
        }

        try {
            $user = Auth::addUser($name, $phone);
        } catch (Throwable $e) {
            fail($e->getMessage());
        }

        echo "Added {$user['name']} (+{$user['phone']}).\n";
        echo "They can now message the bot and request a portal link.\n";
        break;
}
