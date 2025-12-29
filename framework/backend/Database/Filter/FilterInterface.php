<?php

namespace Hilos\Database\Filter;

use Hilos\Database\Object\Object_;

/**
 * Interface for filter criteria
 */
interface FilterInterface
{
    /**
     * Generate SQL WHERE condition
     * 
     * @param string $table Table name
     * @param string $alias Table alias (for JOINs)
     * @return string SQL condition (without WHERE keyword)
     */
    public function toSql(string $table, string $alias = ''): string;

    /**
     * Get SQL parameters for prepared statement
     * 
     * @return array Parameters array (values, not SqlParam objects)
     */
    public function getParams(): array;

    /**
     * Check if object matches filter (for in-memory filtering)
     * 
     * @param Object_ $object Object to check
     * @return bool True if matches
     */
    public function matches(Object_ $object): bool;

    /**
     * Get columns needed for filter
     * 
     * @return array Column names
     */
    public function getColumns(): array;
}

