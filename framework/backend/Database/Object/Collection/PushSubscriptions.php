<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\Http\DeviceName;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\PushSubscriptions as EntityPushSubscriptions;
use Hilos\Database\Entity\Item\PushSubscription as EntityPushSubscription;
use Hilos\Database\Object\Item\PushSubscription as ObjectPushSubscription;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Push\PushSubscriptionIdentity;
use Hilos\Core\Exception\ValidationException;

/**
 * PushSubscriptions object collection - the per-device push-subscription accessor (HIL-199).
 *
 * Persistence primitives for browser push subscriptions, keyed by the device
 * `endpoint` (UNIQUE). The creator inserts new subscriptions; item actions renew an existing
 * device or perform the temporary foreign rebind until HIL-1906;
 * {@see unsubscribeOwned()} removes the acting device, while {@see markGoneOwned()} keeps a
 * transport-expired endpoint visible to its owner. {@see forUser()} lists live endpoints
 * for the push delivery channel to send to; {@see deleteForUser()} clears a user's
 * rows inside account erasure's transaction, before the person's RESTRICT key.
 *
 * @extends Objects<ObjectPushSubscription>
 * @method ObjectPushSubscription|null current()
 * @method ObjectPushSubscription|null first()
 * @method ObjectPushSubscription|null last()
 * @method ObjectPushSubscription|null get(int|string $key)
 * @method ObjectPushSubscription|null offsetGet(mixed $offset)
 */
class PushSubscriptions extends Objects
{
    public const string OBJECT_CLASS = ObjectPushSubscription::class;
    public const string ENTITY_COLLECTION_CLASS = EntityPushSubscriptions::class;
    public const string COLLECTION_KEY = HilosDbContext::pushSubscriptions;

    /**
     * Creates a new endpoint. Renewal and the temporary foreign rebind belong to item actions.
     *
     * @param int $userId Subscribing user id
     * @param string $endpoint Browser push endpoint URL
     * @param string $p256dh Client public key
     * @param string $auth Client auth secret
     * @param ?string $userAgent Device user agent, or null
     * @throws EmptyValueException When the endpoint is empty
     * @throws ValidationException When the endpoint already exists
     * @throws DatabaseException When the lookup or insert fails
     * @throws InvalidArgumentException When the query or sync signal is invalid
     * @throws SourceChangeSubscriberException When an announcement fails
     * @throws LogicException When the collection metadata is invalid
     * @throws WriteNotAllowedException When the writer cannot write the subscription
     * @throws CreateNotAllowedException When the writer cannot create the subscription
     */
    public function subscribe(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): void
    {
        if ($endpoint === '') {
            throw new EmptyValueException('Push subscription endpoint is required');
        }
        if ($this->readIdentityByEndpoint($endpoint) !== null) {
            throw new ValidationException('Push subscription already exists');
        }

        $subscription = static::OBJECT_CLASS::create();
        $subscription->endpoint = $endpoint;
        $subscription->createdAt = TimeHelper::nowMs();
        $subscription->lastSeenAt = $subscription->createdAt;
        $subscription->revision = EntityPushSubscription::INITIAL_REVISION;
        $subscription->userId = $userId;
        $subscription->p256dh = $p256dh;
        $subscription->auth = $auth;
        $subscription->userAgent = $userAgent;
        $subscription->deviceName = DeviceName::fromUserAgent($userAgent);
        $subscription->endpointHash = hash('sha256', $endpoint);
        $subscription->goneAt = null;
        $subscription->sync();

        if ($subscription->id !== null) {
            $this[$subscription->id] = $subscription;
        }
    }

    /**
     * Reads the persisted identity without trusting a held object or hydrating a replacement.
     *
     * @param string $endpoint External browser endpoint
     * @return ?PushSubscriptionIdentity Current row identity, or null when absent
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query order or persisted ids are invalid
     */
    public function readIdentityByEndpoint(string $endpoint): ?PushSubscriptionIdentity
    {
        $entity = static::entityClass()::get( // fresh-sql: input dispatch must see the saved owner even before DB sync arrives.
            [EntityPushSubscription::endpoint => $endpoint],
        )->first();

        return $entity === null ? null : new PushSubscriptionIdentity($entity->id, $entity->user_id);
    }

