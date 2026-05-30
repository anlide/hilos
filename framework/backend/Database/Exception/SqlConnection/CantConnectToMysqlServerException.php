<?php

namespace Hilos\Database\Exception\SqlConnection;

use Hilos\Database\Exception\DatabaseConnectionException;

/**
 * Exception: Can't connect to MySQL server.
 * MySQL Error: 2002, 2003
 */
class CantConnectToMysqlServerException extends DatabaseConnectionException
{
    /** @var list<int> */
    public const array MYSQL_ERROR_CODES = [2002, 2003];
}
