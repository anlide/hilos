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
 * argument is the render seam for translating outgoing messages (HIL-2281); today templates
 * render English and ignore it. Which language an outgoing message is written in is one
 * rule for every channel: docs/agents/architecture/language-and-region.md,
 * "The Language Of A Letter", summarized on {@see MailTemplate}.
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
