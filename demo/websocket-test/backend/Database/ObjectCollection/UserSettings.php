<?php

namespace Demo\WebSocketTest\Database\ObjectCollection;

use ArrayAccess;
use Countable;
use Demo\WebSocketTest\Database\Entity\UserSetting as EntityUserSetting;
use Demo\WebSocketTest\Database\EntityCollection\UserSettings as EntityUserSettings;
use Demo\WebSocketTest\Database\Object\UserSetting as ObjectUserSetting;
use Hilos\Database\Database;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Iterator;

/**
 * UserSettings Object Collection
 * Typed wrapper around Objects for UserSetting objects
 *
 * This is a convenience class that provides type safety and convenience methods
 * while using Objects internally.
 */
final class UserSettings extends Objects implements Iterator, ArrayAccess, Countable
{
    /** @var ObjectUserSetting[] $objects */
    protected array $objects = [];

    /**
     * Initialize collection with all UserSetting objects from database
     *
     * @return self
     * @throws DatabaseException
     */
    public static function initFullDB(): self
    {
        $self = new self();
        $EntityUserSettings = EntityUserSettings::initFullDB();

        foreach ($EntityUserSettings as $key => $EntityUserSetting) {
            $self->objects[$key] = ObjectUserSetting::fromEntity($EntityUserSetting);
        }

        return $self;
    }

    /**
     * Initialize collection with partial database loading (lazy loading enabled)
     *
     * @param int $strategy Lazy loading strategy (LAZY_STRATEGY_BATCH by default)
     * @return self
     */
    public static function initPartialDB(int $strategy = self::LAZY_STRATEGY_BATCH): self
    {
        $self = new self();
        $self->_allowLazyLoading = true;
        $self->_lazyStrategy = $strategy;
        $self->_allLoaded = false;
        return $self;
    }

    /**
     * Initialize empty collection
     *
     * @return self
     */
    public static function initEmpty(): self
    {
        $self = new self();
        $self->objects = [];
        return $self;
    }

    /**
     * Reload all UserSetting objects from database
     *
     * @throws DatabaseException
     */
    public function initAgainFullDB(): void
    {
        $this->objects = [];
        $EntityUserSettings = EntityUserSettings::initFullDB();

        foreach ($EntityUserSettings as $key => $EntityUserSetting) {
            $this->objects[$key] = ObjectUserSetting::fromEntity($EntityUserSetting);
        }

        $this->_allLoaded = true;
        $this->_allowLazyLoading = false;
    }

    /**
     * Get current UserSetting object
     *
     * @return ObjectUserSetting|null Current UserSetting object or null if invalid position
     */
    public function current(): ?ObjectUserSetting
    {
        return parent::current();
    }

    /**
     * Set UserSetting object at offset
     *
     * @param mixed $offset
     * @param ObjectUserSetting $value
     */
    public function offsetSet($offset, $value): void
    {
        if ($value instanceof ObjectUserSetting) {
            if ($offset === null) {
                $this->objects[] = $value;
            } else {
                $this->objects[$offset] = $value;
            }
        }
    }

    /**
     * Get UserSetting object at offset
     *
     * @param mixed $offset
     * @return ObjectUserSetting|null
     */
    public function offsetGet($offset): ?ObjectUserSetting
    {
        return parent::offsetGet($offset);
    }

    /**
     * Lazy load UserSetting object by key
     *
     * @param int|string $key UserSetting ID
     * @return ObjectUserSetting|null
     * @throws DatabaseException
     */
    protected function lazyLoadObject(int|string $key): ?ObjectUserSetting
    {
        $EntityUserSetting = EntityUserSetting::getById((int)$key);
        return $EntityUserSetting !== null ? ObjectUserSetting::fromEntity($EntityUserSetting) : null;
    }

    /**
     * Lazy load count of UserSetting objects from database
     *
     * @return int
     * @throws DatabaseException
     */
    protected function lazyLoadCount(): int
    {
        // Use Entity to get count
        $resultSetCollection = Database::sql(
            "SELECT COUNT(*) as count FROM `" . EntityUserSetting::_table . "`"
        );
        $firstResultSet = $resultSetCollection->first();

        if ($firstResultSet === null) {
            return 0;
        }

        $row = $firstResultSet->first();
        return $row !== null ? (int)($row['count'] ?? 0) : 0;
    }

    /**
     * Lazy load all UserSetting objects from database (for batch strategy)
     *
     * @throws DatabaseException
     */
    protected function lazyLoadAll(): void
    {
        $EntityUserSettings = EntityUserSettings::initFullDB();

        foreach ($EntityUserSettings as $key => $EntityUserSetting) {
            if (!isset($this->objects[$key])) {
                $this->objects[$key] = ObjectUserSetting::fromEntity($EntityUserSetting);
            }
        }

        $this->_allLoaded = true;
    }

    /**
     * Get table name from Entity
     * 
     * @return string Table name
     */
    protected function getTableName(): string
    {
        return EntityUserSetting::_table;
    }
}
