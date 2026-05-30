<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: Lost connection to MySQL server during query.
 * MySQL Error: 2013
 */
class LostConnectionException extends DatabaseRuntimeException
{
    public const int MYSQL_ERROR_CODE = 2013;
}
