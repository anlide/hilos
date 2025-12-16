<?php

namespace Hilos\Database;

/**
 * Entity column type representation enum
 * Represents types used in Entity _types arrays for database column mapping
 * 
 * Note: Some types (datetime, date, time, text, json, binary) are not native PHP types,
 * but are used for semantic representation of database column types in PHP context.
 * In PHP, these are all handled as strings (except decimal which is handled as float).
 */
enum PhpType: string
{
    // Native PHP types
    case INTEGER = 'integer';
    case STRING = 'string';
    case FLOAT = 'float';
    case BOOLEAN = 'boolean';
    
    // Database-specific semantic types (handled as strings in PHP)
    case DATETIME = 'datetime';
    case DATE = 'date';
    case TIME = 'time';
    case TEXT = 'text';  // MySQL TEXT type, handled as string in PHP
    case JSON = 'json';  // MySQL JSON type, handled as string in PHP
    case BINARY = 'binary';  // MySQL BINARY type, handled as string in PHP
    
    // Database-specific numeric type (handled as float in PHP)
    case DECIMAL = 'decimal';  // MySQL DECIMAL type, handled as float in PHP
    
    // Alias for FLOAT (deprecated in PHP 8.5, but may be used in legacy code)
    case DOUBLE = 'double';  // Alias for float, handled as float in PHP
}
