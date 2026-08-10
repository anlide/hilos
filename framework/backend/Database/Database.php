<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Constants\TimeConstants;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseParamsException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\ResultSet\ResultSet;
use Hilos\Database\ResultSet\ResultSetCollection;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;

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
     * Last SQL sent on each connection, kept for error reporting only.
     *
     * {@see nextResult()} learns about a failing statement from mysqli long after
     * {@see sql()} returned, and the exception it maps would otherwise name the table
     * but not the query the statement lived in.
     *
     * @var array<int, string>
     */
    private static array $lastSql = [];

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
        self::$lastSql[$index] = '';
    }

    /**
     * Lists the configured connection indices in ascending order.
     *
     * Read-only view over the private configuration map, so a subsystem that must
     * act on every connection (e.g. the backup dump path) can iterate them without
     * reaching into internal state.
     *
     * @return list<int> Configured connection indices, ascending
     */
    public static function getConfiguredIndices(): array
    {
        $indices = array_keys(self::$configurations);
        sort($indices);

        return $indices;
    }

    /**
     * Returns the immutable settings of a configured connection.
     *
     * Companion to {@see getConfiguredIndices()}: the backup dump path needs the
     * host/credentials/database of each connection to spawn mysqldump against it.
     *
     * @param int $index Connection index
     * @return DatabaseConnectionConfig Connection settings at the index
     * @throws DatabaseException When the connection index is not configured
     */
    public static function getConnectionConfig(int $index): DatabaseConnectionConfig
    {
        return self::$configurations[$index]
            ?? throw new DatabaseException("Connection {$index} is not configured");
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
    public static function connect(
        ?int $index = null,
        bool $retryOnConnectionError = false,
        ?int $maxRetries = null,
        ?int $retryDelaySeconds = null,
    ): void {
        $index = $index ?? self::$currentIndex;

        $config = self::$configurations[$index]
            ?? throw new DatabaseConnectionException("Connection {$index} is not configured");
        
        // Determine retry parameters
        $retries = $maxRetries ?? ($retryOnConnectionError
            ? DatabaseConnectionPolicy::CONNECT_RETRY_MAX_ATTEMPTS
            : DatabaseConnectionPolicy::ATTEMPTS_WITHOUT_RECONNECT);
        $delaySeconds = $retryDelaySeconds ?? ($retryOnConnectionError ? DatabaseConnectionPolicy::CONNECT_RETRY_DELAY_SECONDS : 0);
        
        $lastException = null;
        
        for ($attempt = 0; $attempt < $retries; $attempt++) {
            if ($attempt > 0) {
                sleep($delaySeconds);
            }
            
            try {
                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

                try {
                    // A failed connect is a warning AND an exception, and the warning is the
                    // dangerous half: BaseManager::errorHandler logs it and calls onError(),
                    // which sets shouldExit on a daemon — ending the process that this very
                    // retry loop exists to carry through a blip. Measured: an unresolvable host
                    // raises the handler once per attempt, while a lost link on a live query
                    // raises it not at all.
                    // warning-suppressed: the same failure arrives as mysqli_sql_exception and is mapped right below
                    $mysqli = @mysqli_connect(
                        $config->host,
                        $config->user,
                        $config->password,
                        $config->database,
                        $config->port,
                        $config->socket,
                    );
                } catch (mysqli_sql_exception $e) {
                    $errno = $e->getCode();
                    $error = $e->getMessage();

                    // Retry only for temporary connection errors if retry is enabled
                    if ($retryOnConnectionError && MysqlClientErrorCode::isTemporaryConnectFailure($errno) && $attempt < $retries - 1) {
                        $lastException = new CantConnectToMysqlServerException($error, $errno);
                        continue; // Retry
                    }

                    MysqlExceptionMapper::connectionException($errno, $error);
                }

                // Set charset
                try {
                    mysqli_set_charset($mysqli, $config->charset);
                } catch (mysqli_sql_exception $e) {
                    $errno = $e->getCode();
                    $error = $e->getMessage();
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
                    mysqli_free_result($mysqliResult);
                }
            }
            mysqli_close(self::$connections[$index]);
            self::$connections[$index] = null;
            self::$resultSets[$index] = null;
            self::$lastSql[$index] = '';
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
        self::$lastSql[$index] = $sql;

        // Convert array to SqlParamCollection
        if (is_array($params)) {
            $params = SqlParamCollection::fromArray($params);
        }

        // Parse SQL with parameters
        $parsedSql = self::parseSqlWithParams($sql, $params, $mysqli);

        // Process all remaining result sets from multi-query (if any).
        // A failure here belongs to the PREVIOUS query, whose leftovers we are draining:
        // rethrowing it would kill this correct query with someone else's error.
        try {
            while (mysqli_next_result($mysqli)) {
                mysqli_store_result($mysqli);
            }
        } catch (mysqli_sql_exception) {
            // Drained as far as the previous query allows
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
                            'Database connection was closed. Attempted to reconnect'
                            . " (attempt {$attempts}/{$maxAttempts}) but failed: " . $e->getMessage()
                            . '. Original query: ' . substr($sql, 0, DatabaseException::QUERY_PREVIEW_MAX_LENGTH)
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
            try {
                mysqli_multi_query($mysqli, $parsedSql);
            } catch (mysqli_sql_exception $e) {
                $errno = $e->getCode();
                $error = $e->getMessage();

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
                    $delaySeconds = (int)ceil($delayMs / TimeConstants::MS_PER_SECOND);
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
        // (or null if no result set, e.g. for INSERT/UPDATE/DELETE).
        // Buffering is where the server reports what it could not report earlier — a statement
        // that ran into max_statement_time, a link that died mid-transfer — so the failure is
        // mapped here exactly as the one from multi_query itself.
        try {
            $result = mysqli_store_result($mysqli);
        } catch (mysqli_sql_exception $e) {
            MysqlExceptionMapper::runtimeException($e->getCode(), $e->getMessage(), $sql);
        }
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

        // Set max execution time. A connection that already died is not this method's
        // business: the failure is left to sql() below, which reconnects and reports it
        // as a typed exception, exactly as it did while the warning was suppressed here.
        $oldTimeout = false;
        try {
            $oldTimeout = mysqli_query($mysqli, DatabaseSql::SESSION_MAX_STATEMENT_TIME_GET);
            mysqli_query($mysqli, sprintf(DatabaseSql::SESSION_MAX_STATEMENT_TIME_SET, $timeout * TimeConstants::MS_PER_SECOND));
        } catch (mysqli_sql_exception) {
            // Timeout stays whatever the session had
        }

        try {
            self::sql($sql, $params, $tryReconnect);
        } finally {
            // Restore old timeout, but only while the link is still the one it was read from:
            // sql() may have reconnected, and then $mysqli is the handle close() destroyed,
            // while the session that replaced it starts from the server default anyway.
            if ($oldTimeout !== false && (self::$connections[$index] ?? null) === $mysqli) {
                $row = mysqli_fetch_row($oldTimeout);
                if ($row !== null) {
                    try {
                        mysqli_query($mysqli, sprintf(DatabaseSql::SESSION_MAX_STATEMENT_TIME_SET, $row[0]));
                    } catch (mysqli_sql_exception) {
                        // Thrown from finally, this would overwrite the real error of the query
                    }
                }
            }
        }
    }

    /**
     * @return bool Whether another result set was loaded
     * @throws DatabaseConnectionException When not connected
     * @throws DatabaseRuntimeException When the next statement of the multi-query failed
     */
    public static function nextResult(): bool
    {
        $index = self::$currentIndex;
        $mysqli = self::getConnection($index);

        // Move to next result set. false now means only "there are no more results": a
        // statement that failed arrives as an exception and is mapped, where before it
        // was indistinguishable from the end of the multi-query and disappeared. Buffering
        // the rows is part of the same step — the server reports a timed-out or interrupted
        // statement there, not when the statement header arrives.
        try {
            if (mysqli_next_result($mysqli)) {
                // Store next result set (or null if no result set)
                $result = mysqli_store_result($mysqli);
                $mysqliResult = $result !== false ? $result : null;

                // Create/cache new ResultSet for next result
                self::$resultSets[$index] = $mysqliResult !== null
                    ? ResultSet::fromMysqliResult($mysqliResult)
                    : null;

                return true;
            }
        } catch (mysqli_sql_exception $e) {
            self::$resultSets[$index] = null;
            MysqlExceptionMapper::runtimeException($e->getCode(), $e->getMessage(), self::$lastSql[$index]);
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
     * @throws DatabaseRuntimeException When the server refuses to start the transaction
     */
    public static function transactionStart(): void
    {
        $mysqli = self::getConnection();
        try {
            mysqli_begin_transaction($mysqli);
        } catch (mysqli_sql_exception $e) {
            MysqlExceptionMapper::runtimeException($e->getCode(), $e->getMessage(), DatabaseSql::START_TRANSACTION);
        }
    }

    /**
     * @throws DatabaseConnectionException When not connected
     * @throws DatabaseRuntimeException When the commit fails
     */
    public static function transactionCommit(): void
    {
        $mysqli = self::getConnection();
        try {
            mysqli_commit($mysqli);
        } catch (mysqli_sql_exception $e) {
            MysqlExceptionMapper::runtimeException($e->getCode(), $e->getMessage(), DatabaseSql::COMMIT);
        }
    }

    /**
     * @throws DatabaseConnectionException When not connected
     * @throws DatabaseRuntimeException When the rollback fails
     */
    public static function transactionRollback(): void
    {
        $mysqli = self::getConnection();
        try {
            mysqli_rollback($mysqli);
        } catch (mysqli_sql_exception $e) {
            MysqlExceptionMapper::runtimeException($e->getCode(), $e->getMessage(), DatabaseSql::ROLLBACK);
        }
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
            $result = mysqli_query($mysqli, DatabaseSql::PING);
            if ($result !== false) {
                mysqli_free_result($result);
                return true;
            }
            return false;
        } catch (DatabaseConnectionException | mysqli_sql_exception $e) {
            return false;
        }
    }
}
