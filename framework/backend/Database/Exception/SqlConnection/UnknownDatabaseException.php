<?php

namespace Hilos\Database\Exception\SqlConnection;

use Hilos\Database\Exception\DatabaseConnectionException;

/**
 * Exception: Unknown database.
 * MySQL Error: 1049
 */
class UnknownDatabaseException extends DatabaseConnectionException
{
    public const int MYSQL_ERROR_CODE = 1049;
}
