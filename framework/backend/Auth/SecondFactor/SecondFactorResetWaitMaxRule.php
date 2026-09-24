<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts the longest removal wait a person may choose: at least the default, at most 365 days (HIL-494).
 *
 * The ceiling is what keeps an account from being made unrecoverable for good.
 */
final class SecondFactorResetWaitMaxRule implements SettingValueRuleInterface
{
    /** Refusal text for a value outside the range; the default in force and the ceiling follow. */
    private const string REFUSAL = 'Value must be a whole number of days from the default (%d) to %d';

    /**
     * Checks that the value lies between the default in force and the hard ceiling.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        $default = SecondFactorSettings::storedDays(
            SecondFactorSettings::RESET_WAIT_DEFAULT_DAYS_KEY,
            SecondFactorSettings::RESET_WAIT_FLOOR_DAYS,
        );
        $days = SecondFactorSettings::wholeNumber($value);
        if ($days === null || $days < $default || $days > SecondFactorSettings::RESET_WAIT_CEILING_DAYS) {
            return sprintf(self::REFUSAL, $default, SecondFactorSettings::RESET_WAIT_CEILING_DAYS);
        }

        return null;
    }
}
