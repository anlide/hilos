<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Item;

use Demo\Chat\Database\Entity\Item\UserRename as EntityUserRename;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\UserRename as FrameworkUserRename;

/**
 * UserRename - Object wrapper for a row of chat's rename journal: the framework's fields and the
 * event of the feed that shows the rename.
 *
 * @property ?int $eventId
 */
final class UserRename extends FrameworkUserRename
{
    public const string ENTITY_CLASS = EntityUserRename::class;

    public const string eventId = 'eventId';

    /**
     * Returns the value of a rename journal object property by name.
     *
     * @param string $property Property name (eventId or a framework field)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::eventId => $this->entity->event_id,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a rename journal object property.
     *
     * @param string $property Property name to set
     * @param mixed $value New value (cast to appropriate type)
     * @throws DatabaseException If entity sync fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::eventId => $this->entity->event_id = $value === null ? null : (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the rename journal object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            self::eventId => $this->entity->event_id,
        ];
    }
}
