<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: Data too long for column.
 * MySQL Error: 1406
 */
class DataTooLongException extends DatabaseRuntimeException
{
}
