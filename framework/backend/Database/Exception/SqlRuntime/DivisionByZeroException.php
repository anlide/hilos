<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: Division by zero.
 * MySQL Error: 1365
 */
class DivisionByZeroException extends DatabaseRuntimeException
{
    public const int MYSQL_ERROR_CODE = 1365;
}
