<?php

namespace Demo\Chat\Database\Entity\Collection;

use Demo\Chat\Database\Entity\Item\User as EntityUser;
use Hilos\Database\Entity\Collection\EntityCollection;

/**
 * Users Entity Collection
 * Typed wrapper around EntityCollection for User entities
 *
 * @implements \Iterator<int|string, EntityUser>
 * @implements \ArrayAccess<int|string, EntityUser>
 */
final class Users extends EntityCollection
{
    public static function initFullDB(): self
    {
        return self::fromEntityCollection(EntityUser::getAll());
    }

    public static function initEmpty(): self
    {
        return self::empty();
    }

    public static function fromEntityCollection(EntityCollection $collection): self
    {
        $result = new self();
        foreach ($collection as $key => $entity) {
            $result[$key] = $entity;
        }
        return $result;
    }

    public function get(int|string $key): ?EntityUser
    {
        $entity = parent::get($key);
        return $entity instanceof EntityUser ? $entity : null;
    }

    public function first(): ?EntityUser
    {
        $entity = parent::first();
        return $entity instanceof EntityUser ? $entity : null;
    }

    public function last(): ?EntityUser
    {
        $entity = parent::last();
        return $entity instanceof EntityUser ? $entity : null;
    }

    public function current(): ?EntityUser
    {
        $entity = parent::current();
        return $entity instanceof EntityUser ? $entity : null;
    }

    public function offsetGet(mixed $offset): ?EntityUser
    {
        $entity = parent::offsetGet($offset);
        return $entity instanceof EntityUser ? $entity : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityUser) {
            parent::offsetSet($offset, $value);
        }
    }
}
