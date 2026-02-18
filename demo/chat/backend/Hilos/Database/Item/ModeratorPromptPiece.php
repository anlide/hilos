<?php

namespace Demo\Chat\Hilos\Database\Item;

use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Hilos\Hilos\Database\Item\DbItem;

/**
 * ModeratorPromptPiece Db item - high-level abstraction with lazy loading and relationships.
 *
 * @extends DbItem<ObjectModeratorPromptPiece>
 * @property-read ?int $id
 * @property-read string $section
 * @property-read string $promptPiece
 */
final class ModeratorPromptPiece extends DbItem
{
    public function __construct(ObjectModeratorPromptPiece &$objectModeratorPromptPiece)
    {
        parent::__construct($objectModeratorPromptPiece);
    }

    public function __get(string $name): int|string|bool|null
    {
        return match ($name) {
            ObjectModeratorPromptPiece::id => $this->_object->id,
            ObjectModeratorPromptPiece::section => $this->_object->section,
            ObjectModeratorPromptPiece::promptPiece => $this->_object->promptPiece,
            default => parent::__get($name),
        };
    }

    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false, bool $toFrontend = false): array
    {
        $data = [];
        if ($withId) {
            $data[ObjectModeratorPromptPiece::id] = $this->_object->id;
        }
        $data[ObjectModeratorPromptPiece::section] = $this->_object->section;
        $data[ObjectModeratorPromptPiece::promptPiece] = $this->_object->promptPiece;
        return $data;
    }
}
