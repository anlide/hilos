<?php

namespace Hilos\Exception\Database\SqlRuntime;

use Hilos\Exception\Database\DatabaseRuntimeException;

/**
 * Exception: Cannot add or update a child row: a foreign key constraint fails
 * MySQL Error: 1451, 1452
 */
class ForeignKeyConstraintException extends DatabaseRuntimeException
{
}

