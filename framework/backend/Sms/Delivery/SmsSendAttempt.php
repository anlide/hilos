<?php

declare(strict_types=1);

namespace Hilos\Sms\Delivery;

use Hilos\Notification\Delivery\DeliveryAttempt;

/**
 * SmsSendAttempt - one SMS send in flight, the channel's attempt seam (HIL-285).
 *
 * Extends the delivery pipeline's {@see DeliveryAttempt} with {@see isPermanentFailure()}:
 * the notification-delivery pipeline ignores that flag (its retry ceiling lives in the base
 * agent), but the raw-send pool - which owns its own retry policy for Auth codes - reads it
 * to fail fast on a permanent gateway rejection (4xx, unknown number) instead of retrying and
 * spending money. All three attempt shapes (HTTP, stub, failed) implement it.
 */
interface SmsSendAttempt extends DeliveryAttempt
{
    /**
     * Reports whether the settled failure is terminal and must not be retried.
     *
     * @return bool True when the send settled as a permanent (non-retryable) failure
     */
    public function isPermanentFailure(): bool;
}
