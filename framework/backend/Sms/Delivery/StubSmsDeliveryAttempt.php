<?php

declare(strict_types=1);

namespace Hilos\Sms\Delivery;

use Hilos\Notification\Delivery\DeliveryAttempt;
use Hilos\Sms\DirectSmsProvider;
use Hilos\Sms\SmsMessage;
use Hilos\Sms\SmsSendResult;

/**
 * StubSmsDeliveryAttempt - a synchronous stub send wrapped for the delivery pipeline (HIL-285).
 *
 * Adapts a {@see DirectSmsProvider} (the stub) to the channel agent's {@see DeliveryAttempt}
 * seam: the send settles in the constructor, so {@see tick} is a no-op and the attempt is
 * never busy. The SMS analogue of the file-transport path on the mail side. A send failure
 * never throws - it settles as a result whose {@see errorDetail} the agent records.
 */
final class StubSmsDeliveryAttempt implements SmsSendAttempt
{
    /** Settled result, latched in the constructor. */
    private readonly SmsSendResult $result;

    /**
     * Runs the in-process send and latches its result.
     *
     * @param DirectSmsProvider $provider Provider that settles the send synchronously
     * @param SmsMessage $message Recipient message to send
     * @param float $nowMs Current time in milliseconds
     */
    public function __construct(DirectSmsProvider $provider, SmsMessage $message, float $nowMs)
    {
        $this->result = $provider->send($message, $nowMs);
    }

    /**
     * No-op: a stub send settles synchronously in the constructor.
     *
     * @param float $nowMs Current time in milliseconds (unused)
     */
    public function tick(float $nowMs): void
    {
    }

    /**
     * @return bool Always false - a stub send never stays in flight across ticks
     */
    public function isBusy(): bool
    {
        return false;
    }

    /**
     * @return bool True when the settled send was accepted for delivery
     */
    public function isDelivered(): bool
    {
        return $this->result->delivered;
    }

    /**
     * @return ?string The settled failure sentence, or null when delivered
     */
    public function errorDetail(): ?string
    {
        return $this->result->errorDetail;
    }

    /**
     * @return bool True when the settled send is a permanent (non-retryable) failure
     */
    public function isPermanentFailure(): bool
    {
        return !$this->result->delivered && $this->result->permanent;
    }

    /**
     * No transport resource is held by a stub send.
     */
    public function close(): void
    {
    }
}
