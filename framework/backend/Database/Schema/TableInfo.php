<?php

namespace Hilos\Database\Schema;

/**
 * TableInfo - Information about a database table
 */
readonly class TableInfo
{
    /**
     * @param string $name Table name
     * @param array<string, ColumnInfo> $columns Column information
     * @param array<string> $primaryKeys Primary key column names
     * @param array<string, IndexInfo> $indexes Index information
     * @param array<string, string> $foreignKeys Foreign key mappings (column => table)
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $primaryKeys,
        public array $indexes,
        public array $foreignKeys,
    ) {
    }
}

