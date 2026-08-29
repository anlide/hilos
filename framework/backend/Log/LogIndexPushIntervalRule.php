<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Database\Settings\Validation\NonNegativeIntegerRule;
use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts a whole number of milliseconds no smaller than {@see self::MINIMUM_MS} (HIL-754).
 *
 * The floor is what makes this its own rule rather than {@see NonNegativeIntegerRule}, which the
 * four numeric log keys beside it use: under that one zero is the ordinary way to say "this axis
 * is off", and off here would mean a node sends a frame every time it notices anything — a flooded
 * mesh rather than a disabled feature. Reporting cannot be turned off, so there is nothing zero
 * could honestly mean.
 *
 * It lives beside the log catalog that names it, and not beside the interface it implements: the
 * two rules in the settings namespace are general arithmetic, while this floor is a fact about how
 * often a node may speak.
 */
final class LogIndexPushIntervalRule implements SettingValueRuleInterface
{
    /** Smallest interval an administrator may set, in milliseconds. */
    public const int MINIMUM_MS = 100;

    /** Refusal text shown when the value is not a whole number of at least {@see self::MINIMUM_MS}. */
    private const string REFUSAL = 'Value must be an integer of ' . self::MINIMUM_MS . ' or more';

    /**
     * Checks that the value is an integer (or a digits-only string) of at least the minimum.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value >= self::MINIMUM_MS ? null : self::REFUSAL;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int)$value >= self::MINIMUM_MS ? null : self::REFUSAL;
        }

        return self::REFUSAL;
    }
}
