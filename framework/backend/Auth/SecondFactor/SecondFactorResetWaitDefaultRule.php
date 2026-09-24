<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts the removal wait a person starts on: between the minimum and the maximum in force (HIL-494).
 *
 * A rule sees one value, so the two other bounds are read as they stand now. Moving the whole
 * range therefore takes the writes in an order that keeps it whole - widen the bound first,
 * then move the default - and the refusal names the bounds so the administrator sees which.
 */
final class SecondFactorResetWaitDefaultRule implements SettingValueRuleInterface
{
    /** Refusal text for a value outside the range in force; the minimum and the maximum follow. */
    private const string REFUSAL = 'Value must be a whole number of days from the minimum (%d) to the maximum (%d)';

    /**
     * Checks that the value lies within the hard bounds and between the minimum and the maximum in force.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        $min = SecondFactorSettings::storedDays(
            SecondFactorSettings::RESET_WAIT_MIN_DAYS_KEY,
            SecondFactorSettings::RESET_WAIT_FLOOR_DAYS,
        );
        $max = SecondFactorSettings::storedDays(
            SecondFactorSettings::RESET_WAIT_MAX_DAYS_KEY,
            SecondFactorSettings::RESET_WAIT_CEILING_DAYS,
        );
        $days = SecondFactorSettings::wholeNumber($value);
        if (
            $days === null
            || $days < max($min, SecondFactorSettings::RESET_WAIT_FLOOR_DAYS)
            || $days > min($max, SecondFactorSettings::RESET_WAIT_CEILING_DAYS)
        ) {
            return sprintf(self::REFUSAL, $min, $max);
        }

        return null;
    }
}
