<?php

namespace Demo\WebSocketTest\Database\ObjectCollection;

use ArrayAccess;
use Countable;
use Demo\WebSocketTest\Database\EntityCollection\Users as EntityUsers;
use Demo\WebSocketTest\Database\Object\User as ObjectUser;
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
     * @return self
     */
    public static function initPartialDB(): self
    {
        $self = new self();
        $self->_allowLazyLoading = true;
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
}
