<?php

namespace Hilos\Database;

use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseParamsException;
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
use Hilos\Database\ResultSet\ResultSet;
use Hilos\Database\ResultSet\ResultSetCollection;
use mysqli;
use mysqli_result;

/**
 * Database connection and query management class.
 *
 * Supports multiple database connections with static methods.
 */
class Database
{
    /** Minimum delay between reconnection attempts (milliseconds) */
    private const int RECONNECT_DELAY_MIN_MS = 1000;

    /** Maximum total time for reconnection attempts (milliseconds) */
    private const int RECONNECT_TIMEOUT_MAX_MS = 5000;

    /** @var array<int, array{host: string, user: string, password: string, database: string, port: int, charset: string, socket: ?string, reconnect_attempts: int, reconnect_delay: int}> */
    private static array $configurations = [];

    /** @var array<int, ?mysqli> */
    private static array $connections = [];

    /** @var int Current active connection index */
    private static int $currentIndex = 0;

    /** @var array<int, ?ResultSet> Cached ResultSet instances for each connection */
    private static array $resultSets = [];

    /**
     * Get current result set (for ResultSetCollection).
     *
     * Returns mysqli_result from cached ResultSet.
     *
     * @param ?int $index Connection index
     * @return ?mysqli_result mysqli_result from cached ResultSet or null
     */
    public static function getCurrentResult(?int $index = null): ?mysqli_result
    {
        $index = $index ?? self::$currentIndex;
        return self::$resultSets[$index]?->getMysqliResult();
    }

    /**
     * Get cached ResultSet for current result (preserves pointer position).
     *
     * @param ?int $index Connection index
     * @return ?ResultSet Cached ResultSet or null
     */
    public static function getCachedResultSet(?int $index = null): ?ResultSet
    {
        $index = $index ?? self::$currentIndex;
        return self::$resultSets[$index] ?? null;
    }

    /**
     * Initialize database connections and schema.
     *
     * This method should be overridden in child classes to:
     * 1. Configure database connections using self::configure()
     * 2. Connect to databases using self::connect()
     * 3. Initialize database schema structure using Schema::initialize()
     *
     * Example implementation:
     * ```php
     * public static function initialize(): void
     * {
     *     self::configure(0, 'localhost', 'user', 'pass', 'db');
     *     self::connect(0);
     *     Schema::initialize(0);
     * }
     * ```
     */
    public static function initialize(): void
    {
        // Empty implementation - should be overridden in child classes
    }

    /**
     * Configure a database connection (doesn't connect yet).
     *
     * @param int $index Connection index (0 by default for primary)
     * @param string $host Database host
     * @param string $user Database user
     * @param string $password Database password
     * @param string $database Database name
     * @param int $port Database port
     * @param string $charset Character set with collation
     * @param ?string $socket Unix socket path
     * @param int $reconnectAttempts Number of reconnect attempts
     * @param int $reconnectDelay Delay between reconnects in milliseconds
     */
    public static function configure(
        int $index = 0,
        string $host = 'localhost',
        string $user = 'root',
        string $password = '',
        string $database = '',
        int $port = 3306,
        string $charset = 'utf8mb4',
        ?string $socket = null,
        int $reconnectAttempts = 3,
        int $reconnectDelay = 100
    ): void {
        self::$configurations[$index] = [
            'host' => $host,
            'user' => $user,
            'password' => $password,
            'database' => $database,
            'port' => $port,
            'charset' => $charset,
            'socket' => $socket,
            'reconnect_attempts' => $reconnectAttempts,
            'reconnect_delay' => $reconnectDelay,
        ];
        self::$connections[$index] = null;
        self::$resultSets[$index] = null;
    }

    /**
     * Set current connection index.
     *
     * @param int $index Connection index
     * @throws DatabaseException If connection not configured
     */
    public static function useConnection(int $index): void
    {
        if (!isset(self::$configurations[$index])) {
            throw new DatabaseException("Connection {$index} is not configured");
        }
        self::$currentIndex = $index;
    }

    /**
     * Get current connection index.
     *
     * @return int Active connection index (0 by default)
     */
    public static function getCurrentIndex(): int
    {
        return self::$currentIndex;
    }

