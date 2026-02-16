<?php

namespace Demo\Chat\Database\Entity\Collection;

use Demo\Chat\Database\Entity\Item\Bot as EntityBot;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Exception\DatabaseException;

/**
 * Bots Entity Collection
 * Typed wrapper around EntityCollection for Bot entities
 */
final class Bots
{
    private EntityCollection $collection;

    private function __construct(EntityCollection $collection)
    {
        $this->collection = $collection;
    }

    public static function initFullDB(): self
    {
        $entityCollection = EntityBot::getAll();
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

    public function get(int|string $key): ?EntityBot
    {
        $entity = $this->collection->get($key);
        return $entity instanceof EntityBot ? $entity : null;
    }

    public function first(): ?EntityBot
    {
        $entity = $this->collection->first();
        return $entity instanceof EntityBot ? $entity : null;
    }

    public function last(): ?EntityBot
    {
        $entity = $this->collection->last();
        return $entity instanceof EntityBot ? $entity : null;
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
            return $entity instanceof EntityBot && $callback($entity);
        });
        return new self($filtered);
    }

    /** @return EntityBot[] */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->collection as $entity) {
            if ($entity instanceof EntityBot) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    public function current(): ?EntityBot
    {
        $entity = $this->collection->current();
        return $entity instanceof EntityBot ? $entity : null;
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

    public function offsetGet(mixed $offset): ?EntityBot
    {
        $entity = $this->collection->offsetGet($offset);
        return $entity instanceof EntityBot ? $entity : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityBot) {
            $this->collection->offsetSet($offset, $value);
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->collection->offsetUnset($offset);
    }
}
