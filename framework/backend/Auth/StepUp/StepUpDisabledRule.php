<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Settings\Validation\SettingValueRuleInterface;
use Hilos\Hilos;

/**
 * Refuses a switched-off list that names an operation the project did not declare (HIL-495).
 */
final class StepUpDisabledRule implements SettingValueRuleInterface
{
    /** Refusal text for an unknown operation; the key follows the colon. */
    private const string REFUSAL_UNKNOWN = 'Unknown operation: %s';

    /**
     * @param mixed $value Value about to be stored
     * @return ?string Refusal text, or null when every key is declared
     */
    public static function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return sprintf(self::REFUSAL_UNKNOWN, get_debug_type($value));
        }

        foreach (StepUpSettings::parse($value) as $key) {
            try {
                $declared = Hilos::stepUpOperationDirectoryClass()::has($key);
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            }
            if (!$declared) {
                return sprintf(self::REFUSAL_UNKNOWN, $key);
            }
        }

        return null;
    }
}