    /**
     * Connect to database using configured settings.
     *
     * @param ?int $index Connection index (uses current if null)
     * @param bool $retryOnConnectionError If true, retry connection on temporary errors (2002, 2003)
     * @param ?int $maxRetries Maximum retry attempts (uses reconnect_attempts from config if null)
     * @param ?int $retryDelaySeconds Delay between retries in seconds (uses reconnect_delay/1000 from config if null)
     * @throws DatabaseConnectionException On connection failure
     */
    public static function connect(?int $index = null, bool $retryOnConnectionError = false, ?int $maxRetries = null, ?int $retryDelaySeconds = null): void
    {
        $index = $index ?? self::$currentIndex;

        $config = self::$configurations[$index]
            ?? throw new DatabaseConnectionException("Connection {$index} is not configured");
        
        // Determine retry parameters
        $retries = $maxRetries ?? ($retryOnConnectionError ? 30 : 1);
        $delaySeconds = $retryDelaySeconds ?? ($retryOnConnectionError ? 2 : 0);
        
        $lastException = null;
        
        for ($attempt = 0; $attempt < $retries; $attempt++) {
            if ($attempt > 0) {
                sleep($delaySeconds);
            }
            
            try {
                mysqli_report(MYSQLI_REPORT_OFF);

                $mysqli = @mysqli_connect(
                    $config['host'],
                    $config['user'],
                    $config['password'],
                    $config['database'],
                    $config['port'],
                    $config['socket'],
                );

                if ($mysqli === false) {
                    $errno = mysqli_connect_errno();
                    $error = mysqli_connect_error();
                    
                    // Retry only for temporary connection errors (2002, 2003) if retry is enabled
                    if ($retryOnConnectionError && in_array($errno, [2002, 2003]) && $attempt < $retries - 1) {
                        $lastException = new CantConnectToMysqlServerException($error, $errno);
                        continue; // Retry
                    }
                    
                    self::throwConnectionException($errno, $error);
                }

                // Set charset
                if (!@mysqli_set_charset($mysqli, $config['charset'])) {
                    $errno = mysqli_errno($mysqli);
                    $error = mysqli_error($mysqli);
                    mysqli_close($mysqli);
                    
                    // Charset errors are not retryable
                    self::throwConnectionException($errno, $error);
                }

                self::$connections[$index] = $mysqli;
                return; // Success
                
            } catch (CantConnectToMysqlServerException $e) {
                // Retry only if enabled and not last attempt
                if ($retryOnConnectionError && $attempt < $retries - 1) {
                    $lastException = $e;
                    continue;
                }
                throw $e;
            }
        }
        
        // All retries exhausted
        if ($lastException !== null) {
            throw $lastException;
        }
    }

    /**
     * Closes database connection.
     *
     * @param ?int $index Connection index (uses current if null)
     */
    public static function close(?int $index = null): void
    {
        $index = $index ?? self::$currentIndex;

        if (isset(self::$connections[$index]) && self::$connections[$index] !== null) {
            // Free current result set if exists (get mysqli_result from ResultSet)
            $resultSet = self::$resultSets[$index] ?? null;
            if ($resultSet !== null) {
                $mysqliResult = $resultSet->getMysqliResult();
                if ($mysqliResult !== null && $mysqliResult instanceof mysqli_result) {
                    @mysqli_free_result($mysqliResult);
                }
            }
            @mysqli_close(self::$connections[$index]);
            self::$connections[$index] = null;
            self::$resultSets[$index] = null;
        }
    }

    /**
     * Gets active mysqli connection.
     *
     * @param ?int $index Connection index
     * @throws DatabaseConnectionException If not connected
     */
    private static function getConnection(?int $index = null): mysqli
    {
        $index = $index ?? self::$currentIndex;

        if (!isset(self::$connections[$index]) || self::$connections[$index] === null) {
            throw new DatabaseConnectionException("Not connected to database at index {$index}");
        }

        return self::$connections[$index];
    }

    /**
     * Check if connection is active.
     *
     * @param ?int $index Connection index (uses current if null)
     * @return bool True if connected
     */
    public static function isConnected(?int $index = null): bool
    {
        $index = $index ?? self::$currentIndex;
        return isset(self::$connections[$index]) && self::$connections[$index] !== null;
    }

