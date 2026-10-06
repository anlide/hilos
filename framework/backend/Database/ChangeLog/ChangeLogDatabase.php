<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog;

use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;

/**
 * Names the journal schema on the same server as the primary database.
 */
final class ChangeLogDatabase
{
    public const int CONNECTION_INDEX = 1;

    private const string SUFFIX = '-change-log';

    private const int MAX_IDENTIFIER_LENGTH = 64;

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
