<?php

namespace Demo\WebSocketTest\Database\ObjectCollection;

use ArrayAccess;
use Countable;
use Demo\WebSocketTest\Database\Entity\User as EntityUser;
use Demo\WebSocketTest\Database\EntityCollection\Users as EntityUsers;
use Demo\WebSocketTest\Database\Object\User as ObjectUser;
use Hilos\Database\Database;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Iterator;

/**
 * Users Object Collection
 * Typed wrapper around Objects for User objects
 *
 * This is a convenience class that provides type safety and convenience methods
 * while using Objects internally.
 */
final class Users extends Objects implements Iterator, ArrayAccess, Countable
{
    /** @var ObjectUser[] $objects */
    protected array $objects = [];

    /**
     * Load all users from database
     * Clears existing objects and loads all from database
     *
     * @throws DatabaseException
     */
    public function loadAllFromDB(): void
    {
        $this->objects = [];
        $entityUsers = EntityUsers::initFullDB();
        foreach ($entityUsers as $key => $entityUser) {
            $this->objects[$key] = ObjectUser::fromEntity($entityUser);
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
     * Get current User object
     *
     * @return ObjectUser|null Current User object or null if invalid position
     */
    public function current(): ?ObjectUser
    {
        return parent::current();
    }

    /**
     * Set User object at offset
     *
     * @param mixed $offset
     * @param ObjectUser $value
     */
    public function offsetSet($offset, $value): void
    {
        if ($value instanceof ObjectUser) {
            if ($offset === null) {
                $this->objects[] = $value;
            } else {
                $this->objects[$offset] = $value;
            }
        }
    }

    /**
     * Get User object at offset
     *
     * @param mixed $offset
     * @return ObjectUser|null
     */
    public function offsetGet($offset): ?ObjectUser
    {
        return parent::offsetGet($offset);
    }

    /**
     * Lazy load User object by key
     *
     * @param int|string $key User ID
     * @return ObjectUser|null
     * @throws DatabaseException
     */
    protected function lazyLoadObject(int|string $key): ?ObjectUser
    {
        $entity = EntityUser::getById((int)$key);
        return $entity !== null ? ObjectUser::fromEntity($entity) : null;
    }

    /**
     * Lazy load count of users from database
     *
     * @return int
     * @throws DatabaseException
     */
    protected function lazyLoadCount(): int
    {
        // Use Entity to get count
        $resultSetCollection = Database::sql(
            "SELECT COUNT(*) as count FROM `" . EntityUser::_table . "`"
        );
        $firstResultSet = $resultSetCollection->first();

        if ($firstResultSet === null) {
            return 0;
        }

        $row = $firstResultSet->first();
        return $row !== null ? (int)($row['count'] ?? 0) : 0;
    }

    /**
     * Lazy load all users from database (for batch strategy)
     *
     * @throws DatabaseException
     */
    protected function lazyLoadAll(): void
    {
        $entityUsers = EntityUsers::initFullDB();

        foreach ($entityUsers as $key => $entityUser) {
            if (!isset($this->objects[$key])) {
                $this->objects[$key] = ObjectUser::fromEntity($entityUser);
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
        return EntityUser::_table;
    }

    /**
     * Find user by session token
     *
     * @param string $sessionToken Session token (32 hex characters)
     * @return ObjectUser|null User object or null if not found
     * @throws DatabaseException
     */
    public function findBySession(string $sessionToken): ?ObjectUser
    {
        if (empty($sessionToken)) {
            return null;
        }

        // Use Entity to find by session_token
        $entityCollection = EntityUser::get(['session_token' => $sessionToken]);
        $entityUsers = EntityUsers::fromEntityCollection($entityCollection);
        $entityUser = $entityUsers->first();

        if ($entityUser === null) {
            return null;
        }

        // Check if already loaded in collection
        $userId = $entityUser->id;
        if ($userId !== null && isset($this->objects[$userId])) {
            return $this->objects[$userId];
        }

        // Create ObjectUser from entityUser
        $objectUser = ObjectUser::fromEntity($entityUser);

        // Optionally cache it in collection for future access by ID
        if ($userId !== null) {
            $this->objects[$userId] = $objectUser;
        }

        return $objectUser;
    }

    /**
     * Get User object by key
     *
     * @param int|string $key
     * @return ObjectUser|null
     */
    public function get(int|string $key): ?ObjectUser
    {
        return $this->offsetGet($key);
    }

    /**
     * Get first User object
     *
     * @return ObjectUser|null
     */
    public function first(): ?ObjectUser
    {
        if (empty($this->objects)) {
            return null;
        }

        $keys = array_keys($this->objects);
        return $this->objects[$keys[0]] ?? null;
    }

    /**
     * Get last User object
     *
     * @return ObjectUser|null
     */
    public function last(): ?ObjectUser
    {
        if (empty($this->objects)) {
            return null;
        }

        $keys = array_keys($this->objects);
        $lastKey = end($keys);
        return $this->objects[$lastKey] ?? null;
    }

    /**
     * Filter collection by callback
     *
     * @param callable(ObjectUser): bool $callback
     * @return self New filtered collection
     */
    public function filterByCallback(callable $callback): self
    {
        $filtered = new self();
        foreach ($this->objects as $key => $object) {
            if ($object instanceof ObjectUser && $callback($object)) {
                $filtered->objects[$key] = $object;
            }
        }
        return $filtered;
    }

    /**
     * Convert to array
     *
     * @return ObjectUser[]
     */
    public function toArray(): array
    {
        return array_values($this->objects);
    }
}
