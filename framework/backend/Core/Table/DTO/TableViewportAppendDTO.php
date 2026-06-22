<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * TableViewportAppendDTO - Server-to-client live tail append for one table window.
 *
 * Sent only to a connection whose window is on the last page with room
 * (windowSize < limit) when a new row enters its filtered set. The row is added at
 * the END of the window regardless of sort - a viewport touch (navigate, sort, or
 * search) pulls a fresh, re-sorted window - so the server never recomputes the
 * position. The frontend applies it immediately instead of queuing a pending
 * change and sets the carried counts authoritatively. The row rides the same
 * `{rowKey, slots}` wire fragment as the window snapshot. Addressed per accept key.
 */
final class TableViewportAppendDTO extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string row = 'row';
    public const string totalCount = 'totalCount';
    public const string pageCount = 'pageCount';

    /**
     * Creates a table viewport append payload.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the append is for
     * @param array<string, mixed> $row Row to append as a `{rowKey, slots}` fragment
     * @param int $totalCount Total rows matching the filter
     * @param int $pageCount Page count under the window size
     */
    public function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly array $row,
        public readonly int $totalCount,
        public readonly int $pageCount,
    ) {
    }

    /**
     * Converts the payload to its wire array.
     *
     * @return array<string, mixed> DTO payload in the table-viewport-append wire form
     */
    public function toArray(): array
    {
        return [
            self::page => $this->page,
            self::tableKey => $this->tableKey,
            self::row => $this->row,
            self::totalCount => $this->totalCount,
            self::pageCount => $this->pageCount,
        ];
    }

    /**
     * Restores the payload from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-viewport-append wire form
     * @return static Restored DTO instance
     */
    public static function fromArray(array $data): static
    {
        $row = $data[self::row] ?? [];

        return new static(
            page: (string) ($data[self::page] ?? ''),
            tableKey: (string) ($data[self::tableKey] ?? ''),
            row: is_array($row) ? $row : [],
            totalCount: (int) ($data[self::totalCount] ?? 0),
            pageCount: (int) ($data[self::pageCount] ?? 0),
        );
    }
}
