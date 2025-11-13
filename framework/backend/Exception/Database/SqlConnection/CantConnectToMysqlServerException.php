<?php

namespace Hilos\Exception\Database\SqlConnection;

use Hilos\Exception\Database\DatabaseConnectionException;

/**
 * Exception: Can't connect to MySQL server
 * MySQL Error: 2002, 2003
 */
class CantConnectToMysqlServerException extends DatabaseConnectionException
{
}

