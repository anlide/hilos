<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: Table doesn't exist.
 * MySQL Error: 1146
 */
class TableNotFoundException extends DatabaseRuntimeException
{
    public const int MYSQL_ERROR_CODE = 1146;
}
