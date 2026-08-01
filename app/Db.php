<?php
declare(strict_types=1);

namespace PV;

use PDO;

/**
 * MySQL connection and schema.
 *
 * The database holds accounts and scheduling state only - production figures
 * keep coming from the logger's own files via Data. That keeps the reports
 * accurate to the logger and means there is nothing to backfill or re-sync.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = (string)Env::get('DB_DSN', '');
        if ($dsn === '') {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', 'localhost'),
                Env::get('DB_PORT', '3306'),
                Env::must('DB_NAME')
            );
        }

        $pdo = new PDO(
            $dsn,
            (string)Env::get('DB_USER', ''),
            (string)Env::get('DB_PASSWORD', ''),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );

        // Align the session clock with PHP's timezone. Without this, NOW() runs
        // on the server's clock (commonly UTC) while PHP reads the value back in
        // PV_TIMEZONE - which silently expires every login token the moment it
        // is issued, and misdates every row besides.
        //
        // Not a prepared statement: MySQL does not accept a placeholder for a
        // system variable, so binding it here would fail on exactly the servers
        // this is meant to protect. date('P') yields a fixed +HH:MM, and the
        // pattern below refuses anything else, so there is nothing to inject.
        $offset = date('P');
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $offset) === 1) {
            $pdo->exec("SET time_zone = '{$offset}'");
        }

        return self::$pdo = $pdo;
    }


    /**
     * The channels table, in one place: migrate() creates it on a fresh
     * install and pending() offers the same statement to an upgraded one.
     */
    private static function channelTableSql(): string
    {
        return "
                CREATE TABLE IF NOT EXISTS notification_channels (
                    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id     INT UNSIGNED NOT NULL,
                    transport   VARCHAR(32)  NOT NULL,
                    address     VARCHAR(190) NULL,
                    priority    TINYINT      NOT NULL DEFAULT 0,
                    invite_code VARCHAR(64)  NULL,
                    verified_at DATETIME     NULL,
                    created_at  DATETIME     NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_channel_address (transport, address),
                    UNIQUE KEY uniq_channel_invite (invite_code),
                    KEY idx_channel_user (user_id, priority),
                    CONSTRAINT fk_channel_user FOREIGN KEY (user_id)
                        REFERENCES users (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    /**
     * Schema changes that have not been applied yet.
     *
     * An installation upgraded by pulling new code has the old tables but not
     * the newer columns, and nothing about the running code makes that visible
     * until a query fails. Listing the gap lets every entry point either close
     * it or say plainly what is wrong.
     *
     * @return array<string,string> label => the statement that would fix it
     */
    public static function pending(): array
    {
        $pending = [];

        if (!self::hasTable('users')) {
            return ['schema' => 'not installed'];
        }

        if (!self::hasTable('notification_channels')) {
            $pending['notification_channels'] = self::channelTableSql();
        }

        if (!self::hasColumn('users', 'address')) {
            $pending['users.address'] =
                'ALTER TABLE users ADD COLUMN address VARCHAR(190) NULL AFTER phone';
        }

        // Telegram accounts are created before their chat id is known: the
        // invite code is what the user's first message carries back.
        if (!self::hasColumn('users', 'invite_code')) {
            $pending['users.invite_code'] =
                'ALTER TABLE users ADD COLUMN invite_code VARCHAR(64) NULL AFTER address,
                 ADD UNIQUE KEY uniq_users_invite (invite_code)';
        }

        // Per-user notification preferences. Defaults match the behaviour
        // before they existed, so an upgrade changes nothing until someone
        // opens the settings page.
        foreach ([
            'notify_zero'    => 'TINYINT(1) NOT NULL DEFAULT 1',
            'notify_daily'   => 'TINYINT(1) NOT NULL DEFAULT 0',
            'notify_weekly'  => 'TINYINT(1) NOT NULL DEFAULT 0',
            'notify_monthly' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'notify_time'    => "TIME NOT NULL DEFAULT '12:15'",
        ] as $column => $definition) {
            if (!self::hasColumn('users', $column)) {
                $pending["users.{$column}"] = "ALTER TABLE users ADD COLUMN {$column} {$definition}";
            }
        }

        // notify_time used to be nullable, with NULL meaning "fall back to the
        // installation-wide JOB_TRIGGER_TIME". That setting is gone: every
        // account carries its own time, so an invisible global that silently
        // decided when other people's messages went out was one indirection
        // with nothing left to justify it.
        //
        // Rows that never chose a time inherit whatever that setting said, so
        // nobody's schedule moves when the column stops being nullable.
        if (self::hasColumn('users', 'notify_time') && self::columnIsNullable('users', 'notify_time')) {
            $legacy = trim((string)Env::get('JOB_TRIGGER_TIME', '12:15'));
            $legacy = preg_match('/^(\d{1,2}):(\d{2})/', $legacy, $m) === 1
                ? sprintf('%02d:%02d:00', min(23, (int)$m[1]), min(59, (int)$m[2]))
                : '12:15:00';

            $pending['users.notify_time default'] = [
                'UPDATE users SET notify_time = ' . self::conn()->quote($legacy)
                    . ' WHERE notify_time IS NULL',
                "ALTER TABLE users MODIFY notify_time TIME NOT NULL DEFAULT '12:15'",
            ];
        }

        // Users reached by address have no phone number. It has to be NULL
        // rather than '': the unique index treats every empty string as the
        // same value, so a second address-only user could not be stored, and
        // an empty lookup would match the first one.
        if (!self::columnIsNullable('users', 'phone')) {
            $pending['users.phone nullable'] = 'ALTER TABLE users MODIFY phone VARCHAR(20) NULL';
        }

        return $pending;
    }

    public static function isCurrent(): bool
    {
        return self::pending() === [];
    }

    /**
     * Bring the schema up to date. Safe to run repeatedly, and safe to run on
     * an installation created by any earlier version - it only applies what is
     * actually missing.
     *
     * @return array<int,string> what was applied; empty when already current
     */
    public static function migrate(): array
    {
        $applied = [];

        foreach ([
            'users' => "
                CREATE TABLE IF NOT EXISTS users (
                    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    name       VARCHAR(100) NOT NULL,
                    phone      VARCHAR(20)  NULL,
                    address    VARCHAR(190) NULL,
                    invite_code VARCHAR(64) NULL,
                    notify_zero    TINYINT(1) NOT NULL DEFAULT 1,
                    notify_daily   TINYINT(1) NOT NULL DEFAULT 0,
                    notify_weekly  TINYINT(1) NOT NULL DEFAULT 0,
                    notify_monthly TINYINT(1) NOT NULL DEFAULT 1,
                    notify_time    TIME       NOT NULL DEFAULT '12:15',
                    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
                    created_at DATETIME     NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_users_phone (phone),
                    UNIQUE KEY uniq_users_invite (invite_code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // Tokens are stored as a SHA-256 hash: a database leak then yields
            // nothing usable, since the plaintext only ever exists in the
            // message we sent.
            'login_tokens' => "
                CREATE TABLE IF NOT EXISTS login_tokens (
                    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id    INT UNSIGNED NOT NULL,
                    token_hash CHAR(64)     NOT NULL,
                    created_at DATETIME     NOT NULL,
                    expires_at DATETIME     NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_tokens_hash (token_hash),
                    KEY idx_tokens_user (user_id),
                    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id)
                        REFERENCES users (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // Where a user can be reached, one row per way.
            //
            // An address is only meaningful next to the transport that
            // understands it - the same string is a chat id in one row and a
            // phone number in another - so the two are stored together and
            // nothing downstream has to guess which it is holding.
            //
            // 'priority' orders the attempts: 0 is tried first, and a costly
            // channel like SMS sits at the bottom, used only when the ones
            // above it could not deliver.
            //
            // 'address' is NULL until the channel is verified, because the
            // ones worth having cannot be typed in advance: a Telegram chat id
            // arrives when its owner taps the invite link. That is also why
            // the invite code lives here rather than on the user - adding a
            // second channel to an existing account is the same flow as
            // onboarding, not a special case.
            'notification_channels' => self::channelTableSql(),

            // One row per delivered scheduled message. This is what makes a
            // 15-minute cadence safe: the key is checked before sending, so a
            // message goes out once even though the job runs 96 times a day.
            'job_runs' => "
                CREATE TABLE IF NOT EXISTS job_runs (
                    job_key VARCHAR(64) NOT NULL,
                    ran_at  DATETIME    NOT NULL,
                    PRIMARY KEY (job_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ] as $table => $sql) {
            if (self::hasTable($table)) {
                continue;
            }
            self::conn()->exec($sql);
            $applied[] = "created table {$table}";
        }

        // Then whatever a pre-existing installation is missing. A change may
        // need more than one statement - backfilling a column before it can be
        // made NOT NULL, for instance - so a list is allowed here.
        foreach (self::pending() as $label => $statements) {
            foreach ((array)$statements as $statement) {
                self::conn()->exec($statement);
            }
            $applied[] = "added {$label}";
        }

        // Address-only users must hold NULL, not '', for the unique index.
        self::conn()->exec("UPDATE users SET phone = NULL WHERE phone = ''");

        foreach (self::backfillChannels() as $note) {
            $applied[] = $note;
        }

        return $applied;
    }

    /**
     * Move contacts from the users table into notification_channels.
     *
     * Before channels existed a user had one address and one phone number, in
     * columns. Existing accounts must keep working across the upgrade without
     * anybody being re-invited, so their contacts are copied over on the first
     * migration and skipped on every one after.
     *
     * The old columns are left in place, unused. They are the only copy of the
     * data if this version has to be rolled back, and dropping them buys
     * nothing but risk.
     *
     * @return array<int,string> what was moved
     */
    private static function backfillChannels(): array
    {
        // Only ever runs against an empty table: once a single channel exists,
        // the columns are history and re-copying them would resurrect rows the
        // operator has since deleted.
        if ((int)self::conn()->query('SELECT COUNT(*) FROM notification_channels')->fetchColumn() > 0) {
            return [];
        }

        $transport = strtolower(trim((string)Env::get('MESSAGING_TRANSPORT', 'log')));
        $applied   = [];

        // An address was reachable over whatever transport was configured, so
        // that is the transport it belongs to.
        $moved = self::conn()->exec(
            "INSERT INTO notification_channels
                 (user_id, transport, address, priority, verified_at, created_at)
             SELECT id, " . self::conn()->quote($transport) . ", address, 0, created_at, created_at
             FROM users
             WHERE address IS NOT NULL AND address <> ''"
        );
        if ($moved > 0) {
            $applied[] = "moved {$moved} {$transport} address(es) into notification_channels";
        }

        // A pending invite becomes a pending channel: same code, so a link
        // already sent to somebody still works after the upgrade.
        $invited = self::conn()->exec(
            "INSERT INTO notification_channels
                 (user_id, transport, address, priority, invite_code, created_at)
             SELECT id, " . self::conn()->quote($transport) . ", NULL, 0, invite_code, created_at
             FROM users
             WHERE invite_code IS NOT NULL AND invite_code <> ''"
        );
        if ($invited > 0) {
            $applied[] = "moved {$invited} pending invite(s) into notification_channels";
        }

        // Phone numbers are only carried over when the configured transport
        // actually dials them. Under Telegram they identify nobody, and
        // importing them would recreate exactly the unreachable accounts this
        // table exists to prevent - they stay in users.phone, untouched.
        if (self::transportUsesPhone($transport)) {
            $phones = self::conn()->exec(
                "INSERT INTO notification_channels
                     (user_id, transport, address, priority, verified_at, created_at)
                 SELECT id, " . self::conn()->quote($transport) . ", phone, 0, created_at, created_at
                 FROM users
                 WHERE phone IS NOT NULL AND phone <> ''
                   AND (address IS NULL OR address = '')"
            );
            if ($phones > 0) {
                $applied[] = "moved {$phones} phone number(s) into notification_channels";
            }
        }

        return $applied;
    }

    /** Whether a transport addresses people by phone number. */
    private static function transportUsesPhone(string $transport): bool
    {
        $known = Messenger::available()[$transport] ?? null;
        if ($known === null) {
            return false;
        }

        try {
            return (new $known())->addressKind() === 'phone';
        } catch (\Throwable) {
            return false;
        }
    }

    private static function hasTable(string $table): bool
    {
        $statement = self::conn()->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }

    private static function columnIsNullable(string $table, string $column): bool
    {
        $statement = self::conn()->prepare(
            'SELECT is_nullable FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
        );
        $statement->execute([$table, $column]);

        return strtoupper((string)$statement->fetchColumn()) === 'YES';
    }

    /** Portable column check - MySQL has no ADD COLUMN IF NOT EXISTS. */
    private static function hasColumn(string $table, string $column): bool
    {
        $statement = self::conn()->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
        );
        $statement->execute([$table, $column]);

        return $statement->fetchColumn() !== false;
    }

    /** True when the schema has been created. */
    public static function isInstalled(): bool
    {
        try {
            self::conn()->query('SELECT 1 FROM users LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }
}
