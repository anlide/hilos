<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\ChangeLogChange;
use Hilos\Database\Entity\Item\ChangeLogEntry;
use Hilos\Database\Entity\Item\ChangeLogField;
use Hilos\Database\Entity\Item\ChangeLogReceipt;
use Hilos\Database\Entity\Item\ChangeLogTable;
use Hilos\Database\Entity\Item\ChangeLogValue;

/**
 * Names the journal schema on the same server as the primary database.
 */
final class ChangeLogDatabase
{
    public const int CONNECTION_INDEX = 1;

    private const string SUFFIX = '-change-log';

    private const int MAX_IDENTIFIER_LENGTH = 64;

    private const array JOURNAL_TABLES = [
        ChangeLogTable::_table, ChangeLogField::_table, ChangeLogReceipt::_table,
        ChangeLogEntry::_table, ChangeLogChange::_table, ChangeLogValue::_table,
    ];

    /**
     * @return int Approximate data and index bytes of the six physical journal tables
     * @throws DatabaseException When the configured journal or its size metadata cannot be read
     */
    public static function approximateBytes(): int
    {
        $placeholders = implode(', ', array_fill(0, count(self::JOURNAL_TABLES), '?'));
        return (int)Database::sql(
            'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS journal_bytes'
            . ' FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (' . $placeholders . ')',
            array_merge([self::configuredName()], self::JOURNAL_TABLES),
            index: self::CONNECTION_INDEX,
        )->field('journal_bytes');
    }

    /**
     * @param string $primaryName Primary database name
     * @return string Derived journal database name
     * @throws DatabaseException When the derived name cannot be used as a database identifier
     */
    public static function name(string $primaryName): string
    {
        $name = $primaryName . self::SUFFIX;
        if (strlen($name) > self::MAX_IDENTIFIER_LENGTH || preg_match('/\A[A-Za-z0-9_%-]+\z/D', $name) !== 1) {
            throw new DatabaseException("Invalid change log database name derived from '{$primaryName}'");
        }

        return $name;
    }

    /**
     * @return string Derived journal database name from configured primary connection
     * @throws DatabaseException When the primary connection or name is invalid
     */
    public static function configuredName(): string
    {
        return self::name(Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX)->database);
    }

    /**
     * Identifies the one secondary connection that follows the primary migration track.
     *
     * @param int $index Configured connection index
     * @param string $database Configured database name
     * @return bool Whether this is the derived journal database
     * @throws DatabaseException When the primary connection is not configured
     */
    public static function isJournalConnection(int $index, string $database): bool
    {
        return $index === self::CONNECTION_INDEX
            && $database === Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX)->database . self::SUFFIX;
    }

    /**
     * @param string $name Validated database name
     * @return string Quoted SQL identifier
     * @throws DatabaseException When the name is invalid
     */
    public static function identifier(string $name): string
    {
        if (strlen($name) > self::MAX_IDENTIFIER_LENGTH || preg_match('/\A[A-Za-z0-9_%-]+\z/D', $name) !== 1) {
            throw new DatabaseException("Invalid change log database name '{$name}'");
        }

        return '`' . $name . '`';
    }
}
