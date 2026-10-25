<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use DateTimeImmutable;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\ChangeLog\Section\ChangeLogEntry as SectionChangeLogEntry;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogHistoryRow;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\ChangeLogEntries as ObjectChangeLogEntries;
use Hilos\Database\View\Item\ChangeLogEntry;
use Hilos\HilosException;

/**
 * Addressed by global row id; set reads require a bounded query.
 *
 * @extends DbCollection<ChangeLogEntry, ObjectChangeLogEntries>
 * @method ObjectChangeLogEntries|null getObjectCollection()
 * @method ChangeLogEntry|null current()
 * @method ChangeLogEntry|null first()
 * @method ChangeLogEntry|null last()
 * @method ChangeLogEntry offsetGet(mixed $offset)
 */
class ChangeLogEntries extends DbCollection
{
    public const int MAX_WINDOW_ROWS = 50;
    public const string DB_ITEM_CLASS = ChangeLogEntry::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogEntries::class;

    /**
     * @return ?DateTimeImmutable UTC source time of the lowest journal id, or null for an empty journal
     * @throws DatabaseException When the collection is not database-backed or SQL or moment decoding fails
     */
    public function oldestCreatedAt(): ?DateTimeImmutable
    {
        return ($this->getObjectCollection()
            ?? throw new DatabaseException('Journal section reads require an Object collection'))
            ->oldestCreatedAt();
    }

    /**
     * @param string $table Live or formerly journaled table name
     * @param ChangeLogHistoryFilter $filter Record, field, person and period filters
     * @param bool $newestFirst Whether canonical order is descending source time and id
     * @param ?ChangeLogHistoryAnchor $anchor Complete row key, or an edge
     * @param TableAnchorDirection $direction Side of the anchor in canonical order
     * @param int $limit Requested rows, capped at the section window limit
     * @param int $skip Edge offset, clamped to nonnegative and bounded by the count ceiling
     * @return ChangeLogReadCollection<ChangeLogHistoryRow> Dense rows in canonical order, omitting vanished entries
     * @throws InvalidArgumentException When the record key is invalid or a skip is used with an anchor
     * @throws HilosException When journal, dictionary, schema or person reads or moment decoding fail
     */
    public function historyWindow(
        string $table,
        ChangeLogHistoryFilter $filter,
        bool $newestFirst,
        ?ChangeLogHistoryAnchor $anchor,
        TableAnchorDirection $direction,
        int $limit,
        int $skip = 0,
    ): ChangeLogReadCollection {
        return $this->getObjectCollection()?->historyWindow($table, $filter, $newestFirst, $anchor, $direction, $limit, $skip)
            ?? ChangeLogReadCollection::fromArray([]);
    }

    /**
     * @param string $table Live or formerly journaled table name
     * @param ChangeLogHistoryFilter $filter Record, field, person and period filters
     * @param int $ceiling Exact-count ceiling, clamped to the table ceiling
     * @return int Matching rows, capped at the clamped ceiling plus one without entry hydration
     * @throws InvalidArgumentException When the record key does not match the live primary key
     * @throws HilosException When journal, dictionary, schema or person reads fail
     */
    public function historyCount(
        string $table,
        ChangeLogHistoryFilter $filter,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        return $this->getObjectCollection()?->historyCount($table, $filter, $ceiling) ?? 0;
    }

    /**
     * @param string $table Live or formerly journaled table name
     * @param ChangeLogHistoryFilter $filter Record, field, person and period filters
     * @param bool $newestFirst Chosen canonical order
     * @param ChangeLogHistoryAnchor $anchor Complete row key
     * @param int $ceiling Exact-count ceiling, clamped to the table ceiling
     * @return int Strictly preceding rows, capped at the clamped ceiling plus one without entry hydration
     * @throws InvalidArgumentException When the record key does not match the live primary key
     * @throws HilosException When journal, dictionary, schema or person reads fail
     */
    public function historyCountBefore(
        string $table,
        ChangeLogHistoryFilter $filter,
        bool $newestFirst,
        ChangeLogHistoryAnchor $anchor,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        return $this->getObjectCollection()?->historyCountBefore($table, $filter, $newestFirst, $anchor, $ceiling) ?? 0;
    }

    /**
     * @param int $receiptId Receipt whose entries are read
     * @param ?int $afterEntryId Last entry of the preceding portion; an invalid cursor yields an empty snapshot
     * @param int $limit Requested rows, capped at MAX_WINDOW_ROWS
     * @return ChangeLogReadCollection<SectionChangeLogEntry> Dense full entries ordered by source time and id
     * @throws DatabaseException When journal SQL or moment decoding fails
     */
    public function receiptEntries(int $receiptId, ?int $afterEntryId, int $limit): ChangeLogReadCollection
    {
        return $this->getObjectCollection()?->receiptEntries($receiptId, $afterEntryId, $limit)
            ?? ChangeLogReadCollection::fromArray([]);
    }

    /**
     * @param int $entryId Journal row number
     * @return ?SectionChangeLogEntry Complete entry with long values, or null when absent
     * @throws DatabaseException When journal SQL or moment decoding fails
     */
    public function sectionEntry(int $entryId): ?SectionChangeLogEntry
    {
        return $this->getObjectCollection()?->sectionEntry($entryId);
    }

    /**
     * @param list<int> $ids Finite selected entry ids, read in batches of at most MAX_WINDOW_ROWS
     * @param bool $includeLong Whether to fetch long-value bodies
     * @return ChangeLogReadCollection<SectionChangeLogEntry> Fresh entries keyed by id, omitting absent ids
     * @throws DatabaseException When journal SQL or moment decoding fails
     */
    public function sectionEntriesByIds(array $ids, bool $includeLong): ChangeLogReadCollection
    {
        return $this->getObjectCollection()?->sectionEntriesByIds($ids, $includeLong)
            ?? ChangeLogReadCollection::fromArray([]);
    }
}
