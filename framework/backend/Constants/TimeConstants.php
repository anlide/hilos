<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * Conversion factors between the time units the framework mixes, and the
 * UTC zone name every process sets itself to.
 *
 * A bare `* 1000` next to a `microtime()` call does not say which of the two
 * conversions it is, and the reader has to reconstruct the unit from the variable
 * name. These constants say it in the expression itself; see
 * docs/agents/code-style/magic-values.md, test UNIT.
 *
 * UTC_TIME_ZONE is not a conversion factor. It is the one spelling of UTC the
 * process clock and the project-zone default share, so neither site writes the
 * name on its own.
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

    /**
     * @var int Microseconds in one millisecond, the factor a stamp in milliseconds needs before a
     *     `DateTimeImmutable` will take it: its finest unit is the microsecond
     *
     * Equal to {@see self::MS_PER_SECOND} today and unrelated to it: one converts inside a second,
     * the other inside a millisecond. Sharing a declaration would claim the two move together.
     */
    public const int US_PER_MILLISECOND = 1000;

    /** @var int Seconds in one minute */
    public const int SECONDS_PER_MINUTE = 60;

    /** @var int Seconds in one hour */
    public const int SECONDS_PER_HOUR = 3600;

    /** @var int Seconds in one day, the unit a retention period is usually stated in */
    public const int SECONDS_PER_DAY = 24 * self::SECONDS_PER_HOUR;

    /** @var string IANA name of the zone every Hilos process sets itself to */
    public const string UTC_TIME_ZONE = 'UTC';

    /** @var string UTC moment with millisecond precision and an explicit Z suffix */
    public const string ISO_UTC_MILLISECONDS_FORMAT = 'Y-m-d\TH:i:s.v\Z';
}
