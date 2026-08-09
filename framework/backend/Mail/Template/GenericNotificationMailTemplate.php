<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateParamMissingException;

/**
 * Renders a durable notification (HIL-196) as an email (HIL-197).
 *
 * The notification's title and body are already localized on the backend at emit
 * time, so this template carries them through verbatim: subject is the title, body
 * is the body. A project that wants a richer per-type email overrides the catalog
 * with a `notification.<type>` key.
 */
final class GenericNotificationMailTemplate implements MailTemplate
{
    /** Template param: the notification title (already localized). */
    public const string PARAM_TITLE = 'title';

    /** Template param: the notification body (already localized). */
    public const string PARAM_BODY = 'body';

    /**
     * @param array<string, mixed> $params Template params; reads {@see PARAM_TITLE} and {@see PARAM_BODY}
     * @param ?string $locale Target locale, ignored today (reserved for i18n)
     * @return EmailContent Rendered subject and text body
     * @throws MailTemplateParamMissingException When the params carry no notification title
     */
    public function render(array $params, ?string $locale): EmailContent
    {
        $title = $params[self::PARAM_TITLE] ?? null;
        if (!is_scalar($title) || (string)$title === '') {
            throw new MailTemplateParamMissingException(
                'Generic notification mail template needs a non-empty ' . self::PARAM_TITLE . ' param',
            );
        }

        return new EmailContent(
            (string)$title,
            // external-boundary: an empty body means there is nothing to print under the title
            (string)($params[self::PARAM_BODY] ?? ''),
        );
    }
}
