<?php

namespace Hilos\Exception\Database\SqlRuntime;

use Hilos\Exception\Database\DatabaseRuntimeException;

/**
 * Exception: Table doesn't exist
 * MySQL Error: 1146
 */
class TableNotFoundException extends DatabaseRuntimeException
{
}

