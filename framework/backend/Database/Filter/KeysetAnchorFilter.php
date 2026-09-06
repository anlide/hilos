<?php

declare(strict_types=1);

namespace Hilos\Database\Filter;

use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlSortDirection;

/**
 * The condition that takes a window from an anchor instead of from a count of rows to skip.
 *
 * It is the one builder of that condition, used by the ORM page query and by a table's own SQL
 * alike: the same descriptor answered two ways would be two orderings the day either is edited.
 *
 * The condition is the expanded chain `(a > x) OR (a = x AND b > y)`, not the tuple comparison
 * `ROW(a, b) > ROW(x, y)`: a tuple reaches an index only while every column runs the same way,
 * and a window may order two columns in opposite directions.
 *
 * Nulls are ordered the way the database orders them — first ascending, last descending — so
 * "after a null" ascending is every row that has a value, and descending it is no row at all.
 * Without this the pages around the null boundary would repeat a row or skip one, which is the
 * defect the anchor exists to remove.
 *
 * Column names come from the ordering the caller resolved, never from the anchor's own keys, and
 * the anchored values reach the query as bound parameters. A key the ordering does not name is
 * dropped, and the chain runs over the leading columns the anchor carries: a caller that hands
 * over half a key gets a narrower window, never a column name of its own choosing in the SQL.
 */
final class KeysetAnchorFilter implements FilterInterface
{
    /** @var list<string> Leading key columns the chain compares, in the window's own order */
    private array $columns = [];

    /** @var array<string, mixed> Anchored value of each compared column */
    private array $values = [];

    /** @var array<string, bool> Whether the scan runs up the column, direction and side folded together */
    private array $ascending = [];

    /**
     * @param array<string, string> $orderBy Ordered key column => SqlSortDirection the window is ordered by
     * @param TableAnchorDTO $anchor Place in that order the window is taken from
     * @param TableAnchorDirection $direction Side of the anchor the window is taken from
     */
    public function __construct(array $orderBy, TableAnchorDTO $anchor, TableAnchorDirection $direction)
    {
        foreach ($orderBy as $column => $sqlDirection) {
            if (!array_key_exists($column, $anchor->values)) {
                break;
            }

            $this->columns[] = $column;
            $this->values[$column] = $anchor->values[$column];
            $this->ascending[$column] = ($sqlDirection === SqlSortDirection::ASC) === ($direction === TableAnchorDirection::After);
        }
    }

    /**
     * Builds the keyset chain, or the always-true condition when the anchor named no known column.
     *
     * @param string $table Table name, which the chain does not need: its columns are already this table's
     * @param string $alias Table alias for JOINs, empty for an unaliased column
     * @return string SQL condition (without WHERE keyword)
     */
    public function toSql(string $table, string $alias = ''): string
    {
        if ($this->columns === []) {
            return '1=1';
        }

        // external-boundary: the neutral element of the column reference — an unaliased column needs no prefix
        $prefix = $alias !== '' ? "{$alias}." : '';
        $terms = [];
        $tie = [];
        foreach ($this->columns as $column) {
            $reference = "{$prefix}`{$column}`";
            $strict = $this->strictSql($reference, $column);
            $terms[] = $tie === [] ? $strict : '(' . implode(' AND ', $tie) . " AND {$strict})";
            $tie[] = $this->equalSql($reference, $column);
        }

        return '(' . implode(' OR ', $terms) . ')';
    }

    /**
     * @return list<mixed> Anchored values in placeholder order, ties of each term before its comparison
     */
    public function getParams(): array
    {
        $params = [];
        $tie = [];
        foreach ($this->columns as $column) {
            foreach ($tie as $tieValue) {
                $params[] = $tieValue;
            }

            // A null anchor value binds nothing: both its comparison and its tie are written as IS [NOT] NULL.
            if ($this->values[$column] !== null) {
                $params[] = $this->values[$column];
                $tie[] = $this->values[$column];
            }
        }

        return $params;
    }

    /**
     * Tells whether the object sits past the anchor, the same walk down the key the SQL chain makes.
     *
     * Values are compared loosely, because the column that answers `1` in SQL answers `'1'` after a
     * round trip through the driver, and an anchor that stopped matching there would take the window
     * back to the start of the set.
     *
     * @param Object_ $object Object to place against the anchor
     * @return bool True when the object belongs to the anchored window
     */
    public function matches(Object_ $object): bool
    {
        foreach ($this->columns as $column) {
            $objectValue = $object->{$column} ?? null;
            $anchorValue = $this->values[$column];
            if ($objectValue === null && $anchorValue === null) {
                continue;
            }
            if ($objectValue !== null && $anchorValue !== null && $objectValue == $anchorValue) {
                continue;
            }

            return $this->isPastAnchor($objectValue, $anchorValue, $this->ascending[$column]);
        }

        // Equal down the whole key: this is the anchor row itself, and the window starts past it.
        return false;
    }

    /**
     * @return list<string> Key columns the chain compares
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Writes the strict comparison of one key column against its anchored value.
     *
     * @param string $reference Quoted column reference, alias included
     * @param string $column Key column the comparison is written for
     * @return string Parenthesized SQL condition
     */
    private function strictSql(string $reference, string $column): string
    {
        if ($this->values[$column] === null) {
            return $this->ascending[$column] ? "({$reference} IS NOT NULL)" : '(0 = 1)';
        }

        return $this->ascending[$column]
            ? "({$reference} > ?)"
            : "({$reference} < ? OR {$reference} IS NULL)";
    }

    /**
     * Writes the tie of one key column, which is what lets the next column decide.
     *
     * @param string $reference Quoted column reference, alias included
     * @param string $column Key column the tie is written for
     * @return string SQL condition
     */
    private function equalSql(string $reference, string $column): string
    {
        return $this->values[$column] === null ? "{$reference} IS NULL" : "{$reference} = ?";
    }

    /**
     * Places one value past the anchored one, nulls ordered as the database orders them.
     *
     * @param mixed $objectValue Value the object carries in this column, or null when it has none
     * @param mixed $anchorValue Value the window is anchored at in this column
     * @param bool $ascending Whether the scan runs up the column
     * @return bool True when the object value comes after the anchored one
     */
    private function isPastAnchor(mixed $objectValue, mixed $anchorValue, bool $ascending): bool
    {
        if ($anchorValue === null) {
            return $ascending && $objectValue !== null;
        }
        if ($objectValue === null) {
            return !$ascending;
        }

        return $ascending ? $objectValue > $anchorValue : $objectValue < $anchorValue;
    }
}
