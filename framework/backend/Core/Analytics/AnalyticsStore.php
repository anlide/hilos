<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;

/**
 * The one home of the analytics SQL: every statement that reads or writes an analytics table.
 *
 * This subsystem deliberately bypasses the Hilos ORM and issues raw `Database::sql()`
 * statements. It is high-frequency telemetry whose shape the row-oriented ORM does not serve
 * well: dictionary tables deduplicated by SHA-1 hash via `INSERT IGNORE`, and fact rows
 * accumulated in memory then written in multi-row inserts.
 *
 * Two callers write through it. The writer agent loads the journal files of its node
 * ({@see AnalyticsJournalLoader}); the master process still writes its own facts here
 * synchronously, until HIL-1156 hands them to the journal too. The store itself never decides
 * a moment: every method takes the time of the event as a parameter, because a journal record
 * is loaded long after it happened and its row must carry the moment at the source.
 *
 * A session of a worker or of an agent is found by the key its process drew
 * ({@see AnalyticsJournalRecord::SESSION_KEY_PATTERN}): the insert is an upsert on that key, so
 * describing a session twice costs nothing and yields the same row.
 *
 * The numbers the store learned are cached ({@see AnalyticsIdCache}). Inside a transaction the
 * cache is staged: {@see self::beginStaging()} sets the committed cache aside, and only
 * {@see self::commitStaging()} keeps what was learned since; {@see self::discardStaging()}
 * takes the committed cache back, so a number born in a rolled-back transaction never names a
 * row later.
 *
 * Fact buffers are intentionally array-shaped (raw DB rows keyed by column name).
 */
final class AnalyticsStore
{
    /** @var int Fact rows written by one multi-row insert, and checked by one lookup of the master's rows */
    public const int FACT_PORTION_ROWS = 500;

    private const string TABLE_AGENT_USER_ACTION = 'hilos_analytics_agent_user_action';
    private const string TABLE_AGENT_SYSTEM_SIGNAL = 'hilos_analytics_agent_system_signal';
    private const string TABLE_AGENT_CRON_SIGNAL = 'hilos_analytics_agent_cron_signal';
    private const string TABLE_WORKER_SYSTEM_SIGNAL = 'hilos_analytics_worker_system_signal';
    private const string TABLE_API_AGENT_ACTION = 'hilos_analytics_api_agent_action';

    private const string COLUMN_USER_ACTION_ID = 'user_action_id';
    private const string COLUMN_API_REQUEST_ID = 'api_request_id';

    private AnalyticsIdCache $cache;

    /** @var ?AnalyticsIdCache The cache as it stood before the open transaction; null when none is staged */
    private ?AnalyticsIdCache $committedCache = null;

    /** @var array<string, list<array<string, int|string|null>>> Fact rows waiting for {@see self::flushFacts()}, by table */
    private array $facts = [];

    public function __construct()
    {
        $this->cache = new AnalyticsIdCache();
    }

    /**
     * Sets the committed cache aside: what is learned from here on is kept only by {@see self::commitStaging()}.
     *
     * Called right after the transaction opens. A stage the last transaction left open - a failure
     * its caller did not catch - is discarded first: the framework rolled that transaction back.
     * Memory only; cannot fail.
     */
    public function beginStaging(): void
    {
        $this->discardStaging();
        $this->committedCache = clone $this->cache;
    }

    /**
     * Keeps what the committed transaction taught the cache.
     */
    public function commitStaging(): void
    {
        $this->committedCache = null;
    }

    /**
     * Takes the committed cache back after a rollback, and drops the fact rows the transaction buffered.
     */
    public function discardStaging(): void
    {
        if ($this->committedCache !== null) {
            $this->cache = $this->committedCache;
            $this->committedCache = null;
        }

        $this->facts = [];
    }

    /**
     * Forgets every number of the database the store wrote into, and the fact rows that reference them.
     *
     * Called when that database was replaced under the process: a row of the restored database may
     * take a number the cache still holds for another value, and a stale id then files facts under
     * the wrong name with no error at all. Memory only; cannot fail.
     */
    public function forgetAll(): void
    {
        $this->cache = new AnalyticsIdCache();
        $this->committedCache = null;
        $this->facts = [];
    }

    /**
     * Inserts the worker session named by the key, or finds the row an earlier description wrote.
     *
     * @param string $key Session key of the worker
     * @param int $workerIndex Worker index within the daemon
     * @param bool $monopolistic Whether the worker runs monopolistic agents
     * @param int $startedTs Moment the session started, in milliseconds
     * @return int Worker session id
     * @throws DatabaseException When the upsert fails
     */
    public function ensureWorkerSession(string $key, int $workerIndex, bool $monopolistic, int $startedTs): int
    {
        if (isset($this->cache->workerSessionIds[$key])) {
            return $this->cache->workerSessionIds[$key];
        }

        Database::sql(
            'INSERT INTO `hilos_analytics_worker_session`
                (`session_key`, `worker_index`, `is_monopolistic`, `started_ts`, `stopped_ts`)
             VALUES (UNHEX(?), ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)',
            [$key, $workerIndex, $monopolistic ? 1 : 0, $startedTs],
        );

        $id = Database::lastInsertId();
        $this->cache->workerSessionIds[$key] = $id;

        return $id;
    }

