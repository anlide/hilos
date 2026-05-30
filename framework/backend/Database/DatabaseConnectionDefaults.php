<?php

declare(strict_types=1);

namespace Hilos\Database;

/**
 * Default connection parameters and charset/collation for MySQL access layer.
 */
final class DatabaseConnectionDefaults
{
    public const string HOST = 'localhost';

    public const string USER = 'root';

    public const string PASSWORD = '';

    public const int PORT = 3306;

    public const string CHARSET = 'utf8mb4';

    public const string COLLATION = 'utf8mb4_0900_ai_ci';

    public const string DDL_TABLE_SUFFIX = 'ENGINE=InnoDB DEFAULT CHARSET=' . self::CHARSET . ' COLLATE=' . self::COLLATION;

    /**
     * @return string SET NAMES statement for session charset and collation
     */
    public static function setNamesSql(): string
    {
        return 'SET NAMES ' . self::CHARSET . ' COLLATE ' . self::COLLATION;
    }

    /**
     * @return string CREATE DATABASE charset/collation clause
     */
    public static function createDatabaseCharsetClause(): string
    {
        return 'CHARACTER SET ' . self::CHARSET . ' COLLATE ' . self::COLLATION;
    }
}
