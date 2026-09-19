<?php

declare(strict_types=1);

namespace Hilos\Auth\Method;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;
use Hilos\Hilos;

/**
 * Accepts a list of switched-off sign-in methods that names only wired methods and leaves one on (HIL-427).
 *
 * The rule of the whole method set lives here, on the value, because every door that
 * writes the value passes a setting rule: the sign-in methods screen, the general settings
 * table and a preset alike. A check on the screen alone would let the general table switch
 * every method off and lock the installation out of itself.
 *
 * A key the project never wired is refused rather than ignored on write: the list is what
 * an administrator sees back, and a key nobody can match to a method would sit in it
 * forever. Reading ({@see EnabledAuthMethods}) skips such a key instead, since a method a
 * project stopped wiring leaves its key behind in a value written before.
 */
final class AuthMethodsDisabledRule implements SettingValueRuleInterface
{
    /** Refusal text for a key the project has not wired; the key follows the colon. */
    private const string REFUSAL_UNKNOWN = 'Unknown sign-in method: %s';

    /** Refusal text for a list that would switch every wired method off. */
    private const string REFUSAL_LAST = 'At least one sign-in method must stay on';

    /**
     * Checks that every listed key is a wired method and that one wired method stays on.
     *
     * A project that wires no method at all has nothing to keep on, so only its unknown
     * keys are refused.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return sprintf(self::REFUSAL_UNKNOWN, get_debug_type($value));
        }

        $wired = Hilos::authMethodDirectoryClass()::keys();
        $disabled = AuthMethodSettings::parse($value);
        foreach ($disabled as $key) {
            if (!in_array($key, $wired, true)) {
                return sprintf(self::REFUSAL_UNKNOWN, $key);
            }
        }

        if ($wired !== [] && array_diff($wired, $disabled) === []) {
            return self::REFUSAL_LAST;
        }

        return null;
    }
}
