<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: Duplicate entry for key.
 * MySQL Error: 1062
 */
class DuplicateEntryException extends DatabaseRuntimeException
{
    public const int MYSQL_ERROR_CODE = 1062;
}
