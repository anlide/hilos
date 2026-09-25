<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Http\DeviceName;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\PushSubscriptions as EntityPushSubscriptions;
use Hilos\Database\Entity\Item\PushSubscription as EntityPushSubscription;
use Hilos\Database\Object\Item\PushSubscription as ObjectPushSubscription;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * PushSubscriptions object collection - the per-device push-subscription accessor (HIL-199).
 *
 * Persistence primitives for browser push subscriptions, keyed by the device
 * `endpoint` (UNIQUE). {@see subscribe()} is the opt-in write — it upserts the row
 * for an endpoint (rotated keys or a new owner replace it in place);
 * {@see unsubscribeOwned()} removes the acting device, while {@see markGone()} keeps a
 * transport-expired endpoint visible to its owner. {@see forUser()} lists live endpoints
 * for the push delivery channel to send to; {@see deleteForUser()} clears a user's
 * rows on account deletion (best-effort, soft ref).
 *
 * @extends Objects<ObjectPushSubscription>
 * @method ObjectPushSubscription|null current()
 * @method ObjectPushSubscription|null first()
 * @method ObjectPushSubscription|null last()
 * @method ObjectPushSubscription|null get(int|string $key)
 * @method ObjectPushSubscription|null offsetGet(mixed $offset)
 */
final class PushSubscriptions extends Objects
{
    public const string OBJECT_CLASS = ObjectPushSubscription::class;
    public const string ENTITY_COLLECTION_CLASS = EntityPushSubscriptions::class;
    public const string COLLECTION_KEY = HilosDbContext::pushSubscriptions;

    /**
     * Registers a device's push subscription, upserting by endpoint (HIL-199).
     *
     * The endpoint is the device identity (UNIQUE): a re-subscribe with rotated
     * keys, or the same endpoint arriving under a new owner, updates the one row in
     * place rather than inserting a duplicate. `last_seen_at` is stamped on every
     * write so a resubscribe refreshes liveness.
     *
     * @param int $userId Subscribing user id
     * @param string $endpoint Browser push endpoint URL (device identity)
     * @param string $p256dh Client public key (base64url)
     * @param string $auth Client auth secret (base64url)
     * @param ?string $userAgent Subscribing device user agent, or null
     * @throws EmptyValueException When the endpoint is empty
     * @throws DatabaseException When the lookup or write query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function subscribe(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): void
    {
        if ($endpoint === '') {
            throw new EmptyValueException('Push subscription endpoint is required');
        }

        $now = TimeHelper::getSqlDateTime();
        $subscription = $this->find($endpoint);

        if ($subscription === null) {
            $subscription = ObjectPushSubscription::create();
            $subscription->endpoint = $endpoint;
            $subscription->createdAt = $now;
        }

        $subscription->userId = $userId;
        $subscription->p256dh = $p256dh;
        $subscription->auth = $auth;
        $subscription->userAgent = $userAgent;
        $subscription->deviceName = DeviceName::fromUserAgent($userAgent);
        $subscription->endpointHash = hash('sha256', $endpoint);
        $subscription->goneAt = null;
        $subscription->lastSeenAt = $now;
        $subscription->sync();

        if ($subscription->id !== null) {
            $this[$subscription->id] = $subscription;
        }
    }

    /**
     * Marks transport-expired endpoints while keeping their device rows visible.
     *
     * @param list<string> $endpoints Endpoints reported gone by the push service
     * @return int Rows newly marked gone
     * @throws DatabaseException When a lookup or write query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     */
    public function markGone(array $endpoints): int
    {
        $marked = 0;
        $goneAt = TimeHelper::getSqlDateTime();
        foreach (array_unique($endpoints) as $endpoint) {
            if ($endpoint === '') {
                continue;
            }
            $subscription = $this->find($endpoint);
            if ($subscription === null || $subscription->goneAt !== null) {
                continue;
            }
            $subscription->goneAt = $goneAt;
            $subscription->sync();
            $marked++;
        }

        return $marked;
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
     * @return list<ObjectPushSubscription> The recipient's subscriptions (empty when none, or the table is not activated)
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function forUser(int $userId): array
    {
        if (Schema::getTable(EntityPushSubscription::_table) === null) {
            return [];
        }

        $entities = EntityPushSubscription::get([EntityPushSubscription::user_id => $userId]);

        $subscriptions = [];
        foreach ($entities as $entity) {
            if ($entity->gone_at !== null) {
                continue;
            }
            $id = $entity->id;
            if ($id === null) {
                continue;
            }
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, ObjectPushSubscription::fromEntity($entity));
            }
            $subscriptions[] = $this->objects[$id];
        }

        return $subscriptions;
    }

    /**
     * Removes every subscription of a recipient (account-deletion cleanup).
     *
     * Best-effort: `user_id` is a soft ref with no cascade, so the deleting flow
     * clears the recipient's rows explicitly.
     *
     * @param int $userId Recipient user id
     * @throws DatabaseException When a delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        $entities = EntityPushSubscription::get([EntityPushSubscription::user_id => $userId]);
        foreach ($entities as $entity) {
            $id = $entity->id;
            $subscription = $id !== null && isset($this->objects[$id])
                ? $this->objects[$id]
                : ObjectPushSubscription::fromEntity($entity);
            $subscription->delete();
            if ($id !== null) {
                unset($this[$id]);
            }
        }
    }

    /**
     * Loads the subscription of an endpoint, if present.
     *
     * @param string $endpoint Browser push endpoint URL
     * @return ?ObjectPushSubscription The subscription, or null when the endpoint is unknown
     * @throws DatabaseException When the lookup query fails
     */
    private function find(string $endpoint): ?ObjectPushSubscription
    {
        $entity = EntityPushSubscription::get([EntityPushSubscription::endpoint => $endpoint])->first();

        if ($entity === null || $entity->id === null) {
            return null;
        }

        if (!isset($this->objects[$entity->id])) {
            $this->hydrate($entity->id, ObjectPushSubscription::fromEntity($entity));
        }

        return $this->objects[$entity->id];
    }
}
