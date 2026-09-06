<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

/**
 * The order one table window runs in: a sequence of components, the first one deciding.
 *
 * A window is ordered by a list rather than by one pair because an order served by an index is
 * served by the whole of it — the sequence of the columns and their directions together — so the
 * thing a table declares and the thing a query runs have to be the same shape. A single-column
 * order is that shape with one component in it, not a different kind of order.
 *
 * "No ordering" is the absence of this object, exactly as it is for one {@see TableSortDTO}: an
 * order with no components would say the same thing twice and answer nothing about its own last
 * component, so it cannot be written down — {@see fromWire()} reads an empty list as no ordering
 * and {@see of()} asks for the first component by name.
 *
 * The wire form is the list of nested `{field, direction}` objects, in the order they apply. The
 * key a table declared the order under does not travel: the client picks an order and echoes the
 * order itself back, so the two sides never have to agree on a name for it.
 */
final readonly class TableSortOrderDTO
{
    /**
     * @param list<TableSortDTO> $components Components of the order, the first one deciding; never empty
     */
    private function __construct(public array $components)
    {
    }

    /**
     * Builds an order out of its components, in the sequence they apply.
     *
     * @param TableSortDTO $component Component that decides first
     * @param TableSortDTO ...$more Components settling what the ones before them left equal
     * @return self Order running by those components in that sequence
     */
    public static function of(TableSortDTO $component, TableSortDTO ...$more): self
    {
        return new self([$component, ...array_values($more)]);
    }

    /**
     * Reads the wire order, or null when the window asked for no ordering.
     *
     * A list is what the frame carries, and anything else — a bare object, a string, a missing
     * key — is a window that named no order rather than a malformed one, because the frame is
     * also what an SDK sends when it simply has no ordering to report. An entry that is no sort
     * of its own drops the whole order: half an order is not an order, and running the rest of
     * it would silently serve a window nobody asked for.
     *
     * @param mixed $raw The `sort` value as it arrived on the wire
     * @return ?self The requested ordering, or null when none was requested
     */
    public static function fromWire(mixed $raw): ?self
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            return null;
        }

        $components = [];
        foreach ($raw as $entry) {
            $component = TableSortDTO::fromWire($entry);
            if ($component === null) {
                return null;
            }

            $components[] = $component;
        }

        return new self($components);
    }

    /**
     * Returns the same order carried by other components, one for one.
     *
     * This is how a gate hands an order on with what it added to it — the column each component
     * earned — without any part of the order being rebuilt out of client input a second time.
     *
     * @param list<TableSortDTO> $components Components of the same order, in the same sequence; never empty
     * @return self Same order, running by those components
     */
    public function withComponents(array $components): self
    {
        return new self($components);
    }

    /**
     * Returns the component that settles what all the others left equal.
     *
     * It is the one the tie-breaker reads: the primary key appended after it continues the
     * direction the order ends in, so that the whole order runs one way at its tail.
     *
     * @return TableSortDTO Last component of the order
     */
    public function last(): TableSortDTO
    {
        return $this->components[count($this->components) - 1];
    }

    /**
     * @return list<array{field: string, direction: string}> Wire form: the components in the order they apply
     */
    public function toArray(): array
    {
        return array_map(static fn(TableSortDTO $component): array => $component->toArray(), $this->components);
    }
}
