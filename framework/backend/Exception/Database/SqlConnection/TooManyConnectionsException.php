<?php

namespace Hilos\Exception\Database\SqlConnection;

use Hilos\Exception\Database\DatabaseConnectionException;

/**
 * Exception: Too many connections
 * MySQL Error: 1040
 */
class TooManyConnectionsException extends DatabaseConnectionException
{
}

