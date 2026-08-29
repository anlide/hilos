<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Validation;

/**
 * Declares whether a value may be written to a setting key that names this rule.
 *
 * A catalog entry names its rule in SettingsCatalogConstants::CATALOG_ENTRY_RULE, and every write
 * path goes through {@see SettingValueRules::assertValid()}. The refusal text belongs to the rule
 * and not to the calling write path, so the same mistake is named the same way whoever writes the
 * value — the settings table today, a preset or a restore tomorrow.
 */
interface SettingValueRuleInterface
{
    /**
     * Checks a value against the rule.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string;
}