    /**
     * Executes SQL query with parameters.
     *
     * @param string $sql SQL query with ? placeholders
     * @param array|SqlParamCollection|null $params Query parameters
     * @param bool $tryReconnect Try to reconnect on connection loss
     * @return ResultSetCollection Collection of result sets (even for single result set)
     * @throws DatabaseException On query failure
     */
    public static function sql(string $sql, array|SqlParamCollection|null $params = null, bool $tryReconnect = true): ResultSetCollection
    {
        $index = self::$currentIndex;
        $mysqli = self::getConnection($index);
        $config = self::$configurations[$index];

        // Clear cached ResultSet before new query (new query = new result set)
        self::$resultSets[$index] = null;

        // Convert array to SqlParamCollection
        if (is_array($params)) {
            $params = SqlParamCollection::fromArray($params);
        }

        // Parse SQL with parameters
        $parsedSql = self::parseSqlWithParams($sql, $params, $mysqli);

        // Process all remaining result sets from multi-query (if any)
        while (@mysqli_next_result($mysqli)) {
            @mysqli_store_result($mysqli);
        }

        $attempts = 0;
        $maxAttempts = $tryReconnect ? $config['reconnect_attempts'] : 1;

        while ($attempts < $maxAttempts) {
            $attempts++;

            // Check if connection is still valid before using it
            if (!isset(self::$connections[$index]) || self::$connections[$index] === null || self::$connections[$index] !== $mysqli) {
                // Connection was closed or changed - reconnect if allowed
                if ($tryReconnect && $attempts < $maxAttempts) {
                    try {
                        self::connect($index);
                        $mysqli = self::getConnection($index);
                        // Re-parse SQL with new connection
                        $parsedSql = self::parseSqlWithParams($sql, $params, $mysqli);
                    } catch (DatabaseConnectionException $e) {
                        throw new DatabaseConnectionException(
                            "Database connection was closed. Attempted to reconnect (attempt {$attempts}/{$maxAttempts}) but failed: " . $e->getMessage() . 
                            ". Original query: " . substr($sql, 0, 200)
                        );
                    }
                } else {
                    throw new DatabaseConnectionException(
                        "Database connection is closed at index {$index}. " .
                        "Connection state: " . (isset(self::$connections[$index]) ? "exists but is null" : "does not exist") . ". " .
                        "Query: " . substr($sql, 0, 200)
                    );
                }
            }

            // Execute multi-query
            $result = @mysqli_multi_query($mysqli, $parsedSql);

            if ($result === false) {
                $errno = mysqli_errno($mysqli);
                $error = mysqli_error($mysqli);
                
                // If mysqli_errno returns 0, connection is likely closed
                if ($errno === 0 && $error === '') {
                    $error = 'mysqli object is already closed';
                    $errno = 2006; // Treat as "MySQL server has gone away" for reconnection logic
                }

                // Check if we should try to reconnect
                if ($tryReconnect && self::isConnectionLostError($errno) && $attempts < $maxAttempts) {
                    // Use minimum delay (at least 1 second) to prevent rapid retry attempts
                    $delayMs = max($config['reconnect_delay'], self::RECONNECT_DELAY_MIN_MS);
                    // Ensure total retry time doesn't exceed maximum
                    $totalTimeMs = $attempts * $delayMs;
                    if ($totalTimeMs >= self::RECONNECT_TIMEOUT_MAX_MS) {
                        // Timeout exceeded, throw error
                        self::throwRuntimeException($errno, $error, $sql);
                    }

                    // Sleep using sleep() for seconds (minimum 1 second)
                    $delaySeconds = (int)ceil($delayMs / 1000);
                    sleep($delaySeconds);

                    self::close($index);
                    try {
                        self::connect($index);
                        continue; // Retry query
                    } catch (DatabaseConnectionException $e) {
                        // If reconnection fails, throw the original error
                        self::throwRuntimeException($errno, $error, $sql);
                    }
                }

                self::throwRuntimeException($errno, $error, $sql);
            }

            break; // Success
        }

        // Store first result set immediately after multi_query
        // (or null if no result set, e.g. for INSERT/UPDATE/DELETE)
        $result = @mysqli_store_result($mysqli);
        $mysqliResult = $result !== false ? $result : null;

        // Create/cache ResultSet for this result (reuse same instance to preserve pointer position)
        if ($mysqliResult !== null) {
            // Create new ResultSet only if not cached or if result changed
            // Check if result changed by comparing object identity
            $currentResultSet = self::$resultSets[$index] ?? null;
            if ($currentResultSet === null || $currentResultSet->getMysqliResult() !== $mysqliResult) {
                // Reset pointer for new result set (first time)
                self::$resultSets[$index] = ResultSet::fromMysqliResult($mysqliResult, true);
            }
            // Otherwise reuse existing ResultSet (preserve pointer position)
        } else {
            self::$resultSets[$index] = null;
        }

        // Create ResultSetCollection with only first result set
        // Remaining result sets will be collected on-demand via nextResult()
        $collection = ResultSetCollection::empty();

        // Add cached ResultSet (reuse same instance to preserve pointer position)
        if (self::$resultSets[$index] !== null) {
            $collection->add(self::$resultSets[$index]);
        }

        return $collection;
    }

