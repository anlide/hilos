<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\SqlConnection\AccessDeniedException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\Exception\SqlConnection\HostNotFoundException;
use Hilos\Database\Exception\SqlConnection\ProtocolMismatchException;
use Hilos\Database\Exception\SqlConnection\SslConnectionExceptionErrorException;
use Hilos\Database\Exception\SqlConnection\TimeoutException;
use Hilos\Database\Exception\SqlConnection\TooManyConnectionsException;
use Hilos\Database\Exception\SqlConnection\UnknownDatabaseException;
use Hilos\Database\Exception\SqlRuntime\DataTooLongException;
use Hilos\Database\Exception\SqlRuntime\DeadlockDetectedException;
use Hilos\Database\Exception\SqlRuntime\DivisionByZeroException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\Database\Exception\SqlRuntime\GoneAwayException;
use Hilos\Database\Exception\SqlRuntime\LockWaitTimeoutException;
use Hilos\Database\Exception\SqlRuntime\LostConnectionException;
use Hilos\Database\Exception\SqlRuntime\OutOfRangeValueException;
use Hilos\Database\Exception\SqlRuntime\QueryExecutionTimeoutException;
use Hilos\Database\Exception\SqlRuntime\SyntaxErrorException;
use Hilos\Database\Exception\SqlRuntime\TableNotFoundException;

/**
 * Maps MySQL error codes to typed database exceptions.
 */
final class MysqlExceptionMapper
{
    /**
     * @param int $errno MySQL error number
     * @param string $error Error message
     * @throws AccessDeniedException When credentials are rejected
     * @throws CantConnectToMysqlServerException When the server is unreachable
     * @throws HostNotFoundException When the server host is unknown
     * @throws ProtocolMismatchException When client and server protocol versions mismatch
     * @throws SslConnectionExceptionErrorException When SSL handshake fails
     * @throws TimeoutException When the connection times out
     * @throws TooManyConnectionsException When the server rejects new connections
     * @throws UnknownDatabaseException When the database name is unknown
     * @throws DatabaseConnectionException When MySQL reports another connection error
     */
    public static function connectionException(int $errno, string $error): never
    {
        $exception = match ($errno) {
            AccessDeniedException::MYSQL_ERROR_CODE => new AccessDeniedException($error, $errno),
            CantConnectToMysqlServerException::MYSQL_ERROR_CODES[0],
            CantConnectToMysqlServerException::MYSQL_ERROR_CODES[1] => new CantConnectToMysqlServerException($error, $errno),
            HostNotFoundException::MYSQL_ERROR_CODE => new HostNotFoundException($error, $errno),
            ProtocolMismatchException::MYSQL_ERROR_CODE => new ProtocolMismatchException($error, $errno),
            SslConnectionExceptionErrorException::MYSQL_ERROR_CODE => new SslConnectionExceptionErrorException($error, $errno),
            TimeoutException::MYSQL_ERROR_CODE => new TimeoutException($error, $errno),
            TooManyConnectionsException::MYSQL_ERROR_CODE => new TooManyConnectionsException($error, $errno),
            UnknownDatabaseException::MYSQL_ERROR_CODE => new UnknownDatabaseException($error, $errno),
            default => new DatabaseConnectionException($error, $errno),
        };

        $exception->setMysqlError($errno, $error);
        throw $exception;
    }

    /**
     * @param int $errno MySQL error number
     * @param string $error Error message
     * @param string $query SQL query that failed
     * @throws DataTooLongException When a value exceeds the column length
     * @throws DeadlockDetectedException When InnoDB detects a deadlock
     * @throws DivisionByZeroException When a query divides by zero
     * @throws DuplicateEntryException When a unique constraint is violated
     * @throws ForeignKeyConstraintException When a foreign key constraint fails
     * @throws GoneAwayException When the server closes the connection
     * @throws LockWaitTimeoutException When lock wait time is exceeded
     * @throws LostConnectionException When the connection is lost during query execution
     * @throws OutOfRangeValueException When a value is out of column range
     * @throws QueryExecutionTimeoutException When max_statement_time is exceeded
     * @throws SyntaxErrorException When SQL syntax is invalid
     * @throws TableNotFoundException When a referenced table does not exist
     * @throws DatabaseRuntimeException When MySQL reports another runtime query error
     */
    public static function runtimeException(int $errno, string $error, string $query): never
    {
        $queryPreview = strlen($query) > DatabaseException::QUERY_PREVIEW_MAX_LENGTH
            ? substr($query, 0, DatabaseException::QUERY_PREVIEW_MAX_LENGTH) . '...'
            : $query;
        $detailedMessage = "MySQL Error [{$errno}]: {$error}";
        if ($query !== '') {
            $detailedMessage .= "\nQuery: {$queryPreview}";
        }

        $exception = match ($errno) {
            DataTooLongException::MYSQL_ERROR_CODE => new DataTooLongException($detailedMessage, $errno),
            DeadlockDetectedException::MYSQL_ERROR_CODE => new DeadlockDetectedException($detailedMessage, $errno),
            DivisionByZeroException::MYSQL_ERROR_CODE => new DivisionByZeroException($detailedMessage, $errno),
            DuplicateEntryException::MYSQL_ERROR_CODE => new DuplicateEntryException($detailedMessage, $errno),
            ForeignKeyConstraintException::MYSQL_ERROR_CODES[0],
            ForeignKeyConstraintException::MYSQL_ERROR_CODES[1] => new ForeignKeyConstraintException($detailedMessage, $errno),
            LockWaitTimeoutException::MYSQL_ERROR_CODE => new LockWaitTimeoutException($detailedMessage, $errno),
            OutOfRangeValueException::MYSQL_ERROR_CODE => new OutOfRangeValueException($detailedMessage, $errno),
            SyntaxErrorException::MYSQL_ERROR_CODE => new SyntaxErrorException($detailedMessage, $errno),
            TableNotFoundException::MYSQL_ERROR_CODE => new TableNotFoundException($detailedMessage, $errno),
            QueryExecutionTimeoutException::MYSQL_ERROR_CODE => new QueryExecutionTimeoutException($detailedMessage, $errno),
            LostConnectionException::MYSQL_ERROR_CODE => new LostConnectionException($detailedMessage, $errno),
            GoneAwayException::MYSQL_ERROR_CODE => new GoneAwayException($detailedMessage, $errno),
            default => new DatabaseRuntimeException($detailedMessage, $errno),
        };

        $exception->setMysqlError($errno, $error);
        $exception->setQuery($query);
        throw $exception;
    }
}
