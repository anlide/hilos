<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

/**
 * DeliveryStatus - the lifecycle states of one channel delivery attempt row.
 *
 * Mirrors the `status` ENUM of the hilos_notification_delivery table (HIL-196).
 * A row starts {@see PENDING} when the dispatcher fans a notification to a
 * channel; the channel's delivery agent moves it to {@see SENT} on success or
 * {@see FAILED} once bounded retries are exhausted.
 */
final class DeliveryStatus
{
    /** Queued for the channel agent, not yet delivered. */
    public const string PENDING = 'pending';

    /** Delivered to the channel transport. */
    public const string SENT = 'sent';

    /** Delivery failed after exhausting bounded retries. */
    public const string FAILED = 'failed';

    /** All valid delivery states. */
    public const array ALL = [
        self::PENDING,
        self::SENT,
        self::FAILED,
    ];

    /**
     * Whether the given value is a known delivery status.
     *
     * @param string $status Candidate status value
     * @return bool True when the value is one of the ENUM states
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
