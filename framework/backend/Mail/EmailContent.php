<?php

declare(strict_types=1);

namespace Hilos\Mail;

/**
 * EmailContent - the rendered subject and bodies a mail template produces (HIL-197).
 *
 * The result of {@see \Hilos\Mail\Template\MailTemplate::render()}: subject plus the
 * plain-text body and an optional HTML body. It carries no recipient — the mailer
 * (HIL-197 SLICE 4) pairs it with a resolved address to build an {@see EmailMessage}.
 * When {@see html} is present the message is encoded multipart/alternative with
 * {@see text} as the plain fallback.
 */
final class EmailContent
{
    /**
     * @param string $subject Rendered subject line
     * @param string $text Plain-text body (also the fallback part when html is set)
     * @param ?string $html HTML body, or null for a text-only message
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
    ) {
    }
}
