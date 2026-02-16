<?php

namespace Demo\Chat\Database\Object\Collection;

use ArrayAccess;
use Countable;
use Demo\Chat\Hilos\Database\Hilos;
use Demo\Chat\Database\Entity\Item\User as EntityUser;
use Demo\Chat\Database\Entity\Collection\Users as EntityUsers;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Database;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use Iterator;

/**
 * Users Object Collection
 */
final class Users extends Objects implements Iterator, ArrayAccess, Countable
{
    /** @var ObjectUser[] $objects */
    protected array $objects = [];

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

    public static function initEmpty(): static
    {
        $self = new self();
        $self->objects = [];
        return $self;
    }

    public function current(): ?ObjectUser
    {
        return parent::current();
    }

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

    public function offsetGet($offset): ?ObjectUser
    {
        return parent::offsetGet($offset);
    }

    protected function lazyLoadObject(int|string $key): ?ObjectUser
    {
        $entity = EntityUser::getById((int)$key);
        return $entity !== null ? ObjectUser::fromEntity($entity) : null;
    }

    protected function lazyLoadCount(): int
    {
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

    public function getTableName(): string
    {
        return EntityUser::_table;
    }

    public function getCollectionKey(): string
    {
        return Hilos::users;
    }

    public function findBySession(string $sessionToken): ?ObjectUser
    {
        if (empty($sessionToken)) {
            return null;
        }

        $entityCollection = EntityUser::get(['session_token' => $sessionToken]);
        $entityUsers = EntityUsers::fromEntityCollection($entityCollection);
        $entityUser = $entityUsers->first();

        if ($entityUser === null) {
            return null;
        }

        $userId = $entityUser->id;
        if ($userId !== null && isset($this->objects[$userId])) {
            return $this->objects[$userId];
        }

        $objectUser = ObjectUser::fromEntity($entityUser);

        if ($userId !== null) {
            $this->objects[$userId] = $objectUser;
        }

        return $objectUser;
    }

    public function get(int|string $key): ?ObjectUser
    {
        return $this->offsetGet($key);
    }

    public function first(): ?ObjectUser
    {
        if (empty($this->objects)) {
            return null;
        }

        $keys = array_keys($this->objects);
        return $this->objects[$keys[0]] ?? null;
    }

    public function last(): ?ObjectUser
    {
        if (empty($this->objects)) {
            return null;
        }

        $keys = array_keys($this->objects);
        $lastKey = end($keys);
        return $this->objects[$lastKey] ?? null;
    }

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

    /** @return ObjectUser[] */
    public function toArray(): array
    {
        return array_values($this->objects);
    }
}
