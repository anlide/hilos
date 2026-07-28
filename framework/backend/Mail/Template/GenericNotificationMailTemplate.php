<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Mail\EmailContent;

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
     */
    public function render(array $params, ?string $locale): EmailContent
    {
        return new EmailContent(
            (string)($params[self::PARAM_TITLE] ?? ''),
            (string)($params[self::PARAM_BODY] ?? ''),
        );
    }
}
