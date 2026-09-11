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
 *
 * WHICH LOCALE (HIL-834). An outgoing message is written in the READER's language, not in
 * the language of the screen that ordered it. Sources, top down: the recipient's own
 * recorded language, when the address belongs to a known account carrying one; otherwise
 * the language the ordering session speaks (subdomain hard override, then the guest's own
 * earlier choice, then the project default); otherwise the project default. Accept-Language
 * is never applied on its own - it only feeds the language switcher's recommendations. A key
 * missing in the target language falls back to the catalog default per key, so a message may
 * be partly translated but is never blank and never a refused send. The caller that orders
 * the send decides the locale and passes it in; a delivery agent never re-resolves one,
 * having neither the ordering session nor the recipient's preferences in reach. Nothing
 * remembers a per-flow language: the second letter of a flow resolves again by the same
 * rule. Today every step above the last is silent, which is why templates render the
 * project default and ignore the argument.
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
