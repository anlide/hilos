<?php

namespace Hilos\Database\Filter;

use Hilos\Database\Object\Object_;

/**
 * Filter by column value
 */
class ColumnFilter implements FilterInterface
{
    private string $column;
    private FilterOperator $operator;
    private mixed $value; // Может быть массив для IN

    public function __construct(string $column, FilterOperator $operator, mixed $value)
    {
        $this->column = $column;
        $this->operator = $operator;
        $this->value = $value;
    }

    /**
     * Convert camelCase to snake_case for SQL column names
     */
    private function camelToSnake(string $camel): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $camel));
    }

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

    public function getParams(): array
    {
        return match($this->operator) {
            FilterOperator::IN, FilterOperator::NOT_IN => (array)$this->value,
            FilterOperator::BETWEEN => [$this->value[0], $this->value[1]],
            FilterOperator::IS_NULL, FilterOperator::IS_NOT_NULL => [],
            default => [$this->value],
        };
    }

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

    public function getColumns(): array
    {
        return [$this->column];
    }
}

