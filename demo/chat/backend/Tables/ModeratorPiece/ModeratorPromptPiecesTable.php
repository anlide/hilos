<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece;

use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Database\View\Item\ModeratorPromptPiece as DbModeratorPromptPiece;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\Actions\ModeratorPromptPieceItemActions;
use Demo\Chat\Tables\ModeratorPiece\Actions\ModeratorPromptPiecesTableActions;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DatabaseException;

/**
 * Table definition for moderator prompt pieces.
 *
 * @property-read ModeratorPromptPiecesTableActions $actions Table-level prompt piece creation actions
 */
final class ModeratorPromptPiecesTable extends TableDefinition
{
    public const array BROWSER = [
        BrowserConfigKey::SOURCES => [
            ChatBrowserSource::DB_MODERATOR_PROMPT_PIECES,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::DB_MODERATOR_PROMPT_PIECES,
                BrowserFieldKey::ROW_KEY => ObjectModeratorPromptPiece::id,
                BrowserFieldKey::FIELDS => [
                    ObjectModeratorPromptPiece::id => ModeratorPromptPieceTableRow::id,
                    ObjectModeratorPromptPiece::section => ModeratorPromptPieceTableRow::section,
                    ObjectModeratorPromptPiece::promptPiece => ModeratorPromptPieceTableRow::promptPiece,
                ],
            ],
        ],
    ];

    /**
     * Builds a moderator prompt piece row mutation from a source change.
     *
     * @param SourceChange $change Moderator prompt piece source change to project into the table
     * @return ?TableRowMutationDTO Moderator prompt piece row mutation, or null when the change does not affect this table
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== ChatDbContext::moderatorPromptPieces) {
            return null;
        }

        $pieceId = (int) $change->sourceId;
        if ($pieceId <= 0) {
            return null;
        }

        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $pieceId);
        }

        $dbPiece = Hilos::$db->moderatorPromptPieces[$pieceId] ?? null;
        if ($dbPiece === null) {
            return null;
        }

        return $this->mutation(
            $change->mutationType,
            $pieceId,
            $this->rowFromModeratorPromptPiece($dbPiece),
        );
    }

    /**
     * Queries moderator prompt pieces for the table.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Moderator prompt piece table snapshot
     * @throws DatabaseException When prompt piece query execution fails
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $result = Hilos::$db->moderatorPromptPieces->queryPageItems($query);

        return new TableSnapshotDTO(
            rows: array_map(
                fn(DbModeratorPromptPiece $moderatorPromptPiece): ModeratorPromptPieceTableRow => $this->rowFromModeratorPromptPiece($moderatorPromptPiece),
                $result[TableConstants::RESULT_KEY_ROWS],
            ),
            totalCount: $result[TableConstants::RESULT_KEY_TOTAL_COUNT],
            offset: $query->offset,
            limit: $query->limit,
        );
    }

    /**
     * Builds the moderator prompt pieces table row from the DB item.
     *
     * @param DbModeratorPromptPiece $moderatorPromptPiece DB item to project into the prompt pieces table
     * @return ModeratorPromptPieceTableRow Moderator prompt pieces table row payload
     */
    public function rowFromModeratorPromptPiece(DbModeratorPromptPiece $moderatorPromptPiece): ModeratorPromptPieceTableRow
    {
        return new ModeratorPromptPieceTableRow(
            id: (int) $moderatorPromptPiece->id,
            section: $moderatorPromptPiece->section,
            promptPiece: $moderatorPromptPiece->promptPiece,
        );
    }

    /**
     * Configures the row shape and actions used by the moderator prompt pieces table.
     */
    protected function init(): void
    {
        $this->setRowClass(ModeratorPromptPieceTableRow::class);
        $this->setActionsClass(ModeratorPromptPiecesTableActions::class);
        $this->setItemActionsClass(ModeratorPromptPieceItemActions::class);
    }
}
