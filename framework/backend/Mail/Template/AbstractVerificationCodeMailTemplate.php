<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateParamMissingException;

/**
 * Shared base for the code-carrying auth templates (HIL-197).
 *
 * The `auth.*` verification emails differ only in their subject and framing while
 * all embed one plaintext code; this base extracts the {@see PARAM_CODE} param and
 * assembles the {@see EmailContent}, leaving each subclass to supply the copy.
 * Magic-link sign-in delivers a URL rather than a typed code, so it does not extend
 * this base (see {@see MagicLinkMailTemplate}).
 */
abstract class AbstractVerificationCodeMailTemplate implements MailTemplate
{
    /** Template param: the plaintext verification code to embed. */
    public const string PARAM_CODE = 'code';

    /**
     * @param array<string, mixed> $params Template params; reads {@see PARAM_CODE}
     * @param ?string $locale Target locale, ignored today (reserved for i18n)
     * @return EmailContent Rendered subject and text body
     * @throws MailTemplateParamMissingException When the params carry no code to embed
     */
    public function render(array $params, ?string $locale): EmailContent
    {
        $code = $params[self::PARAM_CODE] ?? null;
        if (!is_scalar($code) || (string)$code === '') {
            throw new MailTemplateParamMissingException(
                'Verification mail template needs a non-empty ' . self::PARAM_CODE . ' param',
            );
        }

        return new EmailContent($this->subject(), $this->body((string)$code));
    }

    /**
     * @return string Subject line for this verification type
     */
    abstract protected function subject(): string;

    /**
     * @param string $code Plaintext code to embed
     * @return string Plain-text body for this verification type
     */
    abstract protected function body(string $code): string;
}
