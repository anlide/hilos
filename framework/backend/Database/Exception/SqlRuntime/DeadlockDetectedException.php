<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: Deadlock found when trying to get lock
 * MySQL Error: 1213
 */
class DeadlockDetectedException extends DatabaseRuntimeException
{
}

