<?php

declare(strict_types=1);

namespace Hilos\Sms\Delivery;

use Hilos\Sms\SmsMessage;

/**
 * RawSmsSend - the mutable state of one queued raw-send op (HIL-285).
 *
 * Agent-internal bookkeeping for input B ({@see SmsDeliveryChannelAgent}): a raw send
 * ({@see \Hilos\Constants\HilosSignalConstants::HILOS_SMS_SEND}, Auth login/add codes) has no
 * durable delivery row, so its message, attempt count, in-flight attempt, and retry schedule
 * live here in memory only. The template key is kept for the failure log - the message text
 * and template params are never logged. Not part of any contract; the agent owns and drops
 * these.
 */
final class RawSmsSend
{
    /** Number of send attempts started so far (for the retry ceiling). */
    public int $attempts = 0;

    /** The in-flight attempt, or null while queued or between retries. */
    public ?SmsSendAttempt $attempt = null;

    /** Earliest time (ms) the next attempt may start, for retry backoff. */
    public float $nextAttemptMs = 0.0;

    /**
     * @param SmsMessage $message The rendered recipient message to send
     * @param ?string $templateKey Template key for the failure log, or null for an inline message
     */
    public function __construct(
        public readonly SmsMessage $message,
        public readonly ?string $templateKey,
    ) {
    }
}
