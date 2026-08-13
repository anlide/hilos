<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Core\Agent\AgentId;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Database\Database;
use Hilos\Utils\Logger;

/**
 * Collects raw analytics data into normalized SQL tables.
 *
 * This subsystem deliberately bypasses the Hilos ORM and issues raw
 * `Database::sql()` statements. It is high-frequency telemetry whose shape the
 * row-oriented ORM does not serve well: dictionary tables deduplicated by SHA-1
 * hash via `INSERT IGNORE`, and event rows accumulated in memory then written in
 * batched multi-row inserts on a timer or size threshold. Failures are
 * contained - any error disables the collector for the rest of the process
 * instead of propagating into application code, so the public methods never
 * throw.
 *
 * Insert buffers are intentionally array-shaped (raw DB rows keyed by column
 * name); internal session caches use typed value objects instead of arrays.
 */
final class AnalyticsCollector
{
    public const string META_API_REQUEST_ID = 'apiRequestId';
    public const string META_USER_ACTION_ID = 'userActionId';

    private const string IDENTITY_TYPE_USER_ID = 'user_id';

    private const int BUFFER_SIZE = 100;
    private const int FLUSH_INTERVAL_MS = 5000;

    /** @var bool Whether collector is enabled */
    private bool $enabled = true;

    /** @var int Timestamp of last buffer flush in milliseconds */
    private int $lastFlushTs;

    /** @var array<string, list<array<string, int|string|null>>> */
    private array $buffers = [
        'hilos_analytics_agent_user_action' => [],
        'hilos_analytics_agent_system_signal' => [],
        'hilos_analytics_agent_cron_signal' => [],
        'hilos_analytics_worker_system_signal' => [],
        'hilos_analytics_api_agent_action' => [],
    ];

    /** @var array<string, int> */
    private array $userAgentIds = [];

    /** @var array<string, int> */
    private array $acceptLanguageIds = [];

    /** @var array<string, int> */
    private array $pageIds = [];

    /** @var array<string, int> */
    private array $pageParamsIds = [];

    /** @var array<string, int> */
    private array $actionNameIds = [];

    /** @var array<string, int> */
    private array $signalNameIds = [];

    /** @var array<string, int> */
    private array $cronNameIds = [];

    /** @var array<string, int> */
    private array $payloadIds = [];

    /** @var array<string, BrowserSessionState> Map of session token to cached browser session state */
    private array $browserSessions = [];

    /** @var array<string, WsConnectionState> Map of accept key to cached WS connection state */
    private array $wsConnections = [];

    /** @var array<string, int> */
    private array $pageSessions = [];

    /** @var ?int Current worker session ID */
    private ?int $workerSessionId = null;

    /** @var array<string, int> */
    private array $agentSessions = [];

    /** @var ?int Active API request ID for signal correlation */
    private ?int $activeApiRequestId = null;

    /** @var ?int Active user action ID for signal correlation */
    private ?int $activeUserActionId = null;

    /**
     * Initializes the flush timer baseline.
     */
    public function __construct()
    {
        $this->lastFlushTs = $this->nowTs();
    }

    /**
     * Creates or refreshes the browser session for the token and returns its id.
     *
     * On an existing session, records user-agent and accept-language changes in
     * their history tables and updates the cached current values.
     *
     * @param ?string $sessionToken Browser session token; null/empty yields null
     * @param ?string $userAgent Raw User-Agent header, or null
     * @param ?string $acceptLanguage Raw Accept-Language header, or null
     * @return ?int Browser session id, or null when the token is empty or collection is disabled
     */
    public function ensureBrowserSession(?string $sessionToken, ?string $userAgent, ?string $acceptLanguage): ?int
    {
        if ($sessionToken === null || $sessionToken === '') {
            return null;
        }

        return $this->runSafely(function () use ($sessionToken, $userAgent, $acceptLanguage): ?int {
            $existing = $this->browserSessions[$sessionToken] ?? $this->loadBrowserSession($sessionToken);
            $userAgentId = $this->ensureUserAgent($userAgent);
            $acceptLanguageId = $this->ensureAcceptLanguage($acceptLanguage);
            $nowTs = $this->nowTs();

            if ($existing === null) {
                Database::sql(
                    'INSERT INTO `hilos_analytics_browser_session`
                        (`session_token`, `user_identity_type`, `user_identity_value`,
                         `current_user_agent_id`, `current_accept_language_id`, `first_seen_ts`, `last_seen_ts`)
                     VALUES (?, NULL, NULL, ?, ?, ?, ?)',
                    [$sessionToken, $userAgentId, $acceptLanguageId, $nowTs, $nowTs],
                );

                $id = Database::lastInsertId();
                $this->browserSessions[$sessionToken] = new BrowserSessionState($id, $userAgentId, $acceptLanguageId);

                return $id;
            }

            $updates = ['`last_seen_ts` = ?'];
            $params = [$nowTs];

            if ($existing->currentUserAgentId !== $userAgentId && $userAgentId !== null) {
                Database::sql(
                    'INSERT INTO `hilos_analytics_browser_session_user_agent_change`
                        (`browser_session_id`, `old_user_agent_id`, `new_user_agent_id`, `changed_ts`)
                     VALUES (?, ?, ?, ?)',
                    [$existing->id, $existing->currentUserAgentId, $userAgentId, $nowTs],
                );
                $updates[] = '`current_user_agent_id` = ?';
                $params[] = $userAgentId;
                $existing->currentUserAgentId = $userAgentId;
            }

            if ($existing->currentAcceptLanguageId !== $acceptLanguageId && $acceptLanguageId !== null) {
                Database::sql(
                    'INSERT INTO `hilos_analytics_browser_session_accept_language_change`
                        (`browser_session_id`, `old_accept_language_id`, `new_accept_language_id`, `changed_ts`)
                     VALUES (?, ?, ?, ?)',
                    [$existing->id, $existing->currentAcceptLanguageId, $acceptLanguageId, $nowTs],
                );
                $updates[] = '`current_accept_language_id` = ?';
                $params[] = $acceptLanguageId;
                $existing->currentAcceptLanguageId = $acceptLanguageId;
            }

            $params[] = $existing->id;
            Database::sql(
                'UPDATE `hilos_analytics_browser_session` SET ' . implode(', ', $updates) . ' WHERE `id` = ?',
                $params,
            );

            $this->browserSessions[$sessionToken] = $existing;
            return $existing->id;
        });
    }

