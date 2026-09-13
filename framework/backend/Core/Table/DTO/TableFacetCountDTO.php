<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\TableConstants;

/**
 * TableFacetCountDTO - How many rows one option of a filter would leave, with the word on that number.
 *
 * The number is either the size of the set or the ceiling the count stopped at, and the two are
 * told apart by {@see $exact} rather than by the number itself: a set of exactly
 * {@see TableConstants::COUNT_CEILING} rows and a set that ran past it carry the same count, and
 * only one of them is drawn as "500+".
 */
final class TableFacetCountDTO extends BaseDTO
{
    /**
     * Creates the count of one option.
     *
     * @param int $count Rows the option leaves, or the ceiling the count stopped at
     * @param bool $exact Whether the count is the size of the set rather than the ceiling
     */
    public function __construct(
        public readonly int $count,
        public readonly bool $exact,
    ) {
    }

    /**
     * Converts the count to its wire array.
     *
     * @return array{count: int, exact: bool} Count with the word on it
     */
    public function toArray(): array
    {
        return [
            TableConstants::FACET_KEY_COUNT => $this->count,
            TableConstants::FACET_KEY_EXACT => $this->exact,
        ];
    }

    /**
     * Restores the count from its wire array.
     *
     * @param array<string, mixed> $data Source data in the facet-count wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses the count or the word on it
     */
    public static function fromArray(array $data): static
    {
        return new static(
            count: self::requireInt($data, TableConstants::FACET_KEY_COUNT),
            exact: self::requireBool($data, TableConstants::FACET_KEY_EXACT),
        );
    }
}
