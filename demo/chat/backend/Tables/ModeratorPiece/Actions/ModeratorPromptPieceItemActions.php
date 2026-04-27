<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece\Actions;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceUpdateActionDTO;
use Demo\Chat\Tables\ModeratorPiece\ModeratorPromptPiecesTable;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;

/**
 * ModeratorPromptPieceItemActions - Item-level actions for a single moderator prompt piece (table layer).
 *
 * Delegates to db layer (ModeratorPromptPieceActions) for actual updates and deletes.
 *
 * @property ModeratorPromptPiecesTable $definition Moderator prompt pieces table definition that builds row mutation payloads.
 */
final class ModeratorPromptPieceItemActions extends TableItemActions
{
    /**
     * Updates piece and returns mutation for broadcasting.
     *
     * @param ModeratorPieceUpdateActionDTO $dto Update payload (only non-null fields applied)
     * @return TableRowMutationDTO Row mutation DTO for broadcast
     * @throws HilosException On db or permission error
     */
    public function update(ModeratorPieceUpdateActionDTO $dto): TableRowMutationDTO
    {
        $dbPiece = Hilos::$db->moderatorPromptPieces[$this->rowKey];
        if ($dto->section !== null || $dto->promptPiece !== null) {
            $dbPiece->actions->update($dto->section, $dto->promptPiece);
        }

        return $this->mutation(TableMutationType::Update, $this->definition->rowFromModeratorPromptPiece($dbPiece));
    }

    /**
     * Deletes piece and returns mutation for broadcasting.
     *
     * @return TableRowMutationDTO Row mutation DTO for broadcast
     * @throws HilosException On db or permission error
     */
    public function delete(): TableRowMutationDTO
    {
        Hilos::$db->moderatorPromptPieces[$this->rowKey]->actions->delete();
        return $this->mutation(TableMutationType::Delete);
    }
}
