<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: Lock wait timeout exceeded
 * MySQL Error: 1205
 */
class LockWaitTimeoutException extends DatabaseRuntimeException
{
}

