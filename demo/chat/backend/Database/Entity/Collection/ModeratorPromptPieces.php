<?php

namespace Demo\Chat\Database\Entity\Collection;

use Demo\Chat\Database\Entity\Item\ModeratorPromptPiece as EntityModeratorPromptPiece;
use Hilos\Database\Entity\Collection\EntityCollection;

/**
 * ModeratorPromptPieces Entity Collection
 * Typed wrapper around EntityCollection for ModeratorPromptPiece entities
 *
 * @implements \Iterator<int|string, EntityModeratorPromptPiece>
 * @implements \ArrayAccess<int|string, EntityModeratorPromptPiece>
 */
final class ModeratorPromptPieces implements \Iterator, \ArrayAccess, \Countable
{
    private EntityCollection $collection;

    private function __construct(EntityCollection $collection)
    {
        $this->collection = $collection;
    }

    public static function initFullDB(): self
    {
        $entityCollection = EntityModeratorPromptPiece::getAll();
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

    public function get(int|string $key): ?EntityModeratorPromptPiece
    {
        $entity = $this->collection->get($key);
        return $entity instanceof EntityModeratorPromptPiece ? $entity : null;
    }

    public function first(): ?EntityModeratorPromptPiece
    {
        $entity = $this->collection->first();
        return $entity instanceof EntityModeratorPromptPiece ? $entity : null;
    }

    public function last(): ?EntityModeratorPromptPiece
    {
        $entity = $this->collection->last();
        return $entity instanceof EntityModeratorPromptPiece ? $entity : null;
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
            return $entity instanceof EntityModeratorPromptPiece && $callback($entity);
        });
        return new self($filtered);
    }

    /** @return EntityModeratorPromptPiece[] */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->collection as $entity) {
            if ($entity instanceof EntityModeratorPromptPiece) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    public function current(): ?EntityModeratorPromptPiece
    {
        $entity = $this->collection->current();
        return $entity instanceof EntityModeratorPromptPiece ? $entity : null;
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

    public function offsetGet(mixed $offset): ?EntityModeratorPromptPiece
    {
        $entity = $this->collection->offsetGet($offset);
        return $entity instanceof EntityModeratorPromptPiece ? $entity : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityModeratorPromptPiece) {
            $this->collection->offsetSet($offset, $value);
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->collection->offsetUnset($offset);
    }
}
