<?php

namespace Hilos\Database\Exception\SqlConnection;

use Hilos\Database\Exception\DatabaseConnectionException;

/**
 * Exception: SSL connection error.
 * MySQL Error: 2026
 */
class SslConnectionExceptionErrorException extends DatabaseConnectionException
{
    public const int MYSQL_ERROR_CODE = 2026;
}
