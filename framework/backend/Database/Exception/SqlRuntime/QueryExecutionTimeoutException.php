<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: Query execution was interrupted, max_statement_time exceeded.
 * MySQL Error: 3024
 */
class QueryExecutionTimeoutException extends DatabaseRuntimeException
{
}