    /**
     * Execute SQL with timeout.
     *
     * @param string $sql SQL query
     * @param array|SqlParamCollection|null $params Query parameters
     * @param int $timeout Timeout in seconds
     * @param bool $tryReconnect Try to reconnect on connection loss
     * @throws DatabaseException On query failure or timeout
     */
    public static function sqlRun(string $sql, array|SqlParamCollection|null $params = null, int $timeout = 30, bool $tryReconnect = true): void
    {
        $index = self::$currentIndex;
        $mysqli = self::getConnection($index);

        // Set max execution time
        $oldTimeout = @mysqli_query($mysqli, "SELECT @@max_statement_time");
        @mysqli_query($mysqli, "SET SESSION max_statement_time = " . ($timeout * 1000));

        try {
            self::sql($sql, $params, $tryReconnect);
        } finally {
            // Restore old timeout
            if ($oldTimeout !== false) {
                $row = mysqli_fetch_row($oldTimeout);
                if ($row !== null) {
                    @mysqli_query($mysqli, "SET SESSION max_statement_time = " . $row[0]);
                }
            }
        }
    }

    /**
     * Get next result set from multi-query.
     *
     * @return bool True if there is a next result set
     * @throws DatabaseConnectionException If not connected
     */
    public static function nextResult(): bool
    {
        $index = self::$currentIndex;
        $mysqli = self::getConnection($index);

        // Move to next result set
        $hasMore = @mysqli_next_result($mysqli);

        if ($hasMore) {
            // Store next result set (or null if no result set)
            $result = @mysqli_store_result($mysqli);
            $mysqliResult = $result !== false ? $result : null;
            
            // Create/cache new ResultSet for next result
            if ($mysqliResult !== null) {
                self::$resultSets[$index] = ResultSet::fromMysqliResult($mysqliResult);
            } else {
                self::$resultSets[$index] = null;
            }
            
            return true;
        }

        // No more result sets
        self::$resultSets[$index] = null;
        return false;
    }

    /**
     * Returns all rows from current result set.
     *
     * Note: loads all rows into memory. For large datasets, use row() in a loop.
     *
     * @return list<array<string, mixed>> Row arrays in order
     */
    public static function rows(): array
    {
        $resultSetCollection = ResultSetCollection::fromDatabase();
        $firstResultSet = $resultSetCollection->first();

        if ($firstResultSet === null) {
            return [];
        }

        return $firstResultSet->rows();
    }

    /**
     * Returns single row from current result set.
     *
     * Advances internal pointer; each call returns the next row.
     *
     * @return ?array<string, mixed> Row as associative array or null if no more rows
     */
    public static function row(): ?array
    {
        $resultSetCollection = ResultSetCollection::fromDatabase();
        $firstResultSet = $resultSetCollection->first();

        if ($firstResultSet === null) {
            return null;
        }

        return $firstResultSet->row();
    }

