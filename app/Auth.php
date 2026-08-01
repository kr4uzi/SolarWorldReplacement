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
        $digits = self::normalizePhone($phone);

        // Never match on an empty number. Anything without digits - an email
        // address, a display name - normalises to '', and matching that would
        // hand an unregistered sender somebody else's account.
        if ($digits === '') {
            return null;
        }

        $statement = Db::conn()->prepare(
            'SELECT * FROM users WHERE phone = ? AND is_active = 1 LIMIT 1'
        );
        $statement->execute([$digits]);

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

    /**
     * Register a user whose address is already known, and open their first
     * channel on it.
     *
     * The transport is named by the caller rather than guessed from the shape
     * of the address: the same digits are a chat id to one transport and a
     * phone number to another, and guessing wrong stores an account that
     * nothing can ever reach.
     */
    public static function addUser(string $name, string $transport, string $address): array
    {
        $address = trim($address);
        if ($address === '') {
            throw new \InvalidArgumentException('An address is required');
        }

        if (Channel::at($transport, $address) !== null) {
            throw new \RuntimeException("A user is already reachable at {$transport} {$address}");
        }

        $statement = Db::conn()->prepare(
            'INSERT INTO users (name, is_active, created_at) VALUES (?, 1, NOW())'
        );
        $statement->execute([$name]);

        $user = self::userById((int)Db::conn()->lastInsertId());
        Channel::add((int)$user['id'], $transport, $address);

        return $user;
    }

    /**
     * The account reachable at this address, on any transport.
     *
     * For operators, who type an address without saying which transport it
     * belongs to. Anything acting on an inbound message uses
     * Channel::userAt() instead, which is scoped to the transport it arrived
     * over - a message only proves who the sender is there.
     */
    public static function userByAddress(string $address): ?array
    {
        if (trim($address) === '') {
            return null;
        }

        $statement = Db::conn()->prepare(
            'SELECT u.* FROM users u
             JOIN notification_channels c ON c.user_id = u.id
             WHERE c.address = ? AND u.is_active = 1
             LIMIT 1'
        );
        $statement->execute([trim($address)]);

        return $statement->fetch() ?: null;
    }

    /**
     * Create an account whose address is not known yet.
     *
     * The addresses worth having cannot be typed from memory - a Telegram chat
     * id is a number nobody knows. So the account is created with a channel
     * holding only a single-use invite code; the user taps a link carrying it,
     * and their address is captured from the message that arrives.
     *
     * @return array{0:array,1:string} the user and the invite code
     */
    public static function createInvite(string $name, string $transport): array
    {
        $statement = Db::conn()->prepare(
            'INSERT INTO users (name, phone, address, is_active, created_at)
             VALUES (?, NULL, NULL, 1, NOW())'
        );
        $statement->execute([$name]);

        $user = self::userById((int)Db::conn()->lastInsertId());

        return [$user, Channel::invite((int)$user['id'], $transport)];
    }

    /**
     * Accounts carrying this name.
     *
     * Names are not unique - two people in one household can share one - so
     * this returns all of them and lets the caller decide. It exists so that
     * inviting somebody twice reaches for the account that already exists
     * rather than quietly creating a second one beside it.
     *
     * @return array<int,array>
     */
    public static function usersByName(string $name): array
    {
        $statement = Db::conn()->prepare(
            'SELECT * FROM users WHERE name = ? AND is_active = 1 ORDER BY id'
        );
        $statement->execute([trim($name)]);

        return $statement->fetchAll();
    }

    /**
     * Issue a fresh invite code for an account that already exists.
     *
     * Any previous code stops working, which is the point: a link that went to
     * the wrong person, or into a chat history somebody else can read, is
     * replaced rather than left live alongside its successor.
     */
    public static function reissueInvite(int $userId, string $transport): string
    {
        return Channel::invite($userId, $transport);
    }

    /**
     * Redeem an invite code by binding the address that presented it.
     * The code is cleared, so a forwarded link cannot claim the channel twice.
     */
    public static function bindInvite(string $code, string $transport, string $address): ?array
    {
        return Channel::redeem($code, $transport, $address);
    }

    /** Which messages a user wants, and when. */
    public static function settings(array $user): array
    {
        return [
            'zero'    => (bool)($user['notify_zero'] ?? true),
            'daily'   => (bool)($user['notify_daily'] ?? false),
            'weekly'  => (bool)($user['notify_weekly'] ?? false),
            'monthly' => (bool)($user['notify_monthly'] ?? true),
            'time'    => self::notifyTime($user),
        ];
    }

    /**
     * 'HH:MM'. Every account carries its own; the column has a default, so
     * there is no installation-wide setting to fall back to.
     */
    public static function notifyTime(array $user): string
    {
        $time = trim((string)($user['notify_time'] ?? ''));

        return preg_match('/^(\d{1,2}):(\d{2})/', $time, $m) === 1
            ? sprintf('%02d:%02d', min(23, (int)$m[1]), min(59, (int)$m[2]))
            : '12:15';
    }

    public static function saveSettings(
        int $userId,
        bool $zero,
        bool $daily,
        bool $weekly,
        bool $monthly,
        string $time
    ): void
    {
        // Seconds are optional: <input type="time"> posts 'HH:MM' in most
        // browsers but 'HH:MM:SS' in some, and rejecting the longer form threw
        // the chosen time away, which reads as "it did not save" with nothing
        // to show why. An unusable value keeps whatever is already stored
        // rather than resetting the account to the default.
        $time = preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($time), $m) === 1
            ? sprintf('%02d:%02d:00', min(23, (int)$m[1]), min(59, (int)$m[2]))
            : null;

        $statement = Db::conn()->prepare(
            'UPDATE users SET notify_zero = ?, notify_daily = ?, notify_weekly = ?,
                 notify_monthly = ?, notify_time = COALESCE(?, notify_time)
             WHERE id = ?'
        );
        $statement->execute([(int)$zero, (int)$daily, (int)$weekly, (int)$monthly, $time, $userId]);
    }

    /** The switches that can be flipped one at a time, and their columns. */
    public const NOTIFICATIONS = [
        'zero'    => 'notify_zero',
        'daily'   => 'notify_daily',
        'weekly'  => 'notify_weekly',
        'monthly' => 'notify_monthly',
    ];

    /**
     * Flip one switch, leaving the others and the time alone.
     *
     * Written as a single UPDATE rather than read-modify-write so two taps in
     * quick succession cannot both act on the same stale value and lose one.
     *
     * @return array|null the user as it now stands, or null for an unknown switch
     */
    public static function toggleNotification(int $userId, string $which): ?array
    {
        $column = self::NOTIFICATIONS[$which] ?? null;
        if ($column === null) {
            return null;
        }

        // The column name is interpolated because a placeholder cannot name
        // one - but it comes from the constant above, never from the request.
        $statement = Db::conn()->prepare("UPDATE users SET {$column} = NOT {$column} WHERE id = ?");
        $statement->execute([$userId]);

        return self::userById($userId);
    }

    /**
     * Delete the account reachable at this contact, and everything hanging
     * off it - channels and login tokens go with it, by foreign key.
     *
     * Accepts a channel address, or a phone number from the columns that
     * predate channels.
     */
    public static function removeUser(string $contact): bool
    {
        $user = self::userByAddress($contact) ?? self::userByPhone($contact);
        if ($user === null) {
            return false;
        }

        $statement = Db::conn()->prepare('DELETE FROM users WHERE id = ?');
        $statement->execute([$user['id']]);

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

        // The TTL is interpolated rather than bound: with native prepared
        // statements some MySQL builds reject a placeholder as the INTERVAL
        // quantity, which would fail here and nowhere else. It is cast to int
        // on the line above, so there is nothing to inject.
        $insert = Db::conn()->prepare(
            'INSERT INTO login_tokens (user_id, token_hash, created_at, expires_at)
             VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ' . $ttl . ' MINUTE))'
        );
        $insert->execute([$userId, hash('sha256', $token)]);

        return $token;
    }

    /**
     * Look at a token without spending it.
     *
     * Needed because anything may fetch a link that arrives in a chat: Telegram
     * builds a preview card, link scanners and antivirus proxies follow URLs.
     * If merely fetching the page consumed the token, the link would be dead by
     * the time its owner tapped it - so the page checks with this, and only the
     * confirming POST redeems.
     */
    public static function peekToken(string $token): ?array
    {
        $statement = Db::conn()->prepare('SELECT * FROM login_tokens WHERE token_hash = ? LIMIT 1');
        $statement->execute([hash('sha256', trim($token))]);
        $row = $statement->fetch();

        if ($row === false || strtotime((string)$row['expires_at']) < time()) {
            return null;
        }

        return self::userById((int)$row['user_id']);
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

        // Sessions outlive the browser being closed. Without a lifetime the
        // cookie dies with the tab and PHP collects the data after ~24
        // minutes, so the portal would demand a fresh link several times a
        // day - and every one of those costs a trip through the chat bot.
        $days     = max(1, (int)Env::get('PORTAL_SESSION_DAYS', 30));
        $lifetime = $days * 86400;

        ini_set('session.gc_maxlifetime', (string)$lifetime);

        session_set_cookie_params([
            'lifetime' => $lifetime,
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
