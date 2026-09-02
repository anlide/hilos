<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\PushSubscriptions as ObjectPushSubscriptions;
use Hilos\Database\View\Collection\PushSubscriptions as DbCollectionPushSubscriptions;
use Hilos\Database\View\Item\PushSubscription;

/**
 * PushSubscriptionsActions - write operations for the PushSubscriptions collection.
 *
 * Backs the `push_subscribe` / `push_unsubscribe` client actions (HIL-199): a device
 * opts in or out of browser push. The actions are mounted on the profile page, which
 * resolves the acting user server-side from the connection, so a client can only ever
 * subscribe its own device.
 *
 * @extends DbActions<PushSubscription, ObjectPushSubscriptions>
 * @property-read DbCollectionPushSubscriptions $collection
 * @property-read ObjectPushSubscriptions $objectCollection
 */
final class PushSubscriptionsActions extends DbActions
{
    /**
     * Registers a device's push subscription (upsert by endpoint).
     *
     * @param int $userId Subscribing user id
     * @param string $endpoint Browser push endpoint URL
     * @param string $p256dh Client public key (base64url)
     * @param string $auth Client auth secret (base64url)
     * @param ?string $userAgent Subscribing device user agent, or null
     * @throws EmptyValueException When the endpoint is empty
     * @throws DatabaseException When the write query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function subscribe(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): void
    {
        $this->objectCollection->subscribe($userId, $endpoint, $p256dh, $auth, $userAgent);
    }

    /**
     * Removes the subscription of an endpoint (device opt-out or stale endpoint).
     *
     * @param string $endpoint Browser push endpoint URL
     * @throws DatabaseException When the delete query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function unsubscribe(string $endpoint): void
    {
        $this->objectCollection->unsubscribe($endpoint);
    }

    /**
     * Removes every subscription of a recipient (account-deletion cleanup).
     *
     * @param int $userId Recipient user id
     * @throws DatabaseException When a delete query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        $this->objectCollection->deleteForUser($userId);
    }
}
