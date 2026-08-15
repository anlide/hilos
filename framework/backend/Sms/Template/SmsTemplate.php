<?php

declare(strict_types=1);

namespace Hilos\Sms\Template;

use Hilos\Mail\Template\MailTemplate;
use Hilos\Sms\Exception\SmsTemplateParamMissingException;

/**
 * SmsTemplate - renders one framework SMS template key into a single text line (HIL-285).
 *
 * The SMS counterpart of {@see MailTemplate}: a template turns
 * caller-supplied params into one plain string - no subject, no HTML, no multipart, because
 * an SMS is a single short line. Templates are resolved by key through
 * {@see SmsTemplateRegistry}, so callers never instantiate one directly. The `$locale`
 * argument is the render seam for the i18n stage (HIL, stage 14); today templates render
 * the project default locale and ignore it.
 */
interface SmsTemplate
{
    /**
     * Renders the template into a single message line.
     *
     * @param array<string, mixed> $params Template params (see the template's PARAM_* keys)
     * @param ?string $locale Target locale, or null for the project default (reserved for i18n)
     * @return string Rendered message text
     * @throws SmsTemplateParamMissingException When a param the template requires is absent
     */
    public function render(array $params, ?string $locale): string;
}
