<?php

namespace Hilos\Database\Object\Item;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Hilos;

/**
 * Base Object class.
 *
 * Manages two Entity instances: current state and synced state.
 * Enables precise change tracking and saves only modified columns.
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
    /** @var TEntity current entity state */
    protected Entity $entity;

    /** @var TEntity synced entity state (from database) */
    protected Entity $entitySync;

    /**
     * Entity class for this Object. Child classes must define:
     *   public const string ENTITY_CLASS = EntityXxx::class;
     *
     * @var class-string<Entity>
     */
    public const string ENTITY_CLASS = '';

    /**
     * Prevents cloning of Object instances.
     */
    final protected function __clone(): void
    {
    }

    /**
     * Prevents direct instantiation. Use create() or fromEntity() instead.
     */
    final protected function __construct()
    {
    }

    /**
     * Create new empty object.
     *
     * @return static Object instance
     * @throws DatabaseException If ENTITY_CLASS not defined
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
     * Create object from entity.
     *
     * @param TEntity $entity Entity to wrap
     * @return static Object instance
     */
    public static function fromEntity(Entity $entity): static
    {
        $obj = new static();
        $obj->entity = $entity;
        $obj->entitySync = clone $entity;
        return $obj;
    }

    /**
     * Apply partial entity update from another process (DB_SYNC_UPDATED).
     * Wire format: keys are Entity::_columns, same as created row / fromRow.
     *
     * @param array<string, mixed> $row Column name => value (diff)
     */
    public function applyDbSyncEntityUpdate(array $row): void
    {
        $this->entity->applySyncPartialRow($row);
        $this->syncRelated();
    }

    /**
     * Debug info for var_dump/print_r.
     *
     * @return array<string, mixed> Entity data as array
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Sync changes to database.
     *
     * Saves only changed columns by comparing entity with entitySync.
     *
     * @throws DatabaseException If database operation fails
     */
    public function sync(): void
    {
        $isCreate = !$this->entity->isRelated();

        if ($this->entity->isRelated()) {
            $changedColumns = $this->getChangedColumns();
            $currentData = $this->entity->toArray();
            $diff = array_intersect_key($currentData, array_flip($changedColumns));
            $this->entity->saveDiff($this->entitySync);
            $this->entitySync = clone $this->entity;
            $result = $diff;
        } else {
            $this->entity->save();
            $this->entitySync = clone $this->entity;
            $result = $this->entity->toArray();
        }

        $this->broadcastDbSyncAfterSync($isCreate, $result);
    }

    /**
     * Mark entity as synced with database (without saving).
     *
     * Useful when entity is modified externally.
     */
    public function syncRelated(): void
    {
        if (!$this->entity->isRelated()) {
            $this->entity->flushRelated();
        }
        $this->entitySync = clone $this->entity;
    }

    /**
     * Delete entity from database and broadcast a delete sync with previous row data.
     *
     * Delete projections may need fields that disappear after the row is removed
     * (for example a settings key or any derived frontend/table lookup key).
     *
     * @throws DatabaseException If database operation fails
     */
    public function delete(): void
    {
        $collectionKey = static::getCollectionKey();
        $idString = $collectionKey !== '' ? $this->getIdString() : '';

        // Keep a tombstone row for DB_SYNC_DELETED consumers; it is no longer
        // available from the object collection after the physical delete.
        $row = $collectionKey !== '' && $idString !== '' ? $this->toArray() : [];

        $this->entity->delete();

        if ($collectionKey !== '' && $idString !== '') {
            $this->queueDbSyncDeleted($collectionKey, $idString, $row);
        }
    }

    /**
     * Check if there are unsaved changes.
     *
     * @return bool True if entity has unsaved changes
     */
    public function hasChanges(): bool
    {
        if (!$this->entity->isRelated()) {
            return true; // New entity not yet saved
        }

        return $this->entity->toArray() !== $this->entitySync->toArray();
    }

    /**
     * Get changed column names.
     *
     * @return list<string> List of changed column names
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
     * Revert changes (restore from entitySync).
     */
    public function revert(): void
    {
        if ($this->entity->isRelated()) {
            $this->entity = clone $this->entitySync;
        }
    }

    /**
     * Collection key for DB sync broadcast (e.g. ChatDbContext::bots).
     *
     * Return empty string to skip broadcast.
     *
     * @return string Collection key or empty string
     */
    protected static function getCollectionKey(): string
    {
        return '';
    }

    /**
     * Broadcast DB sync after sync (created or updated).
     *
     * @param bool $isCreate True if this was a create (new row)
     * @param array<string, mixed> $result Full row for create, diff for update
     * @throws ObjectGetIdStringNotImplementedException If getIdString() is not implemented or primary key is null
     */
    private function broadcastDbSyncAfterSync(bool $isCreate, array $result): void
    {
        $collectionKey = static::getCollectionKey();
        if ($collectionKey === '' || $result === []) {
            return;
        }

        $idString = $this->getIdString();
        if ($isCreate) {
            $this->queueDbSyncCreated($collectionKey, $idString, $result);
        } else {
            $this->queueDbSyncUpdated($collectionKey, $idString, $result);
        }
    }

    /**
     * Queue DB sync created signal (no-op if broadcast disabled).
     *
     * @param string $collectionKey Collection key for broadcast
     * @param string $idString Item ID as string
     * @param array<string, mixed> $row Full row data
     */
    private function queueDbSyncCreated(string $collectionKey, string $idString, array $row): void
    {
        Hilos::$sr?->queueDbSyncSignal(
            SignalConstants::DB_SYNC_CREATED,
            new DbSyncCreatedSignalData($collectionKey, $idString, $row),
        );
    }

    /**
     * Queue DB sync updated signal (no-op if broadcast disabled).
     *
     * @param string $collectionKey Collection key for broadcast
     * @param string $idString Item ID as string
     * @param array<string, mixed> $row Diff row data
     */
    private function queueDbSyncUpdated(string $collectionKey, string $idString, array $row): void
    {
        Hilos::$sr?->queueDbSyncSignal(
            SignalConstants::DB_SYNC_UPDATED,
            new DbSyncUpdatedSignalData($collectionKey, $idString, $row),
        );
    }

    /**
     * Queue DB sync deleted signal (no-op if broadcast disabled).
     *
     * @param string $collectionKey Collection key for broadcast
     * @param string $idString Item ID as string
     * @param array<string, mixed> $row Previous row data
     */
    private function queueDbSyncDeleted(string $collectionKey, string $idString, array $row): void
    {
        Hilos::$sr?->queueDbSyncSignal(
            SignalConstants::DB_SYNC_DELETED,
            new DbSyncDeletedSignalData($collectionKey, $idString, $row),
        );
    }

    /**
     * Check if entity is related to database.
     *
     * @return bool True if entity has DB row
     */
    public function isRelated(): bool
    {
        return $this->entity->isRelated();
    }

    /**
     * Convert to array. Override in child classes to customize output.
     *
     * @return array<string, mixed> Entity data
     */
    public function toArray(): array
    {
        return $this->entity->toArray();
    }

    /**
     * Get primary key column names as they appear in toArray() result.
     * Uses Entity::_primary. For simple keys matches array keys; for composite keys
     * child classes may override if Entity uses different naming (e.g. snake_case).
     *
     * @return list<string> Primary key column names
     */
    public function getPrimaryKeyArrayKeys(): array
    {
        $primaryKeys = is_array($this->entity::_primary) ? $this->entity::_primary : [$this->entity::_primary];
        return $primaryKeys;
    }

    /**
     * Get ID as string (for use as array key)
     * Supports composite keys by returning string representation (joined with ':')
     * Uses Entity::_primary to determine primary key column(s)
     *
     * @return string ID as string (for simple keys) or composite key representation
     * @throws ObjectGetIdStringNotImplementedException If ENTITY_CLASS does not implement getIdString() or primary key is null
     */
    public function getIdString(): string
    {
        $primaryKeys = $this->getPrimaryKeyArrayKeys();
        $parts = [];
        foreach ($primaryKeys as $column) {
            $value = $this->entity->$column
                ?? throw new ObjectGetIdStringNotImplementedException("Cannot get ID string: " . static::class . " primary key is null");
            $parts[] = (string)$value;
        }
        return implode(':', $parts);
    }

    /**
     * Magic getter - must be overridden in child classes.
     *
     * @param string $property Property name
     * @return mixed Property value
     * @throws DatabaseException If property does not exist or entity is misconfigured
     */
    public function __get(string $property): mixed
    {
        if ($property === 'entity' || $property === 'entitySync') {
            throw new DatabaseException('Final class wrongly configured - entity properties should not be accessed directly');
        }

        throw new DatabaseException("Property [{$property}] does not exist or is not accessible");
    }

    /**
     * Magic setter - must be overridden in child classes.
     *
     * @param string $property Property name
     * @param mixed $value Property value
     * @throws DatabaseException If property cannot be set or does not exist
     */
    public function __set(string $property, mixed $value): void
    {
        throw new DatabaseException("Property [{$property}] cannot be set or does not exist");
    }
}
