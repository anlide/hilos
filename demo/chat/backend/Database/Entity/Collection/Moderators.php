<?php

namespace Demo\Chat\Database\Entity\Collection;

use Demo\Chat\Database\Entity\Item\Moderator as EntityModerator;
use Hilos\Database\Entity\Collection\EntityCollection;

/**
 * Moderators Entity Collection
 * Typed wrapper around EntityCollection for Moderator entities
 */
final class Moderators
{
    private EntityCollection $collection;

    private function __construct(EntityCollection $collection)
    {
        $this->collection = $collection;
    }

    public static function initFullDB(): self
    {
        $entityCollection = EntityModerator::getAll();
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

    public function get(int|string $key): ?EntityModerator
    {
        $entity = $this->collection->get($key);
        return $entity instanceof EntityModerator ? $entity : null;
    }

    public function first(): ?EntityModerator
    {
        $entity = $this->collection->first();
        return $entity instanceof EntityModerator ? $entity : null;
    }

    public function last(): ?EntityModerator
    {
        $entity = $this->collection->last();
        return $entity instanceof EntityModerator ? $entity : null;
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
            return $entity instanceof EntityModerator && $callback($entity);
        });
        return new self($filtered);
    }

    /** @return EntityModerator[] */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->collection as $entity) {
            if ($entity instanceof EntityModerator) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    public function current(): ?EntityModerator
    {
        $entity = $this->collection->current();
        return $entity instanceof EntityModerator ? $entity : null;
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

    public function offsetGet(mixed $offset): ?EntityModerator
    {
        $entity = $this->collection->offsetGet($offset);
        return $entity instanceof EntityModerator ? $entity : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityModerator) {
            $this->collection->offsetSet($offset, $value);
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->collection->offsetUnset($offset);
    }
}
