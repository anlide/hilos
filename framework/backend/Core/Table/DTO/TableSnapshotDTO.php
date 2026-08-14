<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\Row\GenericTableRow;
use Hilos\Core\Table\TableConstants;

/**
 * Full snapshot of a table.
 *
 * Contains all rows and metadata needed for initial frontend rendering.
 */
class TableSnapshotDTO extends BaseDTO
{
    /**
     * Creates table snapshot DTO.
     *
     * @param list<AbstractTableRow|array<string, mixed>> $rows Snapshot rows
     * @param int $totalCount Total rows in the full snapshot
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
     * Converts the snapshot to array for WebSocket serialization.
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
     * Creates snapshot DTO from payload array.
     *
     * A row that is not an array is refused instead of becoming an empty one:
     * an empty generic row has no key, so it would travel as a row the table
     * cannot address rather than as the malformed payload it is. The window
     * descriptor is required whole, the page size included — defaulted it reads
     * as {@see TableConstants::NO_LIMIT}, so a payload that lost the field would
     * describe a page of the collection as the whole of it.
     *
     * @param array<string, mixed> $data Raw payload with rows, totalCount, offset, limit keys
     * @return static DTO instance
     * @throws InvalidFormatException When the payload misses the rows or a descriptor field, or a row is not an array
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rows: array_map(
                static fn(mixed $row): AbstractTableRow => is_array($row)
                    ? GenericTableRow::fromArray($row)
                    : throw new InvalidFormatException('Payload carries a snapshot row that is not an array'),
                self::requireArray($data, TableConstants::RESULT_KEY_ROWS),
            ),
            totalCount: self::requireInt($data, TableConstants::RESULT_KEY_TOTAL_COUNT),
            offset: self::requireInt($data, TableConstants::RESULT_KEY_OFFSET),
            limit: self::requireInt($data, TableConstants::RESULT_KEY_LIMIT),
        );
    }
}
