<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\TableConstants;

/**
 * TableViewportSubscription - Worker-local record of one table's live viewport.
 *
 * Holds the window descriptor a connection requested for a table (filter, sort,
 * offset, limit) plus, for every row the server has actually delivered to that
 * connection, a digest of the delivered row. The digest is kept, never the row
 * body: it answers both questions the delta path asks - is this row in the window,
 * and is it still the row this connection was given - while the memory a window
 * costs stays fixed per row rather than growing with the payload.
 *
 * The descriptor is immutable; the delivered rows and the total count are updated
 * as windows are served and as rows leave the set.
 */
final class TableViewportSubscription
{
    private const string ROW_DIGEST_ALGO = 'xxh128';

    /** @var array<string, ?string> Digest of each delivered row, keyed by row-id key, in display order */
    private array $rowDigests = [];

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
     * Records the rows and total count of a freshly served window.
     *
     * @param array<string, array{rowKey: int|string, slots: array<string, mixed>}> $wireRows Wire rows
     *     delivered in the window, keyed by row-id key, in display order
     * @param int $totalCount Total rows matching the filter
     */
    public function recordWindow(array $wireRows, int $totalCount): void
    {
        $this->rowDigests = array_map(self::digest(...), $wireRows);
        $this->totalCount = $totalCount;
    }

    /**
     * Records one row delivered to this connection outside a whole-window build.
     *
     * @param string $rowKey Row-id key
     * @param array{rowKey: int|string, slots: array<string, mixed>} $wireRow Wire row delivered for that key
     */
    public function recordRow(string $rowKey, array $wireRow): void
    {
        $this->rowDigests[$rowKey] = self::digest($wireRow);
    }

    /**
     * Records a new total without touching the delivered rows.
     *
     * @param int $totalCount Total rows matching the filter
     */
    public function recordTotal(int $totalCount): void
    {
        $this->totalCount = $totalCount;
    }

    /**
     * Whether a row is byte-for-byte the row this connection was last given.
     *
     * A row whose digest is unknown - never delivered, or delivered when it could
     * not be encoded - never matches: only a proven match may silence a delta.
     *
     * @param string $rowKey Row-id key
     * @param array{rowKey: int|string, slots: array<string, mixed>} $wireRow Wire row to compare
     * @return bool Whether the delivered row and the given one are the same
     */
    public function matchesRow(string $rowKey, array $wireRow): bool
    {
        $delivered = $this->rowDigests[$rowKey] ?? null;
        $candidate = self::digest($wireRow);

        return $delivered !== null && $candidate !== null && $delivered === $candidate;
    }

    /**
     * Whether a row-id key is currently delivered to this connection.
     *
     * @param string $rowKey Row-id key
     * @return bool Whether the key is in the delivered set
     */
    public function hasRow(string $rowKey): bool
    {
        return array_key_exists($rowKey, $this->rowDigests);
    }

    /**
     * Drops a row from the delivered set (the row left the window).
     *
     * @param string $rowKey Row-id key to drop
     */
    public function forgetRow(string $rowKey): void
    {
        unset($this->rowDigests[$rowKey]);
    }

    /**
     * Row-id keys currently delivered to this connection, in display order.
     *
     * A numeric key comes back out of the map as an int, so the keys are cast
     * before they leave: callers have always been handed strings.
     *
     * @return list<string> Delivered row-id keys
     */
    public function rowIds(): array
    {
        return array_map(strval(...), array_keys($this->rowDigests));
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

    /**
     * Digest of one delivered wire row, or null when it cannot be encoded.
     *
     * @param array{rowKey: int|string, slots: array<string, mixed>} $wireRow Wire row as delivered
     * @return ?string Digest of the row, or null when json_encode refused it
     */
    private static function digest(array $wireRow): ?string
    {
        $encoded = json_encode($wireRow);

        return $encoded === false ? null : hash(self::ROW_DIGEST_ALGO, $encoded);
    }
}
