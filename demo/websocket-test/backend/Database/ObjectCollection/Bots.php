<?php

namespace Demo\WebSocketTest\Database\ObjectCollection;

use ArrayAccess;
use Countable;
use Demo\WebSocketTest\Database\Entity\Bot as EntityBot;
use Demo\WebSocketTest\Database\EntityCollection\Bots as EntityBots;
use Demo\WebSocketTest\Database\Object\Bot as ObjectBot;
use Hilos\Database\Database;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Iterator;

/**
 * Bots Object Collection
 * Typed wrapper around Objects for Bot objects
 *
 * This is a convenience class that provides type safety and convenience methods
 * while using Objects internally.
 */
final class Bots extends Objects implements Iterator, ArrayAccess, Countable
{
    /** @var ObjectBot[] $objects */
    protected array $objects = [];

    /**
     * Load all Bot objects from database
     * Clears existing objects and loads all from database
     *
     * @throws DatabaseException
     */
    public function loadAllFromDB(): void
    {
        $this->objects = [];
        $EntityBots = EntityBots::initFullDB();
        foreach ($EntityBots as $key => $EntityBot) {
            $this->objects[$key] = ObjectBot::fromEntity($EntityBot);
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
    protected function getTableName(): string
    {
        return EntityBot::_table;
    }

    /**
     * Get current Bot object
     *
     * @return ObjectBot|null Current Bot object or null if invalid position
     */
    public function current(): ?ObjectBot
    {
        return parent::current();
    }

    /**
     * Set Bot object at offset
     *
     * @param mixed $offset
     * @param ObjectBot $value
     */
    public function offsetSet($offset, $value): void
    {
        if ($value instanceof ObjectBot) {
            if ($offset === null) {
                $this->objects[] = $value;
            } else {
                $this->objects[$offset] = $value;
            }
        }
    }

    /**
     * Get Bot object at offset
     *
     * @param mixed $offset
     * @return ObjectBot|null
     */
    public function offsetGet($offset): ?ObjectBot
    {
        return parent::offsetGet($offset);
    }

    /**
     * Lazy load Bot object by key
     *
     * @param int|string $key Bot ID
     * @return ObjectBot|null
     * @throws DatabaseException
     */
    protected function lazyLoadObject(int|string $key): ?ObjectBot
    {
        $EntityBot = EntityBot::getById((int)$key);
        return $EntityBot !== null ? ObjectBot::fromEntity($EntityBot) : null;
    }

    /**
     * Lazy load count of Bot objects from database
     *
     * @return int
     * @throws DatabaseException
     */
    protected function lazyLoadCount(): int
    {
        // Use Entity to get count
        $resultSetCollection = Database::sql(
            "SELECT COUNT(*) as count FROM `" . EntityBot::_table . "`"
        );
        $firstResultSet = $resultSetCollection->first();

        if ($firstResultSet === null) {
            return 0;
        }

        $row = $firstResultSet->first();
        return $row !== null ? (int)($row['count'] ?? 0) : 0;
    }

    /**
     * Lazy load all Bot objects from database (for batch strategy)
     *
     * @throws DatabaseException
     */
    protected function lazyLoadAll(): void
    {
        $EntityBots = EntityBots::initFullDB();

        foreach ($EntityBots as $key => $EntityBot) {
            if (!isset($this->objects[$key])) {
                $this->objects[$key] = ObjectBot::fromEntity($EntityBot);
            }
        }

        $this->_allLoaded = true;
    }
}
