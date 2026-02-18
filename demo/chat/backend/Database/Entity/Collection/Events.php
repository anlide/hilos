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
final class Events implements \Iterator, \ArrayAccess, \Countable
{
    private EntityCollection $collection;

    private function __construct(EntityCollection $collection)
    {
        $this->collection = $collection;
    }

    public static function initFullDB(): self
    {
        $entityCollection = EntityEvent::getAll();
        return new self($entityCollection);
    }

    public static function initEmpty(): self
    {
        return new self(EntityCollection::empty());
    }

    public static function fromEntityCollection(EntityCollection $collection): self
    {
        return new self($collection);
    }

    public function getCollection(): EntityCollection
    {
        return $this->collection;
    }

    public function get(int|string $key): ?EntityEvent
    {
        $entity = $this->collection->get($key);
        return $entity instanceof EntityEvent ? $entity : null;
    }

    public function first(): ?EntityEvent
    {
        $entity = $this->collection->first();
        return $entity instanceof EntityEvent ? $entity : null;
    }

    public function last(): ?EntityEvent
    {
        $entity = $this->collection->last();
        return $entity instanceof EntityEvent ? $entity : null;
    }

    public function count(): int
    {
        return $this->collection->count();
    }

    public function isEmpty(): bool
    {
        return $this->collection->count() === 0;
    }

    public function filter(callable $callback): self
    {
        $filtered = $this->collection->filter(function ($entity) use ($callback) {
            return $entity instanceof EntityEvent && $callback($entity);
        });
        return new self($filtered);
    }

    /** @return EntityEvent[] */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->collection as $entity) {
            if ($entity instanceof EntityEvent) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    public function current(): ?EntityEvent
    {
        $entity = $this->collection->current();
        return $entity instanceof EntityEvent ? $entity : null;
    }

    public function key(): int|string|null
    {
        return $this->collection->key();
    }

    public function next(): void
    {
        $this->collection->next();
    }

    public function rewind(): void
    {
        $this->collection->rewind();
    }

    public function valid(): bool
    {
        return $this->collection->valid();
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->collection->offsetExists($offset);
    }

    public function offsetGet(mixed $offset): ?EntityEvent
    {
        $entity = $this->collection->offsetGet($offset);
        return $entity instanceof EntityEvent ? $entity : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityEvent) {
            $this->collection->offsetSet($offset, $value);
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->collection->offsetUnset($offset);
    }
}
