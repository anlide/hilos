<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Validation;

/**
 * Accepts a whole number of zero or more, where zero means "this axis is off".
 *
 * A negative number is refused rather than clamped: the write paths serialize an integer setting
 * with a plain int cast, so a clamp would store a value the admin never typed and show it back as
 * if it had been accepted.
 */
final class NonNegativeIntegerRule implements SettingValueRuleInterface
{
    /** Refusal text shown when the value is not a whole number of zero or more. */
    private const string REFUSAL = 'Value must be an integer of 0 or more';

    /**
     * Checks that the value is an integer (or a digits-only string) of zero or more.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value >= 0 ? null : self::REFUSAL;
        }

        if (is_string($value) && ctype_digit($value)) {
            return null;
        }

        return self::REFUSAL;
    }
}
