<?php

namespace Demo\WebSocketTest\Database\ObjectCollection;

use ArrayAccess;
use Countable;
use Demo\WebSocketTest\Database\Entity\Message as EntityMessage;
use Demo\WebSocketTest\Database\EntityCollection\Messages as EntityMessages;
use Demo\WebSocketTest\Database\Object\Message as ObjectMessage;
use Hilos\Database\Database;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Iterator;

/**
 * Messages Object Collection
 * Typed wrapper around Objects for Message objects
 *
 * This is a convenience class that provides type safety and convenience methods
 * while using Objects internally.
 */
final class Messages extends Objects implements Iterator, ArrayAccess, Countable
{
    /** @var ObjectMessage[] $objects */
    protected array $objects = [];

    /**
     * Initialize collection with all Message objects from database
     *
     * @return self
     * @throws DatabaseException
     */
    public static function initFullDB(): self
    {
        $self = new self();
        $EntityMessages = EntityMessages::initFullDB();

        foreach ($EntityMessages as $key => $EntityMessage) {
            $self->objects[$key] = ObjectMessage::fromEntity($EntityMessage);
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
     * Reload all Message objects from database
     *
     * @throws DatabaseException
     */
    public function initAgainFullDB(): void
    {
        $this->objects = [];
        $EntityMessages = EntityMessages::initFullDB();

        foreach ($EntityMessages as $key => $EntityMessage) {
            $this->objects[$key] = ObjectMessage::fromEntity($EntityMessage);
        }

        $this->_allLoaded = true;
        $this->_allowLazyLoading = false;
    }

    /**
     * Get current Message object
     *
     * @return ObjectMessage|null Current Message object or null if invalid position
     */
    public function current(): ?ObjectMessage
    {
        return parent::current();
    }

    /**
     * Set Message object at offset
     *
     * @param mixed $offset
     * @param ObjectMessage $value
     */
    public function offsetSet($offset, $value): void
    {
        if ($value instanceof ObjectMessage) {
            if ($offset === null) {
                $this->objects[] = $value;
            } else {
                $this->objects[$offset] = $value;
            }
        }
    }

    /**
     * Get Message object at offset
     *
     * @param mixed $offset
     * @return ObjectMessage|null
     */
    public function offsetGet($offset): ?ObjectMessage
    {
        return parent::offsetGet($offset);
    }

    /**
     * Lazy load Message object by key
     *
     * @param int|string $key Message ID
     * @return ObjectMessage|null
     * @throws DatabaseException
     */
    protected function lazyLoadObject(int|string $key): ?ObjectMessage
    {
        $EntityMessage = EntityMessage::getById((int)$key);
        return $EntityMessage !== null ? ObjectMessage::fromEntity($EntityMessage) : null;
    }

    /**
     * Lazy load count of Message objects from database
     *
     * @return int
     * @throws DatabaseException
     */
    protected function lazyLoadCount(): int
    {
        // Use Entity to get count
        $resultSetCollection = Database::sql(
            "SELECT COUNT(*) as count FROM `" . EntityMessage::_table . "`"
        );
        $firstResultSet = $resultSetCollection->first();

        if ($firstResultSet === null) {
            return 0;
        }

        $row = $firstResultSet->first();
        return $row !== null ? (int)($row['count'] ?? 0) : 0;
    }

    /**
     * Lazy load all Message objects from database (for batch strategy)
     *
     * @throws DatabaseException
     */
    protected function lazyLoadAll(): void
    {
        $EntityMessages = EntityMessages::initFullDB();

        foreach ($EntityMessages as $key => $EntityMessage) {
            if (!isset($this->objects[$key])) {
                $this->objects[$key] = ObjectMessage::fromEntity($EntityMessage);
            }
        }

        $this->_allLoaded = true;
    }
}
