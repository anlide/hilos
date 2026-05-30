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

    public const string SESSION_MAX_STATEMENT_TIME_GET = 'SELECT @@max_statement_time';

    public const string SESSION_MAX_STATEMENT_TIME_SET = 'SET SESSION max_statement_time = %d';
}
