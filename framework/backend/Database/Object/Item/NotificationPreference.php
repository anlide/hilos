<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\NotificationPreference as EntityNotificationPreference;
use Hilos\Database\Object\Collection\NotificationPreferences;
use Hilos\Database\Object\Item\Object_;

/**
 * NotificationPreference object - wraps a NotificationPreference entity.
 *
 * One per-user channel opt-out row (HIL-485). A live object exists only for a
 * muted (user, channel) pair; enabling a channel deletes it. Carries no behaviour
 * beyond field access — the upsert/delete orchestration lives on
 * {@see NotificationPreferences}.
 *
 * @extends Object_<EntityNotificationPreference>
 *
 * @property-read ?int $id
 * @property ?int $userId
 * @property string $channel
 * @property bool $enabled
 * @property ?string $createdAt
 * @property ?string $updatedAt
 */
final class NotificationPreference extends Object_
{
    public const string ENTITY_CLASS = EntityNotificationPreference::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string channel = 'channel';
    public const string enabled = 'enabled';
    public const string createdAt = 'createdAt';
    public const string updatedAt = 'updatedAt';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::notificationPreferences)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::notificationPreferences;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, userId, channel, enabled, createdAt, updatedAt)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known NotificationPreference field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::channel => $this->entity->channel,
            self::enabled => $this->entity->enabled,
            self::createdAt => $this->entity->created_at,
            self::updatedAt => $this->entity->updated_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * @param string $property Property name (userId, channel, enabled, createdAt, updatedAt)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a NotificationPreference
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = $value === null ? null : (int)$value,
            self::channel => $this->entity->channel = (string)$value,
            self::enabled => $this->entity->enabled = (bool)$value,
            self::createdAt => $this->entity->created_at = $value === null ? null : (string)$value,
            self::updatedAt => $this->entity->updated_at = $value === null ? null : (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the preference to an associative array.
     *
     * @return array<string, mixed> Preference data (id, userId, channel, enabled, createdAt, updatedAt)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::channel => $this->entity->channel,
            self::enabled => $this->entity->enabled,
            self::createdAt => $this->entity->created_at,
            self::updatedAt => $this->entity->updated_at,
        ];
    }
}
