<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * TableProgressSignalData - Server-to-client live word that work is running on one table.
 *
 * The addressed form of a {@see TableProgressDTO}: the bar a table declared, plus the page and
 * the table key the fan-out writes on the way out. Addressed per accept key, and delivered under
 * the same page guard the live row frames pass, because work over a record is visible to
 * everyone looking at it.
 *
 * The bar travels a second road too, and without an address on that one: the `progress` key of a
 * table's entry in the `windows` section of the page's own answer. A tab opened in the middle of
 * a run has to see the work at once rather than at the next stir of the source, and the section
 * is the only frame that reaches a table before it has a window to draw into.
 *
 * The name carries no `viewport` on purpose: a bar does not belong to the window, and changing
 * the page, the filter or the order leaves it standing.
 */
final class TableProgressSignalData extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string tableKey = 'tableKey';

    /** Key the bars of one table stand under inside the `windows` section of a page answer. */
    public const string progress = 'progress';

    /**
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the bar is for
     * @param TableProgressDTO $progress Bar the table declared
     */
    private function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly TableProgressDTO $progress,
    ) {
    }

    /**
     * Addresses one bar a table declared to a page and a table key.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the bar is for
     * @param TableProgressDTO $progress Bar the table declared
     * @return self Addressed frame carrying that bar
     */
    public static function fromProgress(string $page, string $tableKey, TableProgressDTO $progress): self
    {
        return new self($page, $tableKey, $progress);
    }

    /**
     * Converts the signal payload to its wire array.
     *
     * The bar is spread flat beside the address rather than nested under a key of its own: it is
     * one frame on the wire, and the section of a page answer carries the very same keys with
     * the address left off.
     *
     * @return array<string, mixed> DTO payload in the table-progress wire form
     */
    public function toArray(): array
    {
        return array_merge(
            [
                self::page => $this->page,
                self::tableKey => $this->tableKey,
            ],
            $this->progress->toArray(),
        );
    }

    /**
     * Restores the signal payload from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-progress wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses the addressed table or a field of the bar
     * @throws InvalidArgumentException When the row key and the place the bar stands in disagree
     */
    public static function fromArray(array $data): static
    {
        return new static(
            page: self::requireString($data, self::page),
            tableKey: self::requireString($data, self::tableKey),
            progress: TableProgressDTO::fromArray($data),
        );
    }
}
