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
}
