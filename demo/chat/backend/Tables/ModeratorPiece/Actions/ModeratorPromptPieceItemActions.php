<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece\Actions;

use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\DTO\ModeratorPieceUpdateActionDTO;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;

/**
 * ModeratorPromptPieceItemActions - Item-level actions for a single moderator prompt piece (table layer).
 *
 * Delegates to db layer (ModeratorPromptPieceActions) for actual updates and deletes.
 */
final class ModeratorPromptPieceItemActions extends TableItemActions
{
    /**
     * Updates piece and returns mutation for broadcasting.
     *
     * @param ModeratorPieceUpdateActionDTO $dto Update payload (only non-null fields applied)
     * @return TableMutationEntry Mutation entry for broadcast
     * @throws HilosException On db or permission error
     */
    public function update(ModeratorPieceUpdateActionDTO $dto): TableMutationEntry
    {
        $data = array_filter([
            ObjectModeratorPromptPiece::section => $dto->section,
            ObjectModeratorPromptPiece::promptPiece => $dto->promptPiece,
        ], static fn($v) => $v !== null);

        $dbPiece = Hilos::$db->moderatorPromptPieces[$this->itemId];
        if ($data !== []) {
            $dbPiece->actions->update($data);
        }

        return $this->mutation(TableMutationType::Updated, $this->definition->makeRow($dbPiece->toArray(toFrontend: true)));
    }

    /**
     * Deletes piece and returns mutation for broadcasting.
     *
     * @return TableMutationEntry Mutation entry for broadcast
     * @throws HilosException On db or permission error
     */
    public function delete(): TableMutationEntry
    {
        Hilos::$db->moderatorPromptPieces[$this->itemId]->actions->delete();
        return $this->mutation(TableMutationType::Deleted);
    }
}
