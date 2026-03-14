<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece\Actions;

use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectPiece;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceCreateActionDTO;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;

/**
 * Collection-level actions for the moderator prompt pieces table (table layer).
 *
 * Delegates to db layer (ModeratorPromptPiecesActions) for actual create.
 */
class ModeratorPromptPiecesTableActions extends TableActions
{
    /**
     * Creates a moderator prompt piece and returns mutation for broadcasting.
     *
     * @param ModeratorPieceCreateActionDTO $dto Create payload
     * @return TableMutationEntry Mutation entry for broadcast
     * @throws HilosException On db or permission error
     */
    public function create(ModeratorPieceCreateActionDTO $dto): TableMutationEntry
    {
        $data = [
            ObjectPiece::section => $dto->section,
            ObjectPiece::promptPiece => $dto->promptPiece,
        ];

        $dbPiece = Hilos::$db->moderatorPromptPieces->actions->create($data);

        return $this->mutation(TableMutationType::Created, $dbPiece->id, $dbPiece->toArray(toFrontend: true));
    }
}
