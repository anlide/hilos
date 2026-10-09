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
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\ChangeLog\ChangeLogSectionReader;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItem;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItemKind;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Core\Table\TableWindowPlan;

/** Frozen, server-windowed feed of nonempty receipts and bare journal writes. */
class HilosChangeLogFeedTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'hilosChangeLogFeed';
    public const string FILTER_CHANNEL = 'channel';
    public const string FILTER_TABLE = 'table';
    public const string FILTER_PERIOD = 'period';

    private const string ROW_SLOT = 'feed';
    private const int WINDOW_SIZE = 25;

    /** @return int Rows in the first window */
    public function windowSize(): int
    {
        return self::WINDOW_SIZE;
    }

    /** @return ?TableRowMutationDTO No live row source belongs to this table */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * @param AbstractTableRow $row Feed row
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

    /** @return array<string, WireField> Origins of every feed field */
    public function wireFields(): array
    {
        return [
            HilosChangeLogFeedTableRow::rowKey => WireField::notPersonal(),
            HilosChangeLogFeedTableRow::kind => WireField::notPersonal(),
            HilosChangeLogFeedTableRow::receiptId => WireField::notPersonal(),
            HilosChangeLogFeedTableRow::entryId => WireField::notPersonal(),
            HilosChangeLogFeedTableRow::createdAt => WireField::notPersonal(),
            HilosChangeLogFeedTableRow::actorId => WireField::notPersonal(),
            HilosChangeLogFeedTableRow::actorLabel => WireField::column(HilosDbContext::users, ObjectUser::name),
            HilosChangeLogFeedTableRow::actorDeleted => WireField::notPersonal(),
            HilosChangeLogFeedTableRow::subjectId => WireField::notPersonal(),
            HilosChangeLogFeedTableRow::subjectLabel => WireField::column(HilosDbContext::users, ObjectUser::name),
            HilosChangeLogFeedTableRow::subjectDeleted => WireField::notPersonal(),
            HilosChangeLogFeedTableRow::channel => WireField::notPersonal(),
            HilosChangeLogFeedTableRow::action => WireField::notPersonal(),
            HilosChangeLogFeedTableRow::touched => WireField::each([
                HilosChangeLogFeedTableRow::touchedTable => WireField::notPersonal(),
                HilosChangeLogFeedTableRow::touchedRecords => WireField::notPersonal(),
                HilosChangeLogFeedTableRow::touchedRecordKey => WireField::notPersonal(),
                HilosChangeLogFeedTableRow::touchedMutation => WireField::notPersonal(),
                HilosChangeLogFeedTableRow::touchedChangedFields => WireField::notPersonal(),
            ]),
        ];
    }

    /** @return array<string, string> Search fields scoped by the viewer's visible values */
    protected function searchableFields(): array
    {
        return [
            HilosChangeLogFeedTableRow::actorId => 'actor_user_id',
            HilosChangeLogFeedTableRow::subjectId => 'subject_user_id',
            HilosChangeLogFeedTableRow::actorLabel => 'actor_name',
            HilosChangeLogFeedTableRow::subjectLabel => 'subject_name',
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
        $who = HilosChangeLogTableParts::who($query);
        $filter = new ChangeLogFeedFilter(
            who: $who === false ? null : $who,
            channel: HilosChangeLogTableParts::optionalFilter($query->filter[self::FILTER_CHANNEL] ?? null),
            table: HilosChangeLogTableParts::optionalFilter($query->filter[self::FILTER_TABLE] ?? null),
            since: HilosChangeLogTableParts::since($query->filter[self::FILTER_PERIOD] ?? null),
        );
        $anchor = $query->anchor === null ? null : self::readAnchor($query->anchor);
        if ($who === false) {
            return new TableSnapshotDTO(limit: $limit, rowsBefore: 0);
        }
        $reader = new ChangeLogSectionReader();
        $count = $reader->feedCount($filter);
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
        $items = $reader->feedWindow($filter, $anchor, $direction, $take, $skip);
        $first = $items === [] ? null : self::anchorFor($items[0]);
        $last = $items === [] ? null : self::anchorFor($items[count($items) - 1]);
        $rowsBefore = null;
        if ($exact) {
            $rowsBefore = TableWindowPlan::knownRowsBefore($served, $first, $total);
            if ($rowsBefore === null && $first !== null) {
                $rowsBefore = $reader->feedCountBefore($filter, self::readAnchor($first));
            }
        }
        return new TableSnapshotDTO(
            rows: array_map(HilosChangeLogFeedTableRow::fromItem(...), $items),
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
        $this->setRowClass(HilosChangeLogFeedTableRow::class);
    }

    /**
     * @param TableAnchorDTO $anchor Browser-returned boundary
     * @return ChangeLogFeedAnchor Reader key
     * @throws InvalidArgumentException When any key component is invalid
     */
    private static function readAnchor(TableAnchorDTO $anchor): ChangeLogFeedAnchor
    {
        $kind = $anchor->values[HilosChangeLogTableParts::ANCHOR_KIND] ?? null;
        if (!is_string($kind) || ChangeLogFeedItemKind::tryFrom($kind) === null) {
            throw new InvalidArgumentException('Change log feed anchor carries no kind');
        }
        return new ChangeLogFeedAnchor(
            HilosChangeLogTableParts::readAnchorTime($anchor),
            ChangeLogFeedItemKind::from($kind),
            HilosChangeLogTableParts::readAnchorId($anchor),
        );
    }

    /** @return TableAnchorDTO Lossless key of a feed item */
    private static function anchorFor(ChangeLogFeedItem $item): TableAnchorDTO
    {
        return new TableAnchorDTO([
            HilosChangeLogTableParts::ANCHOR_CREATED_AT => HilosChangeLogTableParts::anchorTime($item->createdAt),
            HilosChangeLogTableParts::ANCHOR_KIND => $item->kind->value,
            HilosChangeLogTableParts::ANCHOR_ID => $item->receipt?->id ?? $item->entry?->id,
        ]);
    }
}
