<?php

namespace Hilos\Database\Exception\SqlConnection;

use Hilos\Database\Exception\DatabaseConnectionException;

/**
 * Exception: Connection timeout.
 * MySQL Error: 2013
 */
class TimeoutException extends DatabaseConnectionException
{
    public const int MYSQL_ERROR_CODE = 2013;
}
