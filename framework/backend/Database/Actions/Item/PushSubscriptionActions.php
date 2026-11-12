<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Database\Object\Item\PushSubscription as ObjectPushSubscription;
use Hilos\Database\View\Item\PushSubscription;
use Hilos\HilosException;

/**
 * @extends DbActions<PushSubscription, ObjectPushSubscription>
 * @property-read ObjectPushSubscription $object
 */
class PushSubscriptionActions extends DbActions
{
    /**
     * Renews an existing device through its personal owner.
     * The object refreshes the saved owner and diff baseline before its Update guard.
     *
     * @param int $userId Subscribing person
     * @param string $endpoint Captured endpoint attribute
     * @param string $p256dh Client public key
     * @param string $auth Client auth secret
     * @param ?string $userAgent Device user agent, or null
     * @return bool Whether the captured identity still matches the requested operation
     * @throws HilosException When ownership, persistence or announcement refuses the write
     */
    public function renewOwned(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): bool
    {
        return $this->object->renewOwned($userId, $endpoint, $p256dh, $auth, $userAgent);
    }
}
