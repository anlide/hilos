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
 *
 * The two boundary anchors are how the next window is asked for: the last one carries paging
 * forward, the first one carries it back. They are how the next window is addressed, and the
 * rows standing before this one are where it sits: a page number is read out of that place
 * rather than counted up by the presses that led to it.
 *
 * The frame - the places standing right outside the window - is the one part of the snapshot
 * that never leaves the server: the subscription judges edits against it, the client has no use
 * for it. So neither the wire form nor the payload reader carries it, and a snapshot read out
 * of a payload does not know its frame.
 */
class TableSnapshotDTO extends BaseDTO
{
    /**
     * Creates table snapshot DTO.
     *
     * @param list<AbstractTableRow|array<string, mixed>> $rows Snapshot rows
     * @param int $totalCount Total rows in the full snapshot
     * @param bool $totalExact Whether that total is the size of the set rather than the ceiling the count stopped at
     * @param int $limit Page size used (TableConstants::NO_LIMIT = all rows)
     * @param ?TableAnchorDTO $firstAnchor Place the first row sits at, or null when the window is empty
     * @param ?TableAnchorDTO $lastAnchor Place the last row sits at, or null when the window is empty
     * @param ?int $rowsBefore Rows of the set standing before the window, or null when the total is not exact
     * @param ?TableWindowFrameDTO $frame Places standing right outside the window, or null when the source did not
     *     report them
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly int $totalCount = 0,
        public readonly bool $totalExact = true,
        public readonly int $limit = TableConstants::NO_LIMIT,
        public readonly ?TableAnchorDTO $firstAnchor = null,
        public readonly ?TableAnchorDTO $lastAnchor = null,
        public readonly ?int $rowsBefore = null,
        public readonly ?TableWindowFrameDTO $frame = null,
    ) {
    }

    /**
     * Converts the snapshot to array for WebSocket serialization.
     *
     * @return array<string, mixed> Rows, totalCount, totalExact, limit, the two boundary anchors and the window's place
     */
    public function toArray(): array
    {
        return [
            TableConstants::RESULT_KEY_ROWS => array_map(
                static fn(AbstractTableRow|array $row): array => $row instanceof AbstractTableRow ? $row->toArray() : $row,
                $this->rows,
            ),
            TableConstants::RESULT_KEY_TOTAL_COUNT => $this->totalCount,
            TableConstants::RESULT_KEY_TOTAL_EXACT => $this->totalExact,
            TableConstants::RESULT_KEY_LIMIT => $this->limit,
            TableConstants::RESULT_KEY_FIRST_ANCHOR => $this->firstAnchor?->toArray(),
            TableConstants::RESULT_KEY_LAST_ANCHOR => $this->lastAnchor?->toArray(),
            TableConstants::RESULT_KEY_ROWS_BEFORE => $this->rowsBefore,
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
     * describe a page of the collection as the whole of it. Whether the total is
     * exact is required for the same reason: defaulted it reads as exact, and a
     * payload that lost it would promise the ceiling as the size of the set. The
     * boundary anchors are the exception, because an empty window really has none,
     * and so is the window's place, which a set counted only up to its ceiling has
     * nothing to report.
     *
     * @param array<string, mixed> $data Raw payload with rows, totalCount, totalExact, limit, boundary anchor
     *     and rowsBefore keys
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
            totalExact: self::requireBool($data, TableConstants::RESULT_KEY_TOTAL_EXACT),
            limit: self::requireInt($data, TableConstants::RESULT_KEY_LIMIT),
            firstAnchor: TableAnchorDTO::fromWire(self::optionalArray($data, TableConstants::RESULT_KEY_FIRST_ANCHOR)),
            lastAnchor: TableAnchorDTO::fromWire(self::optionalArray($data, TableConstants::RESULT_KEY_LAST_ANCHOR)),
            rowsBefore: self::optionalInt($data, TableConstants::RESULT_KEY_ROWS_BEFORE),
        );
    }
}
