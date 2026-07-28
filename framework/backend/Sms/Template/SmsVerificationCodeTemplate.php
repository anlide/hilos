<?php

declare(strict_types=1);

namespace Hilos\Sms\Template;

/**
 * SmsVerificationCodeTemplate - renders a one-time verification code as one SMS line (HIL-285).
 *
 * The shared template for the SMS-delivered verification types (sms_login, sms_add): both
 * carry a short numeric code the recipient reads and types, so one line - "Your verification
 * code is: NNNNNN" - serves both. The code arrives under {@see PARAM_CODE}, the same param
 * key the mail verification templates use, so the verification deliverer places the secret
 * identically for either channel.
 */
final class SmsVerificationCodeTemplate implements SmsTemplate
{
    /** Template param: the plaintext verification code. */
    public const string PARAM_CODE = 'code';

    /**
     * @param array<string, mixed> $params Template params; reads {@see PARAM_CODE}
     * @param ?string $locale Target locale, ignored today (reserved for i18n)
     * @return string Rendered one-line code message
     */
    public function render(array $params, ?string $locale): string
    {
        return 'Your verification code is: ' . (string)($params[self::PARAM_CODE] ?? '');
    }
}
