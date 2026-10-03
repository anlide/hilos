<?php

declare(strict_types=1);

namespace Hilos\Database;

use Closure;
use Hilos\Constants\TimeConstants;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseParamsException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Exception\Transaction\MemoryRollbackFailedException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionLeftOpenException;
use Hilos\Database\Exception\Transaction\TransactionNotOpenException;
use Hilos\Database\ResultSet\ResultSet;
use Hilos\Database\ResultSet\ResultSetCollection;
use Hilos\Database\Transaction\TransactionLevel;
use Hilos\HilosException;
use Hilos\Utils\Logger;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use Throwable;

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
     * Transaction stack of each connection, outermost level first.
     *
     * A MySQL transaction belongs to a connection, so the levels are kept by connection index
     * like {@see self::$connections}; the ORM writes into the current connection, which is why
     * an announcement made under an open transaction on the current index is held on it, and
     * why the memory journal of a write is kept there too. One transaction covers the database
     * and this process's memory - the row cache and the runtime - and its MySQL part opens at
     * the first query on the connection, not at the start.
     *
     * @var array<int, list<TransactionLevel>>
     */
    private static array $transactions = [];

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
     *
     * @throws HilosException Whatever the project's configure, connect and schema build raise
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
     * Closes the connection; a transaction the server held on it is left on the stack as failed.
     *
     * The memory its writes changed goes back here, at the moment the rows go. A transaction that
     * sent no query yet holds nothing on the server, loses nothing, and stays as it stood.
     *
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
            // The transaction went with the session. Its levels stay on the stack as failed
            // so the caller's commit refuses and its rollback closes them: cleared here, the
            // next session would commit a transaction of its own and release announcements
            // about rows that vanished with this one. The memory goes back now, innermost level
            // first, so the code running between the loss and its catch reads what the table
            // holds; a step that fails is logged by the journal, the rows are gone either way.
            $levels = self::$transactions[$index] ?? [];
            if ($levels !== [] && $levels[0]->isOpened()) {
                for ($position = count($levels) - 1; $position >= 0; $position--) {
                    $level = $levels[$position];
                    if ($level->isFailed()) {
                        continue;
                    }
                    $level->dropHeld();
                    $level->markFailed();
                    self::restoreMemory($index, $position + 1, $level);
                }
            }
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
     * @param bool $tryReconnect Whether to reconnect on connection loss; never inside a transaction, whose loss ends it
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

        // The first query of a transaction opens its MySQL part: BEGIN, then the savepoints of
        // the nested levels started since, in order. Sent after the drain, which a BEGIN on a
        // link with results still pending would be refused for.
        self::openPendingLevels($index, $mysqli);

        $attempts = 0;
        $maxAttempts = $tryReconnect ? $config->reconnectAttempts : DatabaseConnectionPolicy::ATTEMPTS_WITHOUT_RECONNECT;

        // A reconnect inside a transaction would lose it without a word: the statements after
        // it would autocommit, and the commit would then release announcements about rows the
        // broken transaction never wrote. A lost connection ends the transaction instead. Before
        // its BEGIN the server holds no transaction to lose, and a reconnect is as safe as ever.
        $levels = self::$transactions[$index] ?? [];
        if ($levels !== [] && $levels[0]->isOpened()) {
            $tryReconnect = false;
            $maxAttempts = DatabaseConnectionPolicy::ATTEMPTS_WITHOUT_RECONNECT;
        }

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
     * Opens the transaction of the current connection.
     *
     * One transaction covers the database and this process's memory: a rollback takes back the
     * rows and puts the row cache and the runtime back as they were. No SQL is sent here and no
     * connection is needed - the first query on the connection sends BEGIN - so work that
     * touches only the runtime never opens the MySQL part at all. A failing BEGIN reaches the
     * caller of that first query.
     *
     * Never nests: a start while a transaction is open on the connection is refused, and the open
     * transaction is left for its own caller to roll back. A nested level opens only when every
     * level of the chain was started with {@see self::transactionStartNestable()}, which the
     * framework itself never calls.
     *
     * @throws NestedTransactionRefusedException When a transaction is already open on the connection
     */
    public static function transactionStart(): void
    {
        $index = self::$currentIndex;
        $depth = count(self::$transactions[$index] ?? []);
        if ($depth > 0) {
            throw self::$transactions[$index][$depth - 1]->isFailed()
                ? NestedTransactionRefusedException::forFailedLevel($index, $depth)
                : NestedTransactionRefusedException::forStart($index, $depth, null);
        }

        self::$transactions[$index] = [new TransactionLevel(nestable: false, savepoint: null)];
    }

    /**
     * Opens a transaction that allows another one to start inside it.
     *
     * With nothing open it is the transaction itself, marked nestable. Inside an open chain whose
     * every level is marked, it is a savepoint named by its depth: its commit releases the
     * savepoint and hands its held announcements and its memory journal to the level under it,
     * its rollback undoes its own writes and its own memory alone. One level without the mark
     * refuses the start, as the plain start does. Like the plain start it sends no SQL: the
     * first query on the connection opens BEGIN and the savepoints in order.
     *
     * Project code may call this; the framework never does, and the NESTABLE-TRANSACTION guard
     * keeps it so - a framework method joins the caller's transaction instead.
     *
     * @throws NestedTransactionRefusedException When a level of the open chain was started without the mark, or failed to commit
     */
    public static function transactionStartNestable(): void
    {
        $index = self::$currentIndex;
        $levels = self::$transactions[$index] ?? [];
        $depth = count($levels);
        if ($depth === 0) {
            self::$transactions[$index] = [new TransactionLevel(nestable: true, savepoint: null)];

            return;
        }

        foreach ($levels as $position => $level) {
            if ($level->isFailed()) {
                throw NestedTransactionRefusedException::forFailedLevel($index, $position + 1);
            }
            if (!$level->nestable) {
                throw NestedTransactionRefusedException::forStart($index, $depth, $position + 1);
            }
        }

        self::$transactions[$index][] = new TransactionLevel(
            nestable: true,
            savepoint: TransactionLevel::SAVEPOINT_PREFIX . ($depth + 1),
        );
    }

    /**
     * Commits the innermost open level of the current connection.
     *
     * A nested level releases its savepoint and hands the announcements it held and its memory
     * journal to the level under it; the outermost level commits, throws its journal away - the
     * memory stays as the writes left it - and then releases every announcement the transaction
     * held, in the order the writes were made. A level that never reached the server commits
     * without SQL. A commit that fails rolls its own level back, puts its memory back, drops
     * what the level held and leaves the level standing as failed for the caller's rollback to
     * close - taken off here, that rollback would reach the parent level.
     *
     * @throws TransactionNotOpenException When no transaction is open, or the innermost level already failed to commit
     * @throws DatabaseConnectionException When not connected
     * @throws DatabaseRuntimeException When the commit or the savepoint release fails
     * @throws HilosException Whatever a released announcement raises, once the commit stands
     */
    public static function transactionCommit(): void
    {
        $index = self::$currentIndex;
        $depth = count(self::$transactions[$index] ?? []);
        if ($depth === 0) {
            throw TransactionNotOpenException::forEmptyStack($index);
        }
        $level = self::$transactions[$index][$depth - 1];
        if ($level->isFailed()) {
            throw TransactionNotOpenException::forFailedLevel($index, $depth);
        }

        if ($level->isOpened()) {
            $mysqli = self::getConnection($index);
            try {
                if ($level->savepoint === null) {
                    mysqli_commit($mysqli);
                } else {
                    mysqli_release_savepoint($mysqli, $level->savepoint);
                }
            } catch (mysqli_sql_exception $e) {
                self::rollBackFailedCommit($mysqli, $index, $depth, $level);
                MysqlExceptionMapper::runtimeException(
                    $e->getCode(),
                    $e->getMessage(),
                    $level->savepoint === null ? DatabaseSql::COMMIT : DatabaseSql::RELEASE_SAVEPOINT,
                );
            }
        }

        array_pop(self::$transactions[$index]);
        if ($level->savepoint !== null) {
            $parent = self::$transactions[$index][$depth - 2];
            foreach ($level->takeHeld() as $announce) {
                $parent->hold($announce);
            }
            foreach ($level->takeUndo() as $undo) {
                $parent->remember($undo);
            }

            return;
        }
        // The journal leaves with the level: the commit stands, and nothing is to be put back.
        self::release($level->takeHeld());
    }

    /**
     * Rolls back the innermost level of the current connection, if there is one.
     *
     * Silent with no transaction open, like ROLLBACK in MySQL: after a commit whose released
     * announcement failed, the caller's catch rolls back a transaction that already stands, and
     * loses nothing by it. A level whose commit failed is already rolled back, its memory
     * included, and is only closed here. The level leaves the stack and drops what it held
     * before the SQL is sent, so a failing ROLLBACK still closes it.
     *
     * The memory the level's writes changed goes back first, step by step in the reverse order of
     * the writes, and then the SQL is sent - none when the level never reached the server. A step
     * that fails stops neither the other steps nor the SQL: memory half put back is worse than any
     * error. The failure raised is the SQL's when it failed; otherwise the first failed step's,
     * as it is when it is a Hilos exception and wrapped when it is not. The rest are logged.
     *
     * @throws DatabaseConnectionException When not connected
     * @throws DatabaseRuntimeException When the rollback fails
     * @throws MemoryRollbackFailedException When a step putting memory back raised something outside the framework's tree
     * @throws HilosException Whatever else a step putting memory back raised
     */
    public static function transactionRollback(): void
    {
        $index = self::$currentIndex;
        $depth = count(self::$transactions[$index] ?? []);
        if ($depth === 0) {
            return;
        }
        $level = array_pop(self::$transactions[$index]);
        if ($level->isFailed()) {
            return;
        }
        $level->dropHeld();
        $memoryFailure = self::restoreMemory($index, $depth, $level);

        if ($level->isOpened()) {
            $mysqli = self::getConnection($index);
            try {
                if ($level->savepoint === null) {
                    mysqli_rollback($mysqli);
                } else {
                    mysqli_query($mysqli, DatabaseSql::rollbackToSavepoint($level->savepoint));
                    mysqli_release_savepoint($mysqli, $level->savepoint);
                }
            } catch (mysqli_sql_exception $e) {
                MysqlExceptionMapper::runtimeException(
                    $e->getCode(),
                    $e->getMessage(),
                    $level->savepoint === null ? DatabaseSql::ROLLBACK : DatabaseSql::ROLLBACK_TO_SAVEPOINT,
                );
            }
        }

        if ($memoryFailure instanceof HilosException) {
            throw $memoryFailure;
        }
        if ($memoryFailure !== null) {
            throw new MemoryRollbackFailedException($memoryFailure);
        }
    }

    /**
     * Makes an announcement now, or once the transaction it was made under commits.
     *
     * Held on the innermost open level of the current connection: a committed nested level
     * hands it to its parent, the outermost commit makes it, a rollback drops it. With no open
     * level - no transaction, no connection at all, or only a level whose commit already failed
     * and whose writes are gone - it is made at once, as every announcement was before there
     * were transactions to wait for. What the announcement raises reaches whoever made it run:
     * the caller here when it runs at once, the caller of the commit that released it otherwise.
     * Its pair for the memory a write changed is {@see self::onRollback()}.
     *
     * @param Closure $announce Announcement to make
     */
    public static function afterCommit(Closure $announce): void
    {
        $levels = self::$transactions[self::$currentIndex] ?? [];
        for ($position = count($levels) - 1; $position >= 0; $position--) {
            if (!$levels[$position]->isFailed()) {
                $levels[$position]->hold($announce);

                return;
            }
        }

        $announce();
    }

    /**
     * Remembers how to put back a change to this process's memory, should the transaction not commit.
     *
     * The pair of {@see self::afterCommit()}: that one holds what is to happen once the writes
     * stand, this one what is to be undone if they do not. Kept on the innermost open level of
     * the current connection: a committed nested level hands it to its parent, the outermost
     * commit throws it away, a rollback - and a failed commit, a lost connection, a handler that
     * left the transaction open - runs it, the steps in the reverse order of the writes. With no
     * open level nothing is kept: outside a transaction a write stands the moment it is made.
     *
     * The write doors of the ORM and of the runtime call it for the memory they change; project
     * code calls it for memory of its own that has to go back with the database.
     *
     * @param Closure $undo Step putting back what the write changed in memory
     */
    public static function onRollback(Closure $undo): void
    {
        $levels = self::$transactions[self::$currentIndex] ?? [];
        for ($position = count($levels) - 1; $position >= 0; $position--) {
            if (!$levels[$position]->isFailed()) {
                $levels[$position]->remember($undo);

                return;
            }
        }
    }

    /**
     * Closes every transaction a handler left open, on every connection, and says so.
     *
     * Called by the framework at the end of a handler - one unit of a worker's tick, one CLI
     * command, a test's tearDown. The held announcements are dropped, the memory every level
     * changed goes back - innermost level first, a failing step logged - the outermost level is
     * rolled back where it reached the server and still stands on a live connection, and the
     * stacks are emptied, so the next handler starts clean whatever this one did. Nothing is
     * raised here: what the failure means for its unit is the caller's to decide, so it is
     * handed back.
     *
     * @return ?TransactionLeftOpenException Failure naming every connection concerned, or null when nothing was left open
     */
    public static function rollBackLeftOpen(): ?TransactionLeftOpenException
    {
        $descriptions = [];
        $rollbackFailure = null;
        foreach (self::$transactions as $index => $levels) {
            if ($levels === []) {
                continue;
            }

            $heldCount = 0;
            for ($position = count($levels) - 1; $position >= 0; $position--) {
                $heldCount += count($levels[$position]->takeHeld());
                self::restoreMemory($index, $position + 1, $levels[$position]);
            }
            $descriptions[] = TransactionLeftOpenException::describe($index, count($levels), $heldCount);

            $mysqli = self::$connections[$index] ?? null;
            if ($mysqli !== null && $levels[0]->isOpened() && !$levels[0]->isFailed()) {
                try {
                    mysqli_rollback($mysqli);
                } catch (mysqli_sql_exception $e) {
                    try {
                        MysqlExceptionMapper::runtimeException($e->getCode(), $e->getMessage(), DatabaseSql::ROLLBACK);
                    } catch (DatabaseRuntimeException $mapped) {
                        $rollbackFailure ??= $mapped;
                    }
                }
            }
            self::$transactions[$index] = [];
        }

        return $descriptions === []
            ? null
            : TransactionLeftOpenException::forConnections($descriptions, $rollbackFailure);
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

        $positions = SqlPlaceholders::positions($sql);
        $placeholderCount = count($positions);
        $paramCount = count($params);

        if ($placeholderCount < $paramCount) {
            throw new DatabaseParamsException(
                "Too many parameters provided for query: {$placeholderCount} placeholders found, {$paramCount} parameters given",
            );
        }

        if ($placeholderCount > $paramCount) {
            throw new DatabaseParamsException(
                "Not enough parameters provided for query: {$placeholderCount} placeholders found, {$paramCount} parameters given",
            );
        }

        $parsedSql = '';
        $copiedUpTo = 0;

        foreach ($positions as $paramIndex => $position) {
            $param = $params[$paramIndex];
            $value = $param->value;
            $parsedSql .= substr($sql, $copiedUpTo, $position - $copiedUpTo);

            // Escape value
            if ($value === null) {
                $parsedSql .= DatabaseSql::SQL_NULL;
            } elseif ($param->type->isNumeric()) {
                $parsedSql .= $value;
            } else {
                $parsedSql .= "'" . mysqli_real_escape_string($mysqli, (string)$value) . "'";
            }

            $copiedUpTo = $position + 1;
        }

        return $parsedSql . substr($sql, $copiedUpTo);
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

    /**
     * Opens the MySQL part of every level of the connection that has not reached the server yet.
     *
     * Called before the first query: BEGIN for the outermost level, then SAVEPOINT for each nested
     * level, in the order they were started. A level is marked open once its statement stands;
     * one the server refuses stays unopened - and so does every level after it - and the failure
     * reaches the caller of the query, as a refused start reached the caller of the start before.
     * A failed level is skipped: its transaction is gone, and its caller's rollback closes it.
     *
     * @param int $index Connection index the levels belong to
     * @param mysqli $mysqli Connection the query is about to be sent on
     * @throws DatabaseRuntimeException When the server refuses BEGIN or a savepoint
     */
    private static function openPendingLevels(int $index, mysqli $mysqli): void
    {
        foreach (self::$transactions[$index] ?? [] as $level) {
            if ($level->isOpened() || $level->isFailed()) {
                continue;
            }
            try {
                if ($level->savepoint === null) {
                    mysqli_begin_transaction($mysqli);
                } else {
                    mysqli_savepoint($mysqli, $level->savepoint);
                }
            } catch (mysqli_sql_exception $e) {
                MysqlExceptionMapper::runtimeException(
                    $e->getCode(),
                    $e->getMessage(),
                    $level->savepoint === null ? DatabaseSql::START_TRANSACTION : DatabaseSql::SAVEPOINT,
                );
            }
            $level->markOpened();
        }
    }

    /**
     * Rolls back the level whose commit just failed, puts its memory back and leaves it standing as failed.
     *
     * The failures of the cleanup are dropped - a failing memory step is logged by the journal -
     * because the caller is owed the failure of the commit, and the level is closed by the
     * caller's rollback either way.
     *
     * @param mysqli $mysqli Connection the level is on
     * @param int $index Connection index the level is on
     * @param int $depth Depth of the level, the outermost being 1
     * @param TransactionLevel $level Level whose commit failed
     */
    private static function rollBackFailedCommit(mysqli $mysqli, int $index, int $depth, TransactionLevel $level): void
    {
        $level->dropHeld();
        $level->markFailed();
        self::restoreMemory($index, $depth, $level);
        try {
            if ($level->savepoint === null) {
                mysqli_rollback($mysqli);
            } else {
                mysqli_query($mysqli, DatabaseSql::rollbackToSavepoint($level->savepoint));
            }
        } catch (mysqli_sql_exception) {
            // The caller is owed the failure of the commit, not of this cleanup
        }
    }

    /**
     * Runs the memory journal of a level that is not going to commit, newest step first.
     *
     * A failing step does not stop the others: every step runs, memory half put back being worse
     * than any error. Every failure but the first is logged as it happens, and when any step
     * failed one line names the connection, the level and how many steps did not get their
     * memory back. The first failure is handed back for the caller to raise or drop.
     *
     * @param int $index Connection index the level is on
     * @param int $depth Depth of the level, the outermost being 1
     * @param TransactionLevel $level Level whose journal runs; it keeps none of it afterwards
     * @return ?Throwable The first failure a step raised, or null when every step ran
     */
    private static function restoreMemory(int $index, int $depth, TransactionLevel $level): ?Throwable
    {
        $steps = array_reverse($level->takeUndo());
        $first = null;
        $failed = 0;
        foreach ($steps as $undo) {
            try {
                $undo();
            } catch (Throwable $failure) {
                $failed++;
                if ($first === null) {
                    $first = $failure;
                    continue;
                }
                Logger::error("A step restoring memory on a rollback failed (connection {$index}, level {$depth}): "
                    . $failure->getMessage());
            }
        }

        if ($first !== null) {
            Logger::error("A rollback of level {$depth} on connection {$index} left {$failed} of " . count($steps)
                . ' memory steps unrestored; the first failure: ' . $first->getMessage());
        }

        return $first;
    }

    /**
     * Makes the announcements a committed transaction held, in the order they were held.
     *
     * The commit already stands, so a failing announcement does not stop the others: every one
     * is made, the first failure is raised afterwards and the rest are logged - swallowed, one
     * would be a sync that vanished without a trace. A Hilos exception is raised as it is; anything
     * else is wrapped, so the commit keeps the one contract its callers catch by.
     *
     * @param list<Closure> $announcements Announcements to make
     * @throws HilosException The first failure an announcement raised
     */
    private static function release(array $announcements): void
    {
        $first = null;
        foreach ($announcements as $announce) {
            try {
                $announce();
            } catch (Throwable $failure) {
                if ($first === null) {
                    $first = $failure;
                    continue;
                }
                Logger::error('An announcement held for the commit failed after it: ' . $failure->getMessage());
            }
        }

        if ($first instanceof HilosException) {
            throw $first;
        }
        if ($first !== null) {
            throw new AnnouncementFailedException($first);
        }
    }
}
