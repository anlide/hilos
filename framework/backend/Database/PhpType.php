<?php

namespace Hilos\Database;

/**
 * Entity _types column identifiers for PHP-side database mapping.
 *
 * Non-native types (datetime, date, time, text, json, binary) are stored as strings in PHP;
 * decimal and double are handled as float.
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
    
    // ORM metadata alias for FLOAT; this is not a PHP `(double)` cast.
    case DOUBLE = 'double';  // Alias for float, handled as float in PHP
}
