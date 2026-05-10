<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\Actions\Item\ModeratorPromptPieceActions;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;

/**
 * ModeratorPromptPiece - Db item with high-level abstraction and lazy loading.
 *
 * @extends DbItem<ObjectModeratorPromptPiece>
 * @method __construct(ObjectModeratorPromptPiece &$objectModeratorPromptPiece)
 *
 * @property-read ?int $id
 * @property-read string $section
 * @property-read string $promptPiece
 * @property-read ModeratorPromptPieceActions $actions Item-level write operations
 */
final class ModeratorPromptPiece extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name
     * @return int|string|bool|ModeratorPromptPieceActions|null Property value or actions
     * @throws PropertyNotFoundException If property does not exist
     */
    public function __get(string $name): int|string|bool|ModeratorPromptPieceActions|null
    {
        return match ($name) {
            ObjectModeratorPromptPiece::id => $this->_object->id,
            ObjectModeratorPromptPiece::section => $this->_object->section,
            ObjectModeratorPromptPiece::promptPiece => $this->_object->promptPiece,
            default => parent::__get($name),
        };
    }
}
