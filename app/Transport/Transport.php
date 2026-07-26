<?php
declare(strict_types=1);

namespace PV\Transport;

/**
 * A way of getting messages to a user.
 *
 * The application deliberately knows nothing about which service is carrying
 * its messages. Everything above this line - the scheduled job, the welcome
 * on registration, the portal links - works in terms of "send this text to
 * this address", so swapping provider means adding one class here rather than
 * touching the rest of the system.
 *
 * The distinction the interface does have to expose is initiated-by-us versus
 * replying-to-them, because that is not cosmetic on every provider: WhatsApp
 * charges for the former and requires a pre-approved template, while a reply
 * inside an open conversation is free-form and free. Providers without that
 * split simply treat both the same.
 */
interface Transport
{
    /** Short identifier used in configuration and logs, e.g. 'whatsapp'. */
    public function name(): string;

    /** Whether this transport has everything it needs to send. */
    public function isConfigured(): bool;

    /**
     * How this transport addresses a user: 'phone' or 'email'.
     * setup.php uses it to validate what an operator types.
     */
    public function addressKind(): string;

    /**
     * Reply inside an existing conversation.
     * @return array{ok:bool,status:int,body:string}
     */
    public function sendReply(string $address, string $text): array;

    /**
     * Message the user without them having written first - alerts, reports,
     * the welcome on registration.
     * @return array{ok:bool,status:int,body:string}
     */
    public function sendNotification(string $address, string $text): array;

    /**
     * Offer the menu. Providers with native menu widgets should use them;
     * the default is to send the options as text.
     * @return array{ok:bool,status:int,body:string}
     */
    public function sendMenu(string $address): array;
}
