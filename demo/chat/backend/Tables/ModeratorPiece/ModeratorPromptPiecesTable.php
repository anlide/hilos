<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece;

use Demo\Chat\Tables\ModeratorPiece\Actions\ModeratorPromptPieceItemActions;
use Demo\Chat\Tables\ModeratorPiece\Actions\ModeratorPromptPiecesTableActions;
use Hilos\Core\Table\Definition\TableDefinition;

/**
 * Moderator prompt pieces table definition with create / update / delete actions.
 *
 * @property-read ModeratorPromptPiecesTableActions $actions
 */
class ModeratorPromptPiecesTable extends TableDefinition
{
    protected function init(): void
    {
        $this->setActionsClass(ModeratorPromptPiecesTableActions::class);
        $this->setItemActionsClass(ModeratorPromptPieceItemActions::class);
    }
}
