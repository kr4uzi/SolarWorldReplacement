<?php
declare(strict_types=1);

namespace PV;

/**
 * Where a user can be reached.
 *
 * A person has as many channels as they like, ordered by priority: the first
 * is tried first, and something costly like SMS sits at the bottom, used only
 * when everything above it failed to deliver. Delivery walks that list and
 * stops at the first success - see Messenger::deliver().
 *
 * A channel is a transport and an address together, never an address alone.
 * The same string means different things to different transports, so pairing
 * them is what stops a phone number being stored where only chat ids can be
 * reached - an account that looks correct in a listing and never receives
 * anything.
 *
 * The address arrives late. Chat ids cannot be typed in advance, so a channel
 * is created holding only an invite code, and the user's first message is what
 * fills it in. That makes adding a second channel to an existing account the
 * same flow as onboarding rather than a special case.
 */
final class Channel
{
    /**
     * Channels for a user, best first.
     *
     * Unverified ones are left out: a channel with no address yet cannot
     * carry anything, and including it would make delivery look like it had
     * failed rather than never having been possible.
     *
     * @return array<int,array>
     */
    public static function forUser(int $userId, bool $verifiedOnly = true): array
    {
        $sql = 'SELECT * FROM notification_channels WHERE user_id = ?'
             . ($verifiedOnly ? " AND address IS NOT NULL AND address <> ''" : '')
             . ' ORDER BY priority, id';

        $statement = Db::conn()->prepare($sql);
        $statement->execute([$userId]);

        return $statement->fetchAll();
    }

    /**
     * The account reachable at this address on this transport.
     *
     * Scoped by transport on purpose: two transports may legitimately use the
     * same string, and an inbound message only ever proves who the sender is
     * on the transport it arrived over.
     */
    public static function userAt(string $transport, string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $statement = Db::conn()->prepare(
            'SELECT u.* FROM users u
             JOIN notification_channels c ON c.user_id = u.id
             WHERE c.transport = ? AND c.address = ? AND u.is_active = 1
             LIMIT 1'
        );
        $statement->execute([$transport, $address]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    /** A channel already carrying this address, whoever it belongs to. */
    public static function at(string $transport, string $address): ?array
    {
        $statement = Db::conn()->prepare(
            'SELECT * FROM notification_channels WHERE transport = ? AND address = ? LIMIT 1'
        );
        $statement->execute([$transport, trim($address)]);
        $channel = $statement->fetch();

        return $channel === false ? null : $channel;
    }

    /**
     * Add a channel whose address is already known.
     *
     * @throws \RuntimeException when that address is already somebody's
     */
    public static function add(int $userId, string $transport, string $address, ?int $priority = null): array
    {
        $address = trim($address);

        if (self::at($transport, $address) !== null) {
            throw new \RuntimeException("{$transport} address {$address} already belongs to an account");
        }

        $statement = Db::conn()->prepare(
            'INSERT INTO notification_channels
                 (user_id, transport, address, priority, verified_at, created_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        );
        $statement->execute([$userId, $transport, $address, $priority ?? self::nextPriority($userId, $transport)]);

        return self::byId((int)Db::conn()->lastInsertId());
    }

    /**
     * Create a channel that is waiting to be claimed, and return its code.
     *
     * Any earlier pending invite for the same transport is dropped rather than
     * left alongside: two live codes for one channel means a link sent by
     * mistake keeps working after its replacement has gone out.
     */
    public static function invite(int $userId, string $transport, ?int $priority = null): string
    {
        $delete = Db::conn()->prepare(
            "DELETE FROM notification_channels
             WHERE user_id = ? AND transport = ? AND (address IS NULL OR address = '')"
        );
        $delete->execute([$userId, $transport]);

        $code      = bin2hex(random_bytes(12));
        $statement = Db::conn()->prepare(
            'INSERT INTO notification_channels
                 (user_id, transport, address, priority, invite_code, created_at)
             VALUES (?, ?, NULL, ?, ?, NOW())'
        );
        $statement->execute([$userId, $transport, $priority ?? self::nextPriority($userId, $transport), $code]);

        return $code;
    }

    /**
     * Claim a pending channel with the address that presented its code.
     *
     * The code is cleared, so a forwarded link cannot claim the channel twice.
     *
     * @return array|null the user the channel belongs to
     */
    public static function redeem(string $code, string $transport, string $address): ?array
    {
        $code    = trim($code);
        $address = trim($address);
        if ($code === '' || $address === '') {
            return null;
        }

        $statement = Db::conn()->prepare(
            'SELECT * FROM notification_channels WHERE invite_code = ? AND transport = ? LIMIT 1'
        );
        $statement->execute([$code, $transport]);
        $channel = $statement->fetch();

        if ($channel === false) {
            return null;
        }

        // Somebody else already holds this address. Refuse rather than move
        // it: whoever is at that address is who the transport will deliver to,
        // so reassigning it would silently redirect one person's messages.
        $existing = self::at($transport, $address);
        if ($existing !== null && (int)$existing['id'] !== (int)$channel['id']) {
            return null;
        }

        $update = Db::conn()->prepare(
            'UPDATE notification_channels
             SET address = ?, invite_code = NULL, verified_at = NOW()
             WHERE id = ?'
        );
        $update->execute([$address, $channel['id']]);

        return Auth::userById((int)$channel['user_id']);
    }

    public static function byId(int $id): array
    {
        $statement = Db::conn()->prepare('SELECT * FROM notification_channels WHERE id = ? LIMIT 1');
        $statement->execute([$id]);

        return (array)$statement->fetch();
    }

    /** Every channel with an account attached, for listings. */
    public static function all(): array
    {
        return Db::conn()->query(
            'SELECT c.*, u.name FROM notification_channels c
             JOIN users u ON u.id = c.user_id
             ORDER BY u.name, c.priority, c.id'
        )->fetchAll();
    }

    public static function remove(int $channelId): bool
    {
        $statement = Db::conn()->prepare('DELETE FROM notification_channels WHERE id = ?');
        $statement->execute([$channelId]);

        return $statement->rowCount() > 0;
    }

    /** Move a channel up or down the order in which it is tried. */
    public static function setPriority(int $channelId, int $priority): void
    {
        $statement = Db::conn()->prepare('UPDATE notification_channels SET priority = ? WHERE id = ?');
        $statement->execute([$priority, $channelId]);
    }

    /**
     * Where a new channel goes in the order.
     *
     * Behind everything the user already has, so adding one never silently
     * takes over from a channel that was working - except for transports that
     * cost money to use, which start at the back on purpose.
     */
    private static function nextPriority(int $userId, string $transport): int
    {
        if (in_array($transport, self::COSTLY, true)) {
            return 90;
        }

        $statement = Db::conn()->prepare(
            'SELECT COALESCE(MAX(priority), -1) + 1 FROM notification_channels WHERE user_id = ?'
        );
        $statement->execute([$userId]);

        return min(89, (int)$statement->fetchColumn());
    }

    /** Transports billed per message, which belong at the bottom of the list. */
    private const COSTLY = ['sms', 'whatsapp'];
}
