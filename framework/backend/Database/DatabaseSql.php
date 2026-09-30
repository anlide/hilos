<?php

declare(strict_types=1);

namespace Hilos\Database;

/**
 * SQL statement fragments used by the MySQL access layer.
 */
final class DatabaseSql
{
    public const string PING = 'SELECT 1';

    public const string UNLOCK_TABLES = 'UNLOCK TABLES';

    public const string LOCK_TABLES_PREFIX = 'LOCK TABLES';

    public const string LOCK_TYPE_READ = 'READ';

    public const string LOCK_TYPE_WRITE = 'WRITE';

    public const string SQL_NULL = 'NULL';

    /**
     * The transaction statements never reach mysqli as text — the driver has its own calls for
     * them — so they exist to name the failing operation in an exception. ROLLBACK TO SAVEPOINT
     * is the one that does go as text: mysqli has no call for it, and the name mysqli_rollback()
     * accepts is only a comment on its ROLLBACK, so {@see rollbackToSavepoint()} spells it out.
     */
    public const string START_TRANSACTION = 'START TRANSACTION';

    public const string COMMIT = 'COMMIT';

    public const string ROLLBACK = 'ROLLBACK';

    public const string SAVEPOINT = 'SAVEPOINT';

    public const string RELEASE_SAVEPOINT = 'RELEASE SAVEPOINT';

    public const string ROLLBACK_TO_SAVEPOINT = 'ROLLBACK TO SAVEPOINT';

    public const string SESSION_MAX_STATEMENT_TIME_GET = 'SELECT @@max_statement_time';

    public const string SESSION_MAX_STATEMENT_TIME_SET = 'SET SESSION max_statement_time = %d';

    /**
     * @param string $table Table name (unescaped, used for probe only)
     * @return string SQL that succeeds when table exists and fails otherwise
     */
    public static function tableExistsProbe(string $table): string
    {
        return 'SELECT 1 FROM `' . $table . '` LIMIT 1';
    }

    /**
     * @param string $savepoint Savepoint name, one the framework minted itself
     * @return string SQL that rolls the transaction back to that savepoint and keeps the savepoint
     */
    public static function rollbackToSavepoint(string $savepoint): string
    {
        return self::ROLLBACK_TO_SAVEPOINT . ' ' . $savepoint;
    }
}
