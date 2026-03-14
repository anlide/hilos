<?php

namespace Hilos\Database\Filter;

use Hilos\Database\Object\Item\Object_;

/**
 * Filter criteria interface.
 *
 * Implementations generate SQL conditions and support in-memory filtering.
 */
interface FilterInterface
{
    /**
     * Generate SQL WHERE condition.
     *
     * @param string $table Table name
     * @param string $alias Table alias (for JOINs)
     * @return string SQL condition (without WHERE keyword)
     */
    public function toSql(string $table, string $alias = ''): string;

    /**
     * Get SQL parameters for prepared statement.
     *
     * @return list<mixed> Parameter values in order (not SqlParam objects)
     */
    public function getParams(): array;

    /**
     * Check if object matches filter (for in-memory filtering).
     *
     * @param Object_ $object Object to check
     * @return bool True if matches
     */
    public function matches(Object_ $object): bool;

    /**
     * Get columns needed for filter.
     *
     * @return list<string> Column names
     */
    public function getColumns(): array;
}

