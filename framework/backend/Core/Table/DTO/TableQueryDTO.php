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
     * @param string $search Full-text search across row values
     * @param string $orderBy Field name to order by (empty = no ordering)
     * @param string $orderDirection TableConstants::ORDER_ASC or TableConstants::ORDER_DESC
     * @param int $offset Zero-based offset for pagination
     * @param int $limit Page size (TableConstants::NO_LIMIT = all rows)
     * @param array<string, mixed> $filter Open viewport filter map a concrete table resolves into its own WHERE (e.g. the delivery-logs channel/status/period filters, HIL-201); `search` is lifted out into {@see $search}
     */
    public function __construct(
        public string $search = '',
        public string $orderBy = '',
        public string $orderDirection = TableConstants::ORDER_ASC,
        public int $offset = 0,
        public int $limit = TableConstants::NO_LIMIT,
        public array $filter = [],
    ) {
    }
}
