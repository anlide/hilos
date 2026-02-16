<?php

namespace Demo\Chat\Hilos\Database\Collection;

use Demo\Chat\Hilos\Database\Item\Bot;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Hilos\Database\Object\Item\Object_;
use Hilos\Hilos\Database\Actions\DbActions;
use Hilos\Hilos\Database\Collection\DbCollection;
use InvalidArgumentException;

/**
 * Bots Db collection - collection of Bot items.
 *
 * @extends DbCollection<Bot>
 * @property-read DbActions $actions Actions for write operations
 */
final class Bots extends DbCollection
{
    protected function createIdea(Object_ &$object): Bot
    {
        if (!($object instanceof ObjectBot)) {
            throw new InvalidArgumentException("Object must be instance of ObjectBot");
        }
        return new Bot($object);
    }

    public function current(): ?Bot
    {
        $item = parent::current();
        return $item instanceof Bot ? $item : null;
    }

    public function first(): ?Bot
    {
        $item = parent::first();
        return $item instanceof Bot ? $item : null;
    }

    public function last(): ?Bot
    {
        $item = parent::last();
        return $item instanceof Bot ? $item : null;
    }

    public function offsetGet(mixed $offset): ?Bot
    {
        $item = parent::offsetGet($offset);
        return $item instanceof Bot ? $item : null;
    }
}
