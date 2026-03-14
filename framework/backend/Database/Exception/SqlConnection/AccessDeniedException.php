<?php

namespace Hilos\Database\Exception\SqlConnection;

use Hilos\Database\Exception\DatabaseConnectionException;

/**
 * Exception: Access denied for user.
 * MySQL Error: 1045
 */
class AccessDeniedException extends DatabaseConnectionException
{
}

