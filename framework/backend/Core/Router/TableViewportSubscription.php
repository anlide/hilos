<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableWindowFrameDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;

/**
 * TableViewportSubscription - Worker-local record of one table's live viewport.
 *
 * Holds the window descriptor a connection requested for a table (filter, sort, size, and the
 * anchor or page number it is addressed by) plus, for every row the server has actually delivered to that
 * connection, a digest of the delivered row and the place that row stood at. The digest is
 * kept, never the row body: it answers both questions the delta path asks - is this row in the
 * window, and is it still the row this connection was given - while the memory a window
 * costs stays fixed per row rather than growing with the payload. The place is kept beside it
 * on the same terms: an anchor is the values of the fields the order is settled by, not a
 * second copy of the row, and it is what lets an edit be judged against the places of the
 * row's NEIGHBOURS rather than against its own former place (HIL-793).
 *
 * A window whose tab declared the fields it draws keeps a second digest beside the first, of the
 * row cut down to those fields (HIL-880). The two answer different questions and neither stands
 * in for the other: the whole row says whether anything reached this connection that it has not
 * seen, the drawn part says whether the screen would show it. A row can leave its set or move in
 * the order over a field nobody draws, so only the second question is ever answered by the cut.
 *
 * The window also keeps the places standing right outside it, as its source reported them at
 * the build (HIL-1037). They never reach the client: they are what lets an edit that moved a row
 * past its outermost neighbour be told apart from one that moved it off the page, and what says
 * whether the window holds an edge of the set. A source that reports no frame - a snapshot a
 * project table builds by hand - leaves the window to read its edges off its address and the
 * size of the build, as it did before the frame existed.
 *
 * The descriptor is immutable; the delivered rows, the total count with the word on how exact
 * it is, the two places the window sits between and the two framing it are updated as windows
 * are served and as rows leave the set.
 */
final class TableViewportSubscription
{
    private const string ROW_DIGEST_ALGO = 'xxh128';

    /** @var array<string, ?string> Digest of each delivered row, keyed by row-id key, in display order */
    private array $rowDigests = [];

    /** @var array<string, ?string> Digest of the drawn part of each delivered row, keyed by row-id key; empty when nothing is declared */
    private array $renderedDigests = [];

    /** @var array<string, ?TableAnchorDTO> Place each delivered row stood at, keyed by row-id key, in display order */
    private array $rowAnchors = [];

    /** Total rows matching the filter at the last window build. */
    private int $totalCount = 0;

    /** Whether that total is the size of the set rather than the ceiling the count stopped at. */
    private bool $totalExact = true;

    /** Rows the last window build delivered, which the edges of the set are read off when no frame was reported. */
    private int $builtRowCount = 0;

    /** Place the first row of the last served window sits at, or null when that window was empty. */
    private ?TableAnchorDTO $firstAnchor = null;

    /** Place the last row of the last served window sits at, or null when that window was empty. */
    private ?TableAnchorDTO $lastAnchor = null;

    /** Places standing right outside the last served window, or null when its source did not report them. */
    private ?TableWindowFrameDTO $frame = null;

    /**
     * @param string $tableKey Table the viewport scopes
     * @param array<string, mixed> $filter Open filter map, resolved to a query by the concrete table
     * @param ?TableSortOrderDTO $sort Requested order, or null for backend arrival order
     * @param int $limit Window size (TableConstants::NO_LIMIT = all rows)
     * @param ?TableAnchorDTO $anchor Place the window was asked from, or null for the edge of the set
     * @param TableAnchorDirection $anchorDirection Side of the anchor, and which edge a null anchor means
     * @param ?int $pageIndex Zero-based page the window jumped to, or null when it is paged by anchor
     * @param list<string> $rendered Fields inside the row slots the tab draws, empty when it declared none
     */
    public function __construct(
        public readonly string $tableKey,
        public readonly array $filter = [],
        public readonly ?TableSortOrderDTO $sort = null,
        public readonly int $limit = TableConstants::NO_LIMIT,
        public readonly ?TableAnchorDTO $anchor = null,
        public readonly TableAnchorDirection $anchorDirection = TableAnchorDirection::After,
        public readonly ?int $pageIndex = null,
        public readonly array $rendered = [],
    ) {
    }