    /**
     * Get single field value from current result set.
     *
     * @param string $fieldName Field name
     * @return mixed Field value or null
     */
    public static function field(string $fieldName): mixed
    {
        $row = self::row();
        return $row[$fieldName] ?? null;
    }

    /**
     * Get number of rows in current result set.
     *
     * @return int Row count
     */
    public static function count(): int
    {
        $resultSetCollection = ResultSetCollection::fromDatabase();
        $firstResultSet = $resultSetCollection->first();

        if ($firstResultSet === null) {
            return 0;
        }

        return $firstResultSet->count();
    }

    /**
     * Get number of affected rows from last query.
     *
     * @return int Number of affected rows
     * @throws DatabaseConnectionException If not connected
     */
    public static function affectedRows(): int
    {
        $mysqli = self::getConnection();
        return mysqli_affected_rows($mysqli);
    }

    /**
     * Get last insert ID.
     *
     * @return int Last insert ID or 0
     * @throws DatabaseConnectionException If not connected
     */
    public static function lastInsertId(): int
    {
        $mysqli = self::getConnection();
        return mysqli_insert_id($mysqli);
    }

    /**
     * Start transaction.
     *
     * @throws DatabaseConnectionException If not connected
     */
    public static function transactionStart(): void
    {
        $mysqli = self::getConnection();
        @mysqli_begin_transaction($mysqli);
    }

    /**
     * Commit transaction.
     *
     * @throws DatabaseConnectionException If not connected
     */
    public static function transactionCommit(): void
    {
        $mysqli = self::getConnection();
        @mysqli_commit($mysqli);
    }

    /**
     * Rollback transaction.
     *
     * @throws DatabaseConnectionException If not connected
     */
    public static function transactionRollback(): void
    {
        $mysqli = self::getConnection();
        @mysqli_rollback($mysqli);
    }

    /**
     * Lock tables.
     *
     * @param array<string, string> $tables Table name => lock type (READ or WRITE)
     * @throws DatabaseException On query failure
     */
    public static function lockTables(array $tables): void
    {
        $locks = [];
        foreach ($tables as $table => $type) {
            $locks[] = "`{$table}` " . strtoupper($type);
        }
        $sql = "LOCK TABLES " . implode(', ', $locks);
        self::sql($sql);
    }

    /**
     * Unlock all tables.
     *
     * @throws DatabaseException On query failure
     */
    public static function unlockTables(): void
    {
        self::sql("UNLOCK TABLES");
    }

