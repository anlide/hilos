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
final class ModeratorPromptPieces extends EntityCollection
{
    public static function initFullDB(): self
    {
        return self::fromEntityCollection(EntityModeratorPromptPiece::getAll());
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

    public function get(int|string $key): ?EntityModeratorPromptPiece
    {
        $entity = parent::get($key);
        return $entity instanceof EntityModeratorPromptPiece ? $entity : null;
    }

    public function first(): ?EntityModeratorPromptPiece
    {
        $entity = parent::first();
        return $entity instanceof EntityModeratorPromptPiece ? $entity : null;
    }

    public function last(): ?EntityModeratorPromptPiece
    {
        $entity = parent::last();
        return $entity instanceof EntityModeratorPromptPiece ? $entity : null;
    }

    public function current(): ?EntityModeratorPromptPiece
    {
        $entity = parent::current();
        return $entity instanceof EntityModeratorPromptPiece ? $entity : null;
    }

    public function offsetGet(mixed $offset): ?EntityModeratorPromptPiece
    {
        $entity = parent::offsetGet($offset);
        return $entity instanceof EntityModeratorPromptPiece ? $entity : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityModeratorPromptPiece) {
            parent::offsetSet($offset, $value);
        }
    }
}