    /**
     * Records the rows and total count of a freshly served window.
     *
     * Every build replaces the frame along with the boundary anchors, a build whose source did not
     * report one included: a frame is a snapshot of the build it came with, and one left over from
     * an earlier build would frame a window that is no longer there.
     *
     * @param array<string, array{rowKey: int|string, slots: array<string, mixed>, staleSources?: list<string>}> $wireRows Wire rows
     *     delivered in the window, keyed by row-id key, in display order
     * @param int $totalCount Total rows matching the filter
     * @param bool $totalExact Whether that total is the size of the set rather than the ceiling the count stopped at
     * @param ?TableAnchorDTO $firstAnchor Place the first delivered row sits at, or null when none was
     * @param ?TableAnchorDTO $lastAnchor Place the last delivered row sits at, or null when none was
     * @param array<string, ?TableAnchorDTO> $rowAnchors Place each delivered row stands at, keyed by row-id key,
     *     empty when the table was not asked or could not say
     * @param ?TableWindowFrameDTO $frame Places standing right outside the window, or null when its source did not
     *     report them
     */
    public function recordWindow(
        array $wireRows,
        int $totalCount,
        bool $totalExact,
        ?TableAnchorDTO $firstAnchor,
        ?TableAnchorDTO $lastAnchor,
        array $rowAnchors = [],
        ?TableWindowFrameDTO $frame = null,
    ): void {
        $this->rowDigests = array_map(self::digest(...), $wireRows);
        $this->renderedDigests = $this->rendered === [] ? [] : array_map($this->renderedDigest(...), $wireRows);
        $this->rowAnchors = $rowAnchors;
        $this->totalCount = $totalCount;
        $this->totalExact = $totalExact;
        $this->builtRowCount = count($wireRows);
        $this->firstAnchor = $firstAnchor;
        $this->lastAnchor = $lastAnchor;
        $this->frame = $frame;
    }

    /**
     * Records one row delivered to this connection outside a whole-window build.
     *
     * @param string $rowKey Row-id key
     * @param array{rowKey: int|string, slots: array<string, mixed>, staleSources?: list<string>} $wireRow Wire row delivered for that key
     * @param ?TableAnchorDTO $anchor Place that row stands at, or null when the table could not say
     */
    public function recordRow(string $rowKey, array $wireRow, ?TableAnchorDTO $anchor = null): void
    {
        $this->rowDigests[$rowKey] = self::digest($wireRow);
        if ($this->rendered !== []) {
            $this->renderedDigests[$rowKey] = $this->renderedDigest($wireRow);
        }
        $this->rowAnchors[$rowKey] = $anchor;
    }

    /**
     * Records a new total without touching the delivered rows.
     *
     * @param int $totalCount Total rows matching the filter
     * @param bool $totalExact Whether that total is the size of the set rather than the ceiling the count stopped at
     */
    public function recordTotal(int $totalCount, bool $totalExact): void
    {
        $this->totalCount = $totalCount;
        $this->totalExact = $totalExact;
    }

