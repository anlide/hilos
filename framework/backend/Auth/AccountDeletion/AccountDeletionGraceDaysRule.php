<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts the grace period of account deletion: a whole number of days from 1 to 365 (HIL-302).
 *
 * The floor is what the grace period is for - a person who asked in a moment of anger, or
 * somebody else at an unattended session, must have at least a day to call it off.
 */
final class AccountDeletionGraceDaysRule implements SettingValueRuleInterface
{
    /** Refusal text for a value that is not a whole number within the bounds. */
    private const string REFUSAL = 'The grace period must be between ' . AccountDeletionSettings::MIN_GRACE_DAYS
        . ' and ' . AccountDeletionSettings::MAX_GRACE_DAYS . ' days';

    /**
     * Checks that the value is a whole number of days within the bounds.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        if (is_int($value)) {
            $days = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $days = (int)$value;
        } else {
            return self::REFUSAL;
        }

        return $days < AccountDeletionSettings::MIN_GRACE_DAYS || $days > AccountDeletionSettings::MAX_GRACE_DAYS
            ? self::REFUSAL
            : null;
    }
}
