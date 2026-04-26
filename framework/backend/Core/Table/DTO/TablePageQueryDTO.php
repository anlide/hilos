<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\Core\Table\TableConstants;

/**
 * Query parameters for future partial table loading.
 *
 * Paging/partial loading is part of the public table API shape, but
 * TableDefinition::getPage() deliberately does not implement it yet.
 */
readonly class TablePageQueryDTO
{
    /**
     * Creates page query parameters for a partial table data request.
     *
     * @param string $search Full-text search across row values
     * @param string $orderBy Field name to order by (empty = no ordering)
     * @param string $orderDirection TableConstants::ORDER_ASC or TableConstants::ORDER_DESC
     * @param int $offset Zero-based offset for pagination
     * @param int $limit Page size (TableConstants::NO_LIMIT = all rows)
     */
    public function __construct(
        public string $search = '',
        public string $orderBy = '',
        public string $orderDirection = TableConstants::ORDER_ASC,
        public int $offset = 0,
        public int $limit = TableConstants::NO_LIMIT,
    ) {
    }
}
