<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateParamMissingException;

/**
 * Delivers a one-time passwordless sign-in link (HIL-197).
 *
 * Unlike the code-carrying auth templates, magic-link sign-in is a clickable URL the
 * recipient follows rather than a code they type, so it embeds {@see PARAM_LINK}
 * rather than extending {@see AbstractVerificationCodeMailTemplate}.
 */
final class MagicLinkMailTemplate implements MailTemplate
{
    /** Template param: the sign-in URL to embed. */
    public const string PARAM_LINK = 'link';

    /**
     * @param array<string, mixed> $params Template params; reads {@see PARAM_LINK}
     * @param ?string $locale Target locale, ignored today (reserved for i18n)
     * @return EmailContent Rendered subject and text body
     * @throws MailTemplateParamMissingException When the params carry no sign-in link
     */
    public function render(array $params, ?string $locale): EmailContent
    {
        $link = $params[self::PARAM_LINK] ?? null;
        if (!is_scalar($link) || (string)$link === '') {
            throw new MailTemplateParamMissingException(
                'Magic-link mail template needs a non-empty ' . self::PARAM_LINK . ' param',
            );
        }

        return new EmailContent(
            'Your sign-in link',
            "Use this link to sign in:\n{$link}\n\n"
            . 'The link can be used once. If you did not request it, you can ignore this message.',
        );
    }
}
