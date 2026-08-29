<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Validation;

use Hilos\Core\Daemon\Cron\CronRule;

/**
 * Accepts an empty string (schedule off) or a cron expression {@see CronRule} can run.
 *
 * Well-formedness is asked of the class that later executes the schedule, so what an admin was
 * allowed to store and what the runtime is able to run are one notion. Counting fields instead
 * accepts "0 3 * * abc", which never fires and shows the admin a schedule that does not exist.
 */
final class CronExpressionRule implements SettingValueRuleInterface
{
    /** Refusal text shown when the value is neither empty nor a runnable cron expression. */
    private const string REFUSAL = 'Value must be an empty string or a five-field cron expression';

    /**
     * Checks that the value is an empty string or an expression the cron rule understands.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return self::REFUSAL;
        }

        if (trim($value) === '') {
            return null;
        }

        return CronRule::isValidExpression($value) ? null : self::REFUSAL;
    }
}
