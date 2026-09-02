<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\EntitySchemaAudit;

/**
 * LiveSchemaReader - reads one connection's schema out of the live database.
 *
 * Three queries per connection, all over the whole database rather than table by table:
 * the compatibility gate asks about every declared table at once, and a restore's own
 * catalog is small enough that one round trip beats N. `information_schema` is the same
 * source {@see EntitySchemaAudit} judges Entity drift by, which is deliberate - a
 * database describing itself is the only description that cannot have drifted.
 *
 * Read after the forward migrations and before the anonymization pass, so what it
 * returns is the schema the `UPDATE` statements will actually meet.
 */
final class LiveSchemaReader
{
    /**
     * `information_schema.COLUMNS` / `STATISTICS` / `KEY_COLUMN_USAGE` /
     * `REFERENTIAL_CONSTRAINTS` result keys.
     */
    private const string COL_TABLE_NAME = 'TABLE_NAME';
    private const string COL_NAME = 'COLUMN_NAME';
    private const string COL_DATA_TYPE = 'DATA_TYPE';
    private const string COL_IS_NULLABLE = 'IS_NULLABLE';
    private const string COL_MAXIMUM_LENGTH = 'CHARACTER_MAXIMUM_LENGTH';
    private const string COL_INDEX_NAME = 'INDEX_NAME';
    private const string COL_SEQ_IN_INDEX = 'SEQ_IN_INDEX';
    private const string COL_NON_UNIQUE = 'NON_UNIQUE';
    private const string COL_ORDINAL_POSITION = 'ORDINAL_POSITION';
    private const string COL_TABLE_SCHEMA = 'TABLE_SCHEMA';
    private const string COL_REFERENCED_TABLE_NAME = 'REFERENCED_TABLE_NAME';
    private const string COL_REFERENCED_TABLE_SCHEMA = 'REFERENCED_TABLE_SCHEMA';
    private const string COL_CONSTRAINT_NAME = 'CONSTRAINT_NAME';
    private const string COL_CONSTRAINT_SCHEMA = 'CONSTRAINT_SCHEMA';
    private const string COL_DELETE_RULE = 'DELETE_RULE';

    /** `IS_NULLABLE` value of a column that accepts NULL. */
    private const string NULLABLE_YES = 'YES';

    /** `DELETE_RULE` values that forbid deleting a referenced row. */
    private const string RULE_RESTRICT = 'RESTRICT';
    private const string RULE_NO_ACTION = 'NO ACTION';

    /** `NON_UNIQUE` value of an index that tells its rows apart. */
    private const int UNIQUE = 0;

    /** Name `information_schema` gives the primary key among the indexes. */
    private const string INDEX_PRIMARY = 'PRIMARY';

    /**
     * Reads every table one connection's database currently declares.
     *
     * @param int $connectionIndex Connection index to read
     * @return array<string, LiveTableSchema> Tables keyed by name
     * @throws DatabaseException When the connection is not configured or a query fails
     */
    public static function read(int $connectionIndex): array
    {
        Database::useConnection($connectionIndex);

        $uniqueIndexes = self::uniqueIndexes();
        $restrictingKeys = self::restrictingKeys();
        $nullability = [];
        $types = [];
        $lengths = [];
        foreach (self::columnRows() as $row) {
            $table = (string)$row[self::COL_TABLE_NAME];
            $column = (string)$row[self::COL_NAME];
            $length = $row[self::COL_MAXIMUM_LENGTH];
            $nullability[$table][$column] = (string)$row[self::COL_IS_NULLABLE] === self::NULLABLE_YES;
            $types[$table][$column] = strtolower((string)$row[self::COL_DATA_TYPE]);
            $lengths[$table][$column] = $length === null ? null : (int)$length;
        }

        $schemas = [];
        foreach ($nullability as $table => $columns) {
            $indexes = $uniqueIndexes[$table] ?? [];
            $schemas[$table] = new LiveTableSchema(
                $table,
                $columns,
                $types[$table],
                $lengths[$table],
                $indexes[self::INDEX_PRIMARY] ?? [],
                $indexes,
                $restrictingKeys[$table] ?? [],
            );
        }

        return $schemas;
    }

    /**
     * Reads every column of the current connection's database.
     *
     * @return list<array<string, mixed>> `information_schema.COLUMNS` rows, ordered by table
     *     and by the position of the column inside it
     * @throws DatabaseException When the query fails
     */
    private static function columnRows(): array
    {
        Database::sql(
            'SELECT ' . self::COL_TABLE_NAME . ', ' . self::COL_NAME . ', ' . self::COL_DATA_TYPE
            . ', ' . self::COL_IS_NULLABLE . ', ' . self::COL_MAXIMUM_LENGTH
            . ' FROM information_schema.COLUMNS WHERE ' . self::COL_TABLE_SCHEMA . ' = DATABASE()'
            . ' ORDER BY ' . self::COL_TABLE_NAME . ', ' . self::COL_ORDINAL_POSITION,
        );

        return Database::rows();
    }

