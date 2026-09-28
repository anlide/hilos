<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\TableConstants;

/**
 * TableViewportCountDTO - Server-to-client live count update for one table window.
 *
 * Carries the filtered total and the page count under the connection's window size
 * whenever they change for its viewport. Unlike a row delta this is navigation
 * metadata, not row content, so the frontend applies it immediately - it rebuilds
 * the pager and can tell it is no longer on the last page - instead of queuing it
 * as a pending change. Addressed per accept key.
 *
 * The page count travels only while the total is exact. Past
 * {@see TableConstants::COUNT_CEILING} the total is the ceiling and reads as "at least this
 * many", and no page count follows from it — so the key is absent rather than zero, which
 * would read as a table with no pages at all.
 *
 * hasRowsAfter says whether rows remain after this window that it does not already hold.
 * It is absent when the server did not settle that question, and with an inexact total:
 * a count stopped at the ceiling cannot establish where the set ends.
 */
final class TableViewportCountDTO extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string totalCount = 'totalCount';
    public const string totalExact = 'totalExact';
    public const string pageCount = 'pageCount';
    public const string hasRowsAfter = 'hasRowsAfter';

    /**
     * Creates a table viewport count payload.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the count is for
     * @param int $totalCount Total rows matching the filter
     * @param bool $totalExact Whether that total is the size of the set rather than the ceiling the count stopped at
     * @param ?int $pageCount Page count under the window size, or null when the total is not exact
     * @param ?bool $hasRowsAfter Whether unheld rows follow the window, or null when not established
     */
    public function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly int $totalCount,
        public readonly bool $totalExact,
        public readonly ?int $pageCount,
        public readonly ?bool $hasRowsAfter = null,
    ) {
    }

    /**
     * Converts the payload to its wire array.
     *
     * @return array<string, mixed> DTO payload in the table-viewport-count wire form
     */
    public function toArray(): array
    {
        $payload = [
            self::page => $this->page,
            self::tableKey => $this->tableKey,
            self::totalCount => $this->totalCount,
            self::totalExact => $this->totalExact,
        ];
        if ($this->pageCount !== null) {
            $payload[self::pageCount] = $this->pageCount;
        }
        if ($this->hasRowsAfter !== null) {
            $payload[self::hasRowsAfter] = $this->hasRowsAfter;
        }

        return $payload;
    }

    /**
     * Restores the payload from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-viewport-count wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses the addressed table, the total or the word on it
     */
    public static function fromArray(array $data): static
    {
        return new static(
            page: self::requireString($data, self::page),
            tableKey: self::requireString($data, self::tableKey),
            totalCount: self::requireInt($data, self::totalCount),
            totalExact: self::requireBool($data, self::totalExact),
            pageCount: self::optionalInt($data, self::pageCount),
            hasRowsAfter: self::optionalBool($data, self::hasRowsAfter),
        );
    }
}
