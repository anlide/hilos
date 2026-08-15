<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Mail\EmailContent;
use Hilos\Mail\Exception\MailTemplateParamMissingException;

/**
 * MailTemplate - renders one framework mail template key into email content (HIL-197).
 *
 * A template turns caller-supplied params into an {@see EmailContent}. Templates are
 * resolved by key through {@see MailTemplateRegistry}, so callers never instantiate
 * one directly. The `$locale` argument is the render seam for the i18n stage (HIL,
 * stage 14); today templates render the project default locale and ignore it.
 */
interface MailTemplate
{
    /**
     * Renders the template into subject and bodies.
     *
     * @param array<string, mixed> $params Template params (see the template's PARAM_* keys)
     * @param ?string $locale Target locale, or null for the project default (reserved for i18n)
     * @return EmailContent Rendered subject and bodies
     * @throws MailTemplateParamMissingException When a param the template requires is absent
     */
    public function render(array $params, ?string $locale): EmailContent;
}
