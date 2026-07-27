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

        // Then whatever a pre-existing installation is missing.
        foreach (self::pending() as $label => $statement) {
            self::conn()->exec($statement);
            $applied[] = "added {$label}";
        }

        // Address-only users must hold NULL, not '', for the unique index.
        self::conn()->exec("UPDATE users SET phone = NULL WHERE phone = ''");

        return $applied;
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
