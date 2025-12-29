<?php

namespace Hilos\Database\Filter;

/**
 * Builder for creating filters
 */
class FilterBuilder
{
    /** @var FilterInterface[] */
    private array $filters = [];
    private FilterLogic $currentLogic = FilterLogic::AND;

    /**
     * Add WHERE condition
     */
    public function where(string $column, FilterOperator $op, mixed $value): self
    {
        $this->filters[] = new ColumnFilter($column, $op, $value);
        return $this;
    }

    /**
     * Add AND WHERE condition
     */
    public function andWhere(string $column, FilterOperator $op, mixed $value): self
    {
        $this->currentLogic = FilterLogic::AND;
        return $this->where($column, $op, $value);
    }

    /**
     * Add OR WHERE condition
     */
    public function orWhere(string $column, FilterOperator $op, mixed $value): self
    {
        $this->currentLogic = FilterLogic::OR;
        return $this->where($column, $op, $value);
    }

    /**
     * Add composite filter
     */
    public function addFilter(FilterInterface $filter): self
    {
        $this->filters[] = $filter;
        return $this;
    }

    /**
     * Build final filter
     */
    public function build(): FilterInterface
    {
        if (empty($this->filters)) {
            throw new \InvalidArgumentException("Cannot build empty filter");
        }

        if (count($this->filters) === 1) {
            return $this->filters[0];
        }

        return new CompositeFilter($this->filters, $this->currentLogic);
    }
}