    /**
     * Parse SQL query with parameters.
     *
     * @param string $sql SQL query with ? placeholders
     * @param ?SqlParamCollection $params Query parameters
     * @param mysqli $mysqli mysqli connection for escaping
     * @return string Parsed SQL with values substituted
     * @throws DatabaseParamsException When parameter count does not match placeholders
     */
    private static function parseSqlWithParams(string $sql, ?SqlParamCollection $params, mysqli $mysqli): string
    {
        if ($params === null || count($params) === 0) {
            return $sql;
        }

        $parsedSql = '';
        $paramIndex = 0;
        $length = strlen($sql);
        $inString = false;
        $stringChar = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            // Handle string literals
            if (($char === '"' || $char === "'") && ($i === 0 || $sql[$i - 1] !== '\\')) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar) {
                    $inString = false;
                }
                $parsedSql .= $char;
                continue;
            }

            // Replace ? with parameter value
            if ($char === '?' && !$inString) {
                $param = $params[$paramIndex]
                    ?? throw new DatabaseParamsException("Not enough parameters provided for query");
                $value = $param->value;

                // Escape value
                if ($value === null) {
                    $parsedSql .= 'NULL';
                } elseif ($param->type === 'i' || $param->type === 'd') {
                    $parsedSql .= $value;
                } else {
                    $parsedSql .= "'" . mysqli_real_escape_string($mysqli, (string)$value) . "'";
                }

                $paramIndex++;
            } else {
                $parsedSql .= $char;
            }
        }

        if ($paramIndex !== count($params)) {
            throw new DatabaseParamsException("Too many parameters provided for query");
        }

        return $parsedSql;
    }

    /**
     * Check if error is connection lost error.
     *
     * @param int $errno MySQL error number
     * @return bool True if error indicates connection lost (2006, 2013)
     */
    private static function isConnectionLostError(int $errno): bool
    {
        return in_array($errno, [2006, 2013], true); // CR_SERVER_GONE_ERROR, CR_SERVER_LOST
    }

    /**
     * Throw appropriate connection exception based on error code.
     *
     * @param int $errno MySQL error number
     * @param string $error Error message
     * @throws DatabaseConnectionException When MySQL connection error occurs
     */
    private static function throwConnectionException(int $errno, string $error): never
    {
        $exception = match ($errno) {
            1045 => new AccessDeniedException($error, $errno),
            2002, 2003 => new CantConnectToMysqlServerException($error, $errno),
            2005 => new HostNotFoundException($error, $errno),
            2007 => new ProtocolMismatchException($error, $errno),
            2026 => new SslConnectionExceptionErrorException($error, $errno),
            2013 => new TimeoutException($error, $errno),
            1040 => new TooManyConnectionsException($error, $errno),
            1049 => new UnknownDatabaseException($error, $errno),
            default => new DatabaseConnectionException($error, $errno),
        };

        $exception->setMysqlError($errno, $error);
        throw $exception;
    }

    /**
     * Throw appropriate runtime exception based on error code.
     *
     * @param int $errno MySQL error number
     * @param string $error Error message
     * @param string $query SQL query that failed
     * @throws DatabaseRuntimeException When MySQL runtime error occurs during query
     */
    private static function throwRuntimeException(int $errno, string $error, string $query): never
    {
        // Build detailed error message
        $queryPreview = strlen($query) > 200 ? substr($query, 0, 200) . '...' : $query;
        $detailedMessage = "MySQL Error [{$errno}]: {$error}";
        if ($query !== '') {
            $detailedMessage .= "\nQuery: {$queryPreview}";
        }

        $exception = match ($errno) {
            1406 => new DataTooLongException($detailedMessage, $errno),
            1213 => new DeadlockDetectedException($detailedMessage, $errno),
            1365 => new DivisionByZeroException($detailedMessage, $errno),
            1062 => new DuplicateEntryException($detailedMessage, $errno),
            1451, 1452 => new ForeignKeyConstraintException($detailedMessage, $errno),
            1205 => new LockWaitTimeoutException($detailedMessage, $errno),
            1264 => new OutOfRangeValueException($detailedMessage, $errno),
            1064 => new SyntaxErrorException($detailedMessage, $errno),
            1146 => new TableNotFoundException($detailedMessage, $errno),
            3024 => new QueryExecutionTimeoutException($detailedMessage, $errno),
            2013 => new LostConnectionException($detailedMessage, $errno),
            2006 => new GoneAwayException($detailedMessage, $errno),
            default => new DatabaseRuntimeException($detailedMessage, $errno),
        };

        $exception->setMysqlError($errno, $error);
        $exception->setQuery($query);
        throw $exception;
    }

    /**
     * Escape string for SQL query.
     *
     * @param string $value Value to escape
     * @return string Escaped string
     * @throws DatabaseConnectionException If not connected
     */
    public static function escape(string $value): string
    {
        $mysqli = self::getConnection();
        return mysqli_real_escape_string($mysqli, $value);
    }

    /**
     * Get server info.
     *
     * @return string MySQL server version
     * @throws DatabaseConnectionException If not connected
     */
    public static function getServerInfo(): string
    {
        $mysqli = self::getConnection();
        return mysqli_get_server_info($mysqli);
    }

    /**
     * Get client info.
     *
     * @return string mysqli client version
     */
    public static function getClientInfo(): string
    {
        return mysqli_get_client_info();
    }

    /**
     * Check if connection is alive by attempting a simple query.
     *
     * Note: mysqli_ping() is deprecated, using SELECT 1 instead.
     *
     * @return bool True if connection is alive, false otherwise
     */
    public static function ping(): bool
    {
        try {
            $mysqli = self::getConnection();
            $result = @mysqli_query($mysqli, 'SELECT 1');
            if ($result !== false) {
                @mysqli_free_result($result);
                return true;
            }
            return false;
        } catch (DatabaseConnectionException $e) {
            return false;
        }
    }
}