    /**
     * Reads the unique indexes of the current connection's database.
     *
     * @return array<string, array<string, list<string>>> Index columns by index name, by table
     * @throws DatabaseException When the query fails
     */
    private static function uniqueIndexes(): array
    {
        Database::sql(
            'SELECT ' . self::COL_TABLE_NAME . ', ' . self::COL_INDEX_NAME . ', ' . self::COL_NAME
            . ' FROM information_schema.STATISTICS WHERE ' . self::COL_TABLE_SCHEMA . ' = DATABASE()'
            . ' AND ' . self::COL_NON_UNIQUE . ' = ' . self::UNIQUE
            . ' ORDER BY ' . self::COL_TABLE_NAME . ', ' . self::COL_INDEX_NAME
            . ', ' . self::COL_SEQ_IN_INDEX,
        );

        $indexes = [];
        foreach (Database::rows() as $row) {
            $indexes[(string)$row[self::COL_TABLE_NAME]][(string)$row[self::COL_INDEX_NAME]][]
                = (string)$row[self::COL_NAME];
        }

        return $indexes;
    }

    /**
     * Reads the incoming foreign keys of the current connection's database that forbid
     * deleting a referenced row.
     *
     * The filter stands on the schema of the PARENT rather than on the schema of the
     * constraint: a key declared in a neighbouring database of the same server forbids the
     * delete just as firmly, and a filter by constraint schema would not see it. `DELETE_RULE`
     * is filtered in SQL rather than here, because {@see LiveTableSchema} carries only what
     * the gate and the pass ask about, and a permitting key is not asked about at all.
     *
     * @return array<string, list<RestrictingForeignKey>> Keys by the name of the table they
     *     reference
     * @throws DatabaseException When the query fails
     */
    private static function restrictingKeys(): array
    {
        Database::sql(
            'SELECT kcu.' . self::COL_REFERENCED_TABLE_NAME
            . ', kcu.' . self::COL_REFERENCED_TABLE_SCHEMA . ', kcu.' . self::COL_TABLE_SCHEMA
            . ', kcu.' . self::COL_TABLE_NAME . ', kcu.' . self::COL_NAME
            . ', kcu.' . self::COL_CONSTRAINT_NAME . ', rc.' . self::COL_DELETE_RULE
            . ' FROM information_schema.KEY_COLUMN_USAGE kcu'
            . ' JOIN information_schema.REFERENTIAL_CONSTRAINTS rc'
            . ' ON rc.' . self::COL_CONSTRAINT_SCHEMA . ' = kcu.' . self::COL_CONSTRAINT_SCHEMA
            . ' AND rc.' . self::COL_CONSTRAINT_NAME . ' = kcu.' . self::COL_CONSTRAINT_NAME
            . ' WHERE kcu.' . self::COL_REFERENCED_TABLE_SCHEMA . ' = DATABASE()'
            . ' AND rc.' . self::COL_DELETE_RULE
            . " IN ('" . self::RULE_RESTRICT . "', '" . self::RULE_NO_ACTION . "')"
            . ' ORDER BY kcu.' . self::COL_REFERENCED_TABLE_NAME
            . ', kcu.' . self::COL_CONSTRAINT_NAME . ', kcu.' . self::COL_ORDINAL_POSITION,
        );

        // A composite key arrives as one row per column, so the columns are gathered by key
        // while everything else - the parent, the child, the rule - repeats on every row of
        // that key and is read back off the last of them.
        $columnsOfKey = [];
        $rowOfKey = [];
        foreach (Database::rows() as $row) {
            $key = self::childTable($row) . '.' . (string)$row[self::COL_CONSTRAINT_NAME];
            $columnsOfKey[$key][] = (string)$row[self::COL_NAME];
            $rowOfKey[$key] = $row;
        }

        $restricting = [];
        foreach ($columnsOfKey as $key => $columns) {
            $row = $rowOfKey[$key];
            $restricting[(string)$row[self::COL_REFERENCED_TABLE_NAME]][] = new RestrictingForeignKey(
                (string)$row[self::COL_CONSTRAINT_NAME],
                self::childTable($row),
                $columns,
                (string)$row[self::COL_DELETE_RULE],
            );
        }

        return $restricting;
    }

    /**
     * Names the table a foreign key row belongs to, as the refusal will print it.
     *
     * @param array<string, mixed> $row One `KEY_COLUMN_USAGE` row
     * @return string Table name, prefixed with its schema only when the key lives in another
     *     database than the table it references
     */
    private static function childTable(array $row): string
    {
        $schema = (string)$row[self::COL_TABLE_SCHEMA];
        $table = (string)$row[self::COL_TABLE_NAME];

        return $schema === (string)$row[self::COL_REFERENCED_TABLE_SCHEMA] ? $table : "{$schema}.{$table}";
    }
}
