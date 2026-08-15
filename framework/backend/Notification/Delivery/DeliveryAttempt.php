<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\API\AsyncHttpClient;
use Hilos\HilosException;

/**
 * DeliveryAttempt - one non-blocking send in flight, the channel transport seam (HIL-196).
 *
 * The async pipeline in {@see AbstractDeliveryChannelAgent} owns the pool,
 * concurrency ceiling, retry policy, and delivery-row bookkeeping; a channel leaf
 * only wraps its transport (an {@see AsyncHttpClient} for push/telegram,
 * an SMTP conversation for email) as one of these and lets the agent pump it. The
 * agent {@see tick()}s it each loop and, once {@see isBusy()} is false, records
 * {@see isDelivered()} as sent or {@see errorDetail()} as a bounded retry — never
 * blocking the worker loop on network I/O.
 */
interface DeliveryAttempt
{
    /**
     * Advances the in-flight send one non-blocking step.
     *
     * @param float $nowMs Current time in milliseconds
     * @throws HilosException Whatever the channel's transport raises while it settles
     */
    public function tick(float $nowMs): void;

    /**
     * Whether the send is still in progress (more ticks needed).
     *
     * @return bool True while the transport has not settled
     */
    public function isBusy(): bool;

    /**
     * Whether the settled send delivered successfully.
     *
     * Only meaningful once {@see isBusy()} is false.
     *
     * @return bool True when the transport accepted the message
     */
    public function isDelivered(): bool;

    /**
     * The failure detail for a settled unsuccessful send, or null on success.
     *
     * Recorded on the delivery row (truncated) and, for the last attempt, kept as
     * `last_error`. Only meaningful once {@see isBusy()} is false.
     *
     * @return ?string Failure detail, or null when delivered
     */
    public function errorDetail(): ?string;

    /**
     * Releases any transport resource (socket, handle) held by the attempt.
     */
    public function close(): void;
}
