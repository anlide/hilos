<?php

namespace Hilos\Database\Exception\SqlConnection;

use Hilos\Database\Exception\DatabaseConnectionException;

/**
 * Exception: Unknown MySQL server host.
 * MySQL Error: 2005
 */
class HostNotFoundException extends DatabaseConnectionException
{
}