    /**
     * Persists an external identity (type/value) on the browser session.
     *
     * @param string $sessionToken Browser session token; empty is ignored
     * @param string $type Identity type tag; empty is ignored
     * @param string $value Identity value; empty is ignored
     */
    public function setBrowserSessionIdentity(string $sessionToken, string $type, string $value): void
    {
        if ($sessionToken === '' || $type === '' || $value === '') {
            return;
        }

        $this->runSafely(function () use ($sessionToken, $type, $value): void {
            $browserSessionId = $this->ensureBrowserSession($sessionToken, null, null);
            if ($browserSessionId === null) {
                return;
            }

            Database::sql(
                'UPDATE `hilos_analytics_browser_session`
                 SET `user_identity_type` = ?, `user_identity_value` = ?, `last_seen_ts` = ?
                 WHERE `id` = ?',
                [$type, $value, $this->nowTs(), $browserSessionId],
            );
        });
    }

    /**
     * Moves a browser session onto the token a login rotated it to (HIL-582).
     *
     * The analytics session is named by the secret rather than by the application
     * session's id, so a rotation that changes only the secret would strand everything
     * collected before the login - page views, WebSocket connections, the user-agent
     * history - under a token nobody presents again, and the identify that follows would
     * open a second session for the same person. The rename keeps one visit whole across
     * the moment it is most worth being whole across.
     *
     * A token whose session was never opened is nothing to rename: the row is created
     * lazily on first use, so a login without prior collection stays a no-op.
     *
     * @param string $oldToken Token the session answered to before the rotation
     * @param string $newToken Token the session answers to now
     */
    public function renameBrowserSession(string $oldToken, string $newToken): void
    {
        if ($oldToken === '' || $newToken === '' || $oldToken === $newToken) {
            return;
        }

        $this->runSafely(function () use ($oldToken, $newToken): void {
            $session = $this->browserSessions[$oldToken] ?? $this->loadBrowserSession($oldToken);
            if ($session === null) {
                return;
            }

            Database::sql(
                'UPDATE `hilos_analytics_browser_session`
                 SET `session_token` = ?, `last_seen_ts` = ?
                 WHERE `id` = ?',
                [$newToken, $this->nowTs(), $session->id],
            );

            unset($this->browserSessions[$oldToken]);
            $this->browserSessions[$newToken] = $session;
        });
    }

    /**
     * Associates a browser session with an authenticated application user.
     *
     * @param string $sessionToken Browser session token
     * @param int $userId Application user id
     */
    public function identifyBrowserSessionUser(string $sessionToken, int $userId): void
    {
        $this->setBrowserSessionIdentity($sessionToken, self::IDENTITY_TYPE_USER_ID, (string)$userId);
    }

