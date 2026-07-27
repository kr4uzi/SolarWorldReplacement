<?php
declare(strict_types=1);

namespace PV\Transport;

/**
 * sendImage() for transports that cannot carry pictures.
 *
 * The caption goes out on its own. Charts are always an illustration of
 * figures that are in the text anyway, so a provider without image support
 * costs presentation rather than information - which is why the caller does
 * not have to ask whether pictures are possible before sending one.
 */
trait TextOnly
{
    public function sendImage(string $address, string $png, string $caption): array
    {
        return $this->sendNotification($address, $caption);
    }
}
