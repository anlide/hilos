<?php

declare(strict_types=1);

namespace Hilos\Theme;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/** Accepts only the three exact theme positions for the installation default (HIL-1426). */
final class ThemeDefaultRule implements SettingValueRuleInterface
{
    /**
     * @param mixed $value Value about to be written
     * @return ?string Refusal text, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        return in_array($value, ThemeSettingsCatalog::THEME_VALUES, true) ? null : ThemeSettingsCatalog::THEME_VALUE_REFUSAL;
    }
}
