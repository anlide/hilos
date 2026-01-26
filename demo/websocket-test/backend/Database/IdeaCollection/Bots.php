<?php

namespace Demo\WebSocketTest\Database\IdeaCollection;

use Demo\WebSocketTest\Database\Idea\Bot as IdeaBot;
use Demo\WebSocketTest\Database\Object\Bot as ObjectBot;
use Hilos\Database\Idea\IdeaActions;
use Hilos\Database\Idea\IdeaCollection;
use Hilos\Database\Object\Object_;
use InvalidArgumentException;

/**
 * Bots Idea Collection
 * Collection of Bot ideas with additional filtering methods
 *
 * @extends IdeaCollection<IdeaBot>
 * @property-read IdeaActions $actions Actions for write operations
 */
final class Bots extends IdeaCollection
{
    // init() and initEmpty() are inherited from IdeaCollection
    // Override only if custom initialization logic is needed

    // getObjectCollection() is inherited from IdeaCollection
    // ObjectCollection is set via setObjectCollection() by Idea::setRepresent()

    /**
     * Create Idea instance from Object
     *
     * @param Object_ $object Object instance (reference)
     * @return IdeaBot
     */
    protected function createIdea(Object_ &$object): IdeaBot
    {
        if (!($object instanceof ObjectBot)) {
            throw new InvalidArgumentException("Object must be instance of ObjectBot");
        }
        return new IdeaBot($object);
    }

    /**
     * Get current Bot idea
     *
     * @return ?IdeaBot Current Bot idea or null if invalid position
     */
    public function current(): ?IdeaBot
    {
        $item = parent::current();
        return $item instanceof IdeaBot ? $item : null;
    }

    /**
     * Get first Bot idea
     *
     * @return ?IdeaBot First Bot idea or null if collection is empty
     */
    public function first(): ?IdeaBot
    {
        $item = parent::first();
        return $item instanceof IdeaBot ? $item : null;
    }

    /**
     * Get last Bot idea
     *
     * @return ?IdeaBot Last Bot idea or null if collection is empty
     */
    public function last(): ?IdeaBot
    {
        $item = parent::last();
        return $item instanceof IdeaBot ? $item : null;
    }

    /**
     * Get Bot idea by offset
     *
     * @param mixed $offset Bot ID
     * @return ?IdeaBot Bot idea or null if not found
     */
    public function offsetGet(mixed $offset): ?IdeaBot
    {
        $item = parent::offsetGet($offset);
        return $item instanceof IdeaBot ? $item : null;
    }

    /**
     * Convert to array with additional options
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false): array
    {
        return parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation);
    }
}
