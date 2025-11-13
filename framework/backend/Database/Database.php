<?php

namespace Hilos\Database;

use Hilos\Exception\Database\DatabaseConnectionException;
use Hilos\Exception\Database\SqlConnection\AccessDeniedException;
use Hilos\Exception\Database\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Exception\Database\SqlConnection\HostNotFoundException;
use Hilos\Exception\Database\SqlConnection\ProtocolMismatchException;
use Hilos\Exception\Database\SqlConnection\SslConnectionExceptionErrorException;
use Hilos\Exception\Database\SqlConnection\TimeoutException;
use Hilos\Exception\Database\SqlConnection\TooManyConnectionsException;
use Hilos\Exception\Database\SqlConnection\UnknownDatabaseException;
use Hilos\Exception\Database\DatabaseRuntimeException;
use Hilos\Exception\Database\SqlRuntime\DataTooLongException;
use Hilos\Exception\Database\SqlRuntime\DeadlockDetectedException;
use Hilos\Exception\Database\SqlRuntime\DivisionByZeroException;
use Hilos\Exception\Database\SqlRuntime\DuplicateEntryException;
use Hilos\Exception\Database\SqlRuntime\ForeignKeyConstraintException;
use Hilos\Exception\Database\SqlRuntime\GoneAwayException;
use Hilos\Exception\Database\SqlRuntime\LockWaitTimeoutException;
use Hilos\Exception\Database\SqlRuntime\LostConnectionException;
use Hilos\Exception\Database\SqlRuntime\OutOfRangeValueException;
use Hilos\Exception\Database\SqlRuntime\QueryExecutionTimeoutException;
use Hilos\Exception\Database\SqlRuntime\SyntaxErrorException;
use Hilos\Exception\Database\SqlRuntime\TableNotFoundException;
use Hilos\Exception\Database\DatabaseParamsException;
use Hilos\Exception\DatabaseException;
use mysqli;
use mysqli_result;

