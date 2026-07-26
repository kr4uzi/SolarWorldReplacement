<?php
declare(strict_types=1);

namespace PV;

/**
 * Accounts, one-time login tokens and sessions.
 *
 * Access works like this: a user asks the WhatsApp bot for the portal, we mint
 * a single-use token, and the link we send back is the only way in. There are
 * no passwords, so possession of the phone is the credential - which is why
 * tokens are short-lived, single-use, and stripped from the URL immediately
 * after they are redeemed.
 */
final class Auth
{
    private const SESSION_KEY = 'pv_user_id';

    /** Store phone numbers as bare digits so lookups are unambiguous. */
    public static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    // --- Accounts -----------------------------------------------------------

    public static function userByPhone(string $phone): ?array
    {
        $statement = Db::conn()->prepare(
            'SELECT * FROM users WHERE phone = ? AND is_active = 1 LIMIT 1'
        );
        $statement->execute([self::normalizePhone($phone)]);

        return $statement->fetch() ?: null;
    }

    public static function userById(int $id): ?array
    {
        $statement = Db::conn()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    /** @return array<int,array> every account that should receive messages */
    public static function activeUsers(): array
    {
        return Db::conn()->query('SELECT * FROM users WHERE is_active = 1 ORDER BY id')->fetchAll();
    }

    public static function addUser(string $name, string $phone): array
    {
        $digits = self::normalizePhone($phone);
        if ($digits === '' || strlen($digits) < 8) {
            throw new \InvalidArgumentException("'{$phone}' is not a usable phone number");
        }
        if (self::userByPhone($digits) !== null) {
            throw new \RuntimeException("A user with phone +{$digits} already exists");
        }

        $statement = Db::conn()->prepare(
            'INSERT INTO users (name, phone, is_active, created_at) VALUES (?, ?, 1, NOW())'
        );
        $statement->execute([$name, $digits]);

        return self::userById((int)Db::conn()->lastInsertId());
    }

    public static function removeUser(string $phone): bool
    {
        $statement = Db::conn()->prepare('DELETE FROM users WHERE phone = ?');
        $statement->execute([self::normalizePhone($phone)]);

        return $statement->rowCount() > 0;
    }

    // --- One-time tokens ----------------------------------------------------

    /**
     * Mint a login token and return the plaintext. Only the hash is stored, so
     * this is the one and only moment the usable value exists.
     */
    public static function issueToken(int $userId): string
    {
        // Drop any outstanding tokens for this user so an old link in the chat
        // history stops working as soon as a fresh one is requested.
        $delete = Db::conn()->prepare('DELETE FROM login_tokens WHERE user_id = ?');
        $delete->execute([$userId]);

        $token = bin2hex(random_bytes(32));
        $ttl   = max(1, (int)Env::get('LOGIN_TOKEN_TTL_MINUTES', 15));

        $insert = Db::conn()->prepare(
            'INSERT INTO login_tokens (user_id, token_hash, created_at, expires_at)
             VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE))'
        );
        $insert->execute([$userId, hash('sha256', $token), $ttl]);

        return $token;
    }

    /**
     * Redeem a token. Returns the user on success, null otherwise.
     * The row is deleted either way, so a token never works twice.
     */
    public static function consumeToken(string $token): ?array
    {
        $hash = hash('sha256', trim($token));

        $statement = Db::conn()->prepare('SELECT * FROM login_tokens WHERE token_hash = ? LIMIT 1');
        $statement->execute([$hash]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $delete = Db::conn()->prepare('DELETE FROM login_tokens WHERE id = ?');
        $delete->execute([$row['id']]);

        if (strtotime((string)$row['expires_at']) < time()) {
            return null;
        }

        return self::userById((int)$row['user_id']);
    }

    public static function purgeExpiredTokens(): int
    {
        $statement = Db::conn()->query('DELETE FROM login_tokens WHERE expires_at < NOW()');

        return $statement->rowCount();
    }

    // --- Sessions -----------------------------------------------------------

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => self::isHttps(),
            'path'     => Router::basePath() ?: '/',
        ]);
        session_start();
    }

    public static function login(array $user): void
    {
        self::startSession();
        // New session id on privilege change, so a pre-login cookie cannot be
        // reused to ride the authenticated session.
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int)$user['id'];
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();
    }

    /** The signed-in user, or null. */
    public static function user(): ?array
    {
        self::startSession();
        $id = $_SESSION[self::SESSION_KEY] ?? null;

        return $id === null ? null : self::userById((int)$id);
    }

    public static function isHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
