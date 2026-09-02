<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Validation;

use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Database\Settings\Exception\SettingValueRefusedException;
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
     * The two ways this refuses are two exceptions on purpose: the value the caller typed
     * being wrong is answered to the caller, while the catalog naming something that is not
     * a rule is the installation being broken and is answered to nobody but the log.
     *
     * @param string $key Setting key being written
     * @param mixed $value Value about to be written
     * @throws SettingValueRefusedException When the key declares a rule and the value fails it
     * @throws SettingInvalidValueException When the catalog names a rule that is not one
     */
    public static function assertValid(string $key, mixed $value): void
    {
        $rule = Hilos::$setting?->ruleFor($key);
        if ($rule === null) {
            return;
        }

        $refusal = $rule::validate($value);
        if ($refusal !== null) {
            throw new SettingValueRefusedException($refusal);
        }
    }
}
