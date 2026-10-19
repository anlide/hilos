<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Validation;

/** Accepts a positive whole number through the shared settings write gate. */
final class PositiveIntegerRule implements SettingValueRuleInterface
{
    /** Refusal for zero, a negative value, or a value that is not a whole number. */
    private const string REFUSAL = 'Value must be a positive integer';

    /**
     * @param mixed $value Value about to be written
     * @return ?string Refusal text, or null for a positive integer or digits-only string
     */
    public static function validate(mixed $value): ?string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return $value > 0 ? null : self::REFUSAL;
        }

        return self::REFUSAL;
    }
}
