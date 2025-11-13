<?php

namespace Hilos\Exception\Database\SqlRuntime;

use Hilos\Exception\Database\DatabaseRuntimeException;

/**
 * Exception: Lock wait timeout exceeded
 * MySQL Error: 1205
 */
class LockWaitTimeoutException extends DatabaseRuntimeException
{
}

