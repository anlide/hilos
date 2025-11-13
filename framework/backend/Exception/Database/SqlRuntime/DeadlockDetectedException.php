<?php

namespace Hilos\Exception\Database\SqlRuntime;

use Hilos\Exception\Database\DatabaseRuntimeException;

/**
 * Exception: Deadlock found when trying to get lock
 * MySQL Error: 1213
 */
class DeadlockDetectedException extends DatabaseRuntimeException
{
}

