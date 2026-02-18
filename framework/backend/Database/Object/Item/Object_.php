<?php

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;

/**
 * Base Object class
 * Manages two Entity instances: current state and synced state
 * Enables precise change tracking and saves only modified columns
 *
 * Child classes must declare ENTITY_CLASS constant.
 *
 * @template TEntity of Entity
 *
 * @property TEntity $entity Current entity state
 * @property TEntity $entitySync Synced entity state (from database)
 */
abstract class Object_
{
    /** @var TEntity */
    protected Entity $entity;
    /** @var TEntity */
    protected Entity $entitySync;

    /**
     * Entity class for this Object. Child classes must define:
     *   public const string ENTITY_CLASS = EntityXxx::class;
     *
     * @var class-string<Entity>
     */
    public const string ENTITY_CLASS = '';

    final protected function __clone(): void
    {
    }

    final protected function __construct()
    {
    }

    /**
     * Create new empty object
     *
     * @return static
     */
    public static function create(): static
    {
        $entityClass = static::ENTITY_CLASS;
        if ($entityClass === '') {
            throw new DatabaseException(static::class . ' must define ENTITY_CLASS constant');
        }
        $obj = new static();
        $obj->entity = $entityClass::getEmpty();
        $obj->entitySync = clone $obj->entity;
        return $obj;
    }

    /**
     * Create object from entity
     *
     * @param TEntity $entity
     * @return static
     */
    public static function fromEntity(Entity $entity): static
    {
        $obj = new static();
        $obj->entity = $entity;
        $obj->entitySync = clone $entity;
        return $obj;
    }

    /**
     * Debug info
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Sync changes to database
     * Saves only changed columns by comparing entity with entitySync
     *
     * @throws DatabaseException If database operation fails
     */
    public function sync(): void
    {
        if ($this->entity->isRelated()) {
            // Entity exists in database, save only differences
            $this->entity->saveDiff($this->entitySync);
        } else {
            // New entity, insert into database
            $this->entity->save();
        }

        // Update synced state
        $this->entitySync = clone $this->entity;
    }

    /**
     * Mark entity as synced with database (without saving)
     * Useful when entity is modified externally
     */
    public function syncRelated(): void
    {
        if (!$this->entity->isRelated()) {
            $this->entity->flushRelated();
        }
        $this->entitySync = clone $this->entity;
    }

    /**
     * Delete entity from database
     *
     * @throws DatabaseException If database operation fails
     */
    public function delete(): void
    {
        $this->entity->delete();
    }

    /**
     * Check if there are unsaved changes
     */
    public function hasChanges(): bool
    {
        if (!$this->entity->isRelated()) {
            return true; // New entity not yet saved
        }

        return $this->entity->toArray() !== $this->entitySync->toArray();
    }

    /**
     * Get changed column names
     */
    public function getChangedColumns(): array
    {
        if (!$this->entity->isRelated()) {
            return []; // New entity, all columns are "new"
        }

        $changed = [];
        $currentData = $this->entity->toArray();
        $syncedData = $this->entitySync->toArray();

        foreach ($currentData as $column => $value) {
            if ($value !== ($syncedData[$column] ?? null)) {
                $changed[] = $column;
            }
        }

        return $changed;
    }

    /**
     * Revert changes (restore from entitySync)
     */
    public function revert(): void
    {
        if ($this->entity->isRelated()) {
            $this->entity = clone $this->entitySync;
        }
    }

    /**
     * Check if entity is related to database
     */
    public function isRelated(): bool
    {
        return $this->entity->isRelated();
    }

    /**
     * Convert to array
     * Override in child classes to customize output
     */
    public function toArray(): array
    {
        return $this->entity->toArray();
    }

    /**
     * Get ID as string (for use as array key)
     * Supports composite keys by returning string representation (joined with ':')
     * Uses Entity::_primary to determine primary key column(s)
     *
     * @return string ID as string (for simple keys) or composite key representation
     * @throws DatabaseException If primary key is null
     */
    public function getIdString(): string
    {
        $primaryKeys = is_array($this->entity::_primary) ? $this->entity::_primary : [$this->entity::_primary];
        $parts = [];
        foreach ($primaryKeys as $column) {
            $value = $this->entity->$column;
            if ($value === null) {
                throw new DatabaseException("Cannot get ID string: " . static::class . " primary key is null");
            }
            $parts[] = (string)$value;
        }
        return implode(':', $parts);
    }

    /**
     * Magic getter - must be overridden in child classes
     *
     * @throws DatabaseException
     */
    public function __get(string $property): mixed
    {
        if ($property === 'entity' || $property === 'entitySync') {
            throw new DatabaseException('Final class wrongly configured - entity properties should not be accessed directly');
        }

        throw new DatabaseException("Property [{$property}] does not exist or is not accessible");
    }

    /**
     * Magic setter - must be overridden in child classes
     *
     * @throws DatabaseException
     */
    public function __set(string $property, mixed $value): void
    {
        throw new DatabaseException("Property [{$property}] cannot be set or does not exist");
    }
}
