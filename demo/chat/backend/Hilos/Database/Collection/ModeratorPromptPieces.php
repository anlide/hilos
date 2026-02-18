<?php

namespace Demo\Chat\Hilos\Database\Collection;

use Demo\Chat\Hilos\Database\Item\ModeratorPromptPiece;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Hilos\Database\Object\Item\Object_;
use Hilos\Hilos\Database\Actions\DbActions;
use Hilos\Hilos\Database\Collection\DbCollection;
use InvalidArgumentException;

/**
 * ModeratorPromptPieces Db collection - collection of ModeratorPromptPiece items.
 *
 * @extends DbCollection<ModeratorPromptPiece>
 * @property-read DbActions $actions Actions for write operations
 */
final class ModeratorPromptPieces extends DbCollection
{
    protected function createIdea(Object_ &$object): ModeratorPromptPiece
    {
        if (!($object instanceof ObjectModeratorPromptPiece)) {
            throw new InvalidArgumentException("Object must be instance of ObjectModeratorPromptPiece");
        }
        return new ModeratorPromptPiece($object);
    }

    public function current(): ?ModeratorPromptPiece
    {
        $item = parent::current();
        return $item instanceof ModeratorPromptPiece ? $item : null;
    }

    public function first(): ?ModeratorPromptPiece
    {
        $item = parent::first();
        return $item instanceof ModeratorPromptPiece ? $item : null;
    }

    public function last(): ?ModeratorPromptPiece
    {
        $item = parent::last();
        return $item instanceof ModeratorPromptPiece ? $item : null;
    }

    public function offsetGet(mixed $offset): ?ModeratorPromptPiece
    {
        $item = parent::offsetGet($offset);
        return $item instanceof ModeratorPromptPiece ? $item : null;
    }
}
