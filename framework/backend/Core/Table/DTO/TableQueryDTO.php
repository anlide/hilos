<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\Core\Table\TableConstants;

/**
 * Internal query parameters used while building a table snapshot.
 *
 * Passed from TableDefinition::getFullSnapshot() to the concrete table query.
 */
readonly class TableQueryDTO
{
    /**
     * Creates query parameters for full snapshot construction.
     *
     * @param ?string $search Full-text search across row values, or null when the window asked for none
     * @param ?TableSortDTO $sort Ordering the window asked for, or null for the table's own default order
     * @param int $offset Zero-based offset for pagination
     * @param int $limit Page size (TableConstants::NO_LIMIT = all rows)
     * @param array<string, mixed> $filter Open viewport filter map a concrete table resolves into its own WHERE (e.g. the delivery-logs channel/status/period filters, HIL-201); `search` is lifted out into {@see $search}
     */
    public function __construct(
        public ?string $search = null,
        public ?TableSortDTO $sort = null,
        public int $offset = 0,
        public int $limit = TableConstants::NO_LIMIT,
        public array $filter = [],
    ) {
    }
}
