<?php

declare(strict_types=1);

namespace Hilos\Tables\ChangeLog;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\ChangeLog\Section\ChangeLogTableSummary;
use Hilos\Hilos;
use Hilos\HilosException;

/** Frozen windows over the live tables' declarations, read anew on each request. */
class HilosChangeLogTablesTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosChangeLogTables';
    public const string FILTER_JOURNAL = 'journal';
    public const string JOURNAL_TRACKED = 'tracked';
    public const string JOURNAL_UNTRACKED = 'untracked';

    private const string ROW_SLOT = 'table';
    private const int WINDOW_SIZE = 25;

    /** @return int Rows in the first window */
    public function windowSize(): int
    {
        return self::WINDOW_SIZE;
    }

    /**
     * @param SourceChange $change Source fact, deliberately ignored
     * @return ?TableRowMutationDTO No live mutations belong to this table
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Live table row
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

    /** @return array<string, WireField> Table names and declaration counts shown to every viewer */
    public function wireFields(): array
    {
        return [
            HilosChangeLogTablesTableRow::name => WireField::notPersonal(),
            HilosChangeLogTablesTableRow::owner => WireField::notPersonal(),
            HilosChangeLogTablesTableRow::journaled => WireField::notPersonal(),
            HilosChangeLogTablesTableRow::columns => WireField::each([
                HilosChangeLogTablesTableRow::columnsValue => WireField::notPersonal(),
                HilosChangeLogTablesTableRow::columnsPersonal => WireField::notPersonal(),
                HilosChangeLogTablesTableRow::columnsBinary => WireField::notPersonal(),
                HilosChangeLogTablesTableRow::columnsSecret => WireField::notPersonal(),
                HilosChangeLogTablesTableRow::columnsNoise => WireField::notPersonal(),
            ]),
            HilosChangeLogTablesTableRow::changesLast24h => WireField::notPersonal(),
            HilosChangeLogTablesTableRow::lastChangeAt => WireField::notPersonal(),
            HilosChangeLogTablesTableRow::changesSort => WireField::notPersonal(),
            HilosChangeLogTablesTableRow::lastChangeSort => WireField::notPersonal(),
        ];
    }

    /** @return array<string, string> Columns mapped to their non-null ordering keys */
    protected function sortableFields(): array
    {
        return [
            HilosChangeLogTablesTableRow::name => HilosChangeLogTablesTableRow::name,
            HilosChangeLogTablesTableRow::changesLast24h => HilosChangeLogTablesTableRow::changesSort,
            HilosChangeLogTablesTableRow::lastChangeAt => HilosChangeLogTablesTableRow::lastChangeSort,
        ];
    }

    /** @return array<string, string> Search by a piece of the table name */
    protected function searchableFields(): array
    {
        return [HilosChangeLogTablesTableRow::name => HilosChangeLogTablesTableRow::name];
    }

    /**
     * @param TableQueryDTO $query Scoped viewport request
     * @return TableSnapshotDTO Exact window of the live table list
     * @throws HilosException When the live declarations or journal activity cannot be read
     * @throws InvalidArgumentException When the journal filter is unknown
     * @throws TableSearchNotSupportedException When the requested search has no declared fields
     * @throws TableSearchFieldUnknownException When a searched field is absent from the row set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = $this->narrow($this->collectRows(), $query);
        $ordering = new TableQueryDTO(
            search: $query->search,
            sort: self::orderingSort($query->sort),
            limit: $query->limit,
            filter: $query->filter,
            anchor: $query->anchor,
            anchorDirection: $query->anchorDirection,
            pageIndex: $query->pageIndex,
            searchableFields: $query->searchableFields,
        );

        return $this->filterInMemory($rows, $ordering);
    }

    /** Configures the inline table row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosChangeLogTablesTableRow::class);
    }

    /**
     * @return ChangeLogReadCollection<ChangeLogTableSummary> Live declarations and activity for this request
     * @throws HilosException When journal SQL, live schema, or placement cannot be read
     */
    protected function summaries(): ChangeLogReadCollection
    {
        return Hilos::$db->changeLogTables->sectionTables();
    }

    /**
     * @return list<array<string, mixed>> Journaled tables first, each group ordered by name
     * @throws HilosException When the table summaries cannot be read
     */
    private function collectRows(): array
    {
        $rows = [];
        foreach ($this->summaries() as $summary) {
            $rows[] = HilosChangeLogTablesTableRow::fromSummary($summary)->toArray();
        }
        usort(
            $rows,
            static fn(array $a, array $b): int
                => ($b[HilosChangeLogTablesTableRow::journaled] <=> $a[HilosChangeLogTablesTableRow::journaled])
                ?: strcmp($a[HilosChangeLogTablesTableRow::name], $b[HilosChangeLogTablesTableRow::name]),
        );

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows All live tables
     * @param TableQueryDTO $query Viewport filter map
     * @return list<array<string, mixed>> Tables with the requested journal declaration
     * @throws InvalidArgumentException When the journal filter is unknown
     */
    private function narrow(array $rows, TableQueryDTO $query): array
    {
        $raw = $query->filter[self::FILTER_JOURNAL] ?? null;
        if ($raw !== null && !is_string($raw)) {
            throw new InvalidArgumentException('Unknown change log journal filter');
        }
        $journal = HilosChangeLogTableParts::optionalFilter($raw);
        if ($journal === null) {
            return $rows;
        }
        $journaled = match ($journal) {
            self::JOURNAL_TRACKED => true,
            self::JOURNAL_UNTRACKED => false,
            default => throw new InvalidArgumentException('Unknown change log journal filter'),
        };

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => $row[HilosChangeLogTablesTableRow::journaled] === $journaled,
        ));
    }

    /**
     * The whitelist resolves wire names to payload columns; the memory filter reads field names.
     *
     * @param ?TableSortOrderDTO $order Whitelisted order over display columns
     * @return ?TableSortOrderDTO Order over payload keys, or null for the source order
     */
    private static function orderingSort(?TableSortOrderDTO $order): ?TableSortOrderDTO
    {
        return $order?->withComponents(array_map(
            static fn(TableSortDTO $component): TableSortDTO
                => new TableSortDTO($component->column ?? $component->field, $component->direction),
            $order->components,
        ));
    }
}
