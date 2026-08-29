<?php

declare(strict_types=1);

namespace Hilos\Utils;

use Hilos\Log\LogLineReader;

/**
 * LogLevel - one step of the severity scale a log line carries, and the scale's own order.
 *
 * Two questions live here that used to have no single home: what the four level names are,
 * and which of them outranks which. The names were already spelled out as {@see Logger}
 * `LEVEL_*` constants and are still read from there - parsing a written log matches those
 * prefixes ({@see LogLineReader}) - so those constants now take their values from
 * these cases instead of a second list being minted beside them. The order is the new part:
 * it is what turns "write from WARNING and worse" into a comparison instead of an enumeration.
 *
 * The scale has no "write nothing" step. {@see LogLevel::Error} is the top, because an
 * installation silent about its own errors is indistinguishable from a dead one.
 */
enum LogLevel: string
{
    /** Diagnostics for whoever is debugging the installation right now; the noisiest step. */
    case Debug = 'DEBUG';

    /** The ordinary running commentary of a process: what it started, what it finished. */
    case Info = 'INFO';

    /** A recoverable but noteworthy condition - the run goes on, someone should know. */
    case Warning = 'WARNING';

    /** A failure. Written whatever the threshold is set to, being the top of the scale. */
    case Error = 'ERROR';

    /**
     * Resolves a level name into a case, or reports that it is not one.
     *
     * Unrecognized input answers with null rather than a throw on purpose: the name arrives
     * from an environment variable, from a settings row an administrator typed into, and from
     * a message another process sent - three places where a wrong value is an ordinary event
     * with a documented answer (fall back to the default and complain once), not an
     * exceptional one.
     *
     * @param string $name Level name as written in env, in settings, or on the wire, e.g. `WARNING`
     * @return ?self Matching case, or null when the name is not a level
     */
    public static function fromName(string $name): ?self
    {
        return self::tryFrom($name);
    }

    /**
     * Rank of this level on the scale, low to high.
     *
     * Ranks are only ever compared against one another - never stored, sent, or shown - so the
     * absolute numbers carry no meaning beyond the ordering they impose.
     *
     * @return int Position on the DEBUG < INFO < WARNING < ERROR scale, counted from 0
     */
    public function severity(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Warning => 2,
            self::Error => 3,
        };
    }
}
