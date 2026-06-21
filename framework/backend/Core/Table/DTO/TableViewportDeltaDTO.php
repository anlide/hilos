<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * TableViewportDeltaDTO - Server-to-client live pending change for one table window.
 *
 * The live stream a connection receives for the rows it actually shows, addressed
 * to its accept key. It never auto-applies on the frontend; it accumulates as a
 * pending change the user resolves with Apply. Discriminated by `kind`:
 *
 * - `row_updated` — a shown row's content changed; carries the new row.
 * - `row_removed` — a shown row was deleted or left the filtered set; carries the
 *   row key and a `reason` (`deleted` / `left_set`).
 * - `set_changed` — membership, total, or page count changed under the filter;
 *   carries the new `totalCount` and `pageCount`.
 *
 * A row rides the same `{rowKey, sources}` fragment shape as the window snapshot.
 */
final class TableViewportDeltaDTO extends BaseDTO implements SignalDataInterface
{
    public const string KIND_ROW_UPDATED = 'row_updated';
    public const string KIND_ROW_REMOVED = 'row_removed';
    public const string KIND_SET_CHANGED = 'set_changed';

    public const string REASON_DELETED = 'deleted';
    public const string REASON_LEFT_SET = 'left_set';

    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string kind = 'kind';
    public const string rowKey = 'rowKey';
    public const string row = 'row';
    public const string reason = 'reason';
    public const string totalCount = 'totalCount';
    public const string pageCount = 'pageCount';

    /**
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the delta is for
     * @param string $kind One of the KIND_* discriminators
     * @param int|string|null $rowKey Affected row key (row_updated / row_removed)
     * @param ?array<string, mixed> $row New row as a `{rowKey, sources}` fragment (row_updated)
     * @param ?string $reason Removal reason `deleted` / `left_set` (row_removed)
     * @param ?int $totalCount Total rows matching the filter (set_changed)
     * @param ?int $pageCount Page count under the window size (set_changed)
     */
    private function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly string $kind,
        public readonly int|string|null $rowKey = null,
        public readonly ?array $row = null,
        public readonly ?string $reason = null,
        public readonly ?int $totalCount = null,
        public readonly ?int $pageCount = null,
    ) {
    }

    /**
     * Creates a row-updated delta.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key
     * @param int|string $rowKey Affected row key
     * @param array<string, mixed> $row New row as a `{rowKey, sources}` fragment
     * @return self Row-updated delta
     */
    public static function rowUpdated(string $page, string $tableKey, int|string $rowKey, array $row): self
    {
        return new self($page, $tableKey, self::KIND_ROW_UPDATED, rowKey: $rowKey, row: $row);
    }

    /**
     * Creates a row-removed delta.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key
     * @param int|string $rowKey Affected row key
     * @param string $reason Removal reason (REASON_DELETED / REASON_LEFT_SET)
     * @return self Row-removed delta
     */
    public static function rowRemoved(string $page, string $tableKey, int|string $rowKey, string $reason): self
    {
        return new self($page, $tableKey, self::KIND_ROW_REMOVED, rowKey: $rowKey, reason: $reason);
    }

    /**
     * Creates a set-changed delta.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key
     * @param int $totalCount Total rows matching the filter
     * @param int $pageCount Page count under the window size
     * @return self Set-changed delta
     */
    public static function setChanged(string $page, string $tableKey, int $totalCount, int $pageCount): self
    {
        return new self($page, $tableKey, self::KIND_SET_CHANGED, totalCount: $totalCount, pageCount: $pageCount);
    }

    /**
     * Converts the delta to its wire array, omitting keys irrelevant to its kind.
     *
     * @return array<string, mixed> DTO payload in the table-viewport-delta wire form
     */
    public function toArray(): array
    {
        $data = [
            self::page => $this->page,
            self::tableKey => $this->tableKey,
            self::kind => $this->kind,
        ];
        if ($this->rowKey !== null) {
            $data[self::rowKey] = $this->rowKey;
        }
        if ($this->row !== null) {
            $data[self::row] = $this->row;
        }
        if ($this->reason !== null) {
            $data[self::reason] = $this->reason;
        }
        if ($this->totalCount !== null) {
            $data[self::totalCount] = $this->totalCount;
        }
        if ($this->pageCount !== null) {
            $data[self::pageCount] = $this->pageCount;
        }

        return $data;
    }

    /**
     * Restores a delta from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-viewport-delta wire form
     * @return static Restored DTO instance
     */
    public static function fromArray(array $data): static
    {
        $rowKey = $data[self::rowKey] ?? null;
        $row = $data[self::row] ?? null;

        return new static(
            page: (string) ($data[self::page] ?? ''),
            tableKey: (string) ($data[self::tableKey] ?? ''),
            kind: (string) ($data[self::kind] ?? ''),
            rowKey: is_int($rowKey) || is_string($rowKey) ? $rowKey : null,
            row: is_array($row) ? $row : null,
            reason: is_string($data[self::reason] ?? null) ? $data[self::reason] : null,
            totalCount: isset($data[self::totalCount]) ? (int) $data[self::totalCount] : null,
            pageCount: isset($data[self::pageCount]) ? (int) $data[self::pageCount] : null,
        );
    }
}
