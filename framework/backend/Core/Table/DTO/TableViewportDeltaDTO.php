<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * TableViewportDeltaDTO - Server-to-client live pending row change for one table window.
 *
 * The live stream a connection receives for the rows it actually shows, addressed
 * to its accept key. It accumulates as a pending change the user resolves with
 * Apply (the originating tab auto-applies its own by row-key correlation). Count
 * and append changes ride their own live signals; this carries only row edits and
 * removals. Discriminated by `kind`:
 *
 * - `row_updated` — a shown row's content changed; carries the new row.
 * - `row_removed` — a shown row was deleted or left the filtered set; carries the
 *   row key and a `reason` (`deleted` / `left_set`).
 *
 * A row rides the same `{rowKey, slots}` wire fragment as the window snapshot.
 */
final class TableViewportDeltaDTO extends BaseDTO implements SignalDataInterface
{
    public const string KIND_ROW_UPDATED = 'row_updated';
    public const string KIND_ROW_REMOVED = 'row_removed';

    public const string REASON_DELETED = 'deleted';
    public const string REASON_LEFT_SET = 'left_set';

    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string kind = 'kind';
    public const string rowKey = 'rowKey';
    public const string row = 'row';
    public const string reason = 'reason';
    public const string live = 'live';
    public const string own = 'own';

    /**
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the delta is for
     * @param string $kind One of the KIND_* discriminators
     * @param int|string|null $rowKey Affected row key (row_updated / row_removed)
     * @param ?array<string, mixed> $row New row as a `{rowKey, slots}` fragment (row_updated)
     * @param ?string $reason Removal reason `deleted` / `left_set` (row_removed)
     * @param bool $live Whether the change applies at once instead of accumulating as pending
     * @param bool $own Whether this receiver authored the change (applies at once, resolving any queued pending)
     */
    private function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly string $kind,
        public readonly int|string|null $rowKey = null,
        public readonly ?array $row = null,
        public readonly ?string $reason = null,
        public readonly bool $live = false,
        public readonly bool $own = false,
    ) {
    }

    /**
     * Creates a row-updated delta.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key
     * @param int|string $rowKey Affected row key
     * @param array<string, mixed> $row New row as a `{rowKey, slots}` fragment
     * @param bool $live Whether the change applies at once instead of accumulating as pending
     * @param bool $own Whether this receiver authored the change (applies at once, resolving any queued pending)
     * @return self Row-updated delta
     */
    public static function rowUpdated(
        string $page,
        string $tableKey,
        int|string $rowKey,
        array $row,
        bool $live = false,
        bool $own = false,
    ): self {
        return new self($page, $tableKey, self::KIND_ROW_UPDATED, rowKey: $rowKey, row: $row, live: $live, own: $own);
    }

    /**
     * Creates a row-removed delta.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key
     * @param int|string $rowKey Affected row key
     * @param string $reason Removal reason (REASON_DELETED / REASON_LEFT_SET)
     * @param bool $live Whether the change applies at once instead of accumulating as pending
     * @param bool $own Whether this receiver authored the change (applies at once, resolving any queued pending)
     * @return self Row-removed delta
     */
    public static function rowRemoved(
        string $page,
        string $tableKey,
        int|string $rowKey,
        string $reason,
        bool $live = false,
        bool $own = false,
    ): self {
        return new self($page, $tableKey, self::KIND_ROW_REMOVED, rowKey: $rowKey, reason: $reason, live: $live, own: $own);
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
        if ($this->live) {
            $data[self::live] = true;
        }
        if ($this->own) {
            $data[self::own] = true;
        }

        return $data;
    }

    /**
     * Restores a delta from its wire array.
     *
     * Only the three fields every kind carries are required. The rest are read
     * as the optional fields {@see self::toArray()} writes them as: it omits a
     * key irrelevant to the kind, and omits a lowered flag, so an absent `live`
     * or `own` is the false that side put there.
     *
     * @param array<string, mixed> $data Source data in the table-viewport-delta wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses the addressed table or the delta kind
     */
    public static function fromArray(array $data): static
    {
        return new static(
            page: self::requireString($data, self::page),
            tableKey: self::requireString($data, self::tableKey),
            kind: self::requireString($data, self::kind),
            rowKey: self::optionalIntOrString($data, self::rowKey),
            row: self::optionalArray($data, self::row),
            reason: self::optionalString($data, self::reason),
            live: self::optionalBool($data, self::live) ?? false,
            own: self::optionalBool($data, self::own) ?? false,
        );
    }
}
