<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/** Accepts the two consent forms. */
final class LegalConsentFormRule implements SettingValueRuleInterface
{
    /**
     * @param mixed $value Value about to be written
     * @return ?string Refusal, or null for an accepted consent form
     */
    public static function validate(mixed $value): ?string
    {
        return in_array($value, [LegalSettings::CONSENT_FORM_CHECKBOX, LegalSettings::CONSENT_FORM_LINE], true)
            ? null : 'Value must be one of: checkbox, line';
    }
}
