<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\ModeratorPiece;

use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Item\ModeratorPromptPiece as EntityModeratorPromptPiece;
use Demo\Chat\Database\Object\Item\ModeratorPromptPiece as ObjectModeratorPromptPiece;
use Demo\Chat\Database\View\Item\ModeratorPromptPiece as DbModeratorPromptPiece;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\ModeratorPiece\Actions\ModeratorPromptPieceItemActions;
use Demo\Chat\Tables\ModeratorPiece\Actions\ModeratorPromptPiecesTableActions;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DatabaseException;

/**
 * Table definition for moderator prompt pieces.
 *
 * @property-read ModeratorPromptPiecesTableActions $actions Table-level prompt piece creation actions
 */
final class ModeratorPromptPiecesTable extends TableDefinition implements ViewportTable
{
    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            ChatBrowserSource::DB_MODERATOR_PROMPT_PIECES,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::DB_MODERATOR_PROMPT_PIECES,
                BrowserTableFieldKey::ROW_KEY => ObjectModeratorPromptPiece::id,
                BrowserTableFieldKey::FIELDS => [
                    ObjectModeratorPromptPiece::id => ModeratorPromptPieceTableRow::id,
                    ObjectModeratorPromptPiece::section => ModeratorPromptPieceTableRow::section,
                    ObjectModeratorPromptPiece::promptPiece => ModeratorPromptPieceTableRow::promptPiece,
                ],
            ],
        ],
    ];

    /**
     * Declares how many rows the first window of the moderator prompt pieces table carries.
     *
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return 10;
    }

    /**
     * Declares the order the first window of the moderator prompt pieces table runs in.
     *
     * @return ?TableSortOrderDTO First window ordered by id ascending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(ModeratorPromptPieceTableRow::id));
    }

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
     * Answers whether one prompt piece belongs to the set a window query describes.
     *
     * The pieces reach the window by query rather than in memory, so a live count under an
     * active search would otherwise re-run that query on every write; the question about one
     * row is put to the same collection the window is served from.
     *
     * @param string|int $rowKey Prompt piece id to place against the set
     * @param TableQueryDTO $query Window query whose search describes the set
     * @return ?bool Whether the piece is in the set, or null when the collection cannot answer
     * @throws DatabaseException When the prompt piece query fails
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field names no column of the entity
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->containsRowInDbCollection(Hilos::$db->moderatorPromptPieces, $rowKey, $query);
    }

    /**
     * Serializes one prompt piece row into its internal browser-row envelope.
     *
     * The piece rides a single entity slot keyed by the DB source name (its `id`
     * makes it an entity the frontend resolves through its piece collection), the
     * same shape the declarative fan-out delivers, so a windowed or delta row
     * resolves through the frontend identically.
     *
     * @param AbstractTableRow $row Prompt piece row from this table's window or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                ChatDbContext::moderatorPromptPieces => $row->toArray(),
            ],
        ];
    }

    /**
     * Declares the one field the pieces admin sorts by, mapped to its entity column.
     *
     * The set mirrors what the frontend marks sortable (`AdminModerator` marks the section
     * column and nothing else); the backend states it again rather than reading it from the
     * window, because the window is the client.
     *
     * @return array<string, string> Wire row fields mapped to the columns they order by
     */
    protected function sortableFields(): array
    {
        return [
            ModeratorPromptPieceTableRow::section => EntityModeratorPromptPiece::section,
        ];
    }

    /**
     * Declares what a prompt-piece row is searched by: its section and the text of the piece.
     *
     * These rows come from the ORM, so each field names a bare column of the entity - the one the
     * search runs its comparison against.
     *
     * @return array<string, string> Searched fields mapped to their entity columns
     */
    protected function searchableFields(): array
    {
        return [
            ModeratorPromptPieceTableRow::section => EntityModeratorPromptPiece::section,
            ModeratorPromptPieceTableRow::promptPiece => EntityModeratorPromptPiece::prompt_piece,
        ];
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
                fn(DbModeratorPromptPiece $moderatorPromptPiece): ModeratorPromptPieceTableRow
                    => $this->rowFromModeratorPromptPiece($moderatorPromptPiece),
                $result[TableConstants::RESULT_KEY_ROWS],
            ),
            totalCount: $result[TableConstants::RESULT_KEY_TOTAL_COUNT],
            totalExact: $result[TableConstants::RESULT_KEY_TOTAL_EXACT],
            limit: $query->limit,
            firstAnchor: $result[TableConstants::RESULT_KEY_FIRST_ANCHOR],
            lastAnchor: $result[TableConstants::RESULT_KEY_LAST_ANCHOR],
            rowsBefore: $result[TableConstants::RESULT_KEY_ROWS_BEFORE],
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