    /**
     * A fresh row lookup leaves missing and foreign devices alone. A duplicate keeps the first gone
     * moment, and a different revision refuses a late report about a renewed subscription.
     *
     * @param int $userId Device owner's id
     * @param int $id Captured subscription row id
     * @param int $revision Captured subscription revision
     * @return bool Whether the matching device was newly marked gone
     * @throws DatabaseException When the lookup or write query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws InvalidArgumentException When the query order or DB-sync signal is invalid
     * @throws WriteNotAllowedException When the caller cannot write the subscription row
     * @throws CreateNotAllowedException When the object writer refuses an insert
     */
    public function markGoneOwned(int $userId, int $id, int $revision): bool
    {
        $entity = static::entityClass()::get( // fresh-sql: a held object can lag behind renewal; its old revision would expire a live device.
            [EntityPushSubscription::id => $id],
        )->first();
        if ($entity === null || $entity->user_id !== $userId || $entity->gone_at !== null || $entity->revision !== $revision) {
            return false;
        }

        if (!isset($this->objects[$id])) {
            $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
        }
        $subscription = $this->objects[$id];
        $subscription->goneAt = TimeHelper::nowMs();
        $subscription->sync();

        return true;
    }

    /**
     * Removes one subscription only when it belongs to the acting user.
     *
     * @param int $userId Acting user id
     * @param int $id Subscription row id
     * @return bool Whether an owned row was removed
     * @throws DatabaseException When the lookup or delete query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws LogicException When the object collection cannot resolve its entity metadata
     */
    public function removeOwned(int $userId, int $id): bool
    {
        $subscription = $this->get($id);
        if ($subscription === null || $subscription->userId !== $userId) {
            return false;
        }

        $subscription->delete();
        unset($this[$id]);

        return true;
    }

    /**
     * Removes the acting user's subscription for one endpoint.
     *
     * @param int $userId Acting user id
     * @param string $endpoint Browser push endpoint URL
     * @throws DatabaseException When the lookup or delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws LogicException When the object collection cannot resolve its entity metadata
     * @throws WriteNotAllowedException When the caller cannot write the subscription row
     */
    public function unsubscribeOwned(int $userId, string $endpoint): void
    {
        if ($endpoint === '') {
            return;
        }

        $subscription = $this->find($endpoint);
        if ($subscription === null || $subscription->userId !== $userId || $subscription->id === null) {
            return;
        }

        $id = $subscription->id;
        $subscription->delete();
        unset($this[$id]);
    }

    /**
     * Lists a recipient's push subscriptions (send targets for the push channel).
     *
     * A project registers the subscription collection unconditionally
     * ({@see HilosDbContext::configure()}) but may not have activated the
     * hilos_push_subscription table (no migration). The read then degrades to an
     * empty list instead of hitting a missing table, keeping push delivery inert
     * until a project activates the table.
     *
     * @param int $userId Recipient user id
     * @return ObjectCollection<ObjectPushSubscription> Active subscriptions in query order; empty if the table is not activated
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function forUser(int $userId): ObjectCollection
    {
        $subscriptions = ObjectCollection::empty();
        if (Schema::getTable(EntityPushSubscription::_table) === null) {
            return $subscriptions;
        }

        $entities = static::entityClass()::get( // fresh-sql: exclude gone or transferred rows even before DB sync reaches this sender.
            [EntityPushSubscription::user_id => $userId],
        );

        foreach ($entities as $entity) {
            if ($entity->gone_at !== null) {
                continue;
            }
            $id = $entity->id;
            if ($id === null) {
                continue;
            }
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
            }
            $subscriptions->add($this->objects[$id]);
        }

        return $subscriptions;
    }

    /**
     * Removes every subscription of a recipient (account-deletion cleanup).
     *
     * The RESTRICT key requires the erasure to remove these rows before the person.
     *
     * @param int $userId Recipient user id
     * @throws DatabaseException When a delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws LogicException When the object collection cannot resolve its entity metadata
     * @throws WriteNotAllowedException When the caller cannot write the subscription row
     */
    public function deleteForUser(int $userId): void
    {
        foreach ($this->loadByColumn(EntityPushSubscription::user_id, $userId) as $id) {
            $this->objects[$id]->delete();
            unset($this[$id]);
        }
    }

    /**
     * Loads the subscription of an endpoint, if present.
     *
     * @param string $endpoint Browser push endpoint URL
     * @return ?ObjectPushSubscription The subscription, or null when the endpoint is unknown
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the endpoint column cannot be resolved
     * @throws LogicException When the object collection cannot resolve its entity metadata
     */
    private function find(string $endpoint): ?ObjectPushSubscription
    {
        foreach ($this->loadByColumn(EntityPushSubscription::endpoint, $endpoint) as $id) {
            return $this->objects[$id];
        }

        return null;
    }
}
