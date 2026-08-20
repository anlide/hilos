<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateParamMissingException;

/**
 * Delivers a one-time passwordless sign-in link (HIL-197).
 *
 * Unlike the code-carrying auth templates, magic-link sign-in leads with a clickable URL
 * the recipient follows, so it embeds {@see PARAM_LINK} rather than extending
 * {@see AbstractVerificationCodeMailTemplate}. It carries {@see PARAM_CODE} as well
 * (HIL-606): a person reading the letter on a phone while the sign-in screen waits on a
 * laptop cannot click their way back to that screen, and typing six digits is what they
 * can do instead.
 *
 * BOTH params are required, and that is the point of the pair: a letter that lost one half
 * silently would offer a way in that does not work, so the missing param is named here
 * rather than mailed. The two halves are two challenges with two attempt ceilings; using
 * either one voids the other, which is what the closing line promises.
 */
final class MagicLinkMailTemplate implements MailTemplate
{
    /** Template param: the sign-in URL to embed. */
    public const string PARAM_LINK = 'link';

    /**
     * Template param: the companion sign-in code to embed.
     *
     * Deliberately its own constant rather than a reuse of
     * {@see AbstractVerificationCodeMailTemplate::PARAM_CODE}: the values coincide, but the
     * two are separate template contracts, and a shared constant would tie this letter's
     * shape to a base it does not extend.
     */
    public const string PARAM_CODE = 'code';

    /**
     * @param array<string, mixed> $params Template params; reads {@see PARAM_LINK} and {@see PARAM_CODE}
     * @param ?string $locale Target locale, ignored today (reserved for i18n)
     * @return EmailContent Rendered subject and text body
     * @throws MailTemplateParamMissingException When the params carry no sign-in link or no code
     */
    public function render(array $params, ?string $locale): EmailContent
    {
        $link = $this->required($params, self::PARAM_LINK);
        $code = $this->required($params, self::PARAM_CODE);

        return new EmailContent(
            'Your sign-in link',
            "Use this link to sign in:\n{$link}\n\n"
            . "Or enter this code on the page that asked for it:\n{$code}\n\n"
            . 'Whichever you use, the other stops working. '
            . 'If you did not request this, you can ignore this message.',
        );
    }

    /**
     * Reads a param this letter cannot be assembled without.
     *
     * @param array<string, mixed> $params Template params as given to the render
     * @param string $name Param key to read
     * @return string Non-empty scalar value of the param, as a string
     * @throws MailTemplateParamMissingException When the param is absent, non-scalar or empty
     */
    private function required(array $params, string $name): string
    {
        $value = $params[$name] ?? null;
        if (!is_scalar($value) || (string)$value === '') {
            throw new MailTemplateParamMissingException(
                'Magic-link mail template needs a non-empty ' . $name . ' param',
            );
        }

        return (string)$value;
    }
}
