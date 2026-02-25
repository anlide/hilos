<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece\Actions;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceUpdateActionDTO;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * Item-level actions for a single moderator prompt piece.
 */
class ModeratorPromptPieceItemActions extends TableItemActions
{
    public function update(ModeratorPieceUpdateActionDTO $dto): TableMutationEntry
    {
        $objectCollection = Hilos::$db->moderatorPromptPieces->getObjectCollection();
        $object = $objectCollection[(string) $this->itemId];

        if ($dto->section !== null) $object->section = $dto->section;
        if ($dto->promptPiece !== null) $object->promptPiece = $dto->promptPiece;
        $object->sync();

        return $this->mutation(TableMutationType::Updated, $object->toArray());
    }

    public function delete(): TableMutationEntry
    {
        $objectCollection = Hilos::$db->moderatorPromptPieces->getObjectCollection();
        $object = $objectCollection[(string) $this->itemId];
        $object->delete();
        unset($objectCollection[(string) $this->itemId]);

        return $this->mutation(TableMutationType::Deleted);
    }
}
