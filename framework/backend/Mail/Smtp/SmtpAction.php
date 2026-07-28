<?php

declare(strict_types=1);

namespace Hilos\Mail\Smtp;

use Hilos\Mail\MailSendOutcome;

/**
 * SmtpAction - the directive an {@see SmtpDialog} step returns to the transport (HIL-197).
 *
 * Exactly one of the three kinds applies: {@see SmtpActionKind::SEND} carries the
 * {@see bytes} to write, {@see SmtpActionKind::START_TLS} carries nothing, and
 * {@see SmtpActionKind::FINISH} carries the settled {@see outcome}.
 */
final class SmtpAction
{
    /**
     * @param SmtpActionKind $kind Which directive this is
     * @param ?string $bytes Bytes to write when the kind is SEND, else null
     * @param ?MailSendOutcome $outcome Settled outcome when the kind is FINISH, else null
     */
    private function __construct(
        public readonly SmtpActionKind $kind,
        public readonly ?string $bytes = null,
        public readonly ?MailSendOutcome $outcome = null,
    ) {
    }

    /**
     * Builds a directive to write bytes to the socket.
     *
     * @param string $bytes Wire bytes to send
     * @return self SEND directive
     */
    public static function send(string $bytes): self
    {
        return new self(SmtpActionKind::SEND, bytes: $bytes);
    }

    /**
     * Builds a directive to perform the TLS handshake.
     *
     * @return self START_TLS directive
     */
    public static function startTls(): self
    {
        return new self(SmtpActionKind::START_TLS);
    }

    /**
     * Builds a directive that settles the send.
     *
     * @param MailSendOutcome $outcome The terminal outcome
     * @return self FINISH directive
     */
    public static function finish(MailSendOutcome $outcome): self
    {
        return new self(SmtpActionKind::FINISH, outcome: $outcome);
    }
}
