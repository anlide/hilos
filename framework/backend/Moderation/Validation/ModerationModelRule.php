<?php

declare(strict_types=1);

namespace Hilos\Moderation\Validation;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts a model name that says something (HIL-1625).
 *
 * Whether the provider knows the model is not asked here: that is a fact about a server this
 * write path never calls, and the check that meets an unknown model reports it as unavailable.
 */
final class ModerationModelRule implements SettingValueRuleInterface
{
    /** Refusal text shown when the value is not a model name. */
    private const string REFUSAL = 'Value must be a model name';

    /**
     * Checks that the value is text with something besides whitespace in it.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? null : self::REFUSAL;
    }
}
