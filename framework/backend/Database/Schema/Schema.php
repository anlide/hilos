<?php

namespace Hilos\Database\Schema;

use Hilos\Database\ColumnType;
use Hilos\Database\Database;
use Hilos\Exception\DatabaseException;

/**
 * Schema - Database structure storage and management
 * 
 * Stores database table structure in static variables per connection index.
 * Provides methods to initialize and query database schema information.
 */
class Schema
{
    // MySQL column key types
    private const string MYSQL_KEY_PRI = 'PRI';
    private const string MYSQL_KEY_UNI = 'UNI';
    private const string MYSQL_NULL_YES = 'YES';
    private const string MYSQL_INDEX_PRIMARY = 'PRIMARY';
    private const string MYSQL_NON_UNIQUE_FALSE = '0';

    // Statistics keys
    private const string STAT_KEY_CONNECTION_INDEX = 'connection_index';
    private const string STAT_KEY_INITIALIZED = 'initialized';
    private const string STAT_KEY_TABLES_COUNT = 'tables_count';
    private const string STAT_KEY_TOTAL_COLUMNS = 'total_columns';
    private const string STAT_KEY_TOTAL_INDEXES = 'total_indexes';
    private const string STAT_KEY_TOTAL_FOREIGN_KEYS = 'total_foreign_keys';
    private const string STAT_KEY_TABLE_NAMES = 'table_names';

    /**
     * @var array<int, array<string, TableInfo>> Table structures per connection index
     */
    private static array $tables = [];

    /**
     * @var array<int, bool> Initialization status per connection index
     */
    private static array $initialized = [];

    /**
     * Initialize schema for a connection index
     * Reads all tables and their structure from database
     * 
     * @param int $index Connection index (default: current)
     * @throws DatabaseException
     */
    public static function initialize(?int $index = null): void
    {
        $index = $index ?? Database::getCurrentIndex();

        if (isset(self::$initialized[$index]) && self::$initialized[$index]) {
            return; // Already initialized
        }

        if (!Database::isConnected($index)) {
            throw new DatabaseException("Database connection {$index} is not established");
        }

        // Save current index
        $originalIndex = Database::getCurrentIndex();
        Database::useConnection($index);

        try {
            // Get all tables
            Database::sql('SHOW TABLES');
            $tables = [];
            while ($row = Database::row()) {
                $tableName = reset($row);
                $tables[] = $tableName;
            }

            // Read structure for each table
            self::$tables[$index] = [];
            foreach ($tables as $tableName) {
                self::$tables[$index][$tableName] = self::readTableStructure($tableName);
            }

            self::$initialized[$index] = true;
        } finally {
            // Restore original index
            Database::useConnection($originalIndex);
        }
    }

    /**
     * Read table structure from database
     * 
     * @param string $tableName Table name
     * @return TableInfo Table structure information
     * @throws DatabaseException
     */
    private static function readTableStructure(string $tableName): TableInfo
    {
        // Get columns
        Database::sql("DESCRIBE `{$tableName}`");
        $columns = Database::rows();

        // Get indexes
        Database::sql("SHOW INDEXES FROM `{$tableName}`");
        $indexes = Database::rows();

        // Parse columns
        $columnInfo = [];
        $primaryKeys = [];

        foreach ($columns as $column) {
            $field = $column['Field'];
            $type = $column['Type'];
            $nullable = $column['Null'] === self::MYSQL_NULL_YES;
            $default = $column['Default'];
            $key = $column['Key'];
            $extra = $column['Extra'] ?? '';

            $phpType = self::mysqlTypeToPhp($type);

            $columnInfo[$field] = new ColumnInfo(
                name: $field,
                mysqlType: $type,
                phpType: $phpType,
                nullable: $nullable,
                default: $default,
                isPrimary: $key === self::MYSQL_KEY_PRI,
                isUnique: $key === self::MYSQL_KEY_UNI,
                extra: $extra
            );

            if ($key === self::MYSQL_KEY_PRI) {
                $primaryKeys[] = $field;
            }
        }

        // Parse indexes
        $indexInfo = [];
        $indexGroups = [];
        foreach ($indexes as $index) {
            $keyName = $index['Key_name'];
            if ($keyName === self::MYSQL_INDEX_PRIMARY) {
                continue; // Already handled in columns
            }

            if (!isset($indexGroups[$keyName])) {
                $indexGroups[$keyName] = [
                    'unique' => $index['Non_unique'] === self::MYSQL_NON_UNIQUE_FALSE,
                    'columns' => []
                ];
            }
            $indexGroups[$keyName]['columns'][] = $index['Column_name'];
        }

        foreach ($indexGroups as $keyName => $info) {
            $indexInfo[$keyName] = new IndexInfo(
                name: $keyName,
                columns: $info['columns'],
                unique: $info['unique']
            );
        }

        // Detect foreign keys (by naming convention: id_*)
        $foreignKeys = [];
        foreach ($columns as $column) {
            $field = $column['Field'];
            if (preg_match('/^id_(\w+)$/', $field, $matches)) {
                $foreignTable = $matches[1];
                $foreignKeys[$field] = $foreignTable;
            }
        }

        return new TableInfo(
            name: $tableName,
            columns: $columnInfo,
            primaryKeys: $primaryKeys,
            indexes: $indexInfo,
            foreignKeys: $foreignKeys
        );
    }

