<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Item;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Item\EventMessage as EntityEventMessage;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * EventMessage - Object wrapper for message event detail.
 *
 * @extends Object_<EntityEventMessage>
 *
 * @property int $eventId
 * @property ?int $authorUserId
 * @property ?int $authorBotId
 * @property string $message
 */
final class EventMessage extends Object_
{
    public const string ENTITY_CLASS = EntityEventMessage::class;

    public const string eventId = 'eventId';
    public const string authorUserId = 'authorUserId';
    public const string authorBotId = 'authorBotId';
    public const string message = 'message';

    /**
     * Returns the database collection key for message event details.
     *
     * @return string Collection key (ChatDbContext::eventMessages)
     */
    protected static function getCollectionKey(): string
    {
        return ChatDbContext::eventMessages;
    }

    /**
     * Returns an event message property by name.
     *
     * @param string $property Property name
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity access or sync fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::eventId => $this->entity->event_id,
            self::authorUserId => $this->entity->author_user_id,
            self::authorBotId => $this->entity->author_bot_id,
            self::message => $this->entity->message,
            default => parent::__get($property),
        };
    }

    /**
     * Sets an event message property.
     *
     * @param string $property Property name
     * @param mixed $value New value
     * @throws DatabaseException If entity access or sync fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::eventId => $this->entity->event_id = (int)$value,
            self::authorUserId => $this->entity->author_user_id = $value === null ? null : (int)$value,
            self::authorBotId => $this->entity->author_bot_id = $value === null ? null : (int)$value,
            self::message => $this->entity->message = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the message detail object to an associative array.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::eventId => $this->entity->event_id,
            self::authorUserId => $this->entity->author_user_id,
            self::authorBotId => $this->entity->author_bot_id,
            self::message => $this->entity->message,
        ];
    }

    /**
     * Returns event_id as the object collection key.
     *
     * @return string Primary key value
     */
    public function getIdString(): string
    {
        return (string)$this->entity->event_id;
    }

    /**
     * Returns object array key names for the event_id primary key.
     *
     * @return list<string> Primary key field names
     */
    public function getPrimaryKeyArrayKeys(): array
    {
        return [self::eventId];
    }
}
