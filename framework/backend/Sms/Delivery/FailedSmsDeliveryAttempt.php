<?php

declare(strict_types=1);

namespace Hilos\Sms\Delivery;

/**
 * FailedSmsDeliveryAttempt - an attempt that settles a fixed permanent failure (HIL-285).
 *
 * The stand-in the SMS agent hands out when a send cannot even start - an invalid gateway
 * config or a request the client refuses to open - so the misconfiguration settles as a
 * permanent failure through the normal outcome path instead of throwing out of the tick loop
 * and crash-looping the worker. The SMS analogue of {@see \Hilos\Mail\FailedMailTransport}.
 * It holds no socket and never touches the network.
 */
final class FailedSmsDeliveryAttempt implements SmsSendAttempt
{
    /**
     * @param string $reason Domain failure sentence reported for the send
     */
    public function __construct(
        private readonly string $reason,
    ) {
    }

    /**
     * @param float $nowMs Current time in milliseconds (unused)
     */
    public function tick(float $nowMs): void
    {
    }

    /**
     * @return bool Always false: the failure settled on construction
     */
    public function isBusy(): bool
    {
        return false;
    }

    /**
     * @return bool Always false: the send never delivered
     */
    public function isDelivered(): bool
    {
        return false;
    }

    /**
     * @return string The fixed failure sentence
     */
    public function errorDetail(): ?string
    {
        return $this->reason;
    }

    /**
     * @return bool Always true: a send that cannot start is a permanent failure
     */
    public function isPermanentFailure(): bool
    {
        return true;
    }

    /**
     * No transport resource is held.
     */
    public function close(): void
    {
    }
}
