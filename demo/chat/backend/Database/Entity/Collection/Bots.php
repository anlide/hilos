<?php

namespace Demo\Chat\Database\Entity\Collection;

use Demo\Chat\Database\Entity\Item\Bot as EntityBot;
use Hilos\Database\Entity\Collection\EntityCollection;

/**
 * Bots Entity Collection
 * Typed wrapper around EntityCollection for Bot entities
 *
 * @implements \Iterator<int|string, EntityBot>
 * @implements \ArrayAccess<int|string, EntityBot>
 */
final class Bots extends EntityCollection
{
    public static function initFullDB(): self
    {
        return self::fromEntityCollection(EntityBot::getAll());
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

    public function get(int|string $key): ?EntityBot
    {
        $entity = parent::get($key);
        return $entity instanceof EntityBot ? $entity : null;
    }

    public function first(): ?EntityBot
    {
        $entity = parent::first();
        return $entity instanceof EntityBot ? $entity : null;
    }

    public function last(): ?EntityBot
    {
        $entity = parent::last();
        return $entity instanceof EntityBot ? $entity : null;
    }

    public function current(): ?EntityBot
    {
        $entity = parent::current();
        return $entity instanceof EntityBot ? $entity : null;
    }

    public function offsetGet(mixed $offset): ?EntityBot
    {
        $entity = parent::offsetGet($offset);
        return $entity instanceof EntityBot ? $entity : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof EntityBot) {
            parent::offsetSet($offset, $value);
        }
    }
}