    /**
     * The same window, delivered rows and all, judged from now on by the fields the tab draws.
     *
     * This is how a window served before its tab could say what it draws - the cold entry, built
     * from the table's declaration while the table was not yet mounted - learns it afterwards
     * (HIL-880). Only the digests of the delivered rows are kept here and never the rows, so the
     * drawn part of each is taken off the rows as the table reads them now, and a row read now is
     * trusted for it only when its whole digest is still the one delivered. A row that changed in
     * between, and a row the read did not return, keep no drawn digest and are compared whole
     * until they are delivered again: a proof is what silences a delta, and there is none for them.
     *
     * Everything else is carried over as it stands, the descriptor included, so the window the
     * connection holds and the one it is judged by stay one window.
     *
     * @param list<string> $rendered Fields inside the row slots the tab draws, empty to compare whole rows
     * @param array<string, array{rowKey: int|string, slots: array<string, mixed>, staleSources?: list<string>}> $wireRows Rows of the
     *     window as the table reads them now, keyed by row-id key
     * @return self The window holding the list, with the drawn digests it could prove
     */
    public function withRendered(array $rendered, array $wireRows): self
    {
        $declared = new self(
            tableKey: $this->tableKey,
            filter: $this->filter,
            sort: $this->sort,
            limit: $this->limit,
            anchor: $this->anchor,
            anchorDirection: $this->anchorDirection,
            pageIndex: $this->pageIndex,
            rendered: $rendered,
        );
        $declared->rowDigests = $this->rowDigests;
        $declared->rowAnchors = $this->rowAnchors;
        $declared->totalCount = $this->totalCount;
        $declared->totalExact = $this->totalExact;
        $declared->builtRowCount = $this->builtRowCount;
        $declared->firstAnchor = $this->firstAnchor;
        $declared->lastAnchor = $this->lastAnchor;
        $declared->frame = $this->frame;
        if ($rendered === []) {
            return $declared;
        }

        foreach ($this->rowDigests as $rowKey => $delivered) {
            $wireRow = $wireRows[$rowKey] ?? null;
            if ($delivered !== null && $wireRow !== null && self::digest($wireRow) === $delivered) {
                $declared->renderedDigests[$rowKey] = $declared->renderedDigest($wireRow);
            }
        }

        return $declared;
    }

    /**
     * Whether a row is byte-for-byte the row this connection was last given.
     *
     * A row whose digest is unknown - never delivered, or delivered when it could
     * not be encoded - never matches: only a proven match may silence a delta.
     *
     * @param string $rowKey Row-id key
     * @param array{rowKey: int|string, slots: array<string, mixed>, staleSources?: list<string>} $wireRow Wire row to compare
     * @return bool Whether the delivered row and the given one are the same
     */
    public function matchesRow(string $rowKey, array $wireRow): bool
    {
        $delivered = $this->rowDigests[$rowKey] ?? null;
        $candidate = self::digest($wireRow);

        return $delivered !== null && $candidate !== null && $delivered === $candidate;
    }

