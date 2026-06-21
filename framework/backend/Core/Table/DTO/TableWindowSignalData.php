<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * TableWindowSignalData - Server-to-client snapshot of one table window.
 *
 * Sent only in reply to a client table_viewport request (window change, cold
 * load, or reconnect) — never in the live stream, where row deltas flow instead.
 * Carries the rows currently in the window plus the descriptor metadata the
 * frontend renders and anchors pending changes against. Rows ride the same
 * `{rowKey, sources}` fragment shape as the browser snapshot; they are typed
 * both ends and JSON only on the wire.
 */
final class TableWindowSignalData extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string rows = 'rows';
    public const string totalCount = 'totalCount';
    public const string offset = 'offset';
    public const string limit = 'limit';

    /**
     * Creates a table window signal payload.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the window is for
     * @param list<array<string, mixed>> $rows Window rows in display order, each `{rowKey, sources}`
     * @param int $totalCount Total rows matching the filter
     * @param int $offset Zero-based window offset
     * @param int $limit Window size (TableConstants::NO_LIMIT = all rows)
     */
    public function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly array $rows,
        public readonly int $totalCount,
        public readonly int $offset,
        public readonly int $limit,
    ) {
    }

    /**
     * Converts the signal payload to its wire array.
     *
     * @return array<string, mixed> DTO payload in the table-window wire form
     */
    public function toArray(): array
    {
        return [
            self::page => $this->page,
            self::tableKey => $this->tableKey,
            self::rows => $this->rows,
            self::totalCount => $this->totalCount,
            self::offset => $this->offset,
            self::limit => $this->limit,
        ];
    }

    /**
     * Restores the signal payload from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-window wire form
     * @return static Restored DTO instance
     */
    public static function fromArray(array $data): static
    {
        $rows = $data[self::rows] ?? [];

        return new static(
            page: (string) ($data[self::page] ?? ''),
            tableKey: (string) ($data[self::tableKey] ?? ''),
            rows: is_array($rows) ? array_values($rows) : [],
            totalCount: (int) ($data[self::totalCount] ?? 0),
            offset: (int) ($data[self::offset] ?? 0),
            limit: (int) ($data[self::limit] ?? 0),
        );
    }
}
