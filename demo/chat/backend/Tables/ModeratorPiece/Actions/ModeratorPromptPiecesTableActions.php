<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece\Actions;

use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectPiece;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceCreateActionDTO;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * Collection-level actions for the moderator prompt pieces table.
 */
class ModeratorPromptPiecesTableActions extends TableActions
{
    public function create(ModeratorPieceCreateActionDTO $dto): TableMutationEntry
    {
        $piece = ObjectPiece::create();
        $piece->section = $dto->section;
        $piece->promptPiece = $dto->promptPiece;
        $piece->sync();

        $objectCollection = Hilos::$db->moderatorPromptPieces->getObjectCollection();
        $objectCollection[$piece->getIdString()] = $piece;

        return $this->mutation(TableMutationType::Created, $piece->id, $piece->toArray());
    }
}
