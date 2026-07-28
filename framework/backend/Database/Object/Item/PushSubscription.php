<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\PushSubscription as EntityPushSubscription;
use Hilos\Database\Object\Item\Object_;

/**
 * PushSubscription object - wraps a PushSubscription entity.
 *
 * One device's browser push subscription (HIL-199). Carries no behaviour beyond
 * field access — the upsert/delete orchestration lives on
 * {@see \Hilos\Database\Object\Collection\PushSubscriptions}. A push delivery reads
 * {@see endpoint} plus the {@see p256dh} / {@see auth} keys to send to the endpoint.
 *
 * @extends Object_<EntityPushSubscription>
 *
 * @property-read ?int $id
 * @property ?int $userId
 * @property string $endpoint
 * @property string $p256dh
 * @property string $auth
 * @property ?string $userAgent
 * @property ?string $createdAt
 * @property ?string $lastSeenAt
 */
final class PushSubscription extends Object_
{
    public const string ENTITY_CLASS = EntityPushSubscription::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string endpoint = 'endpoint';
    public const string p256dh = 'p256dh';
    public const string auth = 'auth';
    public const string userAgent = 'userAgent';
    public const string createdAt = 'createdAt';
    public const string lastSeenAt = 'lastSeenAt';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::pushSubscriptions)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::pushSubscriptions;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, userId, endpoint, p256dh, auth, userAgent, createdAt, lastSeenAt)
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
            self::createdAt => $this->entity->created_at,
            self::lastSeenAt => $this->entity->last_seen_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * @param string $property Property name (userId, endpoint, p256dh, auth, userAgent, createdAt, lastSeenAt)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a PushSubscription
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = $value === null ? null : (int)$value,
            self::endpoint => $this->entity->endpoint = (string)$value,
            self::p256dh => $this->entity->p256dh = (string)$value,
            self::auth => $this->entity->auth = (string)$value,
            self::userAgent => $this->entity->user_agent = $value === null ? null : (string)$value,
            self::createdAt => $this->entity->created_at = $value === null ? null : (string)$value,
            self::lastSeenAt => $this->entity->last_seen_at = $value === null ? null : (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the subscription to an associative array.
     *
     * @return array<string, mixed> Subscription data (id, userId, endpoint, p256dh, auth, userAgent, createdAt, lastSeenAt)
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
            self::createdAt => $this->entity->created_at,
            self::lastSeenAt => $this->entity->last_seen_at,
        ];
    }
}
