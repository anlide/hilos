<?php

namespace Demo\Chat\Database\Entity\Collection;

use Demo\Chat\Database\Entity\Item\Event as EntityEvent;
use Hilos\Database\Entity\Collection\EntityCollection;

/**
 * Events Entity Collection
 * Typed wrapper around EntityCollection for Event entities
 *
 * @implements \Iterator<int|string, EntityEvent>
 * @implements \ArrayAccess<int|string, EntityEvent>
 */
final class Events extends EntityCollection
{
    public static function initFullDB(): self
    {
        return self::fromEntityCollection(EntityEvent::getAll());
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

    public function get(int|string $key): ?EntityEvent
    {
        $entity = parent::get($key);
        return $entity instanceof EntityEvent ? $entity : null;
    }

    public function first(): ?EntityEvent
    {
        $entity = parent::first();
        return $entity instanceof EntityEvent ? $entity : null;
    }

    public function last(): ?EntityEvent
    {
        $entity = parent::last();
        return $entity instanceof EntityEvent ? $entity : null;
    }

    public function current(): ?EntityEvent
    {
        $entity = parent::current();
        return $entity instanceof EntityEvent ? $entity : null;
    }

    public function offsetGet(mixed $offset): ?EntityEvent
    {
        $entity = parent::offsetGet($offset);
        return $entity instanceof EntityEvent ? $entity : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityEvent) {
            parent::offsetSet($offset, $value);
        }
    }
}
