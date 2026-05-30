<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: Cannot add or update a child row: a foreign key constraint fails.
 * MySQL Error: 1451, 1452
 */
class ForeignKeyConstraintException extends DatabaseRuntimeException
{
    /** @var list<int> */
    public const array MYSQL_ERROR_CODES = [1451, 1452];
}
