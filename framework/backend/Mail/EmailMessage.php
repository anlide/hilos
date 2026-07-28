<?php

declare(strict_types=1);

namespace Hilos\Mail;

/**
 * EmailMessage - a ready-to-send email addressed to one recipient (HIL-197).
 *
 * The value the mail subsystem hands a {@see MailTransportInterface}: the resolved
 * recipient, the rendered subject and bodies, and the optional reply-to. The sender
 * identity is not carried here — it is transport/config state (MAIL_FROM_*), applied
 * when the message is encoded, so one recipient message stays portable across
 * transports. When {@see html} is present the transport encodes a multipart/alternative
 * body with {@see text} as the plain fallback.
 */
final class EmailMessage
{
    /**
     * @param string $to Recipient email address
     * @param string $subject Rendered subject line
     * @param string $text Plain-text body (also the fallback part when html is set)
     * @param ?string $toName Recipient display name, or null for the bare address
     * @param ?string $html HTML body, or null for a text-only message
     * @param ?string $replyTo Reply-To address, or null for none
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $toName = null,
        public readonly ?string $html = null,
        public readonly ?string $replyTo = null,
    ) {
    }
}