/**
 * Database connection and query management class
 * Supports multiple database connections with static methods
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

    /** @var array<int, ?mysqli_result> Current active result set for each connection */
    private static array $results = [];

    /**
     * Initialize database connections and schema
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
     * Configure a database connection (doesn't connect yet)
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
        self::$results[$index] = null;
    }

    /**
     * Set current connection index
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
     * Get current connection index
     */
    public static function getCurrentIndex(): int
    {
        return self::$currentIndex;
    }

    /**
     * Connect to database using configured settings
     * 
     * @param int|null $index Connection index (uses current if null)
     * @throws DatabaseConnectionException On connection failure
     */
    public static function connect(?int $index = null): void
    {
        $index = $index ?? self::$currentIndex;

        if (!isset(self::$configurations[$index])) {
            throw new DatabaseConnectionException("Connection {$index} is not configured");
        }

        $config = self::$configurations[$index];

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
            self::throwConnectionException($errno, $error);
        }

        // Set charset
        if (!@mysqli_set_charset($mysqli, $config['charset'])) {
            $errno = mysqli_errno($mysqli);
            $error = mysqli_error($mysqli);
            mysqli_close($mysqli);
            self::throwConnectionException($errno, $error);
        }

        self::$connections[$index] = $mysqli;
    }

    /**
     * Close database connection
     * 
     * @param ?int $index Connection index (uses current if null)
     */
    public static function close(?int $index = null): void
    {
        $index = $index ?? self::$currentIndex;

        if (isset(self::$connections[$index]) && self::$connections[$index] !== null) {
            // Free current result set if exists
            if (isset(self::$results[$index]) && self::$results[$index] instanceof mysqli_result) {
                @mysqli_free_result(self::$results[$index]);
            }
            @mysqli_close(self::$connections[$index]);
            self::$connections[$index] = null;
            self::$results[$index] = null;
        }
    }

    /**
     * Get active mysqli connection
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
     * Check if connected
     */
    public static function isConnected(?int $index = null): bool
    {
        $index = $index ?? self::$currentIndex;
        return isset(self::$connections[$index]) && self::$connections[$index] !== null;
    }

    /**
     * Execute SQL query with parameters
     *
     * @param string $sql SQL query with ? placeholders
     * @param array|SqlParamCollection|null $params Query parameters
     * @param bool $tryReconnect Try to reconnect on connection loss
     * @throws DatabaseException On query failure
     */
    public static function sql(string $sql, array|SqlParamCollection|null $params = null, bool $tryReconnect = true): void
    {
        $index = self::$currentIndex;
        $mysqli = self::getConnection($index);
        $config = self::$configurations[$index];

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

            // Execute multi-query
            $result = @mysqli_multi_query($mysqli, $parsedSql);

            if ($result === false) {
                $errno = mysqli_errno($mysqli);
                $error = mysqli_error($mysqli);

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
        self::$results[$index] = $result !== false ? $result : null;
    }

    /**
     * Execute SQL with timeout
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
     * Get next result set from multi-query
     *
     * @return bool True if there is a next result set
     * @throws DatabaseConnectionException
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
            self::$results[$index] = $result !== false ? $result : null;
            return true;
        }

        // No more result sets
        self::$results[$index] = null;
        return false;
    }

    /**
     * Get all rows from current result set
     * Note: This loads all rows into memory. For large datasets, use row() in a loop instead.
     * 
     * @return array Array of associative arrays
     */
    public static function rows(): array
    {
        $index = self::$currentIndex;
        $result = self::$results[$index] ?? null;

        if (!($result instanceof mysqli_result)) {
            return [];
        }

        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Get single row from current result set
     * Advances the internal pointer, so each call returns the next row
     * 
     * @return array|null Associative array or null if no more rows
     */
    public static function row(): ?array
    {
        $index = self::$currentIndex;
        $result = self::$results[$index] ?? null;

        if (!($result instanceof mysqli_result)) {
            return null;
        }

        $row = mysqli_fetch_assoc($result);
        return $row !== null ? $row : null;
    }

    /**
     * Get single field value from current result set
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
     * Get number of rows in current result set
     */
    public static function count(): int
    {
        $index = self::$currentIndex;
        $result = self::$results[$index] ?? null;

        if (!($result instanceof mysqli_result)) {
            return 0;
        }

        return mysqli_num_rows($result);
    }

    /**
     * Get number of affected rows from last query
     * @throws DatabaseConnectionException
     */
    public static function affectedRows(): int
    {
        $mysqli = self::getConnection();
        return mysqli_affected_rows($mysqli);
    }

    /**
     * Get last insert ID
     * @throws DatabaseConnectionException
     */
    public static function lastInsertId(): int
    {
        $mysqli = self::getConnection();
        return mysqli_insert_id($mysqli);
    }

    /**
     * Start transaction
     * @throws DatabaseConnectionException
     */
    public static function transactionStart(): void
    {
        $mysqli = self::getConnection();
        @mysqli_begin_transaction($mysqli);
    }

    /**
     * Commit transaction
     * @throws DatabaseConnectionException
     */
    public static function transactionCommit(): void
    {
        $mysqli = self::getConnection();
        @mysqli_commit($mysqli);
    }

    /**
     * Rollback transaction
     * @throws DatabaseConnectionException
     */
    public static function transactionRollback(): void
    {
        $mysqli = self::getConnection();
        @mysqli_rollback($mysqli);
    }

    /**
     * Lock tables
     *
     * @param array $tables Array of table names with lock types ['table1' => 'READ', 'table2' => 'WRITE']
     * @throws DatabaseException
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
     * Unlock all tables
     * @throws DatabaseException
     */
    public static function unlockTables(): void
    {
        self::sql("UNLOCK TABLES");
    }

    /**
     * Parse SQL query with parameters
     * @throws DatabaseParamsException
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
                if (!isset($params[$paramIndex])) {
                    throw new DatabaseParamsException("Not enough parameters provided for query");
                }

                $param = $params[$paramIndex];
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
     * Check if error is connection lost error
     */
    private static function isConnectionLostError(int $errno): bool
    {
        return in_array($errno, [2006, 2013], true); // CR_SERVER_GONE_ERROR, CR_SERVER_LOST
    }

    /**
     * Throw appropriate connection exception based on error code
     * 
     * @throws DatabaseConnectionException
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
     * Throw appropriate runtime exception based on error code
     * 
     * @throws DatabaseRuntimeException
     */
    private static function throwRuntimeException(int $errno, string $error, string $query): never
    {
        $exception = match ($errno) {
            1406 => new DataTooLongException($error, $errno),
            1213 => new DeadlockDetectedException($error, $errno),
            1365 => new DivisionByZeroException($error, $errno),
            1062 => new DuplicateEntryException($error, $errno),
            1451, 1452 => new ForeignKeyConstraintException($error, $errno),
            1205 => new LockWaitTimeoutException($error, $errno),
            1264 => new OutOfRangeValueException($error, $errno),
            1064 => new SyntaxErrorException($error, $errno),
            1146 => new TableNotFoundException($error, $errno),
            3024 => new QueryExecutionTimeoutException($error, $errno),
            2013 => new LostConnectionException($error, $errno),
            2006 => new GoneAwayException($error, $errno),
            default => new DatabaseRuntimeException($error, $errno),
        };

        $exception->setMysqlError($errno, $error);
        $exception->setQuery($query);
        throw $exception;
    }

    /**
     * Escape string for SQL query
     * @throws DatabaseConnectionException
     */
    public static function escape(string $value): string
    {
        $mysqli = self::getConnection();
        return mysqli_real_escape_string($mysqli, $value);
    }

    /**
     * Get server info
     * @throws DatabaseConnectionException
     */
    public static function getServerInfo(): string
    {
        $mysqli = self::getConnection();
        return mysqli_get_server_info($mysqli);
    }

    /**
     * Get client info
     */
    public static function getClientInfo(): string
    {
        return mysqli_get_client_info();
    }

    /**
     * Check if connection is alive by attempting a simple query
     * Note: mysqli_ping() is deprecated, using SELECT 1 instead
     * @throws DatabaseConnectionException
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

