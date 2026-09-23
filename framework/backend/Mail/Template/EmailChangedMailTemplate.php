<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateParamMissingException;

/**
 * Tells both sides of an email change that the account address moved (HIL-299).
 *
 * Sent twice after the change commits - once to the old address and once to the new one - so the
 * owner hears about it on whichever mailbox they still read. It is a notice, not a code letter,
 * and both addresses are the substance of it: a letter that could not name them would say nothing.
 */
final class EmailChangedMailTemplate implements MailTemplate
{
    /** Template param: the address the account held before the change. */
    public const string PARAM_WAS = 'was';

    /** Template param: the address the account holds after the change. */
    public const string PARAM_NOW = 'now';

    /**
     * @param array<string, mixed> $params Template params; PARAM_WAS and PARAM_NOW are read
     * @param ?string $locale Target locale, ignored today (reserved for i18n)
     * @return EmailContent Rendered subject and text body
     * @throws MailTemplateParamMissingException When either address is absent or blank
     */
    public function render(array $params, ?string $locale): EmailContent
    {
        $was = self::address($params, self::PARAM_WAS);
        $now = self::address($params, self::PARAM_NOW);

        return new EmailContent(
            'Your email address was changed',
            "The email address of your account was changed from {$was} to {$now}.\n\n"
                . 'This notice goes to both addresses. If you did not make this change, contact support right away.',
        );
    }

    /**
     * Reads one address param, refusing the render when it is absent or blank.
     *
     * @param array<string, mixed> $params Template params
     * @param string $key Param to read
     * @return string The address
     * @throws MailTemplateParamMissingException When the param is absent or blank
     */
    private static function address(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new MailTemplateParamMissingException('Email changed mail template needs a non-empty ' . $key . ' param');
        }

        return $value;
    }
}
