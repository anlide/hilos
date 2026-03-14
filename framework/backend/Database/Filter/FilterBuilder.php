<?php

namespace Hilos\Database\Filter;

/**
 * Builder for creating filters.
 *
 * Accumulates column conditions and composite filters, builds final FilterInterface.
 */
class FilterBuilder
{
    /** @var FilterInterface[] */
    private array $filters = [];
    private FilterLogic $currentLogic = FilterLogic::AND;

    /**
     * Adds WHERE condition for column, operator and value.
     *
     * @param string $column Column name
     * @param FilterOperator $op Comparison operator
     * @param mixed $value Value to compare
     * @return self For chaining
     */
    public function where(string $column, FilterOperator $op, mixed $value): self
    {
        $this->filters[] = new ColumnFilter($column, $op, $value);
        return $this;
    }

    /**
     * Adds AND WHERE condition (sets logic to AND).
     *
     * @param string $column Column name
     * @param FilterOperator $op Comparison operator
     * @param mixed $value Value to compare
     * @return self For chaining
     */
    public function andWhere(string $column, FilterOperator $op, mixed $value): self
    {
        $this->currentLogic = FilterLogic::AND;
        return $this->where($column, $op, $value);
    }

    /**
     * Adds OR WHERE condition (sets logic to OR).
     *
     * @param string $column Column name
     * @param FilterOperator $op Comparison operator
     * @param mixed $value Value to compare
     * @return self For chaining
     */
    public function orWhere(string $column, FilterOperator $op, mixed $value): self
    {
        $this->currentLogic = FilterLogic::OR;
        return $this->where($column, $op, $value);
    }

    /**
     * Adds composite filter (nested FilterInterface).
     *
     * @param FilterInterface $filter Filter instance to add
     * @return self For chaining
     */
    public function addFilter(FilterInterface $filter): self
    {
        $this->filters[] = $filter;
        return $this;
    }

    /**
     * Builds final filter from accumulated conditions.
     *
     * @return FilterInterface Single filter or composite
     * @throws \InvalidArgumentException When no filters added
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

