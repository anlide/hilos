<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Item;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Item\EventUserRename as EntityEventUserRename;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * EventUserRename - Object wrapper for user rename event detail.
 *
 * @extends Object_<EntityEventUserRename>
 *
 * @property int $eventId
 * @property int $targetUserId
 * @property ?int $actorUserId
 * @property string $oldName
 * @property string $newName
 */
final class EventUserRename extends Object_
{
    public const string ENTITY_CLASS = EntityEventUserRename::class;

    public const string eventId = 'eventId';
    public const string targetUserId = 'targetUserId';
    public const string actorUserId = 'actorUserId';
    public const string oldName = 'oldName';
    public const string newName = 'newName';

    /**
     * Returns the database collection key for rename event details.
     *
     * @return string Collection key (ChatDbContext::eventUserRenames)
     */
    protected static function getCollectionKey(): string
    {
        return ChatDbContext::eventUserRenames;
    }

    /**
     * Returns a rename detail property by name.
     *
     * @param string $property Property name
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity access or sync fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::eventId => $this->entity->event_id,
            self::targetUserId => $this->entity->target_user_id,
            self::actorUserId => $this->entity->actor_user_id,
            self::oldName => $this->entity->old_name,
            self::newName => $this->entity->new_name,
            default => parent::__get($property),
        };
    }

    /**
     * Sets a rename detail property.
     *
     * @param string $property Property name
     * @param mixed $value New value
     * @throws DatabaseException If entity access or sync fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::eventId => $this->entity->event_id = (int)$value,
            self::targetUserId => $this->entity->target_user_id = (int)$value,
            self::actorUserId => $this->entity->actor_user_id = $value === null ? null : (int)$value,
            self::oldName => $this->entity->old_name = (string)$value,
            self::newName => $this->entity->new_name = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the rename detail object to an associative array.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::eventId => $this->entity->event_id,
            self::targetUserId => $this->entity->target_user_id,
            self::actorUserId => $this->entity->actor_user_id,
            self::oldName => $this->entity->old_name,
            self::newName => $this->entity->new_name,
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
