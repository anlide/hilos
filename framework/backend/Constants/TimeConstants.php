<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * Conversion factors between the time units the framework mixes.
 *
 * A bare `* 1000` next to a `microtime()` call does not say which of the two
 * conversions it is, and the reader has to reconstruct the unit from the variable
 * name. These constants say it in the expression itself; see
 * docs/agents/code-style/magic-values.md, test UNIT.
 */
final class TimeConstants
{
    /** @var int Milliseconds in one second */
    public const int MS_PER_SECOND = 1000;

    /** @var int Microseconds in one second */
    public const int US_PER_SECOND = 1_000_000;

    /**
     * @var int Nanoseconds in one millisecond, the divisor an `hrtime(true)` span needs
     *
     * Equal to {@see self::US_PER_SECOND} today and unrelated to it: one converts a
     * span of nanoseconds, the other a count of seconds. Sharing a declaration would
     * claim the two move together.
     */
    public const int NS_PER_MILLISECOND = 1_000_000;

    /** @var int Seconds in one minute */
    public const int SECONDS_PER_MINUTE = 60;

    /** @var int Seconds in one hour */
    public const int SECONDS_PER_HOUR = 3600;

    /** @var int Seconds in one day, the unit a retention period is usually stated in */
    public const int SECONDS_PER_DAY = 24 * self::SECONDS_PER_HOUR;
}
