<?php

namespace Demo\Chat\Hilos\Database\DbCollection;

use Demo\Chat\Hilos\Database\Db\Event;
use Demo\Chat\Hilos\Database\DbActions\EventsActions;
use Demo\Chat\Database\Object\Event as ObjectEvent;
use Hilos\Hilos\Database\DbCollection;
use Hilos\Database\Object\Object_;
use InvalidArgumentException;

/**
 * Events Db collection - collection of Event items with additional filtering methods.
 *
 * @extends DbCollection<Event>
 * @property-read EventsActions $actions Actions for write operations
 */
final class Events extends DbCollection
{
    /**
     * Create Event instance from Object.
     *
     * @param Object_ $object Object instance (reference)
     * @return Event
     */
    protected function createIdea(Object_ &$object): Event
    {
        if (!($object instanceof ObjectEvent)) {
            throw new InvalidArgumentException("Object must be instance of ObjectEvent");
        }
        return new Event($object);
    }

    /**
     * Get current Event item
     *
     * @return ?Event Current Event item or null if invalid position
     */
    public function current(): ?Event
    {
        $item = parent::current();
        return $item instanceof Event ? $item : null;
    }

    /**
     * Get first Event item
     *
     * @return ?Event First Event item or null if collection is empty
     */
    public function first(): ?Event
    {
        $item = parent::first();
        return $item instanceof Event ? $item : null;
    }

    /**
     * Get last Event item
     *
     * @return ?Event Last Event item or null if collection is empty
     */
    public function last(): ?Event
    {
        $item = parent::last();
        return $item instanceof Event ? $item : null;
    }

    /**
     * Get Event item by offset
     *
     * @param mixed $offset Event ID
     * @return ?Event Event item or null if not found
     */
    public function offsetGet(mixed $offset): ?Event
    {
        $item = parent::offsetGet($offset);
        return $item instanceof Event ? $item : null;
    }
}