    /**
     * Opens a WebSocket connection row and caches its state.
     *
     * Resolves the owning browser session from the token when present and
     * records the opening client IP.
     *
     * @param string $acceptKey WebSocket accept key; empty yields null
     * @param ?string $sessionToken Browser session token, or null for anonymous
     * @param ?string $clientIp Client IP address (IPv4 or IPv6), or null when unknown
     * @return ?int WS connection id, or null when the accept key is empty or collection is disabled
     */
    public function openWsConnection(string $acceptKey, ?string $sessionToken, ?string $clientIp): ?int
    {
        if ($acceptKey === '') {
            return null;
        }

        return $this->runSafely(function () use ($acceptKey, $sessionToken, $clientIp): ?int {
            $browserSessionId = $sessionToken !== null && $sessionToken !== ''
                ? (($this->browserSessions[$sessionToken] ?? null)?->id ?? $this->ensureBrowserSession($sessionToken, null, null))
                : null;

            $ip = $clientIp !== null ? $this->parseIp($clientIp) : new ParsedIp(null, null);
            $nowTs = $this->nowTs();

            Database::sql(
                'INSERT INTO `hilos_analytics_ws_connection`
                    (`browser_session_id`, `accept_key`, `opened_ipv4`, `opened_ipv6`, `opened_ts`, `closed_ts`)
                 VALUES (?, ?, ?, UNHEX(?), ?, NULL)',
                [$browserSessionId, $acceptKey, $ip->ipv4, $ip->ipv6Hex, $nowTs],
            );

            $id = Database::lastInsertId();
            $this->wsConnections[$acceptKey] = new WsConnectionState($id, $browserSessionId, $ip->ipv4, $ip->ipv6Hex);

            return $id;
        });
    }

    /**
     * Records IPv4/IPv6 changes for an open WS connection against its cached state.
     *
     * @param string $acceptKey WebSocket accept key; empty is ignored
     * @param ?string $clientIp Current client IP address; null is ignored
     */
    public function trackWsConnectionIpChange(string $acceptKey, ?string $clientIp): void
    {
        if ($acceptKey === '' || $clientIp === null) {
            return;
        }

        $this->runSafely(function () use ($acceptKey, $clientIp): void {
            $connection = $this->wsConnections[$acceptKey] ?? null;
            if ($connection === null) {
                return;
            }

            $parsed = $this->parseIp($clientIp);
            $nowTs = $this->nowTs();

            if ($connection->currentIpv4 !== $parsed->ipv4) {
                Database::sql(
                    'INSERT INTO `hilos_analytics_ws_connection_ipv4_change`
                        (`ws_connection_id`, `old_ipv4`, `new_ipv4`, `changed_ts`)
                     VALUES (?, ?, ?, ?)',
                    [$connection->id, $connection->currentIpv4, $parsed->ipv4, $nowTs],
                );
                $connection->currentIpv4 = $parsed->ipv4;
            }

            if ($connection->currentIpv6Hex !== $parsed->ipv6Hex) {
                Database::sql(
                    'INSERT INTO `hilos_analytics_ws_connection_ipv6_change`
                        (`ws_connection_id`, `old_ipv6`, `new_ipv6`, `changed_ts`)
                     VALUES (?, UNHEX(?), UNHEX(?), ?)',
                    [$connection->id, $connection->currentIpv6Hex, $parsed->ipv6Hex, $nowTs],
                );
                $connection->currentIpv6Hex = $parsed->ipv6Hex;
            }

            $this->wsConnections[$acceptKey] = $connection;
        });
    }

    /**
     * Marks an open WS connection closed; no-op when the key is unknown.
     *
     * @param string $acceptKey WebSocket accept key; empty is ignored
     */
    public function closeWsConnection(string $acceptKey): void
    {
        if ($acceptKey === '') {
            return;
        }

        $this->runSafely(function () use ($acceptKey): void {
            $connection = $this->wsConnections[$acceptKey] ?? null;
            if ($connection === null) {
                return;
            }

            Database::sql(
                'UPDATE `hilos_analytics_ws_connection` SET `closed_ts` = ? WHERE `id` = ?',
                [$this->nowTs(), $connection->id],
            );
        });
    }

    /**
     * Opens a page session on a WS connection, closing any prior one first.
     *
     * @param string $acceptKey WebSocket accept key; empty yields null
     * @param string $pageName Page name being opened; empty yields null
     * @param ?array<string, mixed> $params Page route params, or null
     * @return ?int Page session id, or null when the connection is unknown or collection is disabled
     */
    public function openPageSession(string $acceptKey, string $pageName, ?array $params = null): ?int
    {
        if ($acceptKey === '' || $pageName === '') {
            return null;
        }

        return $this->runSafely(function () use ($acceptKey, $pageName, $params): ?int {
            $connection = $this->wsConnections[$acceptKey] ?? null;
            if ($connection === null) {
                return null;
            }

            $this->closePageSession($acceptKey);

            $pageId = $this->ensurePage($pageName);
            $pageParamsId = $this->ensurePageParams($params);

            Database::sql(
                'INSERT INTO `hilos_analytics_page_session`
                    (`ws_connection_id`, `page_id`, `page_params_id`, `opened_ts`, `closed_ts`)
                 VALUES (?, ?, ?, ?, NULL)',
                [$connection->id, $pageId, $pageParamsId, $this->nowTs()],
            );

            $id = Database::lastInsertId();
            $this->pageSessions[$acceptKey] = $id;

            return $id;
        });
    }

