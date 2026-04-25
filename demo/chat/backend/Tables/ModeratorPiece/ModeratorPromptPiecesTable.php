<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\Actions\ModeratorPromptPieceItemActions;
use Demo\Chat\Tables\ModeratorPiece\Actions\ModeratorPromptPiecesTableActions;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableResultDTO;
use Hilos\Database\DatabaseException;

/**
 * ModeratorPromptPiecesTable - Table definition with create/update/delete actions.
 *
 * @property-read ModeratorPromptPiecesTableActions $actions
 */
final class ModeratorPromptPiecesTable extends TableDefinition
{
    /**
     * Queries moderator prompt pieces for the table.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableResultDTO Moderator prompt piece rows
     * @throws DatabaseException If prompt piece query execution fails
     */
    protected function query(TableQueryDTO $query): TableResultDTO
    {
        return $this->queryDbCollection(Hilos::$db->moderatorPromptPieces, $query);
    }

    /**
     * Configures table-level actions (ModeratorPromptPiecesTableActions for create) and item-level actions (ModeratorPromptPieceItemActions for update/delete).
     */
    protected function init(): void
    {
        $this->setRowClass(ModeratorPromptPieceTableRow::class);
        $this->setActionsClass(ModeratorPromptPiecesTableActions::class);
        $this->setItemActionsClass(ModeratorPromptPieceItemActions::class);
    }
}
