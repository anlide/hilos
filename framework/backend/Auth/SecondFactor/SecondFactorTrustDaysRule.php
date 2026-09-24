<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts how many days a browser may be trusted to skip the second factor: 0 to 365 (HIL-494).
 *
 * Zero is a real value and not an error: it takes the "don't ask again on this device"
 * checkbox off the step altogether.
 */
final class SecondFactorTrustDaysRule implements SettingValueRuleInterface
{
    /** Refusal text for a value outside the range. */
    private const string REFUSAL = 'Value must be a whole number of days from 0 to %d';

    /**
     * Checks that the value is a whole number of days within the range.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        $days = SecondFactorSettings::wholeNumber($value);
        if ($days === null || $days < 0 || $days > SecondFactorSettings::TRUST_DAYS_MAX) {
            return sprintf(self::REFUSAL, SecondFactorSettings::TRUST_DAYS_MAX);
        }

        return null;
    }
}