    /**
     * Inserts the agent session named by the key under a worker session, or finds the row already written.
     *
     * @param string $key Session key of the agent
     * @param int $workerSessionId Worker session the agent lives on
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     * @param int $startedTs Moment the session started, in milliseconds
     * @return int Agent session id
     * @throws DatabaseException When the upsert fails
     */
    public function ensureAgentSession(string $key, int $workerSessionId, string $agentType, ?string $agentIndex, int $startedTs): int
    {
        if (isset($this->cache->agentSessionIds[$key])) {
            return $this->cache->agentSessionIds[$key];
        }

        Database::sql(
            'INSERT INTO `hilos_analytics_agent_session`
                (`session_key`, `worker_session_id`, `agent_type`, `agent_index`, `started_ts`, `stopped_ts`)
             VALUES (UNHEX(?), ?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)',
            [$key, $workerSessionId, $agentType, $agentIndex, $startedTs],
        );

        $id = Database::lastInsertId();
        $this->cache->agentSessionIds[$key] = $id;

        return $id;
    }

    /**
     * Finds the worker session row named by the key.
     *
     * @param string $key Session key of the worker
     * @return ?int Worker session id, or null when no row carries the key
     * @throws DatabaseException When the lookup fails
     */
    public function findWorkerSession(string $key): ?int
    {
        return $this->cache->workerSessionIds[$key]
            ?? $this->findSessionByKey('hilos_analytics_worker_session', $key, $this->cache->workerSessionIds);
    }

    /**
     * Finds the agent session row named by the key.
     *
     * @param string $key Session key of the agent
     * @return ?int Agent session id, or null when no row carries the key
     * @throws DatabaseException When the lookup fails
     */
    public function findAgentSession(string $key): ?int
    {
        return $this->cache->agentSessionIds[$key]
            ?? $this->findSessionByKey('hilos_analytics_agent_session', $key, $this->cache->agentSessionIds);
    }

    /**
     * Stamps a worker session stopped, once: a later stop of the same session changes nothing.
     *
     * @param int $workerSessionId Worker session id
     * @param int $ts Moment the worker stopped, in milliseconds
     * @throws DatabaseException When the update fails
     */
    public function stopWorkerSession(int $workerSessionId, int $ts): void
    {
        Database::sql(
            'UPDATE `hilos_analytics_worker_session` SET `stopped_ts` = ? WHERE `id` = ? AND `stopped_ts` IS NULL',
            [$ts, $workerSessionId],
        );
    }

    /**
     * Stamps an agent session stopped, once: a later stop of the same session changes nothing.
     *
     * @param int $agentSessionId Agent session id
     * @param int $ts Moment the agent stopped, in milliseconds
     * @throws DatabaseException When the update fails
     */
    public function stopAgentSession(int $agentSessionId, int $ts): void
    {
        Database::sql(
            'UPDATE `hilos_analytics_agent_session` SET `stopped_ts` = ? WHERE `id` = ? AND `stopped_ts` IS NULL',
            [$ts, $agentSessionId],
        );
    }

