<?php

declare(strict_types=1);

namespace Hilos\Push;

use Hilos\Core\Exception\InvalidArgumentException;

/** Fresh persisted identity of an external push endpoint; neither a row nor a transport payload. */
final readonly class PushSubscriptionIdentity
{
    /**
     * @param int $subscriptionId Captured subscription row id
     * @param int $userId Current owner of that row
     * @throws InvalidArgumentException When either id is not positive
     */
    public function __construct(public int $subscriptionId, public int $userId)
    {
        if ($subscriptionId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('Push subscription identity requires positive ids');
        }
    }
}
