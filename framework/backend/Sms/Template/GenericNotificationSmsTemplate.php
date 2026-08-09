<?php

declare(strict_types=1);

namespace Hilos\Sms\Template;

use Hilos\Sms\Exception\SmsTemplateParamMissingException;

/**
 * Renders a durable notification (HIL-196) as an SMS line (HIL-285).
 *
 * The notification's title and body are already localized on the backend at emit time, so
 * this template carries them through verbatim, joining a non-empty body to the title with a
 * separator to make one line. Segment truncation is the channel agent's job, not the
 * template's. A project that wants a richer per-type SMS overrides the catalog with a
 * `notification.<type>` key.
 */
final class GenericNotificationSmsTemplate implements SmsTemplate
{
    /** Template param: the notification title (already localized). */
    public const string PARAM_TITLE = 'title';

    /** Template param: the notification body (already localized). */
    public const string PARAM_BODY = 'body';

    /**
     * @param array<string, mixed> $params Template params; reads {@see PARAM_TITLE} and {@see PARAM_BODY}
     * @param ?string $locale Target locale, ignored today (reserved for i18n)
     * @return string Rendered one-line notification message
     * @throws SmsTemplateParamMissingException When the params carry no notification title
     */
    public function render(array $params, ?string $locale): string
    {
        $title = $params[self::PARAM_TITLE] ?? null;
        if (!is_scalar($title) || (string)$title === '') {
            throw new SmsTemplateParamMissingException(
                'Generic notification SMS template needs a non-empty ' . self::PARAM_TITLE . ' param',
            );
        }
        // external-boundary: an empty body means there is nothing to print after the title
        $body = (string)($params[self::PARAM_BODY] ?? '');

        return $body === '' ? (string)$title : trim((string)$title . ': ' . $body);
    }
}
