<?php

namespace Hilos\Database\Filter;

use Hilos\Database\Object\Object_;

/**
 * Composite filter combining multiple filters with logic operator
 */
class CompositeFilter implements FilterInterface
{
    /** @var FilterInterface[] */
    private array $filters;
    private FilterLogic $logic;

    /**
     * @param FilterInterface[] $filters
     * @param FilterLogic $logic
     */
    public function __construct(array $filters, FilterLogic $logic = FilterLogic::AND)
    {
        $this->filters = $filters;
        $this->logic = $logic;
    }

    public function toSql(string $table, string $alias = ''): string
    {
        if (empty($this->filters)) {
            return '1=1'; // Always true
        }

        $conditions = array_map(fn($f) => '(' . $f->toSql($table, $alias) . ')', $this->filters);
        return implode(" {$this->logic->value} ", $conditions);
    }

    public function getParams(): array
    {
        return array_merge(...array_map(fn($f) => $f->getParams(), $this->filters));
    }

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

    public function getColumns(): array
    {
        return array_unique(array_merge(...array_map(fn($f) => $f->getColumns(), $this->filters)));
    }
}

