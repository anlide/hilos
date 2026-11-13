<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Exception;

use Hilos\Core\Exception\ValidationException;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * The person's erasure part waits: what the owners after it will need is not captured yet (HIL-1941).
 *
 * Thrown by {@see AbstractUserAgent::eraseAccountPersonalPart()} before it deletes anything, either
 * while the person's addresses wait for their capture or while some of the person's notifications
 * still have deliveries no portion names. No person reads the words; the erasure coordinator tells
 * the wait apart by this type and by {@see $notificationIds}.
 * SCAFFOLD: no live caller until the erasure coordinator answers the wait (HIL-1950), live at the switch (HIL-1952).
 */
final class AccountErasureUncapturedException extends ValidationException
{
    /**
     * @param list<int> $notificationIds Notifications whose deliveries wait for their capture, ascending; empty for the addresses
     * @param string $message Words of the wait
     */
    private function __construct(public readonly array $notificationIds, string $message)
    {
        parent::__construct($message);
    }

    /** @return self The wait for the person's addresses to be captured */
    public static function addresses(): self
    {
        return new self([], 'The person\'s erasure part waits for its addresses to be captured.');
    }

    /**
     * @param list<int> $notificationIds Notifications whose deliveries are not captured, each once
     * @return self The wait for those deliveries, naming the notifications in ascending order
     */
    public static function deliveries(array $notificationIds): self
    {
        sort($notificationIds);

        return new self(
            $notificationIds,
            'The person\'s erasure part waits for the deliveries of notifications #' . implode(', #', $notificationIds) . ' to be captured.',
        );
    }
}
