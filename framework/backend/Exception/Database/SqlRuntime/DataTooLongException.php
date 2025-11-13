<?php

namespace Hilos\Exception\Database\SqlRuntime;

use Hilos\Exception\Database\DatabaseRuntimeException;

/**
 * Exception: Data too long for column
 * MySQL Error: 1406
 */
class DataTooLongException extends DatabaseRuntimeException
{
}

