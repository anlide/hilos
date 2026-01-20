<?php

namespace Demo\WebSocketTest\Database\IdeaCollection;

use Demo\WebSocketTest\Database\Idea;
use Demo\WebSocketTest\Database\Idea\Bot as IdeaBot;
use Demo\WebSocketTest\Database\Object\Bot as ObjectBot;
use Demo\WebSocketTest\Database\ObjectCollection\Bots as ObjectBots;
use Hilos\Database\Idea\IdeaCollection;
use Hilos\Database\Object\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Exception\DatabaseException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bots Idea Collection
 * Collection of Bot ideas with additional filtering methods
 */
final class Bots extends IdeaCollection
{
    // init() and initEmpty() are inherited from IdeaCollection
    // Override only if custom initialization logic is needed

    /**
     * Get ObjectCollection from IdeaStorage
     * Returns null for manual collections
     * 
     * @return Objects|null ObjectCollection instance from IdeaStorage, or null for manual collections
     */
    protected function getObjectCollection(): ?Objects
    {
        $storage = Idea::$storage;
        if ($storage === null) {
            throw new RuntimeException("IdeaStorage not initialized");
        }
        return $storage->bots;
    }

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
     * @return IdeaBot|null Current Bot idea or null if invalid position
     */
    public function current(): ?IdeaBot
    {
        $item = parent::current();
        return $item instanceof IdeaBot ? $item : null;
    }

    /**
     * Get first Bot idea
     * 
     * @return IdeaBot|null First Bot idea or null if collection is empty
     */
    public function first(): ?IdeaBot
    {
        $item = parent::first();
        return $item instanceof IdeaBot ? $item : null;
    }

    /**
     * Get last Bot idea
     * 
     * @return IdeaBot|null Last Bot idea or null if collection is empty
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
     * @return IdeaBot|null Bot idea or null if not found
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
