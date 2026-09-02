<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts the name of a logging mode, or the empty string for none (HIL-762).
 *
 * The names are asked of {@see LogSettingsPresets} rather than listed here, so what an
 * administrator is allowed to store and what the cards can offer are one notion.
 *
 * The empty string is accepted, unlike in the write-level rule beside it: "no mode applied" is a
 * state the screen has a drawing for — no card lit, no differences — while there is no level of
 * the write scale that means "write nothing".
 *
 * The rule exists although an unknown name is already harmless: the page answers "none applied"
 * to anything it does not recognize. The two catch different mistakes. A typo made on the general
 * settings screen is caught here, at the moment of writing, instead of silently unlighting every
 * card; while a name that stopped existing AFTER it was written is not offered to a rule at all,
 * and the page's tolerant reading is what covers that one.
 */
final class LogPresetNameRule implements SettingValueRuleInterface
{
    /** Refusal text shown when the value does not name a mode. */
    private const string REFUSAL = 'Value must name one of the logging modes, or be empty for none';

    /**
     * Checks that the value names a logging mode.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return self::REFUSAL;
        }
        if ($value === '') {
            return null;
        }

        return LogSettingsPresets::presetGroup()->presetByName($value) !== null ? null : self::REFUSAL;
    }
}
