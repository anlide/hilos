<?php

declare(strict_types=1);

namespace Hilos\Mail\Smtp;

use Hilos\Mail\SmtpMailTransport;

/**
 * SmtpActionKind - what {@see SmtpMailTransport} must do after an {@see SmtpDialog} step (HIL-197).
 *
 * The dialog is a pure state machine that never touches the socket itself; it hands the
 * transport one of these directives per reply.
 */
enum SmtpActionKind: string
{
    /** @var string Write the action's bytes to the socket, then read the next reply. */
    case SEND = 'send';

    /** @var string Perform the TLS handshake, then resume via {@see SmtpDialog::onSecured()}. */
    case START_TLS = 'start_tls';

    /** @var string The send has settled; take the action's outcome and close. */
    case FINISH = 'finish';
}
