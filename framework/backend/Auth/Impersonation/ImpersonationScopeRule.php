<?php

declare(strict_types=1);

namespace Hilos\Auth\Impersonation;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/**
 * Accepts what may be done inside someone else's account: only look, or look and act (HIL-1170).
 */
final class ImpersonationScopeRule implements SettingValueRuleInterface
{
    /** Refusal text for a value outside the two. */
    private const string REFUSAL = 'Choose view only or view and act';

    /**
     * Checks that the value is one of the two scope values.
     *
     * @param mixed $value Value about to be written
     * @return ?string Refusal text for the admin, or null when the value is acceptable
     */
    public static function validate(mixed $value): ?string
    {
        return in_array($value, ImpersonationSettings::SCOPE_VALUES, true) ? null : self::REFUSAL;
    }
}
