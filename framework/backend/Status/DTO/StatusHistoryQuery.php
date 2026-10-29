<?php

declare(strict_types=1);

namespace Hilos\Status\DTO;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableSearchTerm;
use Hilos\Status\StatusIncidentOutcome;

/** Validated ephemeral source query; edgeSkip requests a prefix for the merged numbered window. */
final readonly class StatusHistoryQuery
{
    public const int MAX_WINDOW_ROWS = 32;

    public ?string $term;

    /**
     * @param ?string $term Literal title substring, blank means no search
     * @param ?string $componentKey Historical component key, independent of the current catalog
     * @param ?string $outcome Completed incident outcome or maintenance_completed
     * @param ?int $fromMs Inclusive start boundary in epoch milliseconds UTC
     * @param ?int $toExclusiveMs Exclusive start boundary in epoch milliseconds UTC
     * @param string $order Canonical order of every tuple component, asc or desc
     * @param ?StatusHistoryAnchor $anchor Complete tuple, null at an edge
     * @param TableAnchorDirection $direction Side in canonical order; Before starts at the far edge when unanchored
     * @param int $limit Window size, one through MAX_WINDOW_ROWS
     * @param int $edgeSkip Merged numbered skip, zero through COUNT_CEILING; never applied as SQL OFFSET
     * @throws ValidationException When filters, bounds, order or window request are invalid
     */
    public function __construct(
        ?string $term = null,
        public ?string $componentKey = null,
        public ?string $outcome = null,
        public ?int $fromMs = null,
        public ?int $toExclusiveMs = null,
        public string $order = TableConstants::ORDER_DESC,
        public ?StatusHistoryAnchor $anchor = null,
        public TableAnchorDirection $direction = TableAnchorDirection::After,
        public int $limit = self::MAX_WINDOW_ROWS,
        public int $edgeSkip = 0,
    ) {
        $this->term = TableSearchTerm::normalize($term);
        if (($componentKey !== null && trim($componentKey) === '')
            || ($outcome !== null && $outcome !== StatusHistoryEntry::MAINTENANCE_COMPLETED
                && StatusIncidentOutcome::tryFrom($outcome) === null)
            || !in_array($order, [TableConstants::ORDER_ASC, TableConstants::ORDER_DESC], true)
            || ($fromMs !== null && $toExclusiveMs !== null && $fromMs >= $toExclusiveMs)
            || $limit < 1 || $limit > self::MAX_WINDOW_ROWS
            || $edgeSkip < 0 || $edgeSkip > TableConstants::COUNT_CEILING
            || ($anchor !== null && $edgeSkip !== 0)) {
            throw new ValidationException('Choose valid history filters and a window of up to 32 rows.');
        }
    }

    /** @return int Bounded source prefix including one edge probe, at most COUNT_CEILING + MAX_WINDOW_ROWS + 1 */
    public function sourceLimit(): int
    {
        return $this->edgeSkip + $this->limit + 1;
    }

    /** @return bool Whether SQL walks the tuple in ascending order */
    public function readsAscending(): bool
    {
        return ($this->order === TableConstants::ORDER_ASC) === ($this->direction === TableAnchorDirection::After);
    }
}
