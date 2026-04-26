<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\Actions\ModeratorPromptPieceItemActions;
use Demo\Chat\Tables\ModeratorPiece\Actions\ModeratorPromptPiecesTableActions;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSourceEventDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\DatabaseException;

/**
 * ModeratorPromptPiecesTable - Table definition with create/update/delete actions.
 *
 * @property-read ModeratorPromptPiecesTableActions $actions
 */
final class ModeratorPromptPiecesTable extends TableDefinition
{
    /**
     * Builds a moderator prompt piece row mutation from a source event.
     *
     * @param TableSourceEventDTO $event Moderator prompt piece source event to project into the table
     * @return ?TableRowMutationDTO Moderator prompt piece row mutation, or null when the event does not affect this table
     * @throws DatabaseException If source prompt piece lookup fails
     */
    public function buildMutationForSourceEvent(TableSourceEventDTO $event): ?TableRowMutationDTO
    {
        if ($event->sourceKey !== DbChatContext::moderatorPromptPieces) {
            return null;
        }

        $pieceId = (int) $event->sourceRowKey;
        if ($pieceId <= 0) {
            return null;
        }

        if ($event->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $pieceId);
        }

        $dbPiece = Hilos::$db->moderatorPromptPieces[$pieceId] ?? null;
        if ($dbPiece === null) {
            return null;
        }

        return $this->mutation(
            $event->mutationType,
            $pieceId,
            $this->makeRow($dbPiece->toArray(toFrontend: true)),
        );
    }

    /**
     * Queries moderator prompt pieces for the table.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Moderator prompt piece table snapshot
     * @throws DatabaseException If prompt piece query execution fails
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
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
