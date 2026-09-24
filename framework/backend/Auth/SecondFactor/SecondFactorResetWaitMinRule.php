<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts the shortest removal wait a person may choose: at least one day, at most the default (HIL-494).
 *
 * The floor of one day holds for the administrator too: a zero-day wait would make a
 * removal instant, and the delay is the whole of the defence.
 */
final class SecondFactorResetWaitMinRule implements SettingValueRuleInterface
{
    /** Refusal text for a value outside the range; the floor and the default in force follow. */
    private const string REFUSAL = 'Value must be a whole number of days from %d to the default (%d)';

    /**
     * Checks that the value lies between the hard floor and the default in force.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        $default = SecondFactorSettings::storedDays(
            SecondFactorSettings::RESET_WAIT_DEFAULT_DAYS_KEY,
            SecondFactorSettings::RESET_WAIT_CEILING_DAYS,
        );
        $days = SecondFactorSettings::wholeNumber($value);
        if ($days === null || $days < SecondFactorSettings::RESET_WAIT_FLOOR_DAYS || $days > $default) {
            return sprintf(self::REFUSAL, SecondFactorSettings::RESET_WAIT_FLOOR_DAYS, $default);
        }

        return null;
    }
}
