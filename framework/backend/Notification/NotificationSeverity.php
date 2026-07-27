<?php

declare(strict_types=1);

namespace Hilos\Notification;

/**
 * NotificationSeverity - the severity levels a durable notification may carry.
 *
 * Mirrors the `severity` ENUM of the hilos_notification table (HIL-102). The
 * frontend maps a level to an accent/icon; because color alone must not carry
 * meaning, the level is also a stable machine value the UI pairs with text.
 */
final class NotificationSeverity
{
    /** Neutral informational notification (default). */
    public const string INFO = 'info';

    /** A tracked operation completed successfully. */
    public const string SUCCESS = 'success';

    /** Something needs attention but is not an error. */
    public const string WARNING = 'warning';

    /** An operation failed. */
    public const string ERROR = 'error';

    /** All valid severity levels, in ascending order of urgency. */
    public const array ALL = [
        self::INFO,
        self::SUCCESS,
        self::WARNING,
        self::ERROR,
    ];

    /**
     * Whether the given value is a known severity level.
     *
     * @param string $severity Candidate severity value
     * @return bool True when the value is one of the ENUM levels
     */
    public static function isValid(string $severity): bool
    {
        return in_array($severity, self::ALL, true);
    }
}
