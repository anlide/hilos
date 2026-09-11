<?php

declare(strict_types=1);

namespace Hilos\Mail;

/**
 * MailSendOutcome - the settled result of one transport send attempt (HIL-197).
 *
 * A transport pumps a send to completion over several non-blocking ticks, then
 * exposes exactly one of these through {@see MailTransportInterface::consumeResult()}.
 * A delivered outcome carries no error. A failed outcome carries a domain sentence in
 * {@see errorDetail} (never the message body or template params) and a {@see permanent}
 * flag: permanent failures (no verified address, SMTP 5xx) must not be retried, while
 * transient ones (SMTP 4xx, timeout, dropped socket) may be. The retry ceiling itself
 * lives in the delivery-channel agent, not here.
 *
 * {@see delivered} and {@see sentForReal} answer two different questions (HIL-827). The first
 * one is whether the send is over and will not be retried; the second one is whether the
 * message actually went anywhere. A letter the file transport wrote to disk settles both as a
 * finished send ({@see written()}) and as one that mailed nobody, so a surface reporting the
 * send can say so instead of claiming a delivery that is not coming.
 */
final class MailSendOutcome
{
    /**
     * @param bool $delivered Whether the transport accepted the message for delivery
     * @param bool $permanent Whether a failure is terminal (no retry); always false when delivered
     * @param ?string $errorDetail Domain failure sentence, or null when delivered
     * @param bool $sentForReal Whether the message left this installation
     */
    public function __construct(
        public readonly bool $delivered,
        public readonly bool $permanent = false,
        public readonly ?string $errorDetail = null,
        public readonly bool $sentForReal = true,
    ) {
    }

    /**
     * Builds a delivered outcome.
     *
     * @return self Delivered outcome with no error
     */
    public static function delivered(): self
    {
        return new self(true);
    }

    /**
     * Builds an outcome for a message the transport settled without mailing it anywhere.
     *
     * A finished send like a delivered one - there is nothing left to retry and the artifact
     * exists - which is why it is not a failure; it just did not leave the installation.
     *
     * @return self Delivered outcome that mailed nobody
     */
    public static function written(): self
    {
        return new self(true, sentForReal: false);
    }

    /**
     * Builds a failed outcome.
     *
     * @param string $errorDetail Domain failure sentence
     * @param bool $permanent Whether the failure is terminal (no retry)
     * @return self Failed outcome carrying the reason
     */
    public static function failed(string $errorDetail, bool $permanent): self
    {
        return new self(false, $permanent, $errorDetail, sentForReal: false);
    }
}
