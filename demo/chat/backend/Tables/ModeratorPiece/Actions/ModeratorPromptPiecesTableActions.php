<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece\Actions;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceCreateActionDTO;
use Demo\Chat\Tables\ModeratorPiece\ModeratorPromptPiecesTable;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;

/**
 * ModeratorPromptPiecesTableActions - Collection-level actions for the moderator prompt pieces table (table layer).
 *
 * Delegates to db layer (ModeratorPromptPiecesActions) for actual create.
 *
 * @property ModeratorPromptPiecesTable $definition Moderator prompt pieces table definition that builds row mutation payloads.
 */
final class ModeratorPromptPiecesTableActions extends TableActions
{
    /**
     * Creates a moderator prompt piece and returns mutation for broadcasting.
     *
     * @param ModeratorPieceCreateActionDTO $dto Create payload
     * @return TableRowMutationDTO Row mutation DTO for broadcast
     * @throws HilosException On db or permission error
     */
    public function create(ModeratorPieceCreateActionDTO $dto): TableRowMutationDTO
    {
        $dbPiece = Hilos::$db->moderatorPromptPieces->actions->create($dto->section, $dto->promptPiece);

        return $this->mutation(TableMutationType::Create, $dbPiece->id, $this->definition->rowFromModeratorPromptPiece($dbPiece));
    }
}
