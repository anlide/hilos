<?php

declare(strict_types=1);

namespace Hilos\Tables\ChangeLog;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableWindowPlan;
use Hilos\Database\ChangeLog\ChangeLogSectionReader;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryRow;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\User as ObjectUser;

/** Frozen, server-windowed history of one journaled table. */
class HilosChangeLogHistoryTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosChangeLogHistory';
    public const string FILTER_TABLE = 'table';
    public const string FILTER_RECORD = 'record';
    public const string FILTER_FIELD = 'field';
    public const string FILTER_PERIOD = 'period';

    private const string ROW_SLOT = 'history';
    private const int WINDOW_SIZE = 25;

    /** @return int Rows in the first window */
    public function windowSize(): int
    {
        return self::WINDOW_SIZE;
    }

    /** @return TableSortOrderDTO Default newest-first order */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(
            HilosChangeLogHistoryTableRow::createdAt,
            TableConstants::ORDER_DESC,
        ));
    }

    /** @return ?TableRowMutationDTO No live row source belongs to this table */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row History row
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

    /** @return array<string, WireField> Origins of every history field */
    public function wireFields(): array
    {
        return [
            HilosChangeLogHistoryTableRow::rowKey => WireField::notPersonal(),
            HilosChangeLogHistoryTableRow::createdAt => WireField::notPersonal(),
            HilosChangeLogHistoryTableRow::recordKey => WireField::notPersonal(),
            HilosChangeLogHistoryTableRow::mutation => WireField::notPersonal(),
            HilosChangeLogHistoryTableRow::changes => WireField::each([
                HilosChangeLogHistoryTableRow::changeField => WireField::notPersonal(),
                HilosChangeLogHistoryTableRow::changeKind => WireField::notPersonal(),
                HilosChangeLogHistoryTableRow::changeOldPresent => WireField::notPersonal(),
                HilosChangeLogHistoryTableRow::changeNewPresent => WireField::notPersonal(),
                HilosChangeLogHistoryTableRow::changeOldValue => WireField::notPersonal(),
                HilosChangeLogHistoryTableRow::changeNewValue => WireField::notPersonal(),
                HilosChangeLogHistoryTableRow::changeBodyOmitted => WireField::notPersonal(),
            ]),
            HilosChangeLogHistoryTableRow::receiptId => WireField::notPersonal(),
            HilosChangeLogHistoryTableRow::actorId => WireField::notPersonal(),
            HilosChangeLogHistoryTableRow::actorLabel => WireField::column(HilosDbContext::users, ObjectUser::name),
            HilosChangeLogHistoryTableRow::actorDeleted => WireField::notPersonal(),
            HilosChangeLogHistoryTableRow::subjectId => WireField::notPersonal(),
            HilosChangeLogHistoryTableRow::subjectLabel => WireField::column(HilosDbContext::users, ObjectUser::name),
            HilosChangeLogHistoryTableRow::subjectDeleted => WireField::notPersonal(),
            HilosChangeLogHistoryTableRow::channel => WireField::notPersonal(),
        ];
    }

    /** @return array<string, string> Supported sort field and its journal column */
    protected function sortableFields(): array
    {
        return [HilosChangeLogHistoryTableRow::createdAt => 'created_at'];
    }

    /** @return array<string, string> Search fields scoped by the viewer's visible values */
    protected function searchableFields(): array
    {
        return [
            HilosChangeLogHistoryTableRow::actorId => 'actor_user_id',
            HilosChangeLogHistoryTableRow::subjectId => 'subject_user_id',
            HilosChangeLogHistoryTableRow::actorLabel => 'actor_name',
            HilosChangeLogHistoryTableRow::subjectLabel => 'subject_name',
        ];
    }

    /**
     * @param TableQueryDTO $query Scoped viewport request
     * @return TableSnapshotDTO Frozen journal window
     * @throws InvalidArgumentException When a filter or anchor is invalid
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $limit = $query->limit === TableConstants::NO_LIMIT
            ? ChangeLogSectionReader::MAX_WINDOW_ROWS : min($query->limit, ChangeLogSectionReader::MAX_WINDOW_ROWS);
        $served = $query->withLimit($limit);
        $record = $query->filter[self::FILTER_RECORD] ?? null;
        if ($record !== null && (!is_array($record) || $record === [] || !array_is_list($record)
            || array_filter($record, static fn(mixed $part): bool => !is_int($part) && !is_string($part)) !== [])) {
            throw new InvalidArgumentException('Change log history record filter must be a list of key parts');
        }
        $who = HilosChangeLogTableParts::who($query);
        $since = HilosChangeLogTableParts::since($query->filter[self::FILTER_PERIOD] ?? null);
        $anchor = $query->anchor === null ? null : self::readAnchor($query->anchor);
        $table = HilosChangeLogTableParts::optionalFilter($query->filter[self::FILTER_TABLE] ?? null);
        if ($table === null || $who === false) {
            return new TableSnapshotDTO(limit: $limit, rowsBefore: 0);
        }
        $filter = new ChangeLogHistoryFilter(
            recordKey: $record,
            field: HilosChangeLogTableParts::optionalFilter($query->filter[self::FILTER_FIELD] ?? null),
            who: $who,
            since: $since,
        );
        $newestFirst = $query->sort === null || $query->sort->last()->direction === TableConstants::ORDER_DESC;
        $reader = new ChangeLogSectionReader();
        $count = $reader->historyCount($table, $filter);
        $exact = $count <= TableConstants::COUNT_CEILING;
        $total = min($count, TableConstants::COUNT_CEILING);

        $direction = $query->anchorDirection;
        $skip = 0;
        $take = $limit;
        if ($query->pageIndex !== null) {
            $plan = HilosChangeLogTableParts::numberedWindow($query->pageIndex, $limit, $total, $exact);
            if ($plan === null) {
                return new TableSnapshotDTO(
                    totalCount: $total, totalExact: $exact, limit: $limit,
                    rowsBefore: $exact ? TableWindowPlan::knownRowsBefore($served, null, $total) : null,
                );
            }
            $direction = $plan['direction'];
            $skip = $plan['skip'];
            $take = $plan['take'];
        }
        $items = $reader->historyWindow($table, $filter, $newestFirst, $anchor, $direction, $take, $skip);
        $first = $items === [] ? null : self::anchorFor($items[0]);
        $last = $items === [] ? null : self::anchorFor($items[count($items) - 1]);
        $rowsBefore = null;
        if ($exact) {
            $rowsBefore = TableWindowPlan::knownRowsBefore($served, $first, $total);
            if ($rowsBefore === null && $first !== null) {
                $rowsBefore = $reader->historyCountBefore($table, $filter, $newestFirst, self::readAnchor($first));
            }
        }
        return new TableSnapshotDTO(
            rows: array_map(HilosChangeLogHistoryTableRow::fromItem(...), $items),
            totalCount: $total,
            totalExact: $exact,
            limit: $limit,
            firstAnchor: $first,
            lastAnchor: $last,
            rowsBefore: $rowsBefore,
        );
    }

    /** Configures the browser row representation. */
    protected function init(): void
    {
        $this->setRowClass(HilosChangeLogHistoryTableRow::class);
    }

    /**
     * @param TableAnchorDTO $anchor Browser-returned boundary
     * @return ChangeLogHistoryAnchor Reader key
     * @throws InvalidArgumentException When any key component is invalid
     */
    private static function readAnchor(TableAnchorDTO $anchor): ChangeLogHistoryAnchor
    {
        return new ChangeLogHistoryAnchor(
            HilosChangeLogTableParts::readAnchorTime($anchor),
            HilosChangeLogTableParts::readAnchorId($anchor),
        );
    }

    /** @return TableAnchorDTO Lossless key of a journal row */
    private static function anchorFor(ChangeLogHistoryRow $item): TableAnchorDTO
    {
        return new TableAnchorDTO([
            HilosChangeLogTableParts::ANCHOR_CREATED_AT => HilosChangeLogTableParts::anchorTime($item->entry->createdAt),
            HilosChangeLogTableParts::ANCHOR_ID => $item->entry->id,
        ]);
    }
}
