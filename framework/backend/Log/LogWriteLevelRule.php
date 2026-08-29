<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;
use Hilos\Utils\LogLevel;

/**
 * Accepts one of the four level names the write-level scale is made of (HIL-761).
 *
 * The names are asked of {@see LogLevel} itself rather than listed here, so what an
 * administrator is allowed to store and what the logger is able to obey are one notion.
 *
 * An empty string is refused, unlike in the schedule rule beside it: there, empty is the
 * ordinary way to say "this axis is off", while here there is no step of the scale that means
 * "write nothing" - the top of it is ERROR, and an installation silent about its own errors is
 * indistinguishable from a dead one.
 */
final class LogWriteLevelRule implements SettingValueRuleInterface
{
    /** Refusal text shown when the value is not one of the four level names. */
    private const string REFUSAL = 'Value must be one of DEBUG, INFO, WARNING, ERROR';

    /**
     * Checks that the value names a level.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return self::REFUSAL;
        }

        return LogLevel::fromName($value) !== null ? null : self::REFUSAL;
    }
}
