<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\Actions\ModeratorPromptPieceItemActions;
use Demo\Chat\Tables\ModeratorPiece\Actions\ModeratorPromptPiecesTableActions;
use Hilos\Core\Table\DataSource\EntityTableDataSource;
use Hilos\Core\Table\DataSource\TableDataSourceInterface;
use Hilos\Core\Table\Definition\TableDefinition;

/**
 * Moderator prompt pieces table definition with create / update / delete actions.
 *
 * @property-read ModeratorPromptPiecesTableActions $actions
 */
final class ModeratorPromptPiecesTable extends TableDefinition
{
    /**
     * Provides entity data source for the moderator prompt pieces collection.
     *
     * @return TableDataSourceInterface Entity table data source for moderator prompt pieces
     */
    protected function createDataSource(): TableDataSourceInterface
    {
        return new EntityTableDataSource(Hilos::$db->moderatorPromptPieces);
    }

    /**
     * Configures table-level actions (ModeratorPromptPiecesTableActions for create) and item-level actions (ModeratorPromptPieceItemActions for update/delete).
     */
    protected function init(): void
    {
        $this->setActionsClass(ModeratorPromptPiecesTableActions::class);
        $this->setItemActionsClass(ModeratorPromptPieceItemActions::class);
    }
}
