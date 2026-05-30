<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: MySQL server has gone away.
 * MySQL Error: 2006
 */
class GoneAwayException extends DatabaseRuntimeException
{
    public const int MYSQL_ERROR_CODE = 2006;
}
