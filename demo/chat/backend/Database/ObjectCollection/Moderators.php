<?php

namespace Demo\Chat\Database\ObjectCollection;

use ArrayAccess;
use Countable;
use Demo\Chat\Database\Entity\Moderator as EntityModerator;
use Demo\Chat\Database\EntityCollection\Moderators as EntityModerators;
use Demo\Chat\Database\Object\Moderator as ObjectModerator;
use Hilos\Database\Database;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Iterator;

/**
 * Moderators Object Collection
 * Typed wrapper around Objects for Moderator objects
 *
 * This is a convenience class that provides type safety and convenience methods
 * while using Objects internally.
 */
final class Moderators extends Objects implements Iterator, ArrayAccess, Countable
{
    /** @var ObjectModerator[] $objects */
    protected array $objects = [];

    /**
     * Load all Moderator objects from database
     * Clears existing objects and loads all from database
     *
     * @throws DatabaseException
     */
    public function loadAllFromDB(): void
    {
        $this->objects = [];
        $EntityModerators = EntityModerators::initFullDB();
        foreach ($EntityModerators as $key => $EntityModerator) {
            $this->objects[$key] = ObjectModerator::fromEntity($EntityModerator);
        }
        $this->_allLoaded = true;
        $this->_allowLazyLoading = false;
    }

    /**
     * Initialize empty collection
     *
     * @return static
     */
    public static function initEmpty(): static
    {
        $self = new self();
        $self->objects = [];
        return $self;
    }

    /**
     * Get table name from Entity
     * 
     * @return string Table name
     */
    public function getTableName(): string
    {
        return EntityModerator::_table;
    }

    /**
     * Get current Moderator object
     *
     * @return ?ObjectModerator Current Moderator object or null if invalid position
     */
    public function current(): ?ObjectModerator
    {
        return parent::current();
    }

    /**
     * Set Moderator object at offset
     *
     * @param mixed $offset
     * @param ObjectModerator $value
     */
    public function offsetSet($offset, $value): void
    {
        if ($value instanceof ObjectModerator) {
            if ($offset === null) {
                $this->objects[] = $value;
            } else {
                $this->objects[$offset] = $value;
            }
        }
    }

    /**
     * Get Moderator object at offset
     *
     * @param mixed $offset
     * @return ?ObjectModerator
     */
    public function offsetGet($offset): ?ObjectModerator
    {
        return parent::offsetGet($offset);
    }

    /**
     * Lazy load Moderator object by key
     *
     * @param int|string $key Moderator ID
     * @return ?ObjectModerator
     * @throws DatabaseException
     */
    protected function lazyLoadObject(int|string $key): ?ObjectModerator
    {
        $EntityModerator = EntityModerator::getById((int)$key);
        return $EntityModerator !== null ? ObjectModerator::fromEntity($EntityModerator) : null;
    }

    /**
     * Lazy load count of Moderator objects from database
     *
     * @return int
     * @throws DatabaseException
     */
    protected function lazyLoadCount(): int
    {
        // Use Entity to get count
        $resultSetCollection = Database::sql(
            "SELECT COUNT(*) as count FROM `" . EntityModerator::_table . "`"
        );
        $firstResultSet = $resultSetCollection->first();

        if ($firstResultSet === null) {
            return 0;
        }

        $row = $firstResultSet->first();
        return $row !== null ? (int)($row['count'] ?? 0) : 0;
    }

    /**
     * Lazy load all Moderator objects from database (for batch strategy)
     *
     * @throws DatabaseException
     */
    protected function lazyLoadAll(): void
    {
        $EntityModerators = EntityModerators::initFullDB();

        foreach ($EntityModerators as $key => $EntityModerator) {
            if (!isset($this->objects[$key])) {
                $this->objects[$key] = ObjectModerator::fromEntity($EntityModerator);
            }
        }

        $this->_allLoaded = true;
    }
}
