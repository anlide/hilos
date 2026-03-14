<?php

namespace Hilos\Database\Filter;

use Hilos\Database\Object\Item\Object_;

/**
 * Composite filter combining multiple filters with logic operator (AND/OR/XOR).
 *
 * Combines child filters using AND, OR or XOR logic.
 */
class CompositeFilter implements FilterInterface
{
    /** @var list<FilterInterface> child filters */
    private array $filters;

    /** @var FilterLogic logic operator (AND, OR, XOR) */
    private FilterLogic $logic;

    /**
     * Creates composite filter from child filters.
     *
     * @param array<FilterInterface> $filters Child filters
     * @param FilterLogic $logic Logic operator (AND, OR, XOR)
     */
    public function __construct(array $filters, FilterLogic $logic = FilterLogic::AND)
    {
        $this->filters = $filters;
        $this->logic = $logic;
    }

    /**
     * Generates SQL WHERE condition (combines child filters with logic).
     *
     * @param string $table Table name
     * @param string $alias Table alias (for JOINs)
     * @return string SQL condition (without WHERE keyword)
     */
    public function toSql(string $table, string $alias = ''): string
    {
        if (empty($this->filters)) {
            return '1=1'; // Always true
        }

        $conditions = array_map(fn($f) => '(' . $f->toSql($table, $alias) . ')', $this->filters);
        return implode(" {$this->logic->value} ", $conditions);
    }

    /**
     * Returns all parameter values from child filters (in order).
     *
     * @return array<int, mixed> Values for prepared statement
     */
    public function getParams(): array
    {
        return array_merge(...array_map(fn($f) => $f->getParams(), $this->filters));
    }

    /**
     * Checks if object matches all (AND) or any (OR/XOR) child filter.
     *
     * @param Object_ $object Object to check
     * @return bool True if matches
     */
    public function matches(Object_ $object): bool
    {
        if (empty($this->filters)) {
            return true;
        }

        $results = array_map(fn($f) => $f->matches($object), $this->filters);

        return match($this->logic) {
            FilterLogic::AND => !in_array(false, $results, true),
            FilterLogic::OR => in_array(true, $results, true),
            FilterLogic::XOR => count(array_filter($results)) === 1,
        };
    }

    /**
     * Returns unique column names from all child filters.
     *
     * @return array<int, string> Column names
     */
    public function getColumns(): array
    {
        return array_unique(array_merge(...array_map(fn($f) => $f->getColumns(), $this->filters)));
    }
}

