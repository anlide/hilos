<?php

namespace Demo\Chat\Hilos\Database\DbCollection;

use Demo\Chat\Hilos\Database\Db\Bot;
use Demo\Chat\Database\Object\Bot as ObjectBot;
use Hilos\Hilos\Database\DbActions;
use Hilos\Hilos\Database\DbCollection;
use Hilos\Database\Object\Object_;
use InvalidArgumentException;

/**
 * Bots Db collection - collection of Bot items with additional filtering methods.
 *
 * @extends DbCollection<Bot>
 * @property-read DbActions $actions Actions for write operations
 */
final class Bots extends DbCollection
{
    /**
     * Create Bot instance from Object.
     *
     * @param Object_ $object Object instance (reference)
     * @return Bot
     */
    protected function createIdea(Object_ &$object): Bot
    {
        if (!($object instanceof ObjectBot)) {
            throw new InvalidArgumentException("Object must be instance of ObjectBot");
        }
        return new Bot($object);
    }

    /**
     * Get current Bot item
     *
     * @return ?Bot Current Bot item or null if invalid position
     */
    public function current(): ?Bot
    {
        $item = parent::current();
        return $item instanceof Bot ? $item : null;
    }

    /**
     * Get first Bot item
     *
     * @return ?Bot First Bot item or null if collection is empty
     */
    public function first(): ?Bot
    {
        $item = parent::first();
        return $item instanceof Bot ? $item : null;
    }

    /**
     * Get last Bot item
     *
     * @return ?Bot Last Bot item or null if collection is empty
     */
    public function last(): ?Bot
    {
        $item = parent::last();
        return $item instanceof Bot ? $item : null;
    }

    /**
     * Get Bot item by offset
     *
     * @param mixed $offset Bot ID
     * @return ?Bot Bot item or null if not found
     */
    public function offsetGet(mixed $offset): ?Bot
    {
        $item = parent::offsetGet($offset);
        return $item instanceof Bot ? $item : null;
    }
}
