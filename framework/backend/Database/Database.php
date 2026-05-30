<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseParamsException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\ResultSet\ResultSet;
use Hilos\Database\ResultSet\ResultSetCollection;
use mysqli;
use mysqli_result;

/**
 * Static multi-connection MySQL access layer with reconnect and result-set caching.
 */
class Database
{
    /** @var array<int, DatabaseConnectionConfig> */
    private static array $configurations = [];

    /** @var array<int, ?mysqli> */
    private static array $connections = [];

    /** @var int Current active connection index */
    private static int $currentIndex = DatabaseConnectionDefaults::PRIMARY_INDEX;

    /** @var array<int, ?ResultSet> Cached ResultSet instances for each connection */
    private static array $resultSets = [];

    /**
     * @param ?int $index Connection index (defaults to current)
     * @return ?mysqli_result Cached mysqli result or null
     */
    public static function getCurrentResult(?int $index = null): ?mysqli_result
    {
        $index = $index ?? self::$currentIndex;
        return self::$resultSets[$index]?->getMysqliResult();
    }

    /**
     * @param ?int $index Connection index (defaults to current)
     * @return ?ResultSet Cached result set or null
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
     *     self::configure(DatabaseConnectionDefaults::PRIMARY_INDEX, DatabaseConnectionDefaults::HOST, 'user', 'pass', 'db');
     *     self::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);
     *     Schema::initialize(DatabaseConnectionDefaults::PRIMARY_INDEX);
     * }
     * ```
     */
    public static function initialize(): void
    {
        // Empty implementation - should be overridden in child classes
    }

    /**
     * @param int $index Connection index (0 for primary)
     * @param string $host Database host
     * @param string $user Database user
     * @param string $password Database password
     * @param string $database Database name
     * @param int $port Database port
     * @param string $charset Character set with collation
     * @param ?string $socket Unix socket path
     * @param int $reconnectAttempts Reconnect attempt count for sql()
     * @param int $reconnectDelay Delay between reconnects in milliseconds
     */
    public static function configure(
        int $index = DatabaseConnectionDefaults::PRIMARY_INDEX,
        string $host = DatabaseConnectionDefaults::HOST,
        string $user = DatabaseConnectionDefaults::USER,
        string $password = DatabaseConnectionDefaults::PASSWORD,
        string $database = '',
        int $port = DatabaseConnectionDefaults::PORT,
        string $charset = DatabaseConnectionDefaults::CHARSET,
        ?string $socket = null,
        int $reconnectAttempts = DatabaseConnectionPolicy::DEFAULT_RECONNECT_ATTEMPTS,
        int $reconnectDelay = DatabaseConnectionPolicy::DEFAULT_RECONNECT_DELAY_MS
    ): void {
        self::$configurations[$index] = new DatabaseConnectionConfig(
            $host,
            $user,
            $password,
            $database,
            $port,
            $charset,
            $socket,
            $reconnectAttempts,
            $reconnectDelay,
        );
        self::$connections[$index] = null;
        self::$resultSets[$index] = null;
    }

    /**
     * @param int $index Connection index to activate
     * @throws DatabaseException When connection index is not configured
     */
    public static function useConnection(int $index): void
    {
        if (!isset(self::$configurations[$index])) {
            throw new DatabaseException("Connection {$index} is not configured");
        }
        self::$currentIndex = $index;
    }

    /**
     * @return int Active connection index
     */
    public static function getCurrentIndex(): int
    {
        return self::$currentIndex;
    }

