<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * TableViewportOwnCreateDTO - Server-to-client live placed insert of the author's own new row.
 *
 * Sent only to the connection whose write minted the row, and only when the row
 * lands inside that connection's current window. Unlike
 * {@see TableViewportAppendDTO} the position is computed rather than assumed: the
 * server re-selects the window and reports where the row actually sits under the
 * live filter, sort and page, so the author sees what a reload would show. The
 * window keeps its size, so the client drops whatever the insert pushes past the
 * end. The row rides the same `{rowKey, slots}` wire fragment as the window
 * snapshot. Addressed per accept key.
 *
 * The two signals are separate names rather than one name with an optional
 * position, because they carry different rules — "at the end whatever the sort"
 * against "where the sort puts it" — and a rule that shows up only as a present
 * field is a mode nobody declared.
 */
final class TableViewportOwnCreateDTO extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string row = 'row';
    public const string position = 'position';
    public const string totalCount = 'totalCount';
    public const string pageCount = 'pageCount';
    public const string requestId = 'requestId';

    /**
     * Creates a table viewport own-create payload.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the insert is for
     * @param array<string, mixed> $row Row to insert as a `{rowKey, slots}` fragment
     * @param int $position Zero-based index the row takes in the window
     * @param int $totalCount Total rows matching the filter
     * @param int $pageCount Page count under the window size
     * @param ?string $requestId Request id of the action that created the row, or null when it was not tracked
     */
    public function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly array $row,
        public readonly int $position,
        public readonly int $totalCount,
        public readonly int $pageCount,
        public readonly ?string $requestId = null,
    ) {
    }

    /**
     * Converts the payload to its wire array.
     *
     * @return array<string, mixed> DTO payload in the table-viewport-own-create wire form
     */
    public function toArray(): array
    {
        return [
            self::page => $this->page,
            self::tableKey => $this->tableKey,
            self::row => $this->row,
            self::position => $this->position,
            self::totalCount => $this->totalCount,
            self::pageCount => $this->pageCount,
            self::requestId => $this->requestId,
        ];
    }

    /**
     * Restores the payload from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-viewport-own-create wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses the addressed table, the row, the position or a count
     */
    public static function fromArray(array $data): static
    {
        return new static(
            page: self::requireString($data, self::page),
            tableKey: self::requireString($data, self::tableKey),
            row: self::requireArray($data, self::row),
            position: self::requireInt($data, self::position),
            totalCount: self::requireInt($data, self::totalCount),
            pageCount: self::requireInt($data, self::pageCount),
            requestId: self::optionalString($data, self::requestId),
        );
    }
}
