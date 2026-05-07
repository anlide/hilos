<?php

namespace Demo\Chat\Database\Object\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Entity\Item\Event as EntityEvent;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * Event - Object wrapper for event entity.
 *
 * Auto-generated from Entity: Demo\Chat\Database\Entity\Item\Event
 *
 * @extends Object_<EntityEvent>
 *
 * @property-read ?int $id
 * @property ?int $userId
 * @property ?int $botId
 * @property string $type
 * @property string $timestamp
 * @property ?string $data
 */
final class Event extends Object_
{
    public const string ENTITY_CLASS = EntityEvent::class;

    public const string id = 'id';
    public const string userId = 'userId';
    public const string botId = 'botId';
    public const string type = 'type';
    public const string timestamp = 'timestamp';
    public const string data = 'data';
    public const string dataMessage = 'message';

    /**
     * Return collection key used for DbChatContext lookup.
     *
     * @return string Collection key (DbChatContext::events)
     */
    protected static function getCollectionKey(): string
    {
        return DbChatContext::events;
    }

    /**
     * Returns the value of an event object property by name.
     *
     * @param string $property Property name (id, userId, type, timestamp, data)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity access or sync fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::botId => $this->entity->bot_id,
            self::type => $this->entity->type,
            self::timestamp => $this->entity->timestamp,
            self::data => $this->entity->data,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of an event object property.
     *
     * @param string $property Property name to set
     * @param mixed $value New value (cast to appropriate type)
     * @throws DatabaseException If entity access or sync fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = $value === null ? null : (int)$value,
            self::botId => $this->entity->bot_id = $value === null ? null : (int)$value,
            self::type => $this->entity->type = (string)$value,
            self::timestamp => $this->entity->timestamp = (string)$value,
            self::data => $this->entity->data = $value === null ? null : (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the event object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::botId => $this->entity->bot_id,
            self::type => $this->entity->type,
            self::timestamp => $this->entity->timestamp,
            self::data => $this->entity->data,
        ];
    }
}
