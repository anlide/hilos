<?php

declare(strict_types=1);

namespace Hilos\Moderation\Validation;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts a finite number of seconds greater than zero (HIL-1625).
 *
 * Fractions are allowed, because a model request is timed in fractions of a second; NaN and
 * infinity are refused, because neither is a wait a request can be given.
 */
final class ModerationTimeoutRule implements SettingValueRuleInterface
{
    /** Refusal text shown when the value is not a finite number above zero. */
    private const string REFUSAL = 'Value must be a number of seconds above 0';

    /**
     * Checks that the value is a finite positive number or a numeric string of one.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (float)$value;
        }

        if ((is_int($value) || is_float($value)) && is_finite($value) && $value > 0) {
            return null;
        }

        return self::REFUSAL;
    }
}
