<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts how many backup codes one set holds: 1 to 20 (HIL-494).
 *
 * A set already issued keeps its size; the number applies to the sets issued after it.
 */
final class SecondFactorBackupCodesRule implements SettingValueRuleInterface
{
    /** Refusal text for a value outside the range. */
    private const string REFUSAL = 'Value must be a whole number from %d to %d';

    /**
     * Checks that the value is a whole number within the range.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        $count = SecondFactorSettings::wholeNumber($value);
        if (
            $count === null
            || $count < SecondFactorSettings::BACKUP_CODES_MIN
            || $count > SecondFactorSettings::BACKUP_CODES_MAX
        ) {
            return sprintf(self::REFUSAL, SecondFactorSettings::BACKUP_CODES_MIN, SecondFactorSettings::BACKUP_CODES_MAX);
        }

        return null;
    }
}
