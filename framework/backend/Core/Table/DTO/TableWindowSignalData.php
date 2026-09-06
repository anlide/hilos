<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
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
 *
 * The two boundary anchors are what the client asks the next window with — the last one carries
 * paging forward, the first one carries it back — and they are all the window says about where
 * it sits. An empty window has neither.
 */
final class TableWindowSignalData extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string rows = 'rows';
    public const string totalCount = 'totalCount';
    public const string limit = 'limit';
    public const string firstAnchor = 'firstAnchor';
    public const string lastAnchor = 'lastAnchor';

    /**
     * Creates a table window signal payload.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the window is for
     * @param list<array<string, mixed>> $rows Window rows in display order, each `{rowKey, sources}`
     * @param int $totalCount Total rows matching the filter
     * @param int $limit Window size (TableConstants::NO_LIMIT = all rows)
     * @param ?TableAnchorDTO $firstAnchor Place the first row sits at, or null when the window is empty
     * @param ?TableAnchorDTO $lastAnchor Place the last row sits at, or null when the window is empty
     */
    public function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly array $rows,
        public readonly int $totalCount,
        public readonly int $limit,
        public readonly ?TableAnchorDTO $firstAnchor = null,
        public readonly ?TableAnchorDTO $lastAnchor = null,
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
            self::limit => $this->limit,
            self::firstAnchor => $this->firstAnchor?->toArray(),
            self::lastAnchor => $this->lastAnchor?->toArray(),
        ];
    }

    /**
     * Restores the signal payload from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-window wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses a field of the window or its descriptor
     */
    public static function fromArray(array $data): static
    {
        return new static(
            page: self::requireString($data, self::page),
            tableKey: self::requireString($data, self::tableKey),
            rows: array_values(self::requireArray($data, self::rows)),
            totalCount: self::requireInt($data, self::totalCount),
            limit: self::requireInt($data, self::limit),
            firstAnchor: TableAnchorDTO::fromWire(self::optionalArray($data, self::firstAnchor)),
            lastAnchor: TableAnchorDTO::fromWire(self::optionalArray($data, self::lastAnchor)),
        );
    }
}
