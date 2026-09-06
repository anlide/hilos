<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\Core\Table\TableAnchorDirection;

/**
 * The place in a table's own order a window is taken from: one value per key column.
 *
 * The anchor names a position, not a row. It is the values the ordering compares, so the row
 * they were read from may be gone by the time the next window is asked for and the window still
 * continues from the same place — which is the whole reason a window is addressed this way
 * rather than by a count of rows to skip.
 *
 * The key space belongs to the row source, not to the client: the ORM anchors by entity column,
 * an in-memory set by row field. A client never builds an anchor, it echoes back the boundary
 * of the window it was given, so the two never have to agree on names.
 *
 * The absence of this object is the edge of the set, and {@see TableAnchorDirection} says which
 * edge. Its values reach a query as bound parameters only — the column names come from the
 * ordering the server resolved, never from the keys of this map.
 */
final readonly class TableAnchorDTO
{
    /**
     * @param array<string, mixed> $values Value the window is anchored at, per key column
     */
    public function __construct(public array $values = [])
    {
    }

    /**
     * Reads the wire anchor, or null when the window asked for an edge of the set.
     *
     * Only scalar and null values survive, because an anchor value exists to be bound into a
     * comparison: a nested structure has no place in an ordering and would travel as far as the
     * query builder before saying so.
     *
     * @param mixed $raw The `anchor` value as it arrived on the wire
     * @return ?self The place the window is anchored at, or null for the edge of the set
     */
    public static function fromWire(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $values = [];
        foreach ($raw as $column => $value) {
            if (is_string($column) && (is_scalar($value) || $value === null)) {
                $values[$column] = $value;
            }
        }

        return $values === [] ? null : new self($values);
    }

    /**
     * Reads the place one row sits at: the value it carries in every column the order is settled by.
     *
     * @param array<string, mixed> $row Row at a boundary of a window
     * @param list<string> $columns Columns the order is settled by, in that order
     * @return self Place that row sits at in that order
     */
    public static function fromRow(array $row, array $columns): self
    {
        $values = [];
        foreach ($columns as $column) {
            $values[$column] = $row[$column] ?? null;
        }

        return new self($values);
    }

    /**
     * @return array<string, mixed> Wire form: the anchored value of each key column
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
