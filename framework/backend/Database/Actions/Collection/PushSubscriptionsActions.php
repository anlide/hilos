<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
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
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws LogicException When the collection has no usable object metadata
     * @throws UnknownLazyStrategyException When the collection has an unknown loading strategy
     * @throws WriteNotAllowedException When the notifications library cannot add subscriptions
     */
    public function subscribe(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Add);
        $this->objectCollection->subscribe($userId, $endpoint, $p256dh, $auth, $userAgent);
    }

    /**
     * Marks transport-expired endpoints while keeping their rows visible.
     *
     * @param list<string> $endpoints Endpoints reported gone by the push service
     * @return int Rows newly marked gone
     * @throws DatabaseException When a lookup or write query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws LogicException When the collection has no usable object metadata
     * @throws UnknownLazyStrategyException When the collection has an unknown loading strategy
     * @throws WriteNotAllowedException When the notifications library cannot update subscriptions
     */
    public function markGone(array $endpoints): int
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);

        return $this->objectCollection->markGone($endpoints);
    }

    /**
     * Removes one subscription only when it belongs to the acting user.
     *
     * @param int $userId Acting user id
     * @param int $id Subscription row id
     * @return bool Whether an owned row was removed
     * @throws DatabaseException When the lookup or delete query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws LogicException When the collection has no usable object metadata
     * @throws UnknownLazyStrategyException When the collection has an unknown loading strategy
     * @throws WriteNotAllowedException When the notifications library cannot remove subscriptions
     */
    public function removeOwned(int $userId, int $id): bool
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        return $this->objectCollection->removeOwned($userId, $id);
    }

    /**
     * Removes the acting user's subscription for one endpoint.
     *
     * @param int $userId Acting user id
     * @param string $endpoint Browser push endpoint URL
     * @throws DatabaseException When the lookup or delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws LogicException When the collection has no usable object metadata
     * @throws UnknownLazyStrategyException When the collection has an unknown loading strategy
     * @throws WriteNotAllowedException When the notifications library cannot remove subscriptions
     */
    public function unsubscribeOwned(int $userId, string $endpoint): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $this->objectCollection->unsubscribeOwned($userId, $endpoint);
    }

    /**
     * Removes every subscription of a recipient (account-deletion cleanup).
     *
     * @param int $userId Recipient user id
     * @throws DatabaseException When a delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws LogicException When the collection has no usable object metadata
     * @throws UnknownLazyStrategyException When the collection has an unknown loading strategy
     * @throws WriteNotAllowedException When the notifications library cannot remove subscriptions
     */
    public function deleteForUser(int $userId): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $this->objectCollection->deleteForUser($userId);
    }
}
