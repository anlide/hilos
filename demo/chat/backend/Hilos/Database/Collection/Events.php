<?php

namespace Demo\Chat\Hilos\Database\Collection;

use Demo\Chat\Hilos\Database\Item\Event;
use Demo\Chat\Hilos\Database\Actions\EventsActions;
use Demo\Chat\Database\Object\Item\Event as ObjectEvent;
use Hilos\Database\Object\Item\Object_;
use Hilos\Hilos\Database\Collection\DbCollection;
use InvalidArgumentException;

/**
 * Events Db collection - collection of Event items.
 *
 * @extends DbCollection<Event>
 * @property-read EventsActions $actions Actions for write operations
 */
final class Events extends DbCollection
{
    protected function createIdea(Object_ &$object): Event
    {
        if (!($object instanceof ObjectEvent)) {
            throw new InvalidArgumentException("Object must be instance of ObjectEvent");
        }
        return new Event($object);
    }

    public function current(): ?Event
    {
        $item = parent::current();
        return $item instanceof Event ? $item : null;
    }

    public function first(): ?Event
    {
        $item = parent::first();
        return $item instanceof Event ? $item : null;
    }

    public function last(): ?Event
    {
        $item = parent::last();
        return $item instanceof Event ? $item : null;
    }

    public function offsetGet(mixed $offset): ?Event
    {
        $item = parent::offsetGet($offset);
        return $item instanceof Event ? $item : null;
    }
}
