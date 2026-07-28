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
 */
final class MailSendOutcome
{
    /**
     * @param bool $delivered Whether the transport accepted the message for delivery
     * @param bool $permanent Whether a failure is terminal (no retry); always false when delivered
     * @param ?string $errorDetail Domain failure sentence, or null when delivered
     */
    public function __construct(
        public readonly bool $delivered,
        public readonly bool $permanent = false,
        public readonly ?string $errorDetail = null,
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
     * Builds a failed outcome.
     *
     * @param string $errorDetail Domain failure sentence
     * @param bool $permanent Whether the failure is terminal (no retry)
     * @return self Failed outcome carrying the reason
     */
    public static function failed(string $errorDetail, bool $permanent): self
    {
        return new self(false, $permanent, $errorDetail);
    }
}
