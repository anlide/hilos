<?php

declare(strict_types=1);

namespace Hilos\Auth\Method;

use Hilos\Core\CLI\Commands\AdminCreateCommand;
use Hilos\Database\Settings\Validation\SettingValueRuleInterface;
use Hilos\Hilos;

/**
 * Accepts a list of switched-off sign-in methods that names only wired methods and leaves a ready one on (HIL-427).
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
 *
 * One method left on is not enough if nobody can come in through it: a list that leaves on
 * only methods the installation cannot serve ({@see AuthMethodReadiness}) - a provider without
 * its client pair, a mailed link with no mail - locks everybody out as surely as switching
 * everything off (HIL-1080). So the rule keeps one READY method on. An installation with no
 * ready method at all has nothing to keep, and only the refusal of switching everything off
 * applies there.
 *
 * The rule judges the value at the moment it is written, not a transition, and not what
 * happens after: a secret cleared or an env value changed later can still leave only unready
 * methods on. The way out of an installation locked like that is `admin:create <session
 * token>` ({@see AdminCreateCommand}; docs/agents/architecture/command-server.md,
 * "admin:create"), which makes a browser session an administrator, after which the methods
 * are switched back on on the sign-in methods screen.
 */
final class AuthMethodsDisabledRule implements SettingValueRuleInterface
{
    /** Refusal text for a key the project has not wired; the key follows the colon. */
    private const string REFUSAL_UNKNOWN = 'Unknown sign-in method: %s';

    /** Refusal text for a list that would switch every wired method off. */
    private const string REFUSAL_LAST = 'At least one sign-in method must stay on';

    /** Refusal text for a list that would switch off every wired method the installation can serve. */
    private const string REFUSAL_NONE_READY = 'At least one sign-in method that is set up must stay on';

    /**
     * Checks that every listed key is a wired method and that one wired, ready method stays on.
     *
     * In order: an unknown key, then every wired method off, then every ready wired method
     * off. A project that wires no method at all has nothing to keep on, so only its unknown
     * keys are refused; one whose wired methods are all unready has no ready method to keep,
     * so only the second refusal applies there.
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

        $ready = AuthMethodReadiness::readyKeys($wired);
        if ($ready !== [] && array_diff($ready, $disabled) === []) {
            return self::REFUSAL_NONE_READY;
        }

        return null;
    }
}
