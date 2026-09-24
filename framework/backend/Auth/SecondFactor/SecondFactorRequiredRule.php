<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts who must use a second factor: nobody, administrators, or everybody (HIL-494).
 */
final class SecondFactorRequiredRule implements SettingValueRuleInterface
{
    /** Refusal text for a value outside the three. */
    private const string REFUSAL = 'Value must be one of: none, admins, everyone';

    /**
     * Checks that the value is one of the three required values.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        return in_array($value, SecondFactorSettings::REQUIRED_VALUES, true) ? null : self::REFUSAL;
    }
}
