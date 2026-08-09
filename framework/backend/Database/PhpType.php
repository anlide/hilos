<?php

namespace Hilos\Database;

use Hilos\Database\Schema\Schema;

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

    /**
     * Strict PhpType-to-MySQL reference table, keyed off the raw column type
     * (`information_schema.COLUMNS.COLUMN_TYPE` / `DESCRIBE.Type`).
     *
     * Unlike {@see Schema::mysqlTypeToPhp()}, which folds
     * every unknown type into STRING for schema introspection, this returns the
     * exhaustive set of PhpTypes that legitimately map to the MySQL type and an
     * empty list for anything unmapped — so the schema audit reports an unknown
     * type as a mismatch instead of silently accepting it. A base type with two
     * acceptable metadata spellings (double maps to FLOAT or DOUBLE) returns both.
     *
     * @param string $mysqlColumnType Raw MySQL column type, e.g. `int unsigned`, `tinyint(1)`, `enum('a','b')`
     * @return list<self> Accepted PhpTypes, or an empty list when the type is unknown
     */
    public static function forMysqlType(string $mysqlColumnType): array
    {
        $type = strtolower(trim($mysqlColumnType));

        // tinyint(1) is the canonical boolean spelling; wider tinyint is an integer.
        if (preg_match('/^tinyint\s*\(\s*1\s*\)/', $type) === 1) {
            return [self::BOOLEAN];
        }

        preg_match('/^([a-z_]+)/', $type, $matches);
        // external-boundary: COLUMN_TYPE comes from the server and may not start with a type word
        $baseType = $matches[1] ?? '';

        return match ($baseType) {
            'bool', 'boolean' => [self::BOOLEAN],
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'year' => [self::INTEGER],
            'decimal', 'numeric' => [self::DECIMAL],
            'float' => [self::FLOAT],
            'double', 'real' => [self::FLOAT, self::DOUBLE],
            'datetime', 'timestamp' => [self::DATETIME],
            'date' => [self::DATE],
            'time' => [self::TIME],
            'char', 'varchar', 'enum', 'set' => [self::STRING],
            'tinytext', 'text', 'mediumtext' => [self::TEXT],
            // MariaDB stores a JSON column as LONGTEXT (JSON is an alias), so a
            // longtext column may legitimately be mapped as either TEXT or JSON.
            'longtext' => [self::TEXT, self::JSON],
            'json' => [self::JSON],
            'binary', 'varbinary', 'bit', 'blob', 'tinyblob', 'mediumblob', 'longblob' => [self::BINARY],
            default => [],
        };
    }
}
