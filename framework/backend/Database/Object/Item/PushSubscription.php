<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Core\Http\DeviceName;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Database\Entity\Item\PushSubscription as EntityPushSubscription;
use Hilos\Database\Object\Collection\PushSubscriptions as ObjectPushSubscriptions;

/**
 * PushSubscription object - wraps a PushSubscription entity.
 *
 * One device's browser push subscription, with renewal of a captured persisted identity.
 * A push delivery reads
 * {@see endpoint} plus the {@see p256dh} / {@see auth} keys to send to the endpoint.
 *
 * @extends Object_<EntityPushSubscription>
 *
 * @property-read ?int $id
 * @property int $userId
 * @property string $endpoint
 * @property string $p256dh
 * @property string $auth
 * @property ?string $userAgent
 * @property ?string $deviceName
 * @property string $endpointHash
 * @property ?int $goneAt
 * @property int $createdAt
 * @property ?int $lastSeenAt
 * @property int $revision
 */
class PushSubscription extends Object_
{
    public const string ENTITY_CLASS = EntityPushSubscription::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectPushSubscriptions::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string endpoint = 'endpoint';
    public const string p256dh = 'p256dh';
    public const string auth = 'auth';
    public const string userAgent = 'userAgent';
    public const string deviceName = 'deviceName';
    public const string endpointHash = 'endpointHash';
    public const string goneAt = 'goneAt';
    public const string createdAt = 'createdAt';
    public const string lastSeenAt = 'lastSeenAt';
    public const string revision = 'revision';

    /**
     * Renews only this saved row, refreshing both entity and diff baseline before editing.
     *
     * @param int $userId Subscribing person
     * @param string $endpoint Captured endpoint attribute
     * @param string $p256dh Client public key
     * @param string $auth Client auth secret
     * @param ?string $userAgent Device user agent, or null
     * @return bool Whether the captured owned identity still existed and was renewed
     * @throws HilosException When ownership, persistence or announcement refuses the write
     */
    public function renewOwned(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): bool
    {
        $entity = static::ENTITY_CLASS::get([EntityPushSubscription::id => $this->id])->first();
        if ($entity === null) {
            return false;
        }

        Database::assertCanWrite($entity::getDatabase()->connectionIndex);
        $this->restoreSaved($entity->toArray(), related: true);
        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            $this->getIdString(),
            $this->touchedSetKeys(...),
            TruthSourceOperation::Update,
            $this,
        );
        if ($entity->endpoint !== $endpoint || $entity->user_id !== $userId) {
            return false;
        }

        $this->p256dh = $p256dh;
        $this->auth = $auth;
        $this->userAgent = $userAgent;
        $this->deviceName = DeviceName::fromUserAgent($userAgent);
        $this->goneAt = null;
        $this->lastSeenAt = TimeHelper::nowMs();
        $this->revision = $entity->revision + 1;
        $this->sync();

        return true;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, userId, endpoint, p256dh, auth, userAgent, createdAt, lastSeenAt, revision)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known PushSubscription field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::endpoint => $this->entity->endpoint,
            self::p256dh => $this->entity->p256dh,
            self::auth => $this->entity->auth,
            self::userAgent => $this->entity->user_agent,
            self::deviceName => $this->entity->device_name,
            self::endpointHash => $this->entity->endpoint_hash,
            self::goneAt => $this->entity->gone_at,
            self::createdAt => $this->entity->created_at,
            self::lastSeenAt => $this->entity->last_seen_at,
            self::revision => $this->entity->revision,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * @param string $property Property name (userId, endpoint, p256dh, auth, userAgent, createdAt, lastSeenAt, revision)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a PushSubscription
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::endpoint => $this->entity->endpoint = (string)$value,
            self::p256dh => $this->entity->p256dh = (string)$value,
            self::auth => $this->entity->auth = (string)$value,
            self::userAgent => $this->entity->user_agent = $value === null ? null : (string)$value,
            self::deviceName => $this->entity->device_name = $value === null ? null : (string)$value,
            self::endpointHash => $this->entity->endpoint_hash = (string)$value,
            self::goneAt => $this->entity->gone_at = $value === null ? null : (int)$value,
            self::createdAt => $this->entity->created_at = (int)$value,
            self::lastSeenAt => $this->entity->last_seen_at = $value === null ? null : (int)$value,
            self::revision => $this->entity->revision = (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the subscription to an associative array.
     *
     * @return array<string, mixed> Subscription data (id, userId, endpoint, p256dh, auth, userAgent, createdAt, lastSeenAt, revision)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::endpoint => $this->entity->endpoint,
            self::p256dh => $this->entity->p256dh,
            self::auth => $this->entity->auth,
            self::userAgent => $this->entity->user_agent,
            self::deviceName => $this->entity->device_name,
            self::endpointHash => $this->entity->endpoint_hash,
            self::goneAt => $this->entity->gone_at,
            self::createdAt => $this->entity->created_at,
            self::lastSeenAt => $this->entity->last_seen_at,
            self::revision => $this->entity->revision,
        ];
    }
}
