<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * TableViewportFrozenSignalData - Server-to-client word that one table's window stopped receiving its live changes.
 *
 * Sent in the live stream, addressed to the one connection whose window froze, and once per
 * freeze: a change the table could not build for this window, or a throw further down its live
 * road, leaves the rows on the screen where they were, and without this word they would look
 * live. The window thaws on its own — the next successful delivery sends it a full table_window
 * — so the frame carries no instruction, only the moment it froze.
 */
final class TableViewportFrozenSignalData extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string since = 'since';

    /**
     * Creates a table-viewport-frozen signal payload.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key whose window froze
     * @param int $since Server milliseconds of the first failure, on the scale of rt_staleness since
     */
    public function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly int $since,
    ) {
    }

    /**
     * Converts the signal payload to its wire array.
     *
     * @return array<string, mixed> DTO payload in the table-viewport-frozen wire form
     */
    public function toArray(): array
    {
        return [
            self::page => $this->page,
            self::tableKey => $this->tableKey,
            self::since => $this->since,
        ];
    }

    /**
     * Restores the signal payload from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-viewport-frozen wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses page, tableKey, or since
     */
    public static function fromArray(array $data): static
    {
        return new static(
            page: self::requireString($data, self::page),
            tableKey: self::requireString($data, self::tableKey),
            since: self::requireInt($data, self::since),
        );
    }
}
