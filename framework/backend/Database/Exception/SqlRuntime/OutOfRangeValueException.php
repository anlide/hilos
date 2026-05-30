<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: Out of range value for column.
 * MySQL Error: 1264
 */
class OutOfRangeValueException extends DatabaseRuntimeException
{
    public const int MYSQL_ERROR_CODE = 1264;
}
