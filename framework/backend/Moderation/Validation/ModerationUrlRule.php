<?php

declare(strict_types=1);

namespace Hilos\Moderation\Validation;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts an empty string (no override) or an absolute http(s) address of a local LLM server (HIL-1625).
 *
 * Empty is a value of its own and not a blank to be trimmed: it tells the profile to keep the
 * address its environment resolved. Anything else has to be an address as it stands, so a padded
 * one is refused instead of being saved and then failing to connect.
 */
final class ModerationUrlRule implements SettingValueRuleInterface
{
    /** URL schemes a local LLM server is reached over. */
    private const array SCHEMES = ['http', 'https'];

    /** Refusal text shown when the value is neither empty nor an absolute http(s) address. */
    private const string REFUSAL = 'Value must be empty or an absolute http or https URL';

    /**
     * Checks that the value is an empty string or an absolute http(s) URL.
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

        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (filter_var($value, FILTER_VALIDATE_URL) === false || !is_string($scheme)) {
            return self::REFUSAL;
        }

        return in_array(strtolower($scheme), self::SCHEMES, true) ? null : self::REFUSAL;
    }
}
