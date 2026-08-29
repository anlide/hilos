<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Validation;

use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Hilos;

/**
 * The one gate every setting write passes before the value is serialized.
 *
 * Stands in the two write paths of the settings layer — the row that is created and the row that
 * is updated — so a value refused by its rule never reaches the database, whoever writes it. A key
 * whose catalog entry names no rule passes untouched: rules are opt-in per key, and a settings
 * layer without a single rule declared behaves exactly as it did before.
 */
final class SettingValueRules
{
    /**
     * Refuses a value its key's catalog rule rejects.
     *
     * @param string $key Setting key being written
     * @param mixed $value Value about to be written
     * @throws SettingInvalidValueException When the key declares a rule and the value fails it,
     *                                      or when the catalog names a rule that is not one
     */
    public static function assertValid(string $key, mixed $value): void
    {
        $rule = Hilos::$setting?->ruleFor($key);
        if ($rule === null) {
            return;
        }

        $refusal = $rule::validate($value);
        if ($refusal !== null) {
            throw new SettingInvalidValueException($refusal);
        }
    }
}
