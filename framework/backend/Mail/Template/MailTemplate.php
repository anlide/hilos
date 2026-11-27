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
 * one directly. The `$locale` argument is the render seam for translating outgoing
 * messages (HIL-2281); today templates render English and ignore it.
 *
 * WHICH LOCALE (HIL-834). An outgoing message is written in the READER's language. Sources,
 * top down: the language of the account the address belongs to; otherwise the language of
 * the tab that ordered the message - the one its ladder already picked (the language
 * address, the guest's own choice, the browser, the country of the connection, the default
 * language), so that the letter and the tab that ordered it speak one language; otherwise,
 * when no tab ordered it, the default language. A key missing in the target language falls
 * back to the catalog default per key, so a message may be partly translated but is never
 * blank and never a refused send. The caller that orders the send decides the locale and
 * passes it in; a delivery agent never re-resolves one, having neither the ordering tab nor
 * the account in reach. Nothing remembers a per-flow language: the second letter of a flow
 * resolves again by the same rule. Today no caller passes a locale and every template is
 * written in English, so templates render English and ignore the argument.
 *
 * The rule and its reasons: docs/agents/architecture/language-and-region.md,
 * "The Language Of A Letter".
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