    /**
     * @param ?int $index Connection index (defaults to current)
     * @param bool $retryOnConnectionError Whether to retry temporary connection errors
     * @param ?int $maxRetries Max attempts (uses config when null)
     * @param ?int $retryDelaySeconds Delay between retries in seconds (uses config when null)
     * @throws DatabaseConnectionException When connection index is not configured or charset setup fails
     * @throws CantConnectToMysqlServerException When retries are exhausted on temporary errors
     */
    public static function connect(?int $index = null, bool $retryOnConnectionError = false, ?int $maxRetries = null, ?int $retryDelaySeconds = null): void
    {
        $index = $index ?? self::$currentIndex;

        $config = self::$configurations[$index]
            ?? throw new DatabaseConnectionException("Connection {$index} is not configured");
        
        // Determine retry parameters
        $retries = $maxRetries ?? ($retryOnConnectionError ? DatabaseConnectionPolicy::CONNECT_RETRY_MAX_ATTEMPTS : DatabaseConnectionPolicy::ATTEMPTS_WITHOUT_RECONNECT);
        $delaySeconds = $retryDelaySeconds ?? ($retryOnConnectionError ? DatabaseConnectionPolicy::CONNECT_RETRY_DELAY_SECONDS : 0);
        
        $lastException = null;
        
        for ($attempt = 0; $attempt < $retries; $attempt++) {
            if ($attempt > 0) {
                sleep($delaySeconds);
            }
            
            try {
                mysqli_report(MYSQLI_REPORT_OFF);

                $mysqli = @mysqli_connect(
                    $config->host,
                    $config->user,
                    $config->password,
                    $config->database,
                    $config->port,
                    $config->socket,
                );

                if ($mysqli === false) {
                    $errno = mysqli_connect_errno();
                    $error = mysqli_connect_error();
                    
                    // Retry only for temporary connection errors if retry is enabled
                    if ($retryOnConnectionError && MysqlClientErrorCode::isTemporaryConnectFailure($errno) && $attempt < $retries - 1) {
                        $lastException = new CantConnectToMysqlServerException($error, $errno);
                        continue; // Retry
                    }

                    MysqlExceptionMapper::connectionException($errno, $error);
                }

                // Set charset
                if (!@mysqli_set_charset($mysqli, $config->charset)) {
                    $errno = mysqli_errno($mysqli);
                    $error = mysqli_error($mysqli);
                    mysqli_close($mysqli);
                    
                    // Charset errors are not retryable
                    MysqlExceptionMapper::connectionException($errno, $error);
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
     * @param ?int $index Connection index (defaults to current)
     */
    public static function close(?int $index = null): void
    {
        $index = $index ?? self::$currentIndex;

        if (isset(self::$connections[$index]) && self::$connections[$index] !== null) {
            // Free current result set if exists (get mysqli_result from ResultSet)
            $resultSet = self::$resultSets[$index] ?? null;
            if ($resultSet !== null) {
                $mysqliResult = $resultSet->getMysqliResult();
                if ($mysqliResult !== null) {
                    @mysqli_free_result($mysqliResult);
                }
            }
            @mysqli_close(self::$connections[$index]);
            self::$connections[$index] = null;
            self::$resultSets[$index] = null;
        }
    }

    /**
     * @param ?int $index Connection index (defaults to current)
     * @return mysqli Active connection
     * @throws DatabaseConnectionException When not connected at index
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
     * @param ?int $index Connection index (defaults to current)
     * @return bool Whether connection is active
     */
    public static function isConnected(?int $index = null): bool
    {
        $index = $index ?? self::$currentIndex;
        return isset(self::$connections[$index]) && self::$connections[$index] !== null;
    }

    /**
     * @param string $sql SQL with ? placeholders
     * @param array|SqlParamCollection|null $params Bound parameters
     * @param bool $tryReconnect Whether to reconnect on connection loss
     * @return ResultSetCollection First result set collection
     * @throws DatabaseConnectionException When not connected or reconnect fails
     * @throws DatabaseParamsException When parameters are invalid or placeholder count mismatches
     * @throws DatabaseRuntimeException When query execution fails
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
        $maxAttempts = $tryReconnect ? $config->reconnectAttempts : DatabaseConnectionPolicy::ATTEMPTS_WITHOUT_RECONNECT;

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
                            ". Original query: " . substr($sql, 0, DatabaseException::QUERY_PREVIEW_MAX_LENGTH)
                        );
                    }
                } else {
                    throw new DatabaseConnectionException(
                        "Database connection is closed at index {$index}. " .
                        "Connection state: " . (isset(self::$connections[$index]) ? "exists but is null" : "does not exist") . ". " .
                        "Query: " . substr($sql, 0, DatabaseException::QUERY_PREVIEW_MAX_LENGTH)
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
                    $errno = MysqlClientErrorCode::SERVER_GONE->value;
                }

                // Check if we should try to reconnect
                if ($tryReconnect && MysqlClientErrorCode::isConnectionLost($errno) && $attempts < $maxAttempts) {
                    // Use minimum delay (at least 1 second) to prevent rapid retry attempts
                    $delayMs = max($config->reconnectDelay, DatabaseConnectionPolicy::RECONNECT_DELAY_MIN_MS);
                    // Ensure total retry time doesn't exceed maximum
                    $totalTimeMs = $attempts * $delayMs;
                    if ($totalTimeMs >= DatabaseConnectionPolicy::RECONNECT_TIMEOUT_MAX_MS) {
                        // Timeout exceeded, throw error
                        MysqlExceptionMapper::runtimeException($errno, $error, $sql);
                    }

                    // Sleep using sleep() for seconds (minimum 1 second)
                    $delaySeconds = (int)ceil($delayMs / DatabaseConnectionPolicy::MS_PER_SECOND);
                    sleep($delaySeconds);

                    self::close($index);
                    try {
                        self::connect($index);
                        continue; // Retry query
                    } catch (DatabaseConnectionException $e) {
                        // If reconnection fails, throw the original error
                        MysqlExceptionMapper::runtimeException($errno, $error, $sql);
                    }
                }

                MysqlExceptionMapper::runtimeException($errno, $error, $sql);
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
     * @param string $sql SQL query
     * @param array|SqlParamCollection|null $params Bound parameters
     * @param int $timeout Max execution time in seconds
     * @param bool $tryReconnect Whether to reconnect on connection loss
     * @throws DatabaseConnectionException When not connected or reconnect fails
     * @throws DatabaseParamsException When parameters are invalid or placeholder count mismatches
     * @throws DatabaseRuntimeException When query execution fails or times out
     */
    public static function sqlRun(
        string $sql,
        array|SqlParamCollection|null $params = null,
        int $timeout = DatabaseConnectionPolicy::DEFAULT_SQL_RUN_TIMEOUT_SECONDS,
        bool $tryReconnect = true,
    ): void {
        $index = self::$currentIndex;
        $mysqli = self::getConnection($index);

        // Set max execution time
        $oldTimeout = @mysqli_query($mysqli, DatabaseSql::SESSION_MAX_STATEMENT_TIME_GET);
        @mysqli_query($mysqli, sprintf(DatabaseSql::SESSION_MAX_STATEMENT_TIME_SET, $timeout * DatabaseConnectionPolicy::MS_PER_SECOND));

        try {
            self::sql($sql, $params, $tryReconnect);
        } finally {
            // Restore old timeout
            if ($oldTimeout !== false) {
                $row = mysqli_fetch_row($oldTimeout);
                if ($row !== null) {
                    @mysqli_query($mysqli, sprintf(DatabaseSql::SESSION_MAX_STATEMENT_TIME_SET, $row[0]));
                }
            }
        }
    }

    /**
     * @return bool Whether another result set was loaded
     * @throws DatabaseConnectionException When not connected
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
     * Loads all rows from the current result set into memory.
     *
     * @return list<array<string, mixed>> Result rows in order
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
     * Fetches the next row from the current result set.
     *
     * @return ?array<string, mixed> Next row or null when exhausted
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
     * @param string $fieldName Column name
     * @return mixed Column value or null when row or field is missing
     */
    public static function field(string $fieldName): mixed
    {
        $row = self::row();
        return $row[$fieldName] ?? null;
    }

    /**
     * @return int Row count in current result set
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
     * @return int Affected row count from last query
     * @throws DatabaseConnectionException When not connected
     */
    public static function affectedRows(): int
    {
        $mysqli = self::getConnection();
        return mysqli_affected_rows($mysqli);
    }

    /**
     * @return int Last insert ID or 0
     * @throws DatabaseConnectionException When not connected
     */
    public static function lastInsertId(): int
    {
        $mysqli = self::getConnection();
        return mysqli_insert_id($mysqli);
    }

    /**
     * @throws DatabaseConnectionException When not connected
     */
    public static function transactionStart(): void
    {
        $mysqli = self::getConnection();
        @mysqli_begin_transaction($mysqli);
    }

    /**
     * @throws DatabaseConnectionException When not connected
     */
    public static function transactionCommit(): void
    {
        $mysqli = self::getConnection();
        @mysqli_commit($mysqli);
    }

    /**
     * @throws DatabaseConnectionException When not connected
     */
    public static function transactionRollback(): void
    {
        $mysqli = self::getConnection();
        @mysqli_rollback($mysqli);
    }

    /**
     * @param array<string, string> $tables Table name to lock type (READ or WRITE)
     * @throws DatabaseConnectionException When not connected or reconnect fails
     * @throws DatabaseParamsException When parameters are invalid or placeholder count mismatches
     * @throws DatabaseRuntimeException When query execution fails
     */
    public static function lockTables(array $tables): void
    {
        $locks = [];
        foreach ($tables as $table => $type) {
            $locks[] = "`{$table}` " . strtoupper($type);
        }
        $sql = DatabaseSql::LOCK_TABLES_PREFIX . ' ' . implode(', ', $locks);
        self::sql($sql);
    }

    /**
     * @throws DatabaseConnectionException When not connected or reconnect fails
     * @throws DatabaseParamsException When parameters are invalid or placeholder count mismatches
     * @throws DatabaseRuntimeException When query execution fails
     */
    public static function unlockTables(): void
    {
        self::sql(DatabaseSql::UNLOCK_TABLES);
    }

    /**
     * @param string $sql SQL with ? placeholders
     * @param ?SqlParamCollection $params Bound parameters
     * @param mysqli $mysqli Connection used for escaping
     * @return string SQL with substituted values
     * @throws DatabaseParamsException When parameters are invalid or placeholder count mismatches
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
                    $parsedSql .= DatabaseSql::SQL_NULL;
                } elseif ($param->type->isNumeric()) {
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
     * @param string $value Raw string value
     * @return string Escaped string for SQL
     * @throws DatabaseConnectionException When not connected
     */
    public static function escape(string $value): string
    {
        $mysqli = self::getConnection();
        return mysqli_real_escape_string($mysqli, $value);
    }

    /**
     * @return string MySQL server version
     * @throws DatabaseConnectionException When not connected
     */
    public static function getServerInfo(): string
    {
        $mysqli = self::getConnection();
        return mysqli_get_server_info($mysqli);
    }

    /**
     * @return string mysqli client library version
     */
    public static function getClientInfo(): string
    {
        return mysqli_get_client_info();
    }

    /**
     * Uses DatabaseSql::PING instead of mysqli_ping() for PHP compatibility.
     *
     * @return bool Whether the active connection responds
     */
    public static function ping(): bool
    {
        try {
            $mysqli = self::getConnection();
            $result = @mysqli_query($mysqli, DatabaseSql::PING);
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
