<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: Table doesn't exist.
 * MySQL Error: 1146
 */
class TableNotFoundException extends DatabaseRuntimeException
{
}

