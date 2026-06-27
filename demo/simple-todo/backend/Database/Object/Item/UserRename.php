<?php

namespace Demo\SimpleTodo\Database\Object\Item;

use Demo\SimpleTodo\Database\Entity\Item\UserRename as EntityUserRename;
use Demo\SimpleTodo\Database\TodoDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * UserRename - Object wrapper for the user_rename entity.
 *
 * Business logic layer with change tracking.
 *
 * @extends Object_<EntityUserRename>
 *
 * @property-read ?int $id
 * @property int $targetUserId
 * @property string $oldName
 * @property string $newName
 * @property string $timestamp
 */
final class UserRename extends Object_
{
    public const string ENTITY_CLASS = EntityUserRename::class;

    public const string id = 'id';
    public const string targetUserId = 'targetUserId';
    public const string oldName = 'oldName';
    public const string newName = 'newName';
    public const string timestamp = 'timestamp';

    /**
     * Returns the database collection key for this object type.
     *
     * @return string Collection key (TodoDbContext::userRenames)
     */
    protected static function getCollectionKey(): string
    {
        return TodoDbContext::userRenames;
    }

    /**
     * Returns the value of a rename-audit object property by name.
     *
     * @param string $property Property name (id, targetUserId, oldName, newName, timestamp)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::targetUserId => $this->entity->target_user_id,
            self::oldName => $this->entity->old_name,
            self::newName => $this->entity->new_name,
            self::timestamp => $this->entity->timestamp,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a rename-audit object property.
     *
     * @param string $property Property name to set
     * @param mixed $value New value (cast to appropriate type)
     * @throws DatabaseException If entity sync fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::targetUserId => $this->entity->target_user_id = (int)$value,
            self::oldName => $this->entity->old_name = (string)$value,
            self::newName => $this->entity->new_name = (string)$value,
            self::timestamp => $this->entity->timestamp = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the rename-audit object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::targetUserId => $this->entity->target_user_id,
            self::oldName => $this->entity->old_name,
            self::newName => $this->entity->new_name,
            self::timestamp => $this->entity->timestamp,
        ];
    }
}
