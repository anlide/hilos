<?php

namespace Hilos\Database\Schema;

use Hilos\Database\ColumnType;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\SqlParamCollection;

/**
 * Schema - Database structure storage and management.
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

    // MySQL DESCRIBE result columns
    private const string COL_FIELD = 'Field';
    private const string COL_TYPE = 'Type';
    private const string COL_NULL = 'Null';
    private const string COL_DEFAULT = 'Default';
    private const string COL_KEY = 'Key';
    private const string COL_EXTRA = 'Extra';

    // MySQL SHOW INDEXES result columns
    private const string IDX_KEY_NAME = 'Key_name';
    private const string IDX_NON_UNIQUE = 'Non_unique';
    private const string IDX_COLUMN_NAME = 'Column_name';

    // Foreign-key query aliases
    private const string ALIAS_DB_NAME = 'db_name';
    private const string ALIAS_COLUMNS = 'columns';
    private const string ALIAS_REFERENCED_TABLE = 'referenced_table';

    // Internal index-group accumulator keys
    private const string IDX_GROUP_UNIQUE = 'unique';
    private const string IDX_GROUP_COLUMNS = 'columns';

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
     * Initializes schema for a connection index.
     *
     * Reads all tables and their structure from database.
     *
     * @param ?int $index Connection index (default: current)
     * @throws DatabaseException If connection is not established or schema read fails
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
     * Reads table structure from database.
     *
     * @param string $tableName Table name
     * @return TableInfo Table structure information
     * @throws DatabaseException If table structure cannot be read
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
            $field = $column[self::COL_FIELD];
            $type = $column[self::COL_TYPE];
            $nullable = $column[self::COL_NULL] === self::MYSQL_NULL_YES;
            $default = $column[self::COL_DEFAULT];
            $key = $column[self::COL_KEY];
            $extra = $column[self::COL_EXTRA] ?? '';

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
            $keyName = $index[self::IDX_KEY_NAME];
            if ($keyName === self::MYSQL_INDEX_PRIMARY) {
                continue; // Already handled in columns
            }

            if (!isset($indexGroups[$keyName])) {
                $indexGroups[$keyName] = [
                    self::IDX_GROUP_UNIQUE => $index[self::IDX_NON_UNIQUE] === self::MYSQL_NON_UNIQUE_FALSE,
                    self::IDX_GROUP_COLUMNS => []
                ];
            }
            $indexGroups[$keyName][self::IDX_GROUP_COLUMNS][] = $index[self::IDX_COLUMN_NAME];
        }

        foreach ($indexGroups as $keyName => $info) {
            $indexInfo[$keyName] = new IndexInfo(
                name: $keyName,
                columns: $info[self::IDX_GROUP_COLUMNS],
                unique: $info[self::IDX_GROUP_UNIQUE]
            );
        }

        // Get real foreign keys from INFORMATION_SCHEMA
        $foreignKeys = self::getRealForeignKeys($tableName);

        return new TableInfo(
            name: $tableName,
            columns: $columnInfo,
            primaryKeys: $primaryKeys,
            indexes: $indexInfo,
            foreignKeys: $foreignKeys
        );
    }

    /**
     * Gets real foreign keys from INFORMATION_SCHEMA.
     *
     * Supports both simple and composite foreign keys.
     *
     * @param string $tableName Table name
     * @return array<string, string> Foreign keys mapping:
     *   - Simple: 'column' => 'referenced_table'
     *   - Composite: 'col1,col2' => 'referenced_table'
     * @throws DatabaseException If foreign key query fails
     */
    private static function getRealForeignKeys(string $tableName): array
    {
        try {
            // Get database name from current connection
            Database::sql('SELECT DATABASE() as ' . self::ALIAS_DB_NAME);
            $dbRow = Database::row();
            $dbName = $dbRow[self::ALIAS_DB_NAME] ?? null;

            if ($dbName === null) {
                return [];
            }

            // Query INFORMATION_SCHEMA for foreign keys
            // GROUP_CONCAT with ORDER BY to handle composite keys correctly
            Database::sql("
                SELECT
                    GROUP_CONCAT(kcu.COLUMN_NAME ORDER BY kcu.ORDINAL_POSITION SEPARATOR ',') as " . self::ALIAS_COLUMNS . ",
                    kcu.REFERENCED_TABLE_NAME as " . self::ALIAS_REFERENCED_TABLE . "
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                WHERE kcu.TABLE_SCHEMA = ?
                  AND kcu.TABLE_NAME = ?
                  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                GROUP BY kcu.CONSTRAINT_NAME, kcu.REFERENCED_TABLE_NAME
                ORDER BY kcu.CONSTRAINT_NAME, MIN(kcu.ORDINAL_POSITION)
            ", SqlParamCollection::fromArray([$dbName, $tableName]));

            $foreignKeys = [];
            while ($row = Database::row()) {
                $columns = $row[self::ALIAS_COLUMNS];
                $referencedTable = $row[self::ALIAS_REFERENCED_TABLE];

                // For composite keys, use comma-separated string as key
                // For simple keys, use single column name
                $foreignKeys[$columns] = $referencedTable;
            }

            return $foreignKeys;
        } catch (DatabaseException $e) {
            // If INFORMATION_SCHEMA query fails, return empty array
            // This allows the system to continue working even if INFORMATION_SCHEMA is not accessible
            return [];
        }
    }

    /**
     * Gets table structure.
     *
     * @param string $tableName Table name
     * @param ?int $index Connection index
     * @return ?TableInfo Table structure or null if not found
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
     * Gets all tables for a connection index.
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
     * Gets table names for a connection index.
     *
     * @param ?int $index Connection index
     * @return list<string> Table names
     */
    public static function getTableNames(?int $index = null): array
    {
        $index = $index ?? Database::getCurrentIndex();

        return array_keys(self::$tables[$index] ?? []);
    }

    /**
     * Checks if schema is initialized for a connection index.
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
     * Gets schema statistics for a connection index.
     *
     * @param ?int $index Connection index
     * @return array<string, mixed> Statistics (connectionIndex, initialized, tablesCount, etc.)
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
     * Converts MySQL type to PHP type.
     *
     * @param string $mysqlType MySQL column type (e.g. int, varchar, datetime)
     * @return string PHP type (integer, string, float, boolean, datetime, etc.)
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
     * Resets schema for a connection index (for re-initialization).
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
