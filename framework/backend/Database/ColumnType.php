<?php

namespace Hilos\Database;

/**
 * Database column type identifiers for schema metadata.
 */
enum ColumnType: string
{
    case INTEGER = 'integer';
    case STRING = 'string';
    case FLOAT = 'float';
    case BOOLEAN = 'boolean';
    case DATETIME = 'datetime';
    case DATE = 'date';
    case TIME = 'time';
    case TEXT = 'text';
    case JSON = 'json';
    case BINARY = 'binary';
    case DECIMAL = 'decimal';
}

