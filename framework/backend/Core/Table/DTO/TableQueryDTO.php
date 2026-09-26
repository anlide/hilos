<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableSearchField;

/**
 * Internal query parameters used while building a table snapshot.
 *
 * Passed from TableDefinition::getFullSnapshot() to the concrete table query.
 *
 * The window is addressed one of two ways, never both. Paging asks for the rows next to an
 * anchor, and costs the same at any depth. A jump to a numbered page asks for a page index,
 * and is what a numbered page needs: page seven has no anchor until somebody has shown it.
 * A query carrying neither asks for the first window of the set.
 */
readonly class TableQueryDTO
{
    /**
     * Creates query parameters for full snapshot construction.
     *
     * @param ?string $search Full-text search across row values, or null when the window asked for none
     * @param ?TableSortOrderDTO $sort Order the window asked for, or null for the table's own default order
     * @param int $limit Page size (TableConstants::NO_LIMIT = all rows)
     * @param array<string, mixed> $filter Open viewport filter map a concrete table resolves into its
     *     own WHERE (e.g. the delivery-logs channel/status/period filters, HIL-201); `search` is lifted
     *     out into {@see $search}
     * @param ?TableAnchorDTO $anchor Place the window is taken from, or null for the edge of the set
     * @param TableAnchorDirection $anchorDirection Side of the anchor, and which edge a null anchor means
     * @param ?int $pageIndex Zero-based page to jump to, or null when the window is paged by anchor
     * @param array<string, string|TableSearchField> $searchableFields Fields the search reads, `wire row-field
     *     name => column`, each column bare or paired with its way of matching, as the table declared them; empty
     *     until the table's own declaration is put in
     */
    public function __construct(
        public ?string $search = null,
        public ?TableSortOrderDTO $sort = null,
        public int $limit = TableConstants::NO_LIMIT,
        public array $filter = [],
        public ?TableAnchorDTO $anchor = null,
        public TableAnchorDirection $anchorDirection = TableAnchorDirection::After,
        public ?int $pageIndex = null,
        public array $searchableFields = [],
    ) {
    }

    /**
     * Returns the same window with the fields its table declared the search over.
     *
     * The whole map travels, both halves of it: a query run against the database searches by its
     * values, which are columns, and one filtered in memory searches by its keys, which are the
     * fields a row is keyed by. Carrying one half would mean the other is worked out a second time
     * somewhere, and two readings of one declaration are two ways for it to drift.
     *
     * @param array<string, string|TableSearchField> $searchableFields Fields the search reads, `wire row-field
     *     name => column`, each column bare or paired with its way of matching
     * @return self Same window, searched over the declared fields
     */
    public function withSearchScope(array $searchableFields): self
    {
        return new self(
            $this->search,
            $this->sort,
            $this->limit,
            $this->filter,
            $this->anchor,
            $this->anchorDirection,
            $this->pageIndex,
            $searchableFields,
        );
    }

    /**
     * Returns the same window at the page size a table will actually serve.
     *
     * A table with a cap of its own answers an unbounded ask with a bounded window, and every
     * other part of the query - the address above all - has to be read against the size that
     * was served rather than the one that was asked for.
     *
     * @param int $limit Page size the window is served at
     * @return self Same window, sized as it will be served
     */
    public function withLimit(int $limit): self
    {
        return new self(
            $this->search,
            $this->sort,
            $limit,
            $this->filter,
            $this->anchor,
            $this->anchorDirection,
            $this->pageIndex,
            $this->searchableFields,
        );
    }

    /**
     * Returns the same window with one filter lifted.
     *
     * This is the set a count beside a filter's options is taken over: the options of a filter are
     * the choices that filter could make, so the set they are counted against is the one it has not
     * narrowed yet - every other filter and the search still apply.
     *
     * @param string $key Filter-map key to lift
     * @return self Same window, narrowed by every filter but that one
     */
    public function withoutFilter(string $key): self
    {
        $filter = $this->filter;
        unset($filter[$key]);

        return new self(
            $this->search,
            $this->sort,
            $this->limit,
            $filter,
            $this->anchor,
            $this->anchorDirection,
            $this->pageIndex,
            $this->searchableFields,
        );
    }

    /**
     * Returns the same window with one filter set to one value.
     *
     * The condition the value becomes is still the table's to write: the query only carries the
     * value in the open map, the way a window chosen in the filter bar carries it.
     *
     * @param string $key Filter-map key to set
     * @param mixed $value Value the filter narrows by
     * @return self Same window, narrowed by that value in place of whatever the filter held
     */
    public function withFilter(string $key, mixed $value): self
    {
        $filter = $this->filter;
        $filter[$key] = $value;

        return new self(
            $this->search,
            $this->sort,
            $this->limit,
            $filter,
            $this->anchor,
            $this->anchorDirection,
            $this->pageIndex,
            $this->searchableFields,
        );
    }
}
