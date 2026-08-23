<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

use RuntimeException;

/**
 * MailForwardException - the stand's relay would not take a caught message.
 *
 * Its own class rather than a bare RuntimeException, because every provider route
 * turns exactly this failure into the 502 its client reads as a temporary refusal.
 * A catch wide enough to also swallow a bug in the handler would report that bug to
 * the daemon as "the gateway is busy, try later", which is the one answer that keeps
 * a broken stand looking healthy.
 */
final class MailForwardException extends RuntimeException
{
}
