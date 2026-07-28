<?php

declare(strict_types=1);

namespace Hilos\Sms;

/**
 * SmsSendResult - the settled result of one SMS send attempt (HIL-285).
 *
 * The mirror of {@see \Hilos\Mail\MailSendOutcome} for SMS: a provider (or the stub)
 * settles exactly one of these. A delivered result carries no error. A failed result
 * carries a domain sentence in {@see errorDetail} (never the message text or the full
 * number) and a {@see permanent} flag: permanent failures (HTTP 4xx, a provider-reported
 * rejection - unknown number, insufficient funds, stop-list) must not be retried, while
 * transient ones (HTTP 5xx, timeout, dropped socket) may be. The retry ceiling itself
 * lives in the delivery-channel agent, not here. SMS is stricter than email - a typical
 * gateway rejection does not self-heal and every attempt costs money.
 */
final class SmsSendResult
{
    /**
     * @param bool $delivered Whether the provider accepted the message for delivery
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
     * Builds a delivered result.
     *
     * @return self Delivered result with no error
     */
    public static function delivered(): self
    {
        return new self(true);
    }

    /**
     * Builds a failed result.
     *
     * @param string $errorDetail Domain failure sentence
     * @param bool $permanent Whether the failure is terminal (no retry)
     * @return self Failed result carrying the reason
     */
    public static function failed(string $errorDetail, bool $permanent): self
    {
        return new self(false, $permanent, $errorDetail);
    }
}
