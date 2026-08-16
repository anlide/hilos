<?php

declare(strict_types=1);

namespace Hilos\Auth\CodeChannel;

/**
 * CodeChannelSend - what a channel answers about a code it was asked to deliver (HIL-492).
 *
 * The outcome of the second step, read off the transport's own response: delivered,
 * or refused. There is no retry arm on purpose. A code has already been minted by
 * the time a send is attempted, and the send gate counts the MINT - so a retry would
 * either race the person's own second attempt or quietly spend a paid message on a
 * code they are no longer looking at. A failed send is reported to the surface, which
 * offers the resend the person can decide to press.
 *
 * {@see detail} is a domain sentence for the agent log and never reaches the client:
 * the wire carries only a stable reason code, so no provider or network detail
 * escapes to a guest who is not even signed in.
 */
final readonly class CodeChannelSend
{
    /**
     * @param bool $delivered Whether the transport accepted the code for delivery
     * @param ?string $detail Domain failure sentence for the log, null when delivered
     */
    private function __construct(
        public bool $delivered,
        public ?string $detail,
    ) {
    }

    /**
     * Builds the outcome of a code the transport took.
     *
     * @return self Delivered send
     */
    public static function delivered(): self
    {
        return new self(true, null);
    }

    /**
     * Builds the outcome of a code the transport refused.
     *
     * @param string $detail Domain failure sentence for the agent log
     * @return self Failed send
     */
    public static function failed(string $detail): self
    {
        return new self(false, $detail);
    }
}
