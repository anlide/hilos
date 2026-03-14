<?php

namespace Hilos\Database\Exception\SqlConnection;

use Hilos\Database\Exception\DatabaseConnectionException;

/**
 * Exception: Too many connections.
 * MySQL Error: 1040
 */
class TooManyConnectionsException extends DatabaseConnectionException
{
}

