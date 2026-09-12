<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Database\Settings\Validation\NonNegativeIntegerRule;
use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts a whole percentage of the volume no larger than {@see self::MAXIMUM_PERCENT} (HIL-869).
 *
 * The ceiling is what makes this its own rule rather than {@see NonNegativeIntegerRule}, which the
 * numeric log keys beside it use: that one would take 150, a threshold larger than the whole disk,
 * at which the overview says the room has run out and goes on saying it forever, on an empty
 * volume as readily as on a full one.
 *
 * Zero is accepted and means something here, unlike under the keys where it switches an axis off:
 * it is the installation counting the days to a FULL disk rather than to a reserve it keeps. There
 * is no way to turn this off, because a disk always has a floor it cannot go under.
 *
 * It lives beside the log catalog that names it, and not beside the interface it implements: the
 * two rules in the settings namespace are general arithmetic, while this ceiling is a fact about
 * what a share of a volume can mean.
 */
final class LogFreeSpaceThresholdRule implements SettingValueRuleInterface
{
    /** Largest share of the volume an administrator may reserve, in percent. */
    public const int MAXIMUM_PERCENT = 99;

    /** Refusal text shown when the value is not a whole number from 0 to {@see self::MAXIMUM_PERCENT}. */
    private const string REFUSAL = 'Value must be an integer from 0 to ' . self::MAXIMUM_PERCENT;

    /**
     * Checks that the value is an integer (or a digits-only string) within the range.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= self::MAXIMUM_PERCENT ? null : self::REFUSAL;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int)$value <= self::MAXIMUM_PERCENT ? null : self::REFUSAL;
        }

        return self::REFUSAL;
    }
}
