<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Item;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Item\EventAttachment as EntityEventAttachment;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * EventAttachment - Object wrapper for the link between a message event and a registry file.
 *
 * @extends Object_<EntityEventAttachment>
 *
 * @property-read ?int $id
 * @property int $eventId
 * @property int $fileId
 */
final class EventAttachment extends Object_
{
    public const string ENTITY_CLASS = EntityEventAttachment::class;

    public const string id = 'id';
    public const string eventId = 'eventId';
    public const string fileId = 'fileId';

    /**
     * Returns the database collection key for published attachments.
     *
     * @return string Collection key (ChatDbContext::eventAttachments)
     */
    protected static function getCollectionKey(): string
    {
        return ChatDbContext::eventAttachments;
    }

    /**
     * Returns an event attachment property by name.
     *
     * @param string $property Property name
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity access or sync fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::eventId => $this->entity->event_id,
            self::fileId => $this->entity->file_id,
            default => parent::__get($property),
        };
    }

    /**
     * Sets an event attachment property.
     *
     * @param string $property Property name
     * @param mixed $value New value
     * @throws DatabaseException If entity access or sync fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::eventId => $this->entity->event_id = (int)$value,
            self::fileId => $this->entity->file_id = (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the event attachment object to an associative array.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::eventId => $this->entity->event_id,
            self::fileId => $this->entity->file_id,
        ];
    }
}
