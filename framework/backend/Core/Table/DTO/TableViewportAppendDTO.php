<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\TableConstants;

/**
 * TableViewportAppendDTO - Server-to-client live tail append for one table window.
 *
 * Sent only to a connection whose window the new row belongs at the tail of: the window
 * reaches the end of the filtered set, has a free slot (windowSize < limit), and the row's
 * own place in the order that window asked for is below every row it is showing. The server
 * reads that place off the window's boundaries before sending anything, so the END of the
 * window is where the row goes because that is where it belongs - a reload puts it in the
 * same slot, and nothing already shown moves. Every other place, and every window whose
 * place cannot be read at all, travels as a count instead. The frontend applies the append
 * immediately instead of queuing a pending change and sets the carried counts
 * authoritatively. The row rides the same `{rowKey, slots}` wire fragment as the window
 * snapshot. Addressed per accept key.
 *
 * The row arrives whatever the counts say: delivery does not depend on how well the set is
 * counted. The page count, though, travels only while the total is exact — past
 * {@see TableConstants::COUNT_CEILING} the total is the ceiling and nothing about pages
 * follows from it, so the key is absent rather than zero.
 */
final class TableViewportAppendDTO extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string row = 'row';
    public const string totalCount = 'totalCount';
    public const string totalExact = 'totalExact';
    public const string pageCount = 'pageCount';

    /**
     * Creates a table viewport append payload.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the append is for
     * @param array<string, mixed> $row Row to append as a `{rowKey, slots}` fragment
     * @param int $totalCount Total rows matching the filter
     * @param bool $totalExact Whether that total is the size of the set rather than the ceiling the count stopped at
     * @param ?int $pageCount Page count under the window size, or null when the total is not exact
     */
    public function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly array $row,
        public readonly int $totalCount,
        public readonly bool $totalExact,
        public readonly ?int $pageCount,
    ) {
    }

    /**
     * Converts the payload to its wire array.
     *
     * @return array<string, mixed> DTO payload in the table-viewport-append wire form
     */
    public function toArray(): array
    {
        $payload = [
            self::page => $this->page,
            self::tableKey => $this->tableKey,
            self::row => $this->row,
            self::totalCount => $this->totalCount,
            self::totalExact => $this->totalExact,
        ];
        if ($this->pageCount !== null) {
            $payload[self::pageCount] = $this->pageCount;
        }

        return $payload;
    }

    /**
     * Restores the payload from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-viewport-append wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses the addressed table, the row, the total or the word on it
     */
    public static function fromArray(array $data): static
    {
        return new static(
            page: self::requireString($data, self::page),
            tableKey: self::requireString($data, self::tableKey),
            row: self::requireArray($data, self::row),
            totalCount: self::requireInt($data, self::totalCount),
            totalExact: self::requireBool($data, self::totalExact),
            pageCount: self::optionalInt($data, self::pageCount),
        );
    }
}
