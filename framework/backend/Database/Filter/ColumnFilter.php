<?php

namespace Hilos\Database\Filter;

use Hilos\Database\Object\Item\Object_;

/**
 * Filter by column value with operator (equals, in, like, etc.).
 */
class ColumnFilter implements FilterInterface
{
    /** @var string column name (camelCase, converted to snake_case for SQL) */
    private string $column;

    /** @var FilterOperator comparison operator */
    private FilterOperator $operator;

    /** @var mixed filter value (array for IN/NOT_IN, array of 2 for BETWEEN) */
    private mixed $value;

    /**
     * Creates column filter.
     *
     * @param string $column Column name (camelCase, converted to snake_case for SQL)
     * @param FilterOperator $operator Comparison operator
     * @param mixed $value Value (array for IN/NOT_IN, array of 2 for BETWEEN)
     */
    public function __construct(string $column, FilterOperator $operator, mixed $value)
    {
        $this->column = $column;
        $this->operator = $operator;
        $this->value = $value;
    }

    /**
     * Converts camelCase to snake_case for SQL column names.
     *
     * @param string $camel CamelCase string
     * @return string snake_case string
     */
    private function camelToSnake(string $camel): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $camel));
    }

    /**
     * Generates SQL WHERE condition fragment.
     *
     * @param string $table Table name
     * @param string $alias Table alias (for JOINs)
     * @return string SQL condition (without WHERE keyword)
     */
    public function toSql(string $table, string $alias = ''): string
    {
        $prefix = $alias ? "{$alias}." : '';
        // Convert camelCase to snake_case for SQL
        $sqlColumn = $this->camelToSnake($this->column);
        $column = "`{$sqlColumn}`";

        return match($this->operator) {
            FilterOperator::IN => "{$prefix}{$column} IN (" . implode(',', array_fill(0, count((array)$this->value), '?')) . ")",
            FilterOperator::NOT_IN => "{$prefix}{$column} NOT IN (" . implode(',', array_fill(0, count((array)$this->value), '?')) . ")",
            FilterOperator::IS_NULL => "{$prefix}{$column} IS NULL",
            FilterOperator::IS_NOT_NULL => "{$prefix}{$column} IS NOT NULL",
            FilterOperator::BETWEEN => "{$prefix}{$column} BETWEEN ? AND ?",
            default => "{$prefix}{$column} {$this->operator->value} ?",
        };
    }

    /**
     * Returns parameter values for prepared statement binding.
     *
     * @return list<mixed> Values in order for placeholders
     */
    public function getParams(): array
    {
        return match($this->operator) {
            FilterOperator::IN, FilterOperator::NOT_IN => (array)$this->value,
            FilterOperator::BETWEEN => [$this->value[0], $this->value[1]],
            FilterOperator::IS_NULL, FilterOperator::IS_NOT_NULL => [],
            default => [$this->value],
        };
    }

    /**
     * Checks if object matches filter (in-memory).
     *
     * @param Object_ $object Object to check
     * @return bool True if matches
     */
    public function matches(Object_ $object): bool
    {
        $objectValue = $object->{$this->column} ?? null;

        return match($this->operator) {
            FilterOperator::EQUALS => $objectValue === $this->value,
            FilterOperator::NOT_EQUALS => $objectValue !== $this->value,
            FilterOperator::GREATER => $objectValue > $this->value,
            FilterOperator::LESS => $objectValue < $this->value,
            FilterOperator::GREATER_OR_EQUAL => $objectValue >= $this->value,
            FilterOperator::LESS_OR_EQUAL => $objectValue <= $this->value,
            FilterOperator::IN => in_array($objectValue, (array)$this->value, true),
            FilterOperator::NOT_IN => !in_array($objectValue, (array)$this->value, true),
            FilterOperator::LIKE => str_contains((string)$objectValue, (string)$this->value),
            FilterOperator::NOT_LIKE => !str_contains((string)$objectValue, (string)$this->value),
            FilterOperator::IS_NULL => $objectValue === null,
            FilterOperator::IS_NOT_NULL => $objectValue !== null,
            FilterOperator::BETWEEN => $objectValue >= $this->value[0] && $objectValue <= $this->value[1],
        };
    }

    /**
     * Returns column names used in filter.
     *
     * @return list<string> Column names
     */
    public function getColumns(): array
    {
        return [$this->column];
    }
}

