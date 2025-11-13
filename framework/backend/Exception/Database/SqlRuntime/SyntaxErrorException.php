<?php

namespace Hilos\Exception\Database\SqlRuntime;

use Hilos\Exception\Database\DatabaseRuntimeException;

/**
 * Exception: You have an error in your SQL syntax
 * MySQL Error: 1064
 */
class SyntaxErrorException extends DatabaseRuntimeException
{
}