    /**
     * Get table structure
     * 
     * @param string $tableName Table name
     * @param ?int $index Connection index
     * @return TableInfo|null Table structure or null if not found
     */
    public static function getTable(string $tableName, ?int $index = null): ?TableInfo
    {
        $index = $index ?? Database::getCurrentIndex();

        if (!isset(self::$tables[$index][$tableName])) {
            return null;
        }

        return self::$tables[$index][$tableName];
    }

    /**
     * Get all tables for a connection index
     * 
     * @param ?int $index Connection index
     * @return array<string, TableInfo> Array of table structures
     */
    public static function getTables(?int $index = null): array
    {
        $index = $index ?? Database::getCurrentIndex();

        return self::$tables[$index] ?? [];
    }

    /**
     * Get table names for a connection index
     * 
     * @param ?int $index Connection index
     * @return array<string> Array of table names
     */
    public static function getTableNames(?int $index = null): array
    {
        $index = $index ?? Database::getCurrentIndex();

        return array_keys(self::$tables[$index] ?? []);
    }

    /**
     * Check if schema is initialized for a connection index
     * 
     * @param ?int $index Connection index
     * @return bool True if initialized
     */
    public static function isInitialized(?int $index = null): bool
    {
        $index = $index ?? Database::getCurrentIndex();

        return isset(self::$initialized[$index]) && self::$initialized[$index];
    }

    /**
     * Get schema statistics for a connection index
     * 
     * @param ?int $index Connection index
     * @return array Statistics array
     */
    public static function getStatistics(?int $index = null): array
    {
        $index = $index ?? Database::getCurrentIndex();

        $tables = self::getTables($index);
        $totalColumns = 0;
        $totalIndexes = 0;
        $totalForeignKeys = 0;

        foreach ($tables as $table) {
            $totalColumns += count($table->columns);
            $totalIndexes += count($table->indexes);
            $totalForeignKeys += count($table->foreignKeys);
        }

        return [
            self::STAT_KEY_CONNECTION_INDEX => $index,
            self::STAT_KEY_INITIALIZED => self::isInitialized($index),
            self::STAT_KEY_TABLES_COUNT => count($tables),
            self::STAT_KEY_TOTAL_COLUMNS => $totalColumns,
            self::STAT_KEY_TOTAL_INDEXES => $totalIndexes,
            self::STAT_KEY_TOTAL_FOREIGN_KEYS => $totalForeignKeys,
            self::STAT_KEY_TABLE_NAMES => array_keys($tables),
        ];
    }

    /**
     * Convert MySQL type to PHP type
     */
    private static function mysqlTypeToPhp(string $mysqlType): string
    {
        // Check for TINYINT(1) which should be boolean
        if (preg_match('/^tinyint\s*\(\s*1\s*\)/i', $mysqlType)) {
            return ColumnType::BOOLEAN->value;
        }
        if (preg_match('/^(tiny|small|medium|big)?int/i', $mysqlType)) {
            return ColumnType::INTEGER->value;
        }
        if (preg_match('/^(float|double|decimal|numeric)/i', $mysqlType)) {
            return ColumnType::FLOAT->value;
        }
        if (preg_match('/^(bool|boolean)/i', $mysqlType)) {
            return ColumnType::BOOLEAN->value;
        }
        if (preg_match('/^(date|datetime|timestamp)/i', $mysqlType)) {
            return ColumnType::DATETIME->value;
        }
        if (preg_match('/^date/i', $mysqlType)) {
            return ColumnType::DATE->value;
        }
        if (preg_match('/^time/i', $mysqlType)) {
            return ColumnType::TIME->value;
        }
        if (preg_match('/^(text|mediumtext|longtext)/i', $mysqlType)) {
            return ColumnType::TEXT->value;
        }
        if (preg_match('/^json/i', $mysqlType)) {
            return ColumnType::JSON->value;
        }
        if (preg_match('/^(binary|varbinary|blob)/i', $mysqlType)) {
            return ColumnType::BINARY->value;
        }
        return ColumnType::STRING->value;
    }

    /**
     * Reset schema for a connection index (for re-initialization)
     * 
     * @param ?int $index Connection index
     */
    public static function reset(?int $index = null): void
    {
        $index = $index ?? Database::getCurrentIndex();

        unset(self::$tables[$index]);
        unset(self::$initialized[$index]);
    }
}
