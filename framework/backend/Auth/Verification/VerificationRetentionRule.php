<?php

declare(strict_types=1);

namespace Hilos\Auth\Verification;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;
use Hilos\Environment\Exception\EnvException;

/** Retains issue rows for at least the window in which they count against the send cap. */
final class VerificationRetentionRule implements SettingValueRuleInterface
{
    /**
     * @param mixed $value Value about to be written
     * @return ?string Refusal text or null when acceptable
     */
    public static function validate(mixed $value): ?string
    {
        try {
            $window = VerificationSweepSettings::sendWindowSeconds();
        } catch (EnvException) {
            // A broken environment is refused at boot; keep the catalog rule usable in isolation.
            $window = VerificationSweepSettings::SEND_WINDOW_FALLBACK_SECONDS;
        }
        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && $value >= $window) {
            return null;
        }

        return "Retention must be at least the send window of {$window} seconds";
    }
}
