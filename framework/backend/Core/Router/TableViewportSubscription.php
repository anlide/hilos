<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;

/**
 * TableViewportSubscription - Worker-local record of one table's live viewport.
 *
 * Holds the window descriptor a connection requested for a table (filter, sort, size, and the
 * anchor or page number it is addressed by) plus, for every row the server has actually delivered to that
 * connection, a digest of the delivered row. The digest is kept, never the row
 * body: it answers both questions the delta path asks - is this row in the window,
 * and is it still the row this connection was given - while the memory a window
 * costs stays fixed per row rather than growing with the payload.
 *
 * The descriptor is immutable; the delivered rows, the total count and the two places the
 * window sits between are updated as windows are served and as rows leave the set.
 */
final class TableViewportSubscription
{
    private const string ROW_DIGEST_ALGO = 'xxh128';

    /** @var array<string, ?string> Digest of each delivered row, keyed by row-id key, in display order */
    private array $rowDigests = [];

    /** Total rows matching the filter at the last window build. */
    private int $totalCount = 0;

    /** Place the first row of the last served window sits at, or null when that window was empty. */
    private ?TableAnchorDTO $firstAnchor = null;

    /** Place the last row of the last served window sits at, or null when that window was empty. */
    private ?TableAnchorDTO $lastAnchor = null;

    /**
     * @param string $tableKey Table the viewport scopes
     * @param array<string, mixed> $filter Open filter map, resolved to a query by the concrete table
     * @param ?TableSortOrderDTO $sort Requested order, or null for backend arrival order
     * @param int $limit Window size (TableConstants::NO_LIMIT = all rows)
     * @param ?TableAnchorDTO $anchor Place the window was asked from, or null for the edge of the set
     * @param TableAnchorDirection $anchorDirection Side of the anchor, and which edge a null anchor means
     * @param ?int $pageIndex Zero-based page the window jumped to, or null when it is paged by anchor
     */
    public function __construct(
        public readonly string $tableKey,
        public readonly array $filter = [],
        public readonly ?TableSortOrderDTO $sort = null,
        public readonly int $limit = TableConstants::NO_LIMIT,
        public readonly ?TableAnchorDTO $anchor = null,
        public readonly TableAnchorDirection $anchorDirection = TableAnchorDirection::After,
        public readonly ?int $pageIndex = null,
    ) {
    }

    /**
     * Records the rows and total count of a freshly served window.
     *
     * @param array<string, array{rowKey: int|string, slots: array<string, mixed>}> $wireRows Wire rows
     *     delivered in the window, keyed by row-id key, in display order
     * @param int $totalCount Total rows matching the filter
     * @param ?TableAnchorDTO $firstAnchor Place the first delivered row sits at, or null when none was
     * @param ?TableAnchorDTO $lastAnchor Place the last delivered row sits at, or null when none was
     */
    public function recordWindow(array $wireRows, int $totalCount, ?TableAnchorDTO $firstAnchor, ?TableAnchorDTO $lastAnchor): void
    {
        $this->rowDigests = array_map(self::digest(...), $wireRows);
        $this->totalCount = $totalCount;
        $this->firstAnchor = $firstAnchor;
        $this->lastAnchor = $lastAnchor;
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
     * Place the first row of the last served window sits at.
     *
     * SCAFFOLD: nothing on the server reads this yet — the client holds its own boundaries and
     * echoes them back, so paging needs no help from here. It is kept because the server is
     * about to need it for itself: saying whether an arriving row falls above the window means
     * comparing it against this boundary, which is HIL-791.
     *
     * @return ?TableAnchorDTO Boundary the window pages back from, or null when it was empty
     */
    public function firstAnchor(): ?TableAnchorDTO
    {
        return $this->firstAnchor;
    }

    /**
     * Place the last row of the last served window sits at.
     *
     * SCAFFOLD: unread for the same reason as {@see firstAnchor()}, and kept beside it — the
     * pair is what a window sits between, and one of them alone answers nothing.
     *
     * @return ?TableAnchorDTO Boundary the window pages on from, or null when it was empty
     */
    public function lastAnchor(): ?TableAnchorDTO
    {
        return $this->lastAnchor;
    }

    /**
     * Whether the delivered window runs to the end of the filtered set.
     *
     * A window addressed by anchor has no position to report, so the end is read off the one
     * thing that does say: a window shorter than what it asked for ran out of rows. That answers
     * only while it is paging forward - paging back the window stops at the anchor, and a full
     * last page is not recognized until the client asks once more and gets nothing. A window that
     * jumped to a numbered page does know its place, and a window with no limit holds the set.
     *
     * The answer is read rather than stored because the delivered set moves after the window is
     * served: a row appended to a window with room joins it, and a stored flag would still be
     * describing the window before it.
     *
     * @return bool Whether the last row of the set is in the delivered window
     */
    public function reachesEnd(): bool
    {
        if ($this->limit === TableConstants::NO_LIMIT) {
            return true;
        }

        $windowSize = count($this->rowDigests);
        if ($this->pageIndex !== null) {
            return $this->pageIndex * $this->limit + $windowSize >= $this->totalCount;
        }

        return $this->anchorDirection === TableAnchorDirection::After && $windowSize < $this->limit;
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
