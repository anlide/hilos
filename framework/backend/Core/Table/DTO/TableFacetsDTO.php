<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\TableConstants;

/**
 * TableFacetsDTO - The counts beside the options of a table's filters, filter by filter.
 *
 * Each filter carries the count of the set with that filter lifted ("any") and the count of each
 * option it was asked about, keyed by the option value as text - the key the client finds its own
 * option under. A filter the table does not count is absent from the map altogether, which is how
 * "no numbers here" is said: there is no empty entry that could be drawn as a row of zeros.
 */
final class TableFacetsDTO extends BaseDTO
{
    /**
     * Creates the counts of a table's filters.
     *
     * @param array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> $filters Counts by
     *     filter key; an option value that reads as a number keys its count by an integer, which is what a PHP array does
     */
    public function __construct(
        public readonly array $filters,
    ) {
    }

    /**
     * Converts the counts to their wire array.
     *
     * The options of each filter go out as an object even when their keys read as numbers. A PHP
     * array keyed 0, 1, 2 is encoded as a JSON list, and a list is not the map the client looks its
     * options up in - the counts would arrive and match nothing.
     *
     * @return array<string, array{any: array{count: int, exact: bool}, options: object}> Counts in the facets wire form
     */
    public function toArray(): array
    {
        $wire = [];
        foreach ($this->filters as $filterKey => $facet) {
            $wire[$filterKey] = [
                TableConstants::FACET_KEY_ANY => $facet[TableConstants::FACET_KEY_ANY]->toArray(),
                TableConstants::FACET_KEY_OPTIONS => (object) array_map(
                    static fn(TableFacetCountDTO $count): array => $count->toArray(),
                    $facet[TableConstants::FACET_KEY_OPTIONS],
                ),
            ];
        }

        return $wire;
    }

    /**
     * Restores the counts from their wire array.
     *
     * @param array<string, mixed> $data Source data in the facets wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When a filter carries no "any" count, no options, or a count that is not one
     */
    public static function fromArray(array $data): static
    {
        $filters = [];
        foreach ($data as $filterKey => $facet) {
            if (!is_array($facet)) {
                throw new InvalidFormatException('Facets payload carries no counts under filter ' . $filterKey);
            }

            $options = [];
            foreach (self::requireArray($facet, TableConstants::FACET_KEY_OPTIONS) as $optionKey => $count) {
                if (!is_array($count)) {
                    throw new InvalidFormatException("Facets payload carries no count under option {$optionKey} of filter {$filterKey}");
                }
                $options[$optionKey] = TableFacetCountDTO::fromArray($count);
            }

            $filters[$filterKey] = [
                TableConstants::FACET_KEY_ANY => TableFacetCountDTO::fromArray(self::requireArray($facet, TableConstants::FACET_KEY_ANY)),
                TableConstants::FACET_KEY_OPTIONS => $options,
            ];
        }

        return new static($filters);
    }
}
