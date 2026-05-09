<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Entity\Item\EventUserRegistration as EntityEventUserRegistration;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * EventUserRegistration - Object wrapper for registration event detail.
 *
 * @extends Object_<EntityEventUserRegistration>
 *
 * @property int $eventId
 * @property int $targetUserId
 */
final class EventUserRegistration extends Object_
{
    public const string ENTITY_CLASS = EntityEventUserRegistration::class;

    public const string eventId = 'eventId';
    public const string targetUserId = 'targetUserId';

    /**
     * Returns the database collection key for registration event details.
     *
     * @return string Collection key (DbChatContext::eventUserRegistrations)
     */
    protected static function getCollectionKey(): string
    {
        return DbChatContext::eventUserRegistrations;
    }

    /**
     * Returns a registration detail property by name.
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
            default => parent::__get($property),
        };
    }

    /**
     * Sets a registration detail property.
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
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the registration detail object to an associative array.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::eventId => $this->entity->event_id,
            self::targetUserId => $this->entity->target_user_id,
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
