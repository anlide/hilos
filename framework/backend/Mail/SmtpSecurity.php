<?php

declare(strict_types=1);

namespace Hilos\Mail;

/**
 * SmtpSecurity - the transport-security mode of an SMTP connection (HIL-197).
 *
 * Selected from the MAIL_SMTP_SECURITY env value. {@see TLS} wraps the socket in TLS
 * from the first byte (implicit TLS, the classic port 465). {@see STARTTLS} connects in
 * the clear and upgrades in-band after the first EHLO (port 587). {@see NONE} stays plain
 * for a trusted local relay.
 */
enum SmtpSecurity: string
{
    /** @var string Implicit TLS from connect (port 465). */
    case TLS = 'tls';

    /** @var string In-band upgrade via the STARTTLS verb (port 587). */
    case STARTTLS = 'starttls';

    /** @var string Plain connection, no encryption. */
    case NONE = 'none';
}
