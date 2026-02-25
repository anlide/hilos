<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;

/**
 * Result of a stateless table query (get).
 *
 * Contains the rows and pagination metadata.
 */
class TableResultDTO extends BaseDTO
{
    /**
     * @param array<int, array<string, mixed>> $rows Result rows
     * @param int $totalCount Total rows matching the query (before pagination)
     * @param int $offset Zero-based offset used
     * @param int $limit Page size used (0 = unlimited)
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly int $totalCount = 0,
        public readonly int $offset = 0,
        public readonly int $limit = 0,
    ) {
    }

    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'totalCount' => $this->totalCount,
            'offset' => $this->offset,
            'limit' => $this->limit,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            rows: $data['rows'] ?? [],
            totalCount: (int) ($data['totalCount'] ?? 0),
            offset: (int) ($data['offset'] ?? 0),
            limit: (int) ($data['limit'] ?? 0),
        );
    }
}
