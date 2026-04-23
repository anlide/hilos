<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\Row\GenericTableRow;
use Hilos\Core\Table\TableConstants;

/**
 * Result of a stateless table query (get).
 *
 * Contains the rows and pagination metadata.
 */
class TableResultDTO extends BaseDTO
{
    /**
     * Creates table result DTO.
     *
     * @param list<AbstractTableRow|array<string, mixed>> $rows Result rows
     * @param int $totalCount Total rows matching the query (before pagination)
     * @param int $offset Zero-based offset used
     * @param int $limit Page size used (TableConstants::NO_LIMIT = all rows)
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly int $totalCount = 0,
        public readonly int $offset = 0,
        public readonly int $limit = TableConstants::NO_LIMIT,
    ) {
    }

    /**
     * Converts to array for WebSocket serialization.
     *
     * @return array<string, mixed> Rows, totalCount, offset, limit keys
     */
    public function toArray(): array
    {
        return [
            TableConstants::RESULT_KEY_ROWS => array_map(
                static fn(AbstractTableRow|array $row): array => $row instanceof AbstractTableRow ? $row->toArray() : $row,
                $this->rows,
            ),
            TableConstants::RESULT_KEY_TOTAL_COUNT => $this->totalCount,
            TableConstants::RESULT_KEY_OFFSET => $this->offset,
            TableConstants::RESULT_KEY_LIMIT => $this->limit,
        ];
    }

    /**
     * Creates DTO from payload array.
     *
     * @param array<string, mixed> $data Raw payload with rows, totalCount, offset, limit keys
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rows: array_map(
                static fn(mixed $row): AbstractTableRow => is_array($row) ? GenericTableRow::fromArray($row) : GenericTableRow::fromArray([]),
                is_array($data[TableConstants::RESULT_KEY_ROWS] ?? null) ? $data[TableConstants::RESULT_KEY_ROWS] : [],
            ),
            totalCount: (int) ($data[TableConstants::RESULT_KEY_TOTAL_COUNT] ?? 0),
            offset: (int) ($data[TableConstants::RESULT_KEY_OFFSET] ?? 0),
            limit: (int) ($data[TableConstants::RESULT_KEY_LIMIT] ?? 0),
        );
    }
}
