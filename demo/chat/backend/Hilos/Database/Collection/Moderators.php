<?php

namespace Demo\Chat\Hilos\Database\Collection;

use Demo\Chat\Hilos\Database\Item\Moderator;
use Demo\Chat\Database\Object\Item\Moderator as ObjectModerator;
use Hilos\Database\Object\Item\Object_;
use Hilos\Hilos\Database\Actions\DbActions;
use Hilos\Hilos\Database\Collection\DbCollection;
use InvalidArgumentException;

/**
 * Moderators Db collection - collection of Moderator items.
 *
 * @extends DbCollection<Moderator>
 * @property-read DbActions $actions Actions for write operations
 */
final class Moderators extends DbCollection
{
    protected function createIdea(Object_ &$object): Moderator
    {
        if (!($object instanceof ObjectModerator)) {
            throw new InvalidArgumentException("Object must be instance of ObjectModerator");
        }
        return new Moderator($object);
    }

    public function current(): ?Moderator
    {
        $item = parent::current();
        return $item instanceof Moderator ? $item : null;
    }

    public function first(): ?Moderator
    {
        $item = parent::first();
        return $item instanceof Moderator ? $item : null;
    }

    public function last(): ?Moderator
    {
        $item = parent::last();
        return $item instanceof Moderator ? $item : null;
    }

    public function offsetGet(mixed $offset): ?Moderator
    {
        $item = parent::offsetGet($offset);
        return $item instanceof Moderator ? $item : null;
    }
}
