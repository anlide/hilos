<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * TableFacetCountsSignalData - Server-to-client counts beside the options of one table's filters.
 *
 * The counts travel in a frame of their own rather than inside the window frame. The options a
 * count is taken for are declared by the view when it mounts, and the first window has already
 * left with the page's answer by then; counts inside the window frame would mean either a cold
 * page with no numbers until the first change of a filter or the rows sent a second time for the
 * sake of the numbers. The live count of the set travels the same way, in a frame of its own.
 *
 * A frame need not name every filter: it carries the filters whose counts were taken again, and
 * the client lays them over the counts it holds by filter key instead of replacing them. Changing
 * one filter moves the counts of every other filter and leaves its own where they were. Addressed
 * per accept key.
 */
final class TableFacetCountsSignalData extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string facets = 'facets';

    /**
     * Creates a facet-counts payload.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the counts are for
     * @param TableFacetsDTO $facets Counts of the filters taken again, by filter key
     */
    public function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly TableFacetsDTO $facets,
    ) {
    }

    /**
     * Converts the payload to its wire array.
     *
     * The map of filters goes out as an object for the reason its options do
     * ({@see TableFacetsDTO::toArray()}): an empty PHP array is encoded as a JSON list.
     *
     * @return array<string, mixed> DTO payload in the table-facet-counts wire form
     */
    public function toArray(): array
    {
        return [
            self::page => $this->page,
            self::tableKey => $this->tableKey,
            self::facets => (object) $this->facets->toArray(),
        ];
    }

    /**
     * Restores the payload from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-facet-counts wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses the addressed table or carries counts that are not counts
     */
    public static function fromArray(array $data): static
    {
        return new static(
            page: self::requireString($data, self::page),
            tableKey: self::requireString($data, self::tableKey),
            facets: TableFacetsDTO::fromArray(self::requireArray($data, self::facets)),
        );
    }
}
