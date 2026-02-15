<?php

namespace Demo\Chat\Hilos\Database\DbCollection;

use Demo\Chat\Hilos\Database\Db\Moderator;
use Demo\Chat\Database\Object\Moderator as ObjectModerator;
use Hilos\Hilos\Database\DbActions;
use Hilos\Hilos\Database\DbCollection;
use Hilos\Database\Object\Object_;
use InvalidArgumentException;

/**
 * Moderators Db collection - collection of Moderator items with additional filtering methods.
 *
 * @extends DbCollection<Moderator>
 * @property-read DbActions $actions Actions for write operations
 */
final class Moderators extends DbCollection
{
    /**
     * Create Moderator instance from Object.
     *
     * @param Object_ $object Object instance (reference)
     * @return Moderator
     */
    protected function createIdea(Object_ &$object): Moderator
    {
        if (!($object instanceof ObjectModerator)) {
            throw new InvalidArgumentException("Object must be instance of ObjectModerator");
        }
        return new Moderator($object);
    }

    /**
     * Get current Moderator item
     *
     * @return ?Moderator Current Moderator item or null if invalid position
     */
    public function current(): ?Moderator
    {
        $item = parent::current();
        return $item instanceof Moderator ? $item : null;
    }

    /**
     * Get first Moderator item
     *
     * @return ?Moderator First Moderator item or null if collection is empty
     */
    public function first(): ?Moderator
    {
        $item = parent::first();
        return $item instanceof Moderator ? $item : null;
    }

    /**
     * Get last Moderator item
     *
     * @return ?Moderator Last Moderator item or null if collection is empty
     */
    public function last(): ?Moderator
    {
        $item = parent::last();
        return $item instanceof Moderator ? $item : null;
    }

    /**
     * Get Moderator item by offset
     *
     * @param mixed $offset Moderator ID
     * @return ?Moderator Moderator item or null if not found
     */
    public function offsetGet(mixed $offset): ?Moderator
    {
        $item = parent::offsetGet($offset);
        return $item instanceof Moderator ? $item : null;
    }
}
