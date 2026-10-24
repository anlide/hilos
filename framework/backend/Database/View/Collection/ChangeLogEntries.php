<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\ChangeLog\Section\ChangeLogEntry as SectionChangeLogEntry;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\ChangeLogEntries as ObjectChangeLogEntries;
use Hilos\Database\View\Item\ChangeLogEntry;

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
