<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Validation;

use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Database\Settings\Exception\SettingTypeMismatchException;
use Hilos\Database\Settings\Exception\SettingValueRefusedException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;

/**
 * The one gate every setting write passes before the value is serialized.
 *
 * Stands in the two write paths of the settings layer — the row that is created and the row that
 * is updated — so a value of an incompatible type or refused by its rule never reaches the database,
 * whoever writes it. It validates that the value matches its catalog type (or orphan type) and
 * normalizes it to the expected PHP type, then enforces any catalog validation rule declared for
 * the key. A key whose catalog entry names no rule passes untouched once typed: rules are opt-in
 * per key, and a settings layer without a single rule declared validates only value types.
 */
final class SettingValueRules
{
    /**
     * Converts and validates a setting value according to its declared type.
     *
     * Validates that the value matches what typed readers in SettingValue accept, normalizes
     * it to the corresponding PHP type, and refuses any value that cannot be converted.
     *
     * @param string $key Setting key being written
     * @param string $type Setting type (string, integer, float, boolean)
     * @param mixed $value Value about to be written
     * @return mixed Normalized typed value (int, float, bool, or string)
     * @throws SettingValueRefusedException When the value does not match the expected type
     * @throws SettingTypeMismatchException When the setting type is not supported
     */
    public static function typed(string $key, string $type, mixed $value): mixed
    {
        $expected = match ($type) {
            SettingsCatalogConstants::TYPE_INTEGER => 'a whole number',
            SettingsCatalogConstants::TYPE_FLOAT => 'a number',
            SettingsCatalogConstants::TYPE_BOOLEAN => 'true or false',
            SettingsCatalogConstants::TYPE_STRING => 'text',
            default => throw new SettingTypeMismatchException("Setting type '{$type}' is not a supported setting type"),
        };

        if ($type === SettingsCatalogConstants::TYPE_INTEGER) {
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
                return (int)trim($value);
            }
        } elseif ($type === SettingsCatalogConstants::TYPE_FLOAT) {
            if (is_float($value) || is_int($value)) {
                return (float)$value;
            }
            if (is_string($value) && is_numeric(trim($value))) {
                return (float)trim($value);
            }
        } elseif ($type === SettingsCatalogConstants::TYPE_BOOLEAN) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value)) {
                return $value !== 0;
            }
            if (is_string($value)) {
                $trimmed = strtolower(trim($value));
                if ($trimmed === '1' || $trimmed === 'true') {
                    return true;
                }
                if ($trimmed === '0' || $trimmed === 'false') {
                    return false;
                }
            }
        } elseif ($type === SettingsCatalogConstants::TYPE_STRING) {
            if (is_scalar($value)) {
                return (string)$value;
            }
        }

        if (is_scalar($value)) {
            $rendered = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
            throw new SettingValueRefusedException("Setting '{$key}' takes {$expected}; '{$rendered}' is not one");
        }

        throw new SettingValueRefusedException("Setting '{$key}' takes {$expected}; value is not one");
    }

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
