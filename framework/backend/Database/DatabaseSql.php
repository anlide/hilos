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
     * The three transaction statements never reach mysqli as text — the driver has its own
     * calls for them — so they exist to name the failing operation in an exception.
     */
    public const string START_TRANSACTION = 'START TRANSACTION';

    public const string COMMIT = 'COMMIT';

    public const string ROLLBACK = 'ROLLBACK';

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
}