    /**
     * Updates the route params of the current page session.
     *
     * @param string $acceptKey WebSocket accept key; empty is ignored
     * @param ?array<string, mixed> $params New page route params, or null
     */
    public function updatePageSession(string $acceptKey, ?array $params): void
    {
        if ($acceptKey === '') {
            return;
        }

        $this->runSafely(function () use ($acceptKey, $params): void {
            $pageSessionId = $this->pageSessions[$acceptKey] ?? null;
            if ($pageSessionId === null) {
                return;
            }

            Database::sql(
                'UPDATE `hilos_analytics_page_session` SET `page_params_id` = ? WHERE `id` = ?',
                [$this->ensurePageParams($params), $pageSessionId],
            );
        });
    }

    /**
     * Marks the current page session closed and drops it from the cache.
     *
     * @param string $acceptKey WebSocket accept key; empty is ignored
     */
    public function closePageSession(string $acceptKey): void
    {
        if ($acceptKey === '') {
            return;
        }

        $this->runSafely(function () use ($acceptKey): void {
            $pageSessionId = $this->pageSessions[$acceptKey] ?? null;
            if ($pageSessionId === null) {
                return;
            }

            Database::sql(
                'UPDATE `hilos_analytics_page_session` SET `closed_ts` = ? WHERE `id` = ?',
                [$this->nowTs(), $pageSessionId],
            );

            unset($this->pageSessions[$acceptKey]);
        });
    }

    /**
     * Opens a worker session row and stores it as the active worker session.
     *
     * @param int $workerIndex Worker index within the daemon
     * @param bool $isMonopolistic Whether the worker runs monopolistic agents
     * @return ?int Worker session id, or null when collection is disabled
     */
    public function openWorkerSession(int $workerIndex, bool $isMonopolistic): ?int
    {
        return $this->runSafely(function () use ($workerIndex, $isMonopolistic): ?int {
            Database::sql(
                'INSERT INTO `hilos_analytics_worker_session`
                    (`worker_index`, `is_monopolistic`, `started_ts`, `stopped_ts`)
                 VALUES (?, ?, ?, NULL)',
                [$workerIndex, $isMonopolistic ? 1 : 0, $this->nowTs()],
            );

            $this->workerSessionId = Database::lastInsertId();
            return $this->workerSessionId;
        });
    }

    /**
     * Marks the active worker session stopped; no-op when none is open.
     */
    public function closeWorkerSession(): void
    {
        $this->runSafely(function (): void {
            if ($this->workerSessionId === null) {
                return;
            }

            Database::sql(
                'UPDATE `hilos_analytics_worker_session` SET `stopped_ts` = ? WHERE `id` = ?',
                [$this->nowTs(), $this->workerSessionId],
            );
        });
    }

    /**
     * Opens an agent session under the active worker session and caches it.
     *
     * @param string $agentType Agent type identifier; empty yields null
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     * @return ?int Agent session id, or null when there is no worker session or collection is disabled
     */
    public function openAgentSession(string $agentType, ?string $agentIndex): ?int
    {
        if ($agentType === '' || $this->workerSessionId === null) {
            return null;
        }

        return $this->runSafely(function () use ($agentType, $agentIndex): ?int {
            Database::sql(
                'INSERT INTO `hilos_analytics_agent_session`
                    (`worker_session_id`, `agent_type`, `agent_index`, `started_ts`, `stopped_ts`)
                 VALUES (?, ?, ?, ?, NULL)',
                [$this->workerSessionId, $agentType, $agentIndex, $this->nowTs()],
            );

            $id = Database::lastInsertId();
            $this->agentSessions[$this->buildAgentKey($agentType, $agentIndex)] = $id;

            return $id;
        });
    }

    /**
     * Marks an agent session stopped and drops it from the cache.
     *
     * @param string $agentType Agent type identifier; empty is ignored
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     */
    public function closeAgentSession(string $agentType, ?string $agentIndex): void
    {
        if ($agentType === '') {
            return;
        }

        $this->runSafely(function () use ($agentType, $agentIndex): void {
            $key = $this->buildAgentKey($agentType, $agentIndex);
            $agentSessionId = $this->agentSessions[$key] ?? null;
            if ($agentSessionId === null) {
                return;
            }

            Database::sql(
                'UPDATE `hilos_analytics_agent_session` SET `stopped_ts` = ? WHERE `id` = ?',
                [$this->nowTs(), $agentSessionId],
            );

            unset($this->agentSessions[$key]);
        });
    }

