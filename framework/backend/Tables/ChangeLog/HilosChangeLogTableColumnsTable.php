<?php

declare(strict_types=1);

namespace Hilos\Tables\ChangeLog;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\ChangeLog\Section\ChangeLogTableDetail;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\ChangeLog\DTO\HilosChangeLogTablePageSubscribeParams;
use Hilos\Utils\Logger;

/**
 * Request-local column windows in live schema order.
 */
class HilosChangeLogTableColumnsTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosChangeLogTableColumns';
    public const string FILTER_TABLE = 'table';

    private const string ROW_SLOT = 'column';
    private const int WINDOW_SIZE = 25;

    /** @return int Rows in the first window */
    public function windowSize(): int
    {
        return self::WINDOW_SIZE;
    }

    /**
     * @param PageRouteParams $params Route params carrying the live table address
     * @return array<string, mixed> Required table preset of the cold metadata window
     * @throws MissingPageRouteParamException When the live table address is absent or empty
     */
    public function defaultFilter(PageRouteParams $params): array
    {
        return [self::FILTER_TABLE => HilosChangeLogTablePageSubscribeParams::fromPageRouteParams($params)->tableId];
    }

    /**
     * Empty sort vocabularies pass single-field orders through the base gate.
     * This table serves only schema order, so every requested order is dropped.
     *
     * @param TableQueryDTO $query Window scoped by the required table preset
     * @return TableSnapshotDTO Column window in schema order
     * @throws HilosException When table metadata cannot be read or the preset is invalid
     * @throws TableSearchNotSupportedException When a search is requested
     */
    public function getPage(TableQueryDTO $query): TableSnapshotDTO
    {
        if ($query->sort !== null) {
            Logger::warning('Table sort order rejected', ['context' => self::class]);
            $query = new TableQueryDTO(
                search: $query->search, limit: $query->limit, filter: $query->filter,
                anchor: $query->anchor, anchorDirection: $query->anchorDirection, pageIndex: $query->pageIndex,
                shownFields: $query->shownFields,
            );
        }

        return parent::getPage($query);
    }

    /**
     * @param SourceChange $change Source fact, deliberately ignored
     * @return ?TableRowMutationDTO No live mutations belong to this metadata window
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Column metadata row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Browser row envelope
     * @throws TableRowKeyMissingException When the row has no stable key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::ROW_SLOT => $row->toArray()],
        ];
    }

    /** @return array<string, WireField> Schema metadata shared with every viewer */
    public function wireFields(): array
    {
        return [
            HilosChangeLogTableColumnsTableRow::name => WireField::notPersonal(),
            HilosChangeLogTableColumnsTableRow::sqlType => WireField::notPersonal(),
            HilosChangeLogTableColumnsTableRow::mode => WireField::notPersonal(),
            HilosChangeLogTableColumnsTableRow::storage => WireField::notPersonal(),
            HilosChangeLogTableColumnsTableRow::noiseReason => WireField::notPersonal(),
        ];
    }

    /**
     * @param TableQueryDTO $query Window carrying a required live table name
     * @return TableSnapshotDTO Exact window in schema order
     * @throws InvalidArgumentException When the table preset is missing, malformed or unknown
     * @throws HilosException When table metadata cannot be read
     * @throws TableSearchNotSupportedException When a search is requested without declared fields
     * @throws TableSearchFieldUnknownException When a searched field is absent from the row set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $table = $query->filter[self::FILTER_TABLE] ?? null;
        if (!is_string($table) || trim($table) === '') {
            throw new InvalidArgumentException('Change log columns require a non-empty table preset');
        }
        $detail = $this->sectionTable($table)
            ?? throw new InvalidArgumentException('Unknown change log table');
        $rows = [];
        foreach ($detail->columns as $column) {
            $rows[] = HilosChangeLogTableColumnsTableRow::fromColumn($column)->toArray();
        }

        return $this->filterInMemory($rows, $query);
    }

    /** Configures the inline schema column row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosChangeLogTableColumnsTableRow::class);
    }

    /**
     * @param string $table Live table name from the viewport preset
     * @return ?ChangeLogTableDetail Fresh metadata, or null for an unknown table
     * @throws HilosException When the collection, journal SQL or metadata cannot be read
     */
    protected function sectionTable(string $table): ?ChangeLogTableDetail
    {
        return Hilos::$db->changeLogTables->sectionTable($table);
    }
}
