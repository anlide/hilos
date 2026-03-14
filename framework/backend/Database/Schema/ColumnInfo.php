<?php

namespace Hilos\Database\Schema;

/**
 * ColumnInfo - Information about a database column.
 *
 * Holds column metadata from database schema.
 */
readonly class ColumnInfo
{
    /**
     * Creates column info instance.
     *
     * @param string $name Column name
     * @param string $mysqlType MySQL type (e.g., "int(11)", "varchar(255)")
     * @param string $phpType PHP type (e.g., "integer", "string")
     * @param bool $nullable Whether column is nullable
     * @param mixed $default Default value
     * @param bool $isPrimary Whether column is primary key
     * @param bool $isUnique Whether column is unique
     * @param string $extra Extra information (e.g., "auto_increment")
     */
    public function __construct(
        public string $name,
        public string $mysqlType,
        public string $phpType,
        public bool $nullable,
        public mixed $default,
        public bool $isPrimary,
        public bool $isUnique,
        public string $extra,
    ) {
    }
}

