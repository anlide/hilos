<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/** Sweep cannot be disabled: keep codes longer by extending retention instead. */
final class VerificationSweepCronRule implements SettingValueRuleInterface
{
    private const string REFUSAL = 'Value must be a five-field cron expression';

    /**
     * @param mixed $value Value about to be written
     * @return ?string Refusal text or null when the schedule can run
     */
    public static function validate(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' && CronRule::isValidExpression($value)
            ? null
            : self::REFUSAL;
    }
}