    /**
     * Creates or refreshes the browser session for the token and returns its id.
     *
     * On an existing session, records user-agent and accept-language changes in their history
     * tables and moves the current values. `last_seen_ts` never moves back: events of different
     * processes reach the writer out of order.
     *
     * @param string $sessionToken Browser session token
     * @param ?string $userAgent Raw User-Agent header, or null
     * @param ?string $acceptLanguage Raw Accept-Language header, or null
     * @param int $ts Moment of the event, in milliseconds
     * @return int Browser session id
     * @throws DatabaseException When a statement fails
     */
    public function ensureBrowserSession(string $sessionToken, ?string $userAgent, ?string $acceptLanguage, int $ts): int
    {
        $existing = $this->loadBrowserSession($sessionToken);
        $userAgentId = $this->ensureUserAgent($userAgent, $ts);
        $acceptLanguageId = $this->ensureAcceptLanguage($acceptLanguage, $ts);

        if ($existing === null) {
            // Two handshakes of the same visitor can reach the database apart at once, and the
            // token is unique, so the loser of that race must be handed the row the winner
            // created instead of an error. LAST_INSERT_ID(`id`) is what makes lastInsertId()
            // answer with the existing id on the duplicate branch.
            Database::sql(
                'INSERT INTO `hilos_analytics_browser_session`
                    (`session_token`, `user_identity_type`, `user_identity_value`,
                     `current_user_agent_id`, `current_accept_language_id`, `first_seen_ts`, `last_seen_ts`)
                 VALUES (?, NULL, NULL, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`), `last_seen_ts` = GREATEST(`last_seen_ts`, VALUES(`last_seen_ts`))',
                [$sessionToken, $userAgentId, $acceptLanguageId, $ts, $ts],
            );

            $id = Database::lastInsertId();
            $this->cache->browserSessions[$sessionToken] = new BrowserSessionState($id, $userAgentId, $acceptLanguageId);

            return $id;
        }

        $updates = ['`last_seen_ts` = GREATEST(`last_seen_ts`, ?)'];
        $params = [$ts];
        $currentUserAgentId = $existing->currentUserAgentId;
        $currentAcceptLanguageId = $existing->currentAcceptLanguageId;

        if ($userAgentId !== null && $currentUserAgentId !== $userAgentId) {
            Database::sql(
                'INSERT INTO `hilos_analytics_browser_session_user_agent_change`
                    (`browser_session_id`, `old_user_agent_id`, `new_user_agent_id`, `changed_ts`)
                 VALUES (?, ?, ?, ?)',
                [$existing->id, $currentUserAgentId, $userAgentId, $ts],
            );
            $updates[] = '`current_user_agent_id` = ?';
            $params[] = $userAgentId;
            $currentUserAgentId = $userAgentId;
        }

        if ($acceptLanguageId !== null && $currentAcceptLanguageId !== $acceptLanguageId) {
            Database::sql(
                'INSERT INTO `hilos_analytics_browser_session_accept_language_change`
                    (`browser_session_id`, `old_accept_language_id`, `new_accept_language_id`, `changed_ts`)
                 VALUES (?, ?, ?, ?)',
                [$existing->id, $currentAcceptLanguageId, $acceptLanguageId, $ts],
            );
            $updates[] = '`current_accept_language_id` = ?';
            $params[] = $acceptLanguageId;
            $currentAcceptLanguageId = $acceptLanguageId;
        }

        $params[] = $existing->id;
        Database::sql(
            'UPDATE `hilos_analytics_browser_session` SET ' . implode(', ', $updates) . ' WHERE `id` = ?',
            $params,
        );

        $this->cache->browserSessions[$sessionToken] = new BrowserSessionState(
            $existing->id,
            $currentUserAgentId,
            $currentAcceptLanguageId,
        );

        return $existing->id;
    }

    /**
     * Persists an external identity (type/value) on the browser session, opening the session when it has none.
     *
     * @param string $sessionToken Browser session token
     * @param string $type Identity type tag
     * @param string $value Identity value
     * @param int $ts Moment of the identification, in milliseconds
     * @throws DatabaseException When a statement fails
     */
    public function setBrowserSessionIdentity(string $sessionToken, string $type, string $value, int $ts): void
    {
        $browserSessionId = $this->ensureBrowserSession($sessionToken, null, null, $ts);

        Database::sql(
            'UPDATE `hilos_analytics_browser_session`
             SET `user_identity_type` = ?, `user_identity_value` = ?, `last_seen_ts` = GREATEST(`last_seen_ts`, ?)
             WHERE `id` = ?',
            [$type, $value, $ts, $browserSessionId],
        );
    }

    /**
     * Moves a browser session onto the token a login rotated it to (HIL-582).
     *
     * The analytics session is named by the secret rather than by the application session's id,
     * so a rotation that changes only the secret would strand everything collected before the
     * login under a token nobody presents again. A token whose session was never opened is
     * nothing to rename. A session already under the new token - an event of the new token
     * reached the database first - is not touched: the visit stays split rather than the
     * unique token refusing the whole write.
     *
     * @param string $oldToken Token the session answered to before the rotation
     * @param string $newToken Token the session answers to now
     * @param int $ts Moment of the rotation, in milliseconds
     * @return BrowserSessionRename What was done
     * @throws DatabaseException When a statement fails
     */
    public function renameBrowserSession(string $oldToken, string $newToken, int $ts): BrowserSessionRename
    {
        $session = $this->loadBrowserSession($oldToken);
        if ($session === null) {
            return BrowserSessionRename::ABSENT;
        }

        if ($this->loadBrowserSession($newToken) !== null) {
            return BrowserSessionRename::CONFLICT;
        }

        Database::sql(
            'UPDATE `hilos_analytics_browser_session`
             SET `session_token` = ?, `last_seen_ts` = GREATEST(`last_seen_ts`, ?)
             WHERE `id` = ?',
            [$newToken, $ts, $session->id],
        );

        unset($this->cache->browserSessions[$oldToken]);
        $this->cache->browserSessions[$newToken] = $session;

        return BrowserSessionRename::RENAMED;
    }

    /**
     * Opens a WebSocket connection row without an owner and caches it by its accept key.
     *
     * @param string $acceptKey WebSocket accept key
     * @param ?string $clientIp Client IP address (IPv4 or IPv6), or null when unknown
     * @param int $ts Moment the connection opened, in milliseconds
     * @return int WS connection id
     * @throws DatabaseException When the insert fails
     */
    public function openWsConnection(string $acceptKey, ?string $clientIp, int $ts): int
    {
        $ip = $clientIp !== null ? $this->parseIp($clientIp) : new ParsedIp(null, null);

        Database::sql(
            'INSERT INTO `hilos_analytics_ws_connection`
                (`browser_session_id`, `accept_key`, `opened_ipv4`, `opened_ipv6`, `opened_ts`, `closed_ts`)
             VALUES (NULL, ?, ?, UNHEX(?), ?, NULL)',
            [$acceptKey, $ip->ipv4, $ip->ipv6Hex, $ts],
        );

        $id = Database::lastInsertId();
        $this->cache->wsConnections[$acceptKey] = new WsConnectionState($id, $ip->ipv4, $ip->ipv6Hex);

        return $id;
    }

    /**
     * Gives the WebSocket connection row the browser session it belongs to.
     *
     * The connection is found by its accept key rather than by a cached id: the master opened the
     * row, and its cache is not this process's. The key is unique in the table.
     *
     * @param string $acceptKey WebSocket accept key
     * @param int $browserSessionId Browser session the connection belongs to
     * @throws DatabaseException When the update fails
     */
    public function attachWsConnection(string $acceptKey, int $browserSessionId): void
    {
        Database::sql(
            'UPDATE `hilos_analytics_ws_connection` SET `browser_session_id` = ? WHERE `accept_key` = ?',
            [$browserSessionId, $acceptKey],
        );
    }

    /**
     * Records IPv4/IPv6 changes of an open WS connection against its cached state; nothing for an unknown key.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $clientIp Current client IP address
     * @param int $ts Moment of the change, in milliseconds
     * @throws DatabaseException When an insert fails
     */
    public function trackWsConnectionIpChange(string $acceptKey, string $clientIp, int $ts): void
    {
        $connection = $this->cache->wsConnections[$acceptKey] ?? null;
        if ($connection === null) {
            return;
        }

        $parsed = $this->parseIp($clientIp);

        if ($connection->currentIpv4 !== $parsed->ipv4) {
            Database::sql(
                'INSERT INTO `hilos_analytics_ws_connection_ipv4_change`
                    (`ws_connection_id`, `old_ipv4`, `new_ipv4`, `changed_ts`)
                 VALUES (?, ?, ?, ?)',
                [$connection->id, $connection->currentIpv4, $parsed->ipv4, $ts],
            );
        }

        if ($connection->currentIpv6Hex !== $parsed->ipv6Hex) {
            Database::sql(
                'INSERT INTO `hilos_analytics_ws_connection_ipv6_change`
                    (`ws_connection_id`, `old_ipv6`, `new_ipv6`, `changed_ts`)
                 VALUES (?, UNHEX(?), UNHEX(?), ?)',
                [$connection->id, $connection->currentIpv6Hex, $parsed->ipv6Hex, $ts],
            );
        }

        $this->cache->wsConnections[$acceptKey] = new WsConnectionState($connection->id, $parsed->ipv4, $parsed->ipv6Hex);
    }

    /**
     * Marks an open WS connection closed; nothing for an unknown key.
     *
     * @param string $acceptKey WebSocket accept key
     * @param int $ts Moment the connection closed, in milliseconds
     * @throws DatabaseException When the update fails
     */
    public function closeWsConnection(string $acceptKey, int $ts): void
    {
        $connection = $this->cache->wsConnections[$acceptKey] ?? null;
        if ($connection === null) {
            return;
        }

        Database::sql(
            'UPDATE `hilos_analytics_ws_connection` SET `closed_ts` = ? WHERE `id` = ?',
            [$ts, $connection->id],
        );
    }

    /**
     * Opens a page session on a WS connection, closing any prior one first.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $pageName Page name being opened
     * @param ?array<string, mixed> $params Page route params, or null
     * @param int $ts Moment the page opened, in milliseconds
     * @return ?int Page session id, or null when the connection is unknown
     * @throws DatabaseException When a statement fails
     */
    public function openPageSession(string $acceptKey, string $pageName, ?array $params, int $ts): ?int
    {
        $connection = $this->cache->wsConnections[$acceptKey] ?? null;
        if ($connection === null) {
            return null;
        }

        $this->closePageSession($acceptKey, $ts);

        Database::sql(
            'INSERT INTO `hilos_analytics_page_session`
                (`ws_connection_id`, `page_id`, `page_params_id`, `opened_ts`, `closed_ts`)
             VALUES (?, ?, ?, ?, NULL)',
            [$connection->id, $this->ensurePage($pageName, $ts), $this->ensurePageParams($params, $ts), $ts],
        );

        $id = Database::lastInsertId();
        $this->cache->pageSessions[$acceptKey] = $id;

        return $id;
    }

    /**
     * Updates the route params of the current page session; nothing when none is open.
     *
     * @param string $acceptKey WebSocket accept key
     * @param ?array<string, mixed> $params New page route params, or null
     * @param int $ts Moment of the update, in milliseconds
     * @throws DatabaseException When a statement fails
     */
    public function updatePageSession(string $acceptKey, ?array $params, int $ts): void
    {
        $pageSessionId = $this->cache->pageSessions[$acceptKey] ?? null;
        if ($pageSessionId === null) {
            return;
        }

        Database::sql(
            'UPDATE `hilos_analytics_page_session` SET `page_params_id` = ? WHERE `id` = ?',
            [$this->ensurePageParams($params, $ts), $pageSessionId],
        );
    }

    /**
     * Marks the current page session closed and drops it from the cache; nothing when none is open.
     *
     * @param string $acceptKey WebSocket accept key
     * @param int $ts Moment the page closed, in milliseconds
     * @throws DatabaseException When the update fails
     */
    public function closePageSession(string $acceptKey, int $ts): void
    {
        $pageSessionId = $this->cache->pageSessions[$acceptKey] ?? null;
        if ($pageSessionId === null) {
            return;
        }

        Database::sql(
            'UPDATE `hilos_analytics_page_session` SET `closed_ts` = ? WHERE `id` = ?',
            [$ts, $pageSessionId],
        );

        unset($this->cache->pageSessions[$acceptKey]);
    }

    /**
     * Writes a user action against the WS connection and its current page session.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $actionName Client action name
     * @param ?array<string, mixed> $payload Payload already masked by the caller, or null
     * @param int $ts Moment of the action, in milliseconds
     * @return ?int User action id, or null when the connection is unknown
     * @throws DatabaseException When a statement fails
     */
    public function insertUserAction(string $acceptKey, string $actionName, ?array $payload, int $ts): ?int
    {
        $connection = $this->cache->wsConnections[$acceptKey] ?? null;
        if ($connection === null) {
            return null;
        }

        Database::sql(
            'INSERT INTO `hilos_analytics_user_action`
                (`ws_connection_id`, `page_session_id`, `action_name_id`, `payload_json_id`, `created_ts`)
             VALUES (?, ?, ?, ?, ?)',
            [
                $connection->id,
                $this->cache->pageSessions[$acceptKey] ?? null,
                $this->ensureNamedDictionaryValue($actionName, 'hilos_analytics_action_name', $this->cache->actionNameIds, $ts),
                $this->ensurePayloadJson($payload, $ts),
                $ts,
            ],
        );

        return Database::lastInsertId();
    }

    /**
     * Opens an API request row, resolving its browser session.
     *
     * @param ?string $sessionToken Browser session token, or null for anonymous
     * @param string $method HTTP method
     * @param string $path Request path
     * @param ?array<string, mixed> $params Request params, or null
     * @param ?string $userAgent Raw User-Agent header, or null
     * @param ?string $acceptLanguage Raw Accept-Language header, or null
     * @param int $ts Moment the request started, in milliseconds
     * @return int API request id
     * @throws DatabaseException When a statement fails
     */
    public function startApiRequest(
        ?string $sessionToken,
        string $method,
        string $path,
        ?array $params,
        ?string $userAgent,
        ?string $acceptLanguage,
        int $ts,
    ): int {
        $browserSessionId = $sessionToken === null || $sessionToken === ''
            ? null
            : $this->ensureBrowserSession($sessionToken, $userAgent, $acceptLanguage, $ts);

        Database::sql(
            'INSERT INTO `hilos_analytics_api_request`
                (`browser_session_id`, `method`, `path`, `params_json_id`, `status_code`, `duration_ms`, `started_ts`, `finished_ts`)
             VALUES (?, ?, ?, ?, NULL, NULL, ?, NULL)',
            [$browserSessionId, $method, $path, $this->ensurePageParams($params, $ts), $ts],
        );

        return Database::lastInsertId();
    }

    /**
     * Finalizes an API request row with status, duration and finish time.
     *
     * @param int $apiRequestId API request id
     * @param ?int $statusCode HTTP status code, or null
     * @param ?int $durationMs Request duration in milliseconds, or null
     * @param int $ts Moment the request finished, in milliseconds
     * @throws DatabaseException When the update fails
     */
    public function finishApiRequest(int $apiRequestId, ?int $statusCode, ?int $durationMs, int $ts): void
    {
        Database::sql(
            'UPDATE `hilos_analytics_api_request`
             SET `status_code` = ?, `duration_ms` = ?, `finished_ts` = ?
             WHERE `id` = ?',
            [$statusCode, $durationMs, $ts, $apiRequestId],
        );
    }

    /**
     * Buffers an agent reaction to a user action until {@see self::flushFacts()}.
     *
     * @param int $agentSessionId Agent session id
     * @param ?int $userActionId User action row the master wrote, or null when uncorrelated
     * @param string $signalName Signal name handled by the agent
     * @param ?array<string, mixed> $payload Payload already masked by the source, or null
     * @param int $ts Moment of the event, in milliseconds
     * @throws DatabaseException When a dictionary value cannot be written
     */
    public function addAgentUserAction(int $agentSessionId, ?int $userActionId, string $signalName, ?array $payload, int $ts): void
    {
        $this->facts[self::TABLE_AGENT_USER_ACTION][] = [
            'agent_session_id' => $agentSessionId,
            self::COLUMN_USER_ACTION_ID => $userActionId,
            'signal_name_id' => $this->ensureNamedDictionaryValue($signalName, 'hilos_analytics_signal_name', $this->cache->signalNameIds, $ts),
            'payload_json_id' => $this->ensurePayloadJson($payload, $ts),
            'created_ts' => $ts,
        ];
    }

    /**
     * Buffers a system signal delivered to an agent until {@see self::flushFacts()}.
     *
     * @param int $agentSessionId Agent session id
     * @param string $signalName System signal name
     * @param ?array<string, mixed> $payload Payload, or null
     * @param int $ts Moment of the event, in milliseconds
     * @throws DatabaseException When a dictionary value cannot be written
     */
    public function addAgentSystemSignal(int $agentSessionId, string $signalName, ?array $payload, int $ts): void
    {
        $this->facts[self::TABLE_AGENT_SYSTEM_SIGNAL][] = [
            'agent_session_id' => $agentSessionId,
            'signal_name_id' => $this->ensureNamedDictionaryValue($signalName, 'hilos_analytics_signal_name', $this->cache->signalNameIds, $ts),
            'payload_json_id' => $this->ensurePayloadJson($payload, $ts),
            'created_ts' => $ts,
        ];
    }

    /**
     * Buffers a cron signal delivered to an agent until {@see self::flushFacts()}.
     *
     * @param int $agentSessionId Agent session id
     * @param string $cronName Cron job name
     * @param ?array<string, mixed> $payload Payload, or null
     * @param int $ts Moment of the event, in milliseconds
     * @throws DatabaseException When a dictionary value cannot be written
     */
    public function addAgentCronSignal(int $agentSessionId, string $cronName, ?array $payload, int $ts): void
    {
        $this->facts[self::TABLE_AGENT_CRON_SIGNAL][] = [
            'agent_session_id' => $agentSessionId,
            'cron_name_id' => $this->ensureNamedDictionaryValue($cronName, 'hilos_analytics_cron_name', $this->cache->cronNameIds, $ts),
            'payload_json_id' => $this->ensurePayloadJson($payload, $ts),
            'created_ts' => $ts,
        ];
    }

    /**
     * Buffers a system signal delivered to a worker until {@see self::flushFacts()}.
     *
     * @param int $workerSessionId Worker session id
     * @param string $signalName System signal name
     * @param ?array<string, mixed> $payload Payload, or null
     * @param int $ts Moment of the event, in milliseconds
     * @throws DatabaseException When a dictionary value cannot be written
     */
    public function addWorkerSystemSignal(int $workerSessionId, string $signalName, ?array $payload, int $ts): void
    {
        $this->facts[self::TABLE_WORKER_SYSTEM_SIGNAL][] = [
            'worker_session_id' => $workerSessionId,
            'signal_name_id' => $this->ensureNamedDictionaryValue($signalName, 'hilos_analytics_signal_name', $this->cache->signalNameIds, $ts),
            'payload_json_id' => $this->ensurePayloadJson($payload, $ts),
            'created_ts' => $ts,
        ];
    }

    /**
     * Buffers a signal an agent received inside an HTTP request until {@see self::flushFacts()}.
     *
     * @param int $apiRequestId API request row the master wrote
     * @param int $agentSessionId Agent session id
     * @param string $signalName Signal name dispatched to the agent
     * @param ?array<string, mixed> $payload Payload already masked by the source, or null
     * @param int $ts Moment of the event, in milliseconds
     * @throws DatabaseException When a dictionary value cannot be written
     */
    public function addApiAgentAction(int $apiRequestId, int $agentSessionId, string $signalName, ?array $payload, int $ts): void
    {
        $this->facts[self::TABLE_API_AGENT_ACTION][] = [
            self::COLUMN_API_REQUEST_ID => $apiRequestId,
            'agent_session_id' => $agentSessionId,
            'signal_name_id' => $this->ensureNamedDictionaryValue($signalName, 'hilos_analytics_signal_name', $this->cache->signalNameIds, $ts),
            'payload_json_id' => $this->ensurePayloadJson($payload, $ts),
            'created_ts' => $ts,
        ];
    }

    /**
     * @return int Fact rows buffered and not yet written, across all tables
     */
    public function pendingFactCount(): int
    {
        $count = 0;
        foreach ($this->facts as $rows) {
            $count += count($rows);
        }

        return $count;
    }

    /**
     * Writes the buffered fact rows in multi-row inserts of at most {@see self::FACT_PORTION_ROWS}.
     *
     * Before each insert the rows of the master process the portion names are looked up, one
     * select per table for the whole portion: the master writes them synchronously before the
     * signal leaves, but a restore may have taken them since. A vanished user action leaves its
     * reaction uncorrelated; a vanished API request takes its row with it, since the row means
     * nothing without the request it belongs to.
     *
     * The buffer is emptied before the first insert, so a failure does not write the same rows
     * twice on the next flush.
     *
     * @return int Rows dropped because the API request they belong to is gone
     * @throws DatabaseException When a lookup or an insert fails
     */
    public function flushFacts(): int
    {
        $facts = $this->facts;
        $this->facts = [];

        $dropped = 0;
        foreach ($facts as $table => $rows) {
            foreach (array_chunk($rows, self::FACT_PORTION_ROWS) as $portion) {
                $kept = $this->keepRowsOfLiveMasterRows($portion);
                $dropped += count($portion) - count($kept);
                $this->bulkInsert($table, $kept);
            }
        }

        return $dropped;
    }

    /**
     * Whether the writer already loaded the journal file.
     *
     * @param string $nodeId Cluster node id of the file's node, '' outside a cluster
     * @param string $fileName Name of the ready journal file
     * @return bool True when a row remembers the file
     * @throws DatabaseException When the lookup fails
     */
    public function isFileLoaded(string $nodeId, string $fileName): bool
    {
        Database::sql(
            'SELECT `id` FROM `hilos_analytics_journal_file` WHERE `node_id` = ? AND `file_name` = ? LIMIT 1',
            [$nodeId, $fileName],
        );

        return Database::row() !== null;
    }

    /**
     * Remembers a loaded journal file; called inside the transaction that wrote its rows.
     *
     * @param string $nodeId Cluster node id of the file's node, '' outside a cluster
     * @param string $fileName Name of the ready journal file
     * @param int $recordCount Records the file carried, its header aside
     * @param int $ts Moment of the load, in milliseconds
     * @throws DatabaseException When the insert fails, a second load of the same file among the causes
     */
    public function markFileLoaded(string $nodeId, string $fileName, int $recordCount, int $ts): void
    {
        Database::sql(
            'INSERT INTO `hilos_analytics_journal_file` (`node_id`, `file_name`, `record_count`, `loaded_ts`)
             VALUES (?, ?, ?, ?)',
            [$nodeId, $fileName, $recordCount, $ts],
        );
    }

    /**
     * Reads a session row by its key and caches what it found.
     *
     * @param string $table Session table
     * @param string $key Session key in hex
     * @param array<string, int> $cache Key-to-id cache of that table, updated in place
     * @return ?int Session id, or null when no row carries the key
     * @throws DatabaseException When the lookup fails
     */
    private function findSessionByKey(string $table, string $key, array &$cache): ?int
    {
        Database::sql(
            "SELECT `id` FROM `{$table}` WHERE `session_key` = UNHEX(?) LIMIT 1",
            [$key],
        );

        $id = Database::field('id');
        if ($id === null) {
            return null;
        }

        $cache[$key] = (int)$id;

        return (int)$id;
    }

    /**
     * Returns the browser session row of a token from the cache or the database.
     *
     * @param string $sessionToken Session token
     * @return ?BrowserSessionState Session state, or null when no row answers to the token
     * @throws DatabaseException When the lookup fails
     */
    private function loadBrowserSession(string $sessionToken): ?BrowserSessionState
    {
        if (isset($this->cache->browserSessions[$sessionToken])) {
            return $this->cache->browserSessions[$sessionToken];
        }

        Database::sql(
            'SELECT `id`, `current_user_agent_id`, `current_accept_language_id`
             FROM `hilos_analytics_browser_session`
             WHERE `session_token` = ?
             LIMIT 1',
            [$sessionToken],
        );

        $row = Database::row();
        if ($row === null) {
            return null;
        }

        $session = new BrowserSessionState(
            (int)$row['id'],
            isset($row['current_user_agent_id']) ? (int)$row['current_user_agent_id'] : null,
            isset($row['current_accept_language_id']) ? (int)$row['current_accept_language_id'] : null,
        );
        $this->cache->browserSessions[$sessionToken] = $session;

        return $session;
    }

    /**
     * Returns the dictionary id for a User-Agent value, inserting it on first use.
     *
     * @param ?string $value Raw User-Agent header; null/empty yields null
     * @param int $ts Moment of first use, in milliseconds
     * @return ?int Dictionary id, or null when empty
     * @throws DatabaseException When the dictionary cannot be written
     */
    private function ensureUserAgent(?string $value, int $ts): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->ensureHashedDictionaryValue('hilos_analytics_user_agent', 'value', $value, $this->cache->userAgentIds, $ts);
    }

    /**
     * Returns the dictionary id for an Accept-Language value, inserting it on first use.
     *
     * @param ?string $value Raw Accept-Language header; null/empty yields null
     * @param int $ts Moment of first use, in milliseconds
     * @return ?int Dictionary id, or null when empty
     * @throws DatabaseException When the dictionary cannot be written
     */
    private function ensureAcceptLanguage(?string $value, int $ts): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->ensureHashedDictionaryValue('hilos_analytics_accept_language', 'value', $value, $this->cache->acceptLanguageIds, $ts);
    }

    /**
     * Returns the dictionary id for a page name, inserting it on first use.
     *
     * @param string $pageName Page name
     * @param int $ts Moment of first use, in milliseconds
     * @return int Dictionary id
     * @throws DatabaseException When the dictionary cannot be written
     */
    private function ensurePage(string $pageName, int $ts): int
    {
        if (isset($this->cache->pageIds[$pageName])) {
            return $this->cache->pageIds[$pageName];
        }

        Database::sql(
            'INSERT IGNORE INTO `hilos_analytics_page` (`page_name`, `created_ts`) VALUES (?, ?)',
            [$pageName, $ts],
        );
        Database::sql(
            'SELECT `id` FROM `hilos_analytics_page` WHERE `page_name` = ? LIMIT 1',
            [$pageName],
        );

        $id = (int)Database::field('id');
        $this->cache->pageIds[$pageName] = $id;

        return $id;
    }

    /**
     * Returns the dictionary id for normalized page params, inserting it on first use.
     *
     * @param ?array<string, mixed> $params Page route params; null/empty yields null
     * @param int $ts Moment of first use, in milliseconds
     * @return ?int Dictionary id, or null when empty
     * @throws DatabaseException When the dictionary cannot be written
     */
    private function ensurePageParams(?array $params, int $ts): ?int
    {
        return $this->ensureJsonDictionaryValue($params, 'hilos_analytics_page_params', 'params_json', $this->cache->pageParamsIds, $ts);
    }

    /**
     * Returns the dictionary id for a normalized JSON payload, inserting it on first use.
     *
     * @param ?array<string, mixed> $payload Payload; null/empty yields null
     * @param int $ts Moment of first use, in milliseconds
     * @return ?int Dictionary id, or null when empty
     * @throws DatabaseException When the dictionary cannot be written
     */
    private function ensurePayloadJson(?array $payload, int $ts): ?int
    {
        return $this->ensureJsonDictionaryValue($payload, 'hilos_analytics_payload_json', 'payload_json', $this->cache->payloadIds, $ts);
    }

    /**
     * Returns the cached or inserted id for a name-keyed dictionary table.
     *
     * @param string $name Lookup name
     * @param string $table Dictionary table name
     * @param array<string, int> $cache By-name id cache, updated in place
     * @param int $ts Moment of first use, in milliseconds
     * @return int Dictionary id
     * @throws DatabaseException When the dictionary cannot be written
     */
    private function ensureNamedDictionaryValue(string $name, string $table, array &$cache, int $ts): int
    {
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        Database::sql(
            "INSERT IGNORE INTO `{$table}` (`name`, `created_ts`) VALUES (?, ?)",
            [$name, $ts],
        );
        Database::sql(
            "SELECT `id` FROM `{$table}` WHERE `name` = ? LIMIT 1",
            [$name],
        );

        $id = (int)Database::field('id');
        $cache[$name] = $id;

        return $id;
    }

    /**
     * Returns the cached or inserted id for a SHA-1-deduplicated dictionary table.
     *
     * @param string $table Dictionary table name
     * @param string $valueColumn Column holding the raw value
     * @param string $value Raw value deduplicated by hash
     * @param array<string, int> $cache By-value id cache, updated in place
     * @param int $ts Moment of first use, in milliseconds
     * @return ?int Dictionary id, or null when the row cannot be read back
     * @throws DatabaseException When the dictionary cannot be written
     */
    private function ensureHashedDictionaryValue(string $table, string $valueColumn, string $value, array &$cache, int $ts): ?int
    {
        if (isset($cache[$value])) {
            return $cache[$value];
        }

        $hash = sha1($value);

        Database::sql(
            "INSERT IGNORE INTO `{$table}` (`sha1_hash`, `{$valueColumn}`, `created_ts`) VALUES (UNHEX(?), ?, ?)",
            [$hash, $value, $ts],
        );
        Database::sql(
            "SELECT `id` FROM `{$table}` WHERE `sha1_hash` = UNHEX(?) LIMIT 1",
            [$hash],
        );

        $id = Database::field('id');
        if ($id === null) {
            return null;
        }

        $cache[$value] = (int)$id;

        return (int)$id;
    }

    /**
     * Returns the cached or inserted id for a SHA-1-deduplicated JSON dictionary table.
     *
     * @param ?array<string, mixed> $value Value to normalize and store; null/empty yields null
     * @param string $table Dictionary table name
     * @param string $column Column holding the JSON text
     * @param array<string, int> $cache By-JSON id cache, updated in place
     * @param int $ts Moment of first use, in milliseconds
     * @return ?int Dictionary id, or null when empty or not encodable
     * @throws DatabaseException When the dictionary cannot be written
     */
    private function ensureJsonDictionaryValue(?array $value, string $table, string $column, array &$cache, int $ts): ?int
    {
        $json = $this->normalizeJson($value);
        if ($json === null) {
            return null;
        }

        return $this->ensureHashedDictionaryValue($table, $column, $json, $cache, $ts);
    }

    /**
     * Keeps the rows of a portion whose master rows still exist, clearing a vanished user action.
     *
     * @param list<array<string, int|string|null>> $rows Fact rows of one table
     * @return list<array<string, int|string|null>> Rows to insert
     * @throws DatabaseException When a lookup fails
     */
    private function keepRowsOfLiveMasterRows(array $rows): array
    {
        $liveUserActions = $this->existingIds('hilos_analytics_user_action', array_column($rows, self::COLUMN_USER_ACTION_ID));
        $liveApiRequests = $this->existingIds('hilos_analytics_api_request', array_column($rows, self::COLUMN_API_REQUEST_ID));

        $kept = [];
        foreach ($rows as $row) {
            $apiRequestId = $row[self::COLUMN_API_REQUEST_ID] ?? null;
            if ($apiRequestId !== null && !isset($liveApiRequests[$apiRequestId])) {
                continue;
            }

            $userActionId = $row[self::COLUMN_USER_ACTION_ID] ?? null;
            if ($userActionId !== null && !isset($liveUserActions[$userActionId])) {
                $row[self::COLUMN_USER_ACTION_ID] = null;
            }

            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * Returns which of the ids are rows of the table, in one select.
     *
     * @param string $table Table of the master process
     * @param list<int|string|null> $ids Ids named by a portion; nulls are ignored
     * @return array<int, true> Ids that exist, as keys
     * @throws DatabaseException When the lookup fails
     */
    private function existingIds(string $table, array $ids): array
    {
        $wanted = array_values(array_unique(array_map('intval', array_filter($ids, static fn(int|string|null $id): bool => $id !== null))));
        if ($wanted === []) {
            return [];
        }

        Database::sql(
            "SELECT `id` FROM `{$table}` WHERE `id` IN (" . implode(', ', array_fill(0, count($wanted), '?')) . ')',
            $wanted,
        );

        $existing = [];
        foreach (Database::rows() as $row) {
            $existing[(int)$row['id']] = true;
        }

        return $existing;
    }

    /**
     * Writes rows of one table in a single multi-row insert.
     *
     * @param string $table Target analytics table name
     * @param list<array<string, int|string|null>> $rows Column-keyed rows to insert
     * @throws DatabaseException When the insert fails
     */
    private function bulkInsert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = array_keys($rows[0]);
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $valuesSql = implode(', ', array_fill(0, count($rows), $placeholders));

        $params = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $params[] = $row[$column] ?? null;
            }
        }

        $quotedColumns = implode(', ', array_map(
            static fn(string $column): string => "`{$column}`",
            $columns,
        ));

        Database::sql(
            "INSERT INTO `{$table}` ({$quotedColumns}) VALUES {$valuesSql}",
            $params,
        );
    }

    /**
     * Parses an IP address into its IPv4 or IPv6 representation.
     *
     * @param string $ip IP address string; empty or invalid yields a null/null result
     * @return ParsedIp Parsed components (exactly one set for a valid address)
     */
    private function parseIp(string $ip): ParsedIp
    {
        if ($ip === '') {
            return new ParsedIp(null, null);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipv4 = ip2long($ip);
            return new ParsedIp(
                $ipv4 === false ? null : (int)sprintf('%u', $ipv4),
                null,
            );
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $binary = inet_pton($ip);
            return new ParsedIp(
                null,
                $binary === false ? null : bin2hex($binary),
            );
        }

        return new ParsedIp(null, null);
    }

    /**
     * Normalizes an array to canonical JSON for stable hashing.
     *
     * Recursively sorts associative keys so equivalent payloads hash identically.
     *
     * @param ?array<string, mixed> $value Value to encode; null/empty yields null
     * @return ?string Canonical JSON, or null when empty or not encodable
     */
    private function normalizeJson(?array $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        $normalized = $this->sortRecursive($value);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    /**
     * Recursively sorts associative arrays by key, leaving lists in order.
     *
     * @param mixed $value Value to sort
     * @return mixed Value with associative arrays key-sorted
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursive($item);
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
