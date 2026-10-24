<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedAnchor;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItem;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\ChangeLog\Section\ChangeLogReceipt as SectionChangeLogReceipt;
use Hilos\Database\ChangeLog\Section\ChangeLogReceiptHeader;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\ChangeLogReceipts as ObjectChangeLogReceipts;
use Hilos\Database\View\Item\ChangeLogReceipt;

/**
 * Addressed by global row id; set reads require a bounded query.
 *
 * @extends DbCollection<ChangeLogReceipt, ObjectChangeLogReceipts>
 * @method ObjectChangeLogReceipts|null getObjectCollection()
 * @method ChangeLogReceipt|null current()
 * @method ChangeLogReceipt|null first()
 * @method ChangeLogReceipt|null last()
 * @method ChangeLogReceipt offsetGet(mixed $offset)
 */
class ChangeLogReceipts extends DbCollection
{
    public const string DB_ITEM_CLASS = ChangeLogReceipt::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogReceipts::class;

    /**
     * @param ChangeLogFeedFilter $filter Receipt and bare-entry filters
     * @param ?ChangeLogFeedAnchor $anchor Complete time, kind and row key, or an edge
     * @param TableAnchorDirection $direction Side of the anchor in newest-first order
     * @param int $limit Requested rows, capped at the section window limit
     * @param int $skip Nonnegative edge offset, at most the table count ceiling
     * @return ChangeLogReadCollection<ChangeLogFeedItem> Dense rows in canonical newest-first order
     * @throws InvalidArgumentException When a skip is used with an anchor
     * @throws DatabaseException When journal or person SQL or moment decoding fails
     */
    public function feedWindow(
        ChangeLogFeedFilter $filter,
        ?ChangeLogFeedAnchor $anchor,
        TableAnchorDirection $direction,
        int $limit,
        int $skip = 0,
    ): ChangeLogReadCollection {
        return $this->getObjectCollection()?->feedWindow($filter, $anchor, $direction, $limit, $skip)
            ?? ChangeLogReadCollection::fromArray([]);
    }

    /**
     * @param ChangeLogFeedFilter $filter Receipt and bare-entry filters
     * @param int $ceiling Exact-count ceiling, clamped to the table ceiling
     * @return int Matching rows, capped at the clamped ceiling plus one
     * @throws DatabaseException When journal or person SQL fails
     */
    public function feedCount(ChangeLogFeedFilter $filter, int $ceiling = TableConstants::COUNT_CEILING): int
    {
        return $this->getObjectCollection()?->feedCount($filter, $ceiling) ?? 0;
    }

    /**
     * @param ChangeLogFeedFilter $filter Receipt and bare-entry filters
     * @param ChangeLogFeedAnchor $anchor Complete row key
     * @param int $ceiling Exact-count ceiling, clamped to the table ceiling
     * @return int Strictly preceding rows, capped at the clamped ceiling plus one
     * @throws DatabaseException When journal or person SQL fails
     */
    public function feedCountBefore(
        ChangeLogFeedFilter $filter,
        ChangeLogFeedAnchor $anchor,
        int $ceiling = TableConstants::COUNT_CEILING,
    ): int {
        return $this->getObjectCollection()?->feedCountBefore($filter, $anchor, $ceiling) ?? 0;
    }

    /**
     * @param int $id Receipt row number
     * @return ?SectionChangeLogReceipt Full summary, or null for a missing or empty receipt
     * @throws DatabaseException When journal or person SQL or moment decoding fails
     */
    public function sectionReceipt(int $id): ?SectionChangeLogReceipt
    {
        return $this->getObjectCollection()?->sectionReceipt($id);
    }

    /**
     * @param list<int> $ids Finite selected receipt ids, read in batches of at most the section window limit
     * @return ChangeLogReadCollection<SectionChangeLogReceipt> Full summaries keyed by receipt id, omitting missing ids
     * @throws DatabaseException When journal or person SQL or moment decoding fails
     */
    public function sectionReceiptsByIds(array $ids): ChangeLogReadCollection
    {
        return $this->getObjectCollection()?->sectionReceiptsByIds($ids) ?? ChangeLogReadCollection::fromArray([]);
    }

    /**
     * @param list<int> $ids Finite selected receipt ids, read in batches of at most the section window limit
     * @return ChangeLogReadCollection<ChangeLogReceiptHeader> Attribution keyed by receipt id without entry summaries
     * @throws DatabaseException When journal or person SQL or moment decoding fails
     */
    public function sectionHeadersByIds(array $ids): ChangeLogReadCollection
    {
        return $this->getObjectCollection()?->sectionHeadersByIds($ids) ?? ChangeLogReadCollection::fromArray([]);
    }
}