    /**
     * Whether a row draws exactly as the row this connection was last given.
     *
     * Only the fields the tab declared are compared, so a row that changed in a field no cell
     * reads answers yes (HIL-880). A window that declared nothing has no cut to compare, and the
     * question falls back to the whole row, which is how every window was compared before. The
     * same proof is demanded as there: an unknown digest never matches.
     *
     * @param string $rowKey Row-id key
     * @param array{rowKey: int|string, slots: array<string, mixed>, staleSources?: list<string>} $wireRow Wire row to compare
     * @return bool Whether the delivered row and the given one draw the same
     */
    public function matchesRenderedRow(string $rowKey, array $wireRow): bool
    {
        if ($this->rendered === []) {
            return $this->matchesRow($rowKey, $wireRow);
        }

        $delivered = $this->renderedDigests[$rowKey] ?? null;
        $candidate = $this->renderedDigest($wireRow);

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
        unset($this->rowDigests[$rowKey], $this->renderedDigests[$rowKey], $this->rowAnchors[$rowKey]);
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
     * Place each delivered row stands at, in display order.
     *
     * This is what an edit of a shown row is judged against: the places of the rows around it,
     * read as they were delivered. A null place is a row the table could not name a place for,
     * and it is kept rather than skipped so that the map stays in step with the display order.
     *
     * @return array<string, ?TableAnchorDTO> Place of each delivered row, keyed by row-id key
     */
    public function rowAnchors(): array
    {
        return $this->rowAnchors;
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
     * Whether the recorded total is the size of the set rather than the ceiling the count stopped at.
     *
     * Everything derived from the total is derived from this too: a count that stopped at its
     * ceiling says "at least this many" and supports no page count, no nearer end of the set and
     * no end at all.
     *
     * @return bool Whether the total is the size of the filtered set
     */
    public function totalExact(): bool
    {
        return $this->totalExact;
    }

    /**
     * Place the first row of the last served window sits at.
     *
     * The server reads it for itself: an arriving row is above the window when it places above
     * this boundary, which is how a create is judged before anything is sent (HIL-791). The
     * client holds its own copy of the same boundary and echoes it back for paging, so the two
     * readings never meet.
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
     * Read together with {@see firstAnchor()} — the pair is what a window sits between, and one
     * of them alone answers nothing: a row not above the first boundary is inside the window
     * only if it is also not below this one.
     *
     * @return ?TableAnchorDTO Boundary the window pages on from, or null when it was empty
     */
    public function lastAnchor(): ?TableAnchorDTO
    {
        return $this->lastAnchor;
    }

    /**
     * Places standing right outside the last served window.
     *
     * A snapshot of that build, like the boundary anchors: an edit, a removal or a creation at the
     * edge of the window since then is not in it. A side without a place is the edge of the set.
     *
     * @return ?TableWindowFrameDTO Frame of the window, or null when its source did not report one
     */
    public function frame(): ?TableWindowFrameDTO
    {
        return $this->frame;
    }

    /**
     * Whether the delivered window runs from the start of the filtered set.
     *
     * A window whose source reported its frame holds the start exactly when nothing frames it
     * from above: the source looked one place further and found the edge of the set. That answer
     * is the build's, like the frame itself, and it is the same for every address.
     *
     * A window without a frame - its snapshot built by hand, or empty - reads the start off its
     * address and the size of its build, which is what every window did before the frame existed
     * and what keeps such a window from counting as the edge on every side. It is the twin of
     * {@see reachesEnd()}, read off the same three ways a window is addressed. A
     * window with no limit holds the set, and a window that jumped to a numbered page holds the
     * start on page zero. A window addressed by anchor holds the start when it was asked forward
     * from the edge of the set, or when it paged back and got fewer rows than it asked for:
     * nothing was left above it.
     *
     * For an anchored window the edge is a property of the last build: a row removed from the
     * delivered set does not create an edge, and a row appended afterwards does not take one
     * away. The build size is therefore kept apart from the live delivered rows.
     *
     * Unlike the end, the numbered-page reading does not need the total, so an inexact count
     * takes nothing away here: page zero is the start of the set however far the count got.
     *
     * @return bool Whether the first row of the set is in the delivered window
     */
    public function reachesStart(): bool
    {
        if ($this->limit === TableConstants::NO_LIMIT) {
            return true;
        }

        if ($this->frame !== null) {
            return $this->frame->before === null;
        }

        if ($this->pageIndex !== null) {
            return $this->pageIndex === 0;
        }

        if ($this->anchorDirection === TableAnchorDirection::After) {
            return $this->anchor === null;
        }

        return $this->builtRowCount < $this->limit;
    }

    /**
     * Whether the delivered window runs to the end of the filtered set.
     *
     * A window whose source reported its frame holds the end exactly when nothing frames it from
     * below. That reading is exact where the others are not: it answers a full last page paged
     * back to, and a numbered page past the count's ceiling, both of which the readings below
     * cannot tell from the middle of the set. It is the build's answer, so a row created into the
     * window afterwards does not take the end away and a row removed from it does not create one.
     *
     * A window without a frame - its snapshot built by hand, or empty - falls back on the readings
     * below, which are what every window did before the frame existed. A window addressed by
     * anchor has no position to report, so the end is read off the one
     * thing that does say: a window shorter than what it asked for ran out of rows. That answers
     * only while it is paging forward - paging back the window stops at the anchor, and a full
     * last page is not recognized until the client asks once more and gets nothing. A window that
     * jumped to a numbered page does know its place, and a window with no limit holds the set.
     *
     * For an anchored window the edge is a property of the last build: a row removed from the
     * delivered set does not create an edge, and a row appended afterwards does not take one
     * away. The build size is therefore kept apart from the live delivered rows.
     *
     * The numbered-page reading is the one that needs the total, so it is the one an inexact
     * count takes away: past the ceiling the number is not where the set ends, and answering
     * yes off it would place the end of the set at 500 on every set larger than that. The
     * numbered reading stays live for the same reason as its total: a row appended to the window
     * and the matching increase of the total must move together in its arithmetic. The anchored
     * reading does not use the total and keeps the build's answer instead.
     *
     * @return bool Whether the last row of the set is in the delivered window
     */
    public function reachesEnd(): bool
    {
        if ($this->limit === TableConstants::NO_LIMIT) {
            return true;
        }

        if ($this->frame !== null) {
            return $this->frame->after === null;
        }

        if ($this->pageIndex !== null) {
            return $this->totalExact
                && $this->pageIndex * $this->limit + count($this->rowDigests) >= $this->totalCount;
        }

        return $this->anchorDirection === TableAnchorDirection::After && $this->builtRowCount < $this->limit;
    }

    /**
     * Digest of one delivered wire row, or null when it cannot be encoded.
     *
     * The freshness list is dropped before the row is hashed. The digest answers one question -
     * is this the same CONTENT this connection was given - and a source falling behind changes
     * no content: counted in, a link dropping would raise a content delta for every row of the
     * window, which is the very lie about a change HIL-790 closed (HIL-800).
     *
     * @param array{rowKey: int|string, slots: array<string, mixed>, staleSources?: list<string>} $wireRow Wire row as delivered
     * @return ?string Digest of the row, or null when json_encode refused it
     */
    private static function digest(array $wireRow): ?string
    {
        unset($wireRow[PagePayload::staleSources]);
        $encoded = json_encode($wireRow);

        return $encoded === false ? null : hash(self::ROW_DIGEST_ALGO, $encoded);
    }

    /**
     * Digest of the part of one delivered wire row the tab draws, or null when it cannot be encoded.
     *
     * @param array{rowKey: int|string, slots: array<string, mixed>, staleSources?: list<string>} $wireRow Wire row as delivered
     * @return ?string Digest of the drawn part of the row, or null when json_encode refused it
     */
    private function renderedDigest(array $wireRow): ?string
    {
        return self::digest($this->projectRow($wireRow));
    }

    /**
     * Cuts one wire row down to the fields the tab draws.
     *
     * The fields live inside the slots, not at the top of the row, so the cut is made inside every
     * slot that is a map of field to value; a slot of any other shape names no fields and is kept
     * whole, and so are the row key and everything else beside the slots. The kept fields stay in
     * the order the row carries them rather than the order they were declared in: the digest is a
     * hash of the encoded row, and a column moved in the declaration would otherwise read as a
     * change of every row in the window.
     *
     * @param array{rowKey: int|string, slots: array<string, mixed>, staleSources?: list<string>} $wireRow Wire row as delivered
     * @return array{rowKey: int|string, slots: array<string, mixed>, staleSources?: list<string>} The same row holding only the drawn fields
     */
    private function projectRow(array $wireRow): array
    {
        $drawn = array_flip($this->rendered);
        foreach ($wireRow[PagePayload::slots] as $slotKey => $slot) {
            if (is_array($slot) && !array_is_list($slot)) {
                $wireRow[PagePayload::slots][$slotKey] = array_intersect_key($slot, $drawn);
            }
        }

        return $wireRow;
    }
}
