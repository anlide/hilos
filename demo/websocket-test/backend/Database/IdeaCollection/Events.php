<?php

namespace Demo\WebSocketTest\Database\IdeaCollection;

use Demo\WebSocketTest\Database\Idea\Event as IdeaEvent;
use Demo\WebSocketTest\Database\IdeaActions\EventsActions as IdeaActionsEvents;
use Demo\WebSocketTest\Database\Object\Event as ObjectEvent;
use Hilos\Database\Idea\IdeaCollection;
use Hilos\Database\Object\Object_;
use InvalidArgumentException;

/**
 * Events Idea Collection
 * Collection of Event ideas with additional filtering methods
 *
 * @extends IdeaCollection<IdeaEvent>
 * @property-read IdeaActionsEvents $actions Actions for write operations
 */
final class Events extends IdeaCollection
{
    // init() and initEmpty() are inherited from IdeaCollection
    // Override only if custom initialization logic is needed

    // getObjectCollection() is inherited from IdeaCollection
    // ObjectCollection is set via setObjectCollection() by Idea::setRepresent()

    /**
     * Create Idea instance from Object
     *
     * @param Object_ $object Object instance (reference)
     * @return IdeaEvent
     */
    protected function createIdea(Object_ &$object): IdeaEvent
    {
        if (!($object instanceof ObjectEvent)) {
            throw new InvalidArgumentException("Object must be instance of ObjectEvent");
        }
        return new IdeaEvent($object);
    }

    /**
     * Get current Event idea
     *
     * @return IdeaEvent|null Current Event idea or null if invalid position
     */
    public function current(): ?IdeaEvent
    {
        $item = parent::current();
        return $item instanceof IdeaEvent ? $item : null;
    }

    /**
     * Get first Event idea
     *
     * @return IdeaEvent|null First Event idea or null if collection is empty
     */
    public function first(): ?IdeaEvent
    {
        $item = parent::first();
        return $item instanceof IdeaEvent ? $item : null;
    }

    /**
     * Get last Event idea
     *
     * @return IdeaEvent|null Last Event idea or null if collection is empty
     */
    public function last(): ?IdeaEvent
    {
        $item = parent::last();
        return $item instanceof IdeaEvent ? $item : null;
    }

    /**
     * Get Event idea by offset
     *
     * @param mixed $offset Event ID
     * @return IdeaEvent|null Event idea or null if not found
     */
    public function offsetGet(mixed $offset): ?IdeaEvent
    {
        $item = parent::offsetGet($offset);
        return $item instanceof IdeaEvent ? $item : null;
    }

    /**
     * Convert to array with additional options
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        return parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation);
    }
}
