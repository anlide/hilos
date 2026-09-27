<?php

declare(strict_types=1);

namespace Hilos\Legal;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/** Accepts the two treatments of a missed acceptance deadline. */
final class LegalRefusalRule implements SettingValueRuleInterface
{
    /**
     * @param mixed $value Value about to be written
     * @return ?string Refusal, or null for an accepted deadline treatment
     */
    public static function validate(mixed $value): ?string
    {
        return in_array($value, [LegalSettings::REFUSAL_FREEZE, LegalSettings::REFUSAL_REMIND], true)
            ? null : 'Value must be one of: freeze, remind';
    }
}
