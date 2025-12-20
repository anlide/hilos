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
 * Collection of User objects
 */
final class Users extends Objects implements Iterator, ArrayAccess, Countable
{
    /** @var ObjectUser[] $objects */
    protected array $objects = [];

    /**
     * Initialize collection with all users from database
     *
     * @return self
     * @throws DatabaseException
     */
    public static function initFullDB(): self
    {
        $self = new self();
        $entityUsers = EntityUsers::initFullDB();

        foreach ($entityUsers as $key => $entityUser) {
            $self->objects[$key] = ObjectUser::fromEntity($entityUser);
        }

        return $self;
    }

    /**
     * Reload all users from database
     *
     * @throws DatabaseException
     */
    public function initAgainFullDB(): void
    {
        $this->objects = [];
        $entityUsers = EntityUsers::initFullDB();

        foreach ($entityUsers as $key => $entityUser) {
            $this->objects[$key] = ObjectUser::fromEntity($entityUser);
        }
    }

    /**
     * Initialize collection with partial database loading (lazy loading enabled)
     *
     * @param int $strategy Lazy loading strategy (LAZY_STRATEGY_KEY by default - never load all)
     * @return self
     */
    public static function initPartialDB(int $strategy = self::LAZY_STRATEGY_KEY): self
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
     * Get current User object
     *
     * @return ObjectUser
     */
    public function current(): ObjectUser
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
}
