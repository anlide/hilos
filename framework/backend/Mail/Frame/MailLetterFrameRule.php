<?php

declare(strict_types=1);

namespace Hilos\Mail\Frame;

use Hilos\Database\Settings\Validation\SettingValueRuleInterface;

/** The shared settings write gate for letter frames. */
final class MailLetterFrameRule implements SettingValueRuleInterface
{
    /**
     * @param mixed $value Proposed HTML frame, without scalar coercion
     * @return ?string Safe refusal sentence, or null when valid
     */
    public static function validate(mixed $value): ?string
    {
        return is_string($value) ? MailLetterFrame::validate($value) : MailLetterFrame::TYPE_REFUSAL;
    }
}
