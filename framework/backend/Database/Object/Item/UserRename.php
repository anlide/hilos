<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\UserRename as EntityUserRename;
use Hilos\Database\Object\Collection\UserRenames as ObjectUserRenames;

/**
 * UserRename - Object wrapper for one row of the framework rename journal.
 *
 * Business logic layer with change tracking.
 *
 * @extends Object_<EntityUserRename>
 *
 * @property-read ?int $id
 * @property int $userId
 * @property ?int $renamedByUserId
 * @property string $oldName
 * @property string $newName
 * @property string $renamedAt
 */
class UserRename extends Object_
{
    public const string ENTITY_CLASS = EntityUserRename::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUserRenames::class;

    public const string id = 'id';
    public const string userId = 'userId';
    public const string renamedByUserId = 'renamedByUserId';
    public const string oldName = 'oldName';
    public const string newName = 'newName';
    public const string renamedAt = 'renamedAt';

    /**
     * Returns the value of a rename journal object property by name.
     *
     * @param string $property Property name (id, userId, renamedByUserId, oldName, newName, renamedAt)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::renamedByUserId => $this->entity->renamed_by_user_id,
            self::oldName => $this->entity->old_name,
            self::newName => $this->entity->new_name,
            self::renamedAt => $this->entity->renamed_at,
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
            self::userId => $this->entity->user_id = (int)$value,
            self::renamedByUserId => $this->entity->renamed_by_user_id = $value === null ? null : (int)$value,
            self::oldName => $this->entity->old_name = (string)$value,
            self::newName => $this->entity->new_name = (string)$value,
            self::renamedAt => $this->entity->renamed_at = (string)$value,
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
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::renamedByUserId => $this->entity->renamed_by_user_id,
            self::oldName => $this->entity->old_name,
            self::newName => $this->entity->new_name,
            self::renamedAt => $this->entity->renamed_at,
        ];
    }
}
