<?php

namespace Demo\Chat\Database\IdeaCollection;

use Demo\Chat\Database\Idea\Moderator as IdeaModerator;
use Demo\Chat\Database\Object\Moderator as ObjectModerator;
use Hilos\Database\Idea\IdeaActions;
use Hilos\Database\Idea\IdeaCollection;
use Hilos\Database\Object\Object_;
use InvalidArgumentException;

/**
 * Moderators Idea Collection
 * Collection of Moderator ideas with additional filtering methods
 *
 * @extends IdeaCollection<IdeaModerator>
 * @property-read IdeaActions $actions Actions for write operations
 */
final class Moderators extends IdeaCollection
{
    // init() and initEmpty() are inherited from IdeaCollection
    // Override only if custom initialization logic is needed

    // getObjectCollection() is inherited from IdeaCollection
    // ObjectCollection is set via setObjectCollection() by Idea::setRepresent()

    /**
     * Create Idea instance from Object
     *
     * @param Object_ $object Object instance (reference)
     * @return IdeaModerator
     */
    protected function createIdea(Object_ &$object): IdeaModerator
    {
        if (!($object instanceof ObjectModerator)) {
            throw new InvalidArgumentException("Object must be instance of ObjectModerator");
        }
        return new IdeaModerator($object);
    }

    /**
     * Get current Moderator idea
     *
     * @return ?IdeaModerator Current Moderator idea or null if invalid position
     */
    public function current(): ?IdeaModerator
    {
        $item = parent::current();
        return $item instanceof IdeaModerator ? $item : null;
    }

    /**
     * Get first Moderator idea
     *
     * @return ?IdeaModerator First Moderator idea or null if collection is empty
     */
    public function first(): ?IdeaModerator
    {
        $item = parent::first();
        return $item instanceof IdeaModerator ? $item : null;
    }

    /**
     * Get last Moderator idea
     *
     * @return ?IdeaModerator Last Moderator idea or null if collection is empty
     */
    public function last(): ?IdeaModerator
    {
        $item = parent::last();
        return $item instanceof IdeaModerator ? $item : null;
    }

    /**
     * Get Moderator idea by offset
     *
     * @param mixed $offset Moderator ID
     * @return ?IdeaModerator Moderator idea or null if not found
     */
    public function offsetGet(mixed $offset): ?IdeaModerator
    {
        $item = parent::offsetGet($offset);
        return $item instanceof IdeaModerator ? $item : null;
    }

    /**
     * Convert to array with additional options
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false, bool $toFrontend = false): array
    {
        return parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation, $toFrontend);
    }
}
