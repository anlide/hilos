<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\TableConstants;

/**
 * TableViewportSubscription - Worker-local record of one table's live viewport.
 *
 * Holds the window descriptor a connection requested for a table (filter, sort,
 * offset, limit) plus the row-id keys the server has actually delivered to that
 * connection. Only the KEYS are kept, never the row bodies: a window body can be
 * large, and the point-wise delta diff needs only membership plus the descriptor.
 *
 * The descriptor is immutable; the delivered row-id set and the total count are
 * updated as windows are served and as rows leave the set.
 */
final class TableViewportSubscription
{
    /** @var list<string> Row-id keys the server has delivered to this connection */
    private array $rowIds = [];

    /** Total rows matching the filter at the last window build. */
    private int $totalCount = 0;

    /**
     * @param string $tableKey Table the viewport scopes
     * @param array<string, mixed> $filter Open filter map, resolved to a query by the concrete table
     * @param ?TableSortDTO $sort Requested ordering, or null for backend arrival order
     * @param int $offset Zero-based window offset
     * @param int $limit Window size (TableConstants::NO_LIMIT = all rows)
     */
    public function __construct(
        public readonly string $tableKey,
        public readonly array $filter = [],
        public readonly ?TableSortDTO $sort = null,
        public readonly int $offset = 0,
        public readonly int $limit = TableConstants::NO_LIMIT,
    ) {
    }

    /**
     * Records the row-id keys and total count of a freshly served window.
     *
     * @param list<string> $rowIds Row-id keys delivered in the window, in display order
     * @param int $totalCount Total rows matching the filter
     */
    public function recordWindow(array $rowIds, int $totalCount): void
    {
        $this->rowIds = array_values($rowIds);
        $this->totalCount = $totalCount;
    }

    /**
     * Whether a row-id key is currently delivered to this connection.
     *
     * @param string $rowKey Row-id key
     * @return bool Whether the key is in the delivered set
     */
    public function hasRow(string $rowKey): bool
    {
        return in_array($rowKey, $this->rowIds, true);
    }

    /**
     * Drops a row-id key from the delivered set (the row left the window).
     *
     * @param string $rowKey Row-id key to drop
     */
    public function forgetRow(string $rowKey): void
    {
        $this->rowIds = array_values(array_filter(
            $this->rowIds,
            static fn(string $id): bool => $id !== $rowKey,
        ));
    }

    /**
     * Row-id keys currently delivered to this connection, in display order.
     *
     * @return list<string> Delivered row-id keys
     */
    public function rowIds(): array
    {
        return $this->rowIds;
    }

    /**
     * Total rows matching the filter at the last window build.
     *
     * @return int Total row count
     */
    public function totalCount(): int
    {
        return $this->totalCount;
    }
}
