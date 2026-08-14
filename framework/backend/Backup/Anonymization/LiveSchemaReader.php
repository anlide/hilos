<?php

declare(strict_types=1);

namespace Hilos\Backup\Anonymization;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\EntitySchemaAudit;

/**
 * LiveSchemaReader - reads one connection's schema out of the live database.
 *
 * Two queries per connection, both over the whole database rather than table by table:
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
    /** `information_schema.COLUMNS` / `STATISTICS` result keys. */
    private const string COL_TABLE_NAME = 'TABLE_NAME';
    private const string COL_NAME = 'COLUMN_NAME';
    private const string COL_DATA_TYPE = 'DATA_TYPE';
    private const string COL_IS_NULLABLE = 'IS_NULLABLE';
    private const string COL_MAXIMUM_LENGTH = 'CHARACTER_MAXIMUM_LENGTH';
    private const string COL_INDEX_NAME = 'INDEX_NAME';
    private const string COL_SEQ_IN_INDEX = 'SEQ_IN_INDEX';
    private const string COL_NON_UNIQUE = 'NON_UNIQUE';

    /** `IS_NULLABLE` value of a column that accepts NULL. */
    private const string NULLABLE_YES = 'YES';

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
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . ' ORDER BY ' . self::COL_TABLE_NAME . ', ORDINAL_POSITION',
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
            . ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
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
}
