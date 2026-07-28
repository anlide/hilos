<?php

declare(strict_types=1);

namespace Hilos\Mail\Smtp;

/**
 * SmtpConnectionPhase - the non-blocking I/O phase of {@see SmtpMailTransport} (HIL-197).
 *
 * Distinct from {@see SmtpState} (which tracks the protocol conversation): this tracks
 * what the socket is doing this tick — completing the async connect, performing a TLS
 * handshake, flushing a pending write, or reading the next reply.
 */
enum SmtpConnectionPhase: string
{
    /** @var string Async connect in progress. */
    case CONNECTING = 'connecting';

    /** @var string TLS handshake in progress. */
    case HANDSHAKE = 'handshake';

    /** @var string Flushing bytes to the socket. */
    case WRITING = 'writing';

    /** @var string Reading the next server reply. */
    case READING = 'reading';

    /** @var string No send in flight. */
    case IDLE = 'idle';
}
