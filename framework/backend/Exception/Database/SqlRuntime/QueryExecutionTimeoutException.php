<?php

namespace Hilos\Exception\Database\SqlRuntime;

use Hilos\Exception\Database\DatabaseRuntimeException;

/**
 * Exception: Query execution was interrupted, max_statement_time exceeded
 * MySQL Error: 3024
 */
class QueryExecutionTimeoutException extends DatabaseRuntimeException
{
}

