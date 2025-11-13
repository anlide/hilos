<?php

namespace Hilos\Exception\Database\SqlConnection;

use Hilos\Exception\Database\DatabaseConnectionException;

/**
 * Exception: Access denied for user
 * MySQL Error: 1045
 */
class AccessDeniedException extends DatabaseConnectionException
{
}

