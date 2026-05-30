<?php

namespace Hilos\Database\Exception\SqlRuntime;

use Hilos\Database\Exception\DatabaseRuntimeException;

/**
 * Exception: You have an error in your SQL syntax.
 * MySQL Error: 1064
 */
class SyntaxErrorException extends DatabaseRuntimeException
{
    public const int MYSQL_ERROR_CODE = 1064;
}
