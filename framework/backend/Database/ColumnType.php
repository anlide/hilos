<?php

namespace Hilos\Database;

/**
 * Database column types enum.
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