    /**
     * Persists a user action against the WS connection and current page session.
     *
     * @param string $acceptKey WebSocket accept key; empty yields null
     * @param string $actionName Client action name; empty yields null
     * @param ?array<string, mixed> $payload Action payload, or null
     * @return ?int User action id, or null when the connection is unknown or collection is disabled
     */
    public function logUserAction(string $acceptKey, string $actionName, ?array $payload): ?int
    {
        if ($acceptKey === '' || $actionName === '') {
            return null;
        }

        return $this->runSafely(function () use ($acceptKey, $actionName, $payload): ?int {
            $connection = $this->wsConnections[$acceptKey] ?? null;
            if ($connection === null) {
                return null;
            }

            Database::sql(
                'INSERT INTO `hilos_analytics_user_action`
                    (`ws_connection_id`, `page_session_id`, `action_name_id`, `payload_json_id`, `created_ts`)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $connection->id,
                    $this->pageSessions[$acceptKey] ?? null,
                    $this->ensureActionName($actionName),
                    $this->ensurePayloadJson($payload),
                    $this->nowTs(),
                ],
            );

            return Database::lastInsertId();
        });
    }

    /**
     * Buffers an agent reaction to a user action for the next flush.
     *
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     * @param ?int $userActionId Originating user action id, or null when uncorrelated
     * @param string $signalName Signal name handled by the agent; empty is ignored
     * @param ?array<string, mixed> $payload Signal payload, or null
     */
    public function logAgentUserAction(string $agentType, ?string $agentIndex, ?int $userActionId, string $signalName, ?array $payload): void
    {
        if ($signalName === '') {
            return;
        }

        $this->runSafely(function () use ($agentType, $agentIndex, $userActionId, $signalName, $payload): void {
            $agentSessionId = $this->getAgentSessionId($agentType, $agentIndex);
            if ($agentSessionId === null) {
                return;
            }

            $this->queueBufferedInsert('hilos_analytics_agent_user_action', [
                'agent_session_id' => $agentSessionId,
                'user_action_id' => $userActionId,
                'signal_name_id' => $this->ensureSignalName($signalName),
                'payload_json_id' => $this->ensurePayloadJson($payload),
                'created_ts' => $this->nowTs(),
            ]);
        });
    }

    /**
     * Buffers an agent system-signal event for the next flush.
     *
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     * @param string $signalName System signal name; empty is ignored
     * @param ?array<string, mixed> $payload Signal payload, or null
     */
    public function logAgentSystemSignal(string $agentType, ?string $agentIndex, string $signalName, ?array $payload): void
    {
        if ($signalName === '') {
            return;
        }

        $this->runSafely(function () use ($agentType, $agentIndex, $signalName, $payload): void {
            $agentSessionId = $this->getAgentSessionId($agentType, $agentIndex);
            if ($agentSessionId === null) {
                return;
            }

            $this->queueBufferedInsert('hilos_analytics_agent_system_signal', [
                'agent_session_id' => $agentSessionId,
                'signal_name_id' => $this->ensureSignalName($signalName),
                'payload_json_id' => $this->ensurePayloadJson($payload),
                'created_ts' => $this->nowTs(),
            ]);
        });
    }

    /**
     * Buffers an agent cron-signal event for the next flush.
     *
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     * @param string $cronName Cron job name; empty is ignored
     * @param ?array<string, mixed> $payload Signal payload, or null
     */
    public function logAgentCronSignal(string $agentType, ?string $agentIndex, string $cronName, ?array $payload): void
    {
        if ($cronName === '') {
            return;
        }

        $this->runSafely(function () use ($agentType, $agentIndex, $cronName, $payload): void {
            $agentSessionId = $this->getAgentSessionId($agentType, $agentIndex);
            if ($agentSessionId === null) {
                return;
            }

            $this->queueBufferedInsert('hilos_analytics_agent_cron_signal', [
                'agent_session_id' => $agentSessionId,
                'cron_name_id' => $this->ensureCronName($cronName),
                'payload_json_id' => $this->ensurePayloadJson($payload),
                'created_ts' => $this->nowTs(),
            ]);
        });
    }

    /**
     * Buffers a worker system-signal event for the next flush.
     *
     * @param string $signalName System signal name; empty is ignored
     * @param ?array<string, mixed> $payload Signal payload, or null
     */
    public function logWorkerSystemSignal(string $signalName, ?array $payload): void
    {
        if ($signalName === '') {
            return;
        }

        $this->runSafely(function () use ($signalName, $payload): void {
            if ($this->workerSessionId === null) {
                return;
            }

            $this->queueBufferedInsert('hilos_analytics_worker_system_signal', [
                'worker_session_id' => $this->workerSessionId,
                'signal_name_id' => $this->ensureSignalName($signalName),
                'payload_json_id' => $this->ensurePayloadJson($payload),
                'created_ts' => $this->nowTs(),
            ]);
        });
    }

    /**
     * Opens an API request row, resolving its browser session, and marks it active.
     *
     * @param ?string $sessionToken Browser session token, or null for anonymous
     * @param string $method HTTP method
     * @param string $path Request path
     * @param ?array<string, mixed> $params Request params, or null
     * @param ?string $userAgent Raw User-Agent header, or null
     * @param ?string $acceptLanguage Raw Accept-Language header, or null
     * @return ?int API request id, or null when collection is disabled
     */
    public function startApiRequest(
        ?string $sessionToken,
        string $method,
        string $path,
        ?array $params,
        ?string $userAgent,
        ?string $acceptLanguage,
    ): ?int {
        return $this->runSafely(function () use ($sessionToken, $method, $path, $params, $userAgent, $acceptLanguage): ?int {
            $browserSessionId = $this->ensureBrowserSession($sessionToken, $userAgent, $acceptLanguage);

            Database::sql(
                'INSERT INTO `hilos_analytics_api_request`
                    (`browser_session_id`, `method`, `path`, `params_json_id`, `status_code`, `duration_ms`, `started_ts`, `finished_ts`)
                 VALUES (?, ?, ?, ?, NULL, NULL, ?, NULL)',
                [$browserSessionId, $method, $path, $this->ensurePageParams($params), $this->nowTs()],
            );

            $this->activeApiRequestId = Database::lastInsertId();
            return $this->activeApiRequestId;
        });
    }

    /**
     * Finalizes an API request row with status, duration and finish time.
     *
     * @param ?int $apiRequestId API request id; null is ignored
     * @param ?int $statusCode HTTP status code, or null
     * @param ?int $durationMs Request duration in milliseconds, or null
     */
    public function finishApiRequest(?int $apiRequestId, ?int $statusCode, ?int $durationMs): void
    {
        if ($apiRequestId === null) {
            return;
        }

        $this->runSafely(function () use ($apiRequestId, $statusCode, $durationMs): void {
            Database::sql(
                'UPDATE `hilos_analytics_api_request`
                 SET `status_code` = ?, `duration_ms` = ?, `finished_ts` = ?
                 WHERE `id` = ?',
                [$statusCode, $durationMs, $this->nowTs(), $apiRequestId],
            );

            if ($this->activeApiRequestId === $apiRequestId) {
                $this->activeApiRequestId = null;
            }
        });
    }

    /**
     * Buffers an agent action triggered by an API request for the next flush.
     *
     * @param int $apiRequestId Originating API request id
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     * @param string $signalName Signal name dispatched to the agent; empty is ignored
     * @param ?array<string, mixed> $payload Signal payload, or null
     */
    public function logApiAgentAction(int $apiRequestId, string $agentType, ?string $agentIndex, string $signalName, ?array $payload): void
    {
        if ($signalName === '') {
            return;
        }

        $this->runSafely(function () use ($apiRequestId, $agentType, $agentIndex, $signalName, $payload): void {
            $agentSessionId = $this->getAgentSessionId($agentType, $agentIndex);
            if ($agentSessionId === null) {
                return;
            }

            $this->queueBufferedInsert('hilos_analytics_api_agent_action', [
                'api_request_id' => $apiRequestId,
                'agent_session_id' => $agentSessionId,
                'signal_name_id' => $this->ensureSignalName($signalName),
                'payload_json_id' => $this->ensurePayloadJson($payload),
                'created_ts' => $this->nowTs(),
            ]);
        });
    }

    /**
     * Returns the dictionary id for a User-Agent value, inserting it on first use.
     *
     * @param ?string $value Raw User-Agent header; null/empty yields null
     * @return ?int Dictionary id, or null when empty or collection is disabled
     */
    public function ensureUserAgent(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->ensureHashedDictionaryValue(
            'hilos_analytics_user_agent',
            'value',
            $value,
            $this->userAgentIds,
        );
    }

    /**
     * Returns the dictionary id for an Accept-Language value, inserting it on first use.
     *
     * @param ?string $value Raw Accept-Language header; null/empty yields null
     * @return ?int Dictionary id, or null when empty or collection is disabled
     */
    public function ensureAcceptLanguage(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->ensureHashedDictionaryValue(
            'hilos_analytics_accept_language',
            'value',
            $value,
            $this->acceptLanguageIds,
        );
    }

    /**
     * Returns the dictionary id for a page name, inserting it on first use.
     *
     * @param string $pageName Page name
     * @return int Dictionary id (0 when collection is disabled)
     */
    public function ensurePage(string $pageName): int
    {
        if (isset($this->pageIds[$pageName])) {
            return $this->pageIds[$pageName];
        }

        return $this->runSafely(function () use ($pageName): int {
            Database::sql(
                'INSERT IGNORE INTO `hilos_analytics_page` (`page_name`, `created_ts`) VALUES (?, ?)',
                [$pageName, $this->nowTs()],
            );

            Database::sql(
                'SELECT `id` FROM `hilos_analytics_page` WHERE `page_name` = ? LIMIT 1',
                [$pageName],
            );

            $id = (int)Database::field('id');
            $this->pageIds[$pageName] = $id;
            return $id;
        }, 0);
    }

    /**
     * Returns the dictionary id for normalized page params, inserting it on first use.
     *
     * @param ?array<string, mixed> $params Page route params; null/empty yields null
     * @return ?int Dictionary id, or null when empty or collection is disabled
     */
    public function ensurePageParams(?array $params): ?int
    {
        return $this->ensureJsonDictionaryValue($params, 'hilos_analytics_page_params', 'params_json', $this->pageParamsIds);
    }

    /**
     * Returns the dictionary id for an action name, inserting it on first use.
     *
     * @param string $name Action name
     * @return int Dictionary id (0 when collection is disabled)
     */
    public function ensureActionName(string $name): int
    {
        return $this->ensureNamedDictionaryValue($name, 'hilos_analytics_action_name', $this->actionNameIds);
    }

    /**
     * Returns the dictionary id for a signal name, inserting it on first use.
     *
     * @param string $name Signal name
     * @return int Dictionary id (0 when collection is disabled)
     */
    public function ensureSignalName(string $name): int
    {
        return $this->ensureNamedDictionaryValue($name, 'hilos_analytics_signal_name', $this->signalNameIds);
    }

    /**
     * Returns the dictionary id for a cron name, inserting it on first use.
     *
     * @param string $name Cron job name
     * @return int Dictionary id (0 when collection is disabled)
     */
    public function ensureCronName(string $name): int
    {
        return $this->ensureNamedDictionaryValue($name, 'hilos_analytics_cron_name', $this->cronNameIds);
    }

    /**
     * Returns the dictionary id for a normalized JSON payload, inserting it on first use.
     *
     * @param ?array<string, mixed> $payload Payload; null/empty yields null
     * @return ?int Dictionary id, or null when empty or collection is disabled
     */
    public function ensurePayloadJson(?array $payload): ?int
    {
        return $this->ensureJsonDictionaryValue($payload, 'hilos_analytics_payload_json', 'payload_json', $this->payloadIds);
    }

    /**
     * Sets the active user action id used to correlate subsequent signals.
     *
     * @param ?int $userActionId User action id to correlate, or null to clear
     */
    public function startUserActionCapture(?int $userActionId): void
    {
        $this->activeUserActionId = $userActionId;
    }

    /**
     * Clears the active user action correlation id.
     */
    public function clearUserActionCapture(): void
    {
        $this->activeUserActionId = null;
    }

    /**
     * Builds correlation metadata for an outgoing signal from the active ids.
     *
     * @return array<string, int> Meta keyed by META_* constants; empty when nothing is active
     */
    public function captureSignalMeta(): array
    {
        $meta = [];

        if ($this->activeApiRequestId !== null) {
            $meta[self::META_API_REQUEST_ID] = $this->activeApiRequestId;
        }

        if ($this->activeUserActionId !== null) {
            $meta[self::META_USER_ACTION_ID] = $this->activeUserActionId;
        }

        return $meta;
    }

    /**
     * Reads an integer correlation value from signal metadata.
     *
     * @param SignalDTO $signal Signal carrying meta
     * @param string $key Meta key (a META_* constant)
     * @return ?int Integer value, or null when absent or non-numeric
     */
    public function getSignalMetaInt(SignalDTO $signal, string $key): ?int
    {
        $value = $signal->meta[$key] ?? null;
        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * Flushes buffered rows when the flush interval has elapsed.
     */
    public function tick(): void
    {
        if (!$this->enabled) {
            return;
        }

        if (($this->nowTs() - $this->lastFlushTs) >= self::FLUSH_INTERVAL_MS) {
            $this->flush();
        }
    }

    /**
     * Writes all buffered rows in batched inserts and resets the flush timer.
     */
    public function flush(): void
    {
        $this->runSafely(function (): void {
            foreach ($this->buffers as $table => $rows) {
                if ($rows === []) {
                    continue;
                }

                $this->bulkInsert($table, $rows);
                $this->buffers[$table] = [];
            }

            $this->lastFlushTs = $this->nowTs();
        });
    }

    /**
     * Flushes remaining rows and clears active correlation ids.
     */
    public function shutdown(): void
    {
        $this->flush();
        $this->activeApiRequestId = null;
        $this->activeUserActionId = null;
    }

    /**
     * Returns the cached or inserted id for a name-keyed dictionary table.
     *
     * @param string $name Lookup name
     * @param string $table Dictionary table name
     * @param array<string, int> $cache By-name id cache, updated in place
     * @return int Dictionary id (0 when collection is disabled)
     */
    private function ensureNamedDictionaryValue(string $name, string $table, array &$cache): int
    {
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        return $this->runSafely(function () use ($name, $table, &$cache): int {
            Database::sql(
                "INSERT IGNORE INTO `{$table}` (`name`, `created_ts`) VALUES (?, ?)",
                [$name, $this->nowTs()],
            );
            Database::sql(
                "SELECT `id` FROM `{$table}` WHERE `name` = ? LIMIT 1",
                [$name],
            );

            $id = (int)Database::field('id');
            $cache[$name] = $id;

            return $id;
        }, 0);
    }

    /**
     * Returns the cached or inserted id for a SHA-1-deduplicated dictionary table.
     *
     * @param string $table Dictionary table name
     * @param string $valueColumn Column holding the raw value
     * @param string $value Raw value deduplicated by hash
     * @param array<string, int> $cache By-value id cache, updated in place
     * @return ?int Dictionary id, or null when collection is disabled
     */
    private function ensureHashedDictionaryValue(string $table, string $valueColumn, string $value, array &$cache): ?int
    {
        if (isset($cache[$value])) {
            return $cache[$value];
        }

        return $this->runSafely(function () use ($table, $valueColumn, $value, &$cache): ?int {
            $hash = sha1($value);

            Database::sql(
                "INSERT IGNORE INTO `{$table}` (`sha1_hash`, `{$valueColumn}`, `created_ts`) VALUES (UNHEX(?), ?, ?)",
                [$hash, $value, $this->nowTs()],
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
        });
    }

    /**
     * Returns the cached or inserted id for a SHA-1-deduplicated JSON dictionary table.
     *
     * @param ?array<string, mixed> $value Value to normalize and store; null/empty yields null
     * @param string $table Dictionary table name
     * @param string $column Column holding the JSON text
     * @param array<string, int> $cache By-JSON id cache, updated in place
     * @return ?int Dictionary id, or null when empty or collection is disabled
     */
    private function ensureJsonDictionaryValue(?array $value, string $table, string $column, array &$cache): ?int
    {
        $json = $this->normalizeJson($value);
        if ($json === null) {
            return null;
        }

        if (isset($cache[$json])) {
            return $cache[$json];
        }

        return $this->runSafely(function () use ($json, $table, $column, &$cache): ?int {
            $hash = sha1($json);

            Database::sql(
                "INSERT IGNORE INTO `{$table}` (`sha1_hash`, `{$column}`, `created_ts`) VALUES (UNHEX(?), ?, ?)",
                [$hash, $json, $this->nowTs()],
            );
            Database::sql(
                "SELECT `id` FROM `{$table}` WHERE `sha1_hash` = UNHEX(?) LIMIT 1",
                [$hash],
            );

            $id = Database::field('id');
            if ($id === null) {
                return null;
            }

            $cache[$json] = (int)$id;
            return (int)$id;
        });
    }

    /**
     * Loads and caches the browser session row for a token.
     *
     * @param string $sessionToken Session token
     * @return ?BrowserSessionState Cached state, or null when absent or collection is disabled
     */
    private function loadBrowserSession(string $sessionToken): ?BrowserSessionState
    {
        return $this->runSafely(function () use ($sessionToken): ?BrowserSessionState {
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
            $this->browserSessions[$sessionToken] = $session;

            return $session;
        });
    }

    /**
     * Returns the cached agent session id for a type/index pair, or null.
     *
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     * @return ?int Cached agent session id, or null when not open
     */
    private function getAgentSessionId(string $agentType, ?string $agentIndex): ?int
    {
        return $this->agentSessions[$this->buildAgentKey($agentType, $agentIndex)] ?? null;
    }

    /**
     * Builds the cache key for an agent type/index pair.
     *
     * A singleton agent keys apart from an agent whose index happens to be empty:
     * collapsed onto one key, the second {@see self::openAgentSession()} would
     * overwrite the first entry, every later `logAgent*` of both agents would land
     * under one `agent_session_id`, and the first to stop would stamp the other's
     * row and clear the key — leaving the survivor logging nothing at all for the
     * rest of its life, and the first agent's row open forever.
     *
     * Keying the singleton by its bare type reads the two apart on the assumption
     * the whole repository already runs on — that an agent type carries no
     * separator of its own, which is what lets {@see AgentId::fromId()} split one.
     *
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     * @return string Composite cache key
     */
    private function buildAgentKey(string $agentType, ?string $agentIndex): string
    {
        return $agentIndex === null ? $agentType : $agentType . '::' . $agentIndex;
    }

    /**
     * Appends a row to a table buffer and flushes when the size threshold is hit.
     *
     * @param string $table Target analytics table name
     * @param array<string, int|string|null> $row Column-keyed row payload
     */
    private function queueBufferedInsert(string $table, array $row): void
    {
        $this->buffers[$table][] = $row;

        if ($this->getBufferedRowsCount() >= self::BUFFER_SIZE) {
            $this->flush();
        }
    }

    /**
     * Returns the total number of rows currently buffered across all tables.
     *
     * @return int Buffered row count
     */
    private function getBufferedRowsCount(): int
    {
        $count = 0;
        foreach ($this->buffers as $rows) {
            $count += count($rows);
        }
        return $count;
    }

    /**
     * Writes buffered rows for one table in a single multi-row insert.
     *
     * @param string $table Target analytics table name
     * @param list<array<string, int|string|null>> $rows Column-keyed rows to insert
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
     * Returns the current timestamp in milliseconds.
     *
     * @return int Current Unix time in milliseconds
     */
    private function nowTs(): int
    {
        return (int)floor(microtime(true) * 1000);
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

    /**
     * Runs a collector operation, containing any failure.
     *
     * Returns the default immediately when the collector is disabled. On any
     * throwable, disables the collector for the rest of the process, logs the
     * error, and returns the default instead of propagating.
     *
     * @param callable $callback Operation to run
     * @param mixed $default Value returned when disabled or on failure
     * @return mixed The callback result, or the default
     */
    private function runSafely(callable $callback, mixed $default = null): mixed
    {
        if (!$this->enabled) {
            return $default;
        }

        try {
            return $callback();
        } catch (\Throwable $throwable) {
            $this->enabled = false;
            Logger::error('Analytics collector disabled: ' . $throwable->getMessage());
            return $default;
        }
    }
}
