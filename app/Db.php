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
        $pdo->prepare('SET time_zone = ?')->execute([date('P')]);

        return self::$pdo = $pdo;
    }

    /**
     * Create the schema. Safe to run repeatedly - every statement is
     * IF NOT EXISTS, so setup.php can be re-run without dropping anything.
     */
    public static function migrate(): array
    {
        $statements = [
            'users' => "
                CREATE TABLE IF NOT EXISTS users (
                    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    name       VARCHAR(100) NOT NULL,
                    phone      VARCHAR(20)  NOT NULL,
                    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
                    created_at DATETIME     NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_users_phone (phone)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            // Tokens are stored as a SHA-256 hash: a database leak then yields
            // nothing usable, since the plaintext only ever exists in the
            // WhatsApp message we sent.
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
        ];

        $created = [];
        foreach ($statements as $table => $sql) {
            self::conn()->exec($sql);
            $created[] = $table;
        }

        // Not every transport addresses people by phone number - some use an
        // email or an account handle. Added separately so existing
        // installations pick it up without a manual migration.
        if (!self::hasColumn('users', 'address')) {
            self::conn()->exec('ALTER TABLE users ADD COLUMN address VARCHAR(190) NULL AFTER phone');
            $created[] = 'users.address';
        }

        // Users reached by address have no phone number. It has to be NULL
        // rather than '': the unique index treats every empty string as the
        // same value, so a second address-only user could not be stored, and
        // an empty lookup would match the first one.
        self::conn()->exec('ALTER TABLE users MODIFY phone VARCHAR(20) NULL');
        self::conn()->exec("UPDATE users SET phone = NULL WHERE phone = ''");

        return $created;
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
