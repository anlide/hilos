<?php

namespace Demo\Chat\Hilos\Database\Item;

use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Hilos\Hilos\Database\Exception\Item\PropertyNotFoundException;
use Hilos\Hilos\Database\Item\DbItem;

/**
 * ModeratorPromptPiece Db item - high-level abstraction with lazy loading and relationships.
 *
 * @extends DbItem<ObjectModeratorPromptPiece>
 * @method __construct(ObjectModeratorPromptPiece &$objectModeratorPromptPiece)
 * @property-read ?int $id
 * @property-read string $section
 * @property-read string $promptPiece
 */
final class ModeratorPromptPiece extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name
     * @return int|string|bool|null Property value
     * @throws PropertyNotFoundException If property does not exist
     */
    public function __get(string $name): int|string|bool|null
    {
        return match ($name) {
            ObjectModeratorPromptPiece::id => $this->_object->id,
            ObjectModeratorPromptPiece::section => $this->_object->section,
            ObjectModeratorPromptPiece::promptPiece => $this->_object->promptPiece,
            default => parent::__get($name),
        };
    }
}
