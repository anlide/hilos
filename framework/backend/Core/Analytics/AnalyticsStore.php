<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Utils\Logger;

/**
 * The one home of the analytics SQL: every statement that reads or writes an analytics table.
 *
 * This subsystem deliberately bypasses the Hilos ORM and issues raw `Database::sql()`
 * statements. It is high-frequency telemetry whose shape the row-oriented ORM does not serve
 * well: dictionary tables deduplicated by SHA-1 hash via `INSERT IGNORE`, and fact rows
 * accumulated in memory then written in multi-row inserts.
 *
 * One cluster writer loads each node's journal files through it
 * ({@see AnalyticsJournalLoader}). The store itself never decides
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
    /** @var int Fact rows written by one multi-row insert and linked by one lookup of cause keys */
    public const int FACT_PORTION_ROWS = 500;

    private const string TABLE_AGENT_USER_ACTION = 'hilos_analytics_agent_user_action';
    private const string TABLE_AGENT_SYSTEM_SIGNAL = 'hilos_analytics_agent_system_signal';
    private const string TABLE_AGENT_CRON_SIGNAL = 'hilos_analytics_agent_cron_signal';
    private const string TABLE_WORKER_SYSTEM_SIGNAL = 'hilos_analytics_worker_system_signal';
    private const string TABLE_API_AGENT_ACTION = 'hilos_analytics_api_agent_action';

    private const string COLUMN_USER_ACTION_ID = 'user_action_id';
    private const string COLUMN_API_REQUEST_ID = 'api_request_id';
    private const string COLUMN_USER_ACTION_KEY = 'user_action_key';
    private const string COLUMN_API_REQUEST_KEY = 'api_request_key';
    private const int MAX_TOKEN_ALIAS_HOPS = 8;

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
        $cached = $this->cache->browserSessions[$sessionToken] ?? null;
        if ($cached !== null) {
            $existing = $cached;
            $resolvedToken = $this->resolveBrowserSessionToken($sessionToken);
        } else {
            $existing = $this->loadBrowserSessionDirect($sessionToken);
            $resolvedToken = $existing === null ? $this->resolveBrowserSessionToken($sessionToken) : $sessionToken;
            $existing ??= $this->loadBrowserSession($resolvedToken);
        }
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
                [$resolvedToken, $userAgentId, $acceptLanguageId, $ts, $ts],
            );

            $id = Database::lastInsertId();
            $this->cache->browserSessions[$resolvedToken] = new BrowserSessionState($id, $userAgentId, $acceptLanguageId);
            $this->cache->browserSessions[$sessionToken] = $this->cache->browserSessions[$resolvedToken];

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

        $this->cache->browserSessions[$resolvedToken] = new BrowserSessionState(
            $existing->id,
            $currentUserAgentId,
            $currentAcceptLanguageId,
        );
        $this->cache->browserSessions[$sessionToken] = $this->cache->browserSessions[$resolvedToken];

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
     * login under a token nobody presents again. A token whose session was never opened
     * becomes an alias. When both tokens already have sessions, their rows and dependent facts
     * join under the new token.
     *
     * @param string $oldToken Token the session answered to before the rotation
     * @param string $newToken Token the session answers to now
     * @param int $ts Moment of the rotation, in milliseconds
     * @return BrowserSessionRename What was done
     * @throws DatabaseException When a statement fails
     */
    public function renameBrowserSession(string $oldToken, string $newToken, int $ts): BrowserSessionRename
    {
        Database::sql(
            'INSERT IGNORE INTO `hilos_analytics_browser_session_alias` (`old_token`, `new_token`, `created_ts`) VALUES (?, ?, ?)',
            [$oldToken, $newToken, $ts],
        );

        $old = $this->loadBrowserSessionDirect($oldToken);
        $resolvedNewToken = $this->resolveBrowserSessionToken($newToken);
        $new = $this->loadBrowserSession($resolvedNewToken);
        if ($old === null) {
            return BrowserSessionRename::ALIASED;
        }

        if ($new === null) {
            Database::sql(
                'UPDATE `hilos_analytics_browser_session`
                 SET `session_token` = ?, `last_seen_ts` = GREATEST(`last_seen_ts`, ?)
                 WHERE `id` = ?',
                [$resolvedNewToken, $ts, $old->id],
            );
            $this->cache->browserSessions[$oldToken] = $old;
            $this->cache->browserSessions[$resolvedNewToken] = $old;
            return BrowserSessionRename::RENAMED;
        }

        if ($old->id === $new->id) {
            return BrowserSessionRename::ALIASED;
        }

        Database::sql(
            'SELECT `first_seen_ts`, `last_seen_ts`, `user_identity_type`, `user_identity_value`,
                    `current_user_agent_id`, `current_accept_language_id`
             FROM `hilos_analytics_browser_session` WHERE `id` = ?',
            [$old->id],
        );
        $oldRow = Database::row();
        Database::sql(
            'SELECT `first_seen_ts`, `last_seen_ts`, `user_identity_type`, `user_identity_value`,
                    `current_user_agent_id`, `current_accept_language_id`
             FROM `hilos_analytics_browser_session` WHERE `id` = ?',
            [$new->id],
        );
        $newRow = Database::row();
        if ($oldRow === null || $newRow === null) {
            return BrowserSessionRename::ALIASED;
        }

        Database::sql(
            'UPDATE `hilos_analytics_browser_session` SET `first_seen_ts` = ?, `last_seen_ts` = ?,
                    `user_identity_type` = ?, `user_identity_value` = ?,
                    `current_user_agent_id` = ?, `current_accept_language_id` = ? WHERE `id` = ?',
            [min((int)$oldRow['first_seen_ts'], (int)$newRow['first_seen_ts']),
                max($ts, (int)$oldRow['last_seen_ts'], (int)$newRow['last_seen_ts']),
                $newRow['user_identity_type'] ?? $oldRow['user_identity_type'],
                $newRow['user_identity_type'] !== null ? $newRow['user_identity_value'] : $oldRow['user_identity_value'],
                $newRow['current_user_agent_id'] ?? $oldRow['current_user_agent_id'],
                $newRow['current_accept_language_id'] ?? $oldRow['current_accept_language_id'], $new->id],
        );
        foreach (['hilos_analytics_ws_connection', 'hilos_analytics_api_request',
            'hilos_analytics_browser_session_user_agent_change', 'hilos_analytics_browser_session_accept_language_change'] as $table) {
            Database::sql("UPDATE `{$table}` SET `browser_session_id` = ? WHERE `browser_session_id` = ?", [$new->id, $old->id]);
        }
        Database::sql('DELETE FROM `hilos_analytics_browser_session` WHERE `id` = ?', [$old->id]);

        $merged = new BrowserSessionState($new->id,
            isset($newRow['current_user_agent_id']) ? (int)$newRow['current_user_agent_id'] : $old->currentUserAgentId,
            isset($newRow['current_accept_language_id']) ? (int)$newRow['current_accept_language_id'] : $old->currentAcceptLanguageId);
        foreach ($this->cache->browserSessions as $token => $state) {
            if ($state->id === $old->id || $state->id === $new->id) {
                $this->cache->browserSessions[$token] = $merged;
            }
        }
        $this->cache->browserSessions[$oldToken] = $merged;
        $this->cache->browserSessions[$resolvedNewToken] = $merged;

        return BrowserSessionRename::MERGED;
    }

    /**
     * Opens a WebSocket connection row without an owner and caches it by its accept key.
     *
     * @param string $acceptKey WebSocket accept key
     * @param ?string $clientIp Client IP address (IPv4 or IPv6), or null when unknown
     * @param int $ts Moment the connection opened, in milliseconds
     * @throws DatabaseException When the insert fails
     */
    public function openWsConnection(string $acceptKey, ?string $clientIp, int $ts): void
    {
        $ip = $clientIp !== null ? $this->parseIp($clientIp) : new ParsedIp(null, null);

        Database::sql(
            'INSERT INTO `hilos_analytics_ws_connection`
                (`browser_session_id`, `accept_key`, `opened_ipv4`, `opened_ipv6`, `opened_ts`, `closed_ts`)
             VALUES (NULL, ?, ?, UNHEX(?), ?, NULL)
             ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`),
                 `opened_ipv4` = VALUES(`opened_ipv4`), `opened_ipv6` = VALUES(`opened_ipv6`),
                 `opened_ts` = VALUES(`opened_ts`)',
            [$acceptKey, $ip->ipv4, $ip->ipv6Hex, $ts],
        );

        $id = Database::lastInsertId();
        $this->cache->wsConnections[$acceptKey] = new WsConnectionState($id, $ip->ipv4, $ip->ipv6Hex);
    }

    /**
     * Gives the WebSocket connection row the browser session it belongs to.
     *
     * The attachment may reach this writer before the opening from another node. Both upsert
     * the same accept key, and the later opening fills the address and opening moment.
     *
     * @param string $acceptKey WebSocket accept key
     * @param int $browserSessionId Browser session the connection belongs to
     * @param int $ts Moment of the attachment, in milliseconds
     * @throws DatabaseException When the upsert fails
     */
    public function attachWsConnection(string $acceptKey, int $browserSessionId, int $ts): void
    {
        Database::sql(
            'INSERT INTO `hilos_analytics_ws_connection`
                (`browser_session_id`, `accept_key`, `opened_ipv4`, `opened_ipv6`, `opened_ts`, `closed_ts`)
             VALUES (?, ?, NULL, NULL, ?, NULL)
             ON DUPLICATE KEY UPDATE `browser_session_id` = VALUES(`browser_session_id`)',
            [$browserSessionId, $acceptKey, $ts],
        );
    }

    /**
     * Records IPv4/IPv6 changes of an open WS connection against its cached state; nothing for an unknown key.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $clientIp Current client IP address
     * @param int $ts Moment of the change, in milliseconds
     * @return ?int Connection row id, or null when it does not exist
     * @throws DatabaseException When an insert or lookup fails
     */
    public function trackWsConnectionIpChange(string $acceptKey, string $clientIp, int $ts): ?int
    {
        $connection = $this->cache->wsConnections[$acceptKey] ?? null;
        if ($connection === null) {
            Database::sql(
                'SELECT `id`, `opened_ipv4`, HEX(`opened_ipv6`) AS `opened_ipv6_hex`
                 FROM `hilos_analytics_ws_connection` WHERE `accept_key` = ? LIMIT 1',
                [$acceptKey],
            );
            $row = Database::row();
            if ($row === null) {
                return null;
            }
            $id = (int)$row['id'];
            Database::sql(
                'SELECT `new_ipv4` FROM `hilos_analytics_ws_connection_ipv4_change`
                 WHERE `ws_connection_id` = ? ORDER BY `id` DESC LIMIT 1',
                [$id],
            );
            $ipv4Change = Database::row();
            Database::sql(
                'SELECT HEX(`new_ipv6`) AS `new_ipv6_hex` FROM `hilos_analytics_ws_connection_ipv6_change`
                 WHERE `ws_connection_id` = ? ORDER BY `id` DESC LIMIT 1',
                [$id],
            );
            $ipv6Change = Database::row();
            $connection = new WsConnectionState(
                $id,
                $ipv4Change === null ? (isset($row['opened_ipv4']) ? (int)$row['opened_ipv4'] : null)
                    : (isset($ipv4Change['new_ipv4']) ? (int)$ipv4Change['new_ipv4'] : null),
                $ipv6Change === null ? (isset($row['opened_ipv6_hex']) ? strtolower((string)$row['opened_ipv6_hex']) : null)
                    : (isset($ipv6Change['new_ipv6_hex']) ? strtolower((string)$ipv6Change['new_ipv6_hex']) : null),
            );
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
        return $connection->id;
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
        Database::sql(
            'UPDATE `hilos_analytics_ws_connection` SET `closed_ts` = ? WHERE `accept_key` = ?',
            [$ts, $acceptKey],
        );
    }

    /**
     * Opens the page named by its process key. The source sends a separate close for the previous page.
     *
     * @param string $key Page session key
     * @param string $acceptKey WebSocket accept key
     * @param string $pageName Page name
     * @param ?array<string, mixed> $params Page route parameters
     * @param int $ts Opening moment in milliseconds
     * @return ?int Page session id, or null when its connection is unknown
     * @throws DatabaseException When a statement fails
     */
    public function openPageSession(string $key, string $acceptKey, string $pageName, ?array $params, int $ts): ?int
    {
        $connectionId = $this->findWsConnection($acceptKey);
        if ($connectionId === null) {
            return null;
        }
        Database::sql(
            'INSERT INTO `hilos_analytics_page_session`
                (`session_key`, `ws_connection_id`, `page_id`, `page_params_id`, `opened_ts`, `closed_ts`)
             VALUES (UNHEX(?), ?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)',
            [$key, $connectionId, $this->ensurePage($pageName, $ts), $this->ensurePageParams($params, $ts), $ts],
        );
        $id = Database::lastInsertId();
        $this->cache->pageSessions[$key] = $id;
        return $id;
    }

    /**
     * @param string $key Page session key
     * @param ?array<string, mixed> $params New page route parameters
     * @param int $ts Update moment in milliseconds
     * @throws DatabaseException When a statement fails
     */
    public function updatePageSession(string $key, ?array $params, int $ts): void
    {
        $id = $this->findPageSession($key);
        if ($id === null) {
            return;
        }
        Database::sql('UPDATE `hilos_analytics_page_session` SET `page_params_id` = ? WHERE `id` = ?',
            [$this->ensurePageParams($params, $ts), $id]);
    }

    /**
     * @param string $key Page session key
     * @param int $ts Closing moment in milliseconds
     * @throws DatabaseException When the update fails
     */
    public function closePageSession(string $key, int $ts): void
    {
        Database::sql(
            'UPDATE `hilos_analytics_page_session` SET `closed_ts` = ?
             WHERE `session_key` = UNHEX(?) AND `closed_ts` IS NULL',
            [$ts, $key],
        );
    }

    /**
     * Writes a keyed user action and connects agent responses that reached the writer first.
     *
     * @param string $key User action key
     * @param string $acceptKey WebSocket accept key
     * @param ?string $pageKey Current page session key, or null
     * @param string $actionName Client action name
     * @param ?array<string, mixed> $payload Masked action payload
     * @param int $ts Action moment in milliseconds
     * @return ?int Action row id, or null when the connection is unknown
     * @throws DatabaseException When a statement fails
     */
    public function insertUserAction(string $key, string $acceptKey, ?string $pageKey, string $actionName, ?array $payload, int $ts): ?int
    {
        $connectionId = $this->findWsConnection($acceptKey);
        if ($connectionId === null) {
            return null;
        }
        Database::sql(
            'INSERT INTO `hilos_analytics_user_action`
                (`action_key`, `ws_connection_id`, `page_session_id`, `action_name_id`, `payload_json_id`, `created_ts`)
             VALUES (UNHEX(?), ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)',
            [$key, $connectionId, $pageKey === null ? null : $this->findPageSession($pageKey),
                $this->ensureNamedDictionaryValue($actionName, 'hilos_analytics_action_name', $this->cache->actionNameIds, $ts),
                $this->ensurePayloadJson($payload, $ts), $ts],
        );
        $id = Database::lastInsertId();
        Database::sql(
            'UPDATE `hilos_analytics_agent_user_action` SET `user_action_id` = ?
             WHERE `user_action_key` = UNHEX(?) AND `user_action_id` IS NULL',
            [$id, $key],
        );
        return $id;
    }

    /**
     * Writes one completed HTTP request and connects agent facts that reached the writer first.
     *
     * @param string $key Request key
     * @param ?string $sessionToken Browser session token, or null
     * @param string $method HTTP method
     * @param string $path Request path
     * @param ?array<string, mixed> $params Route parameters
     * @param ?string $userAgent User-Agent header
     * @param ?string $acceptLanguage Accept-Language header
     * @param int $startedTs Start moment in milliseconds
     * @param ?int $statusCode HTTP status, or null when the client left
     * @param ?int $durationMs Duration in milliseconds, or null
     * @param int $finishedTs Completion moment in milliseconds
     * @throws DatabaseException When a statement fails
     */
    public function insertApiRequest(
        string $key,
        ?string $sessionToken,
        string $method,
        string $path,
        ?array $params,
        ?string $userAgent,
        ?string $acceptLanguage,
        int $startedTs,
        ?int $statusCode,
        ?int $durationMs,
        int $finishedTs,
    ): void {
        $browserSessionId = $sessionToken === null || $sessionToken === ''
            ? null : $this->ensureBrowserSession($sessionToken, $userAgent, $acceptLanguage, $startedTs);
        Database::sql(
            'INSERT INTO `hilos_analytics_api_request`
                (`request_key`, `browser_session_id`, `method`, `path`, `params_json_id`, `status_code`,
                 `duration_ms`, `started_ts`, `finished_ts`)
             VALUES (UNHEX(?), ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)',
            [$key, $browserSessionId, $method, $path, $this->ensurePageParams($params, $startedTs),
                $statusCode, $durationMs, $startedTs, $finishedTs],
        );
        $id = Database::lastInsertId();
        Database::sql(
            'UPDATE `hilos_analytics_api_agent_action` SET `api_request_id` = ?
             WHERE `api_request_key` = UNHEX(?) AND `api_request_id` IS NULL',
            [$id, $key],
        );
    }

    /**
     * Buffers an agent reaction to a user action until {@see self::flushFacts()}.
     *
     * @param int $agentSessionId Agent session id
     * @param ?string $userActionKey Key of the originating action, or null
     * @param string $signalName Signal name handled by the agent
     * @param ?array<string, mixed> $payload Payload already masked by the source, or null
     * @param int $ts Moment of the event, in milliseconds
     * @throws DatabaseException When a dictionary value cannot be written
     */
    public function addAgentUserAction(int $agentSessionId, ?string $userActionKey, string $signalName, ?array $payload, int $ts): void
    {
        $this->facts[self::TABLE_AGENT_USER_ACTION][] = [
            'agent_session_id' => $agentSessionId,
            self::COLUMN_USER_ACTION_ID => null,
            self::COLUMN_USER_ACTION_KEY => $userActionKey === null ? null : hex2bin($userActionKey),
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
     * @param string $apiRequestKey Key of the originating API request
     * @param int $agentSessionId Agent session id
     * @param string $signalName Signal name dispatched to the agent
     * @param ?array<string, mixed> $payload Payload already masked by the source, or null
     * @param int $ts Moment of the event, in milliseconds
     * @throws DatabaseException When a dictionary value cannot be written
     */
    public function addApiAgentAction(string $apiRequestKey, int $agentSessionId, string $signalName, ?array $payload, int $ts): void
    {
        $this->facts[self::TABLE_API_AGENT_ACTION][] = [
            self::COLUMN_API_REQUEST_ID => null,
            self::COLUMN_API_REQUEST_KEY => hex2bin($apiRequestKey),
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
     * Before each insert the cause keys the portion names are looked up in one select per table.
     * A cause that has not arrived yet leaves its response's row without a number; inserting
     * the cause later fills it.
     *
     * The buffer is emptied before the first insert, so a failure does not write the same rows
     * twice on the next flush.
     *
     * @throws DatabaseException When a lookup or an insert fails
     */
    public function flushFacts(): void
    {
        $facts = $this->facts;
        $this->facts = [];

        foreach ($facts as $table => $rows) {
            foreach (array_chunk($rows, self::FACT_PORTION_ROWS) as $portion) {
                $this->bulkInsert($table, $this->linkCauses($portion));
            }
        }
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
     * Writes a counted loss in the transaction of the journal file that carried it.
     *
     * @param string $nodeId Node whose file carried the count
     * @param string $reason Loss reason or loader skip value
     * @param int $events Events lost
     * @param int $fromTs First loss moment in milliseconds
     * @param int $toTs Last loss moment in milliseconds
     * @throws DatabaseException When the insert fails
     */
    public function insertLoss(string $nodeId, string $reason, int $events, int $fromTs, int $toTs): void
    {
        Database::sql(
            'INSERT INTO `hilos_analytics_loss` (`node_id`, `reason`, `event_count`, `from_ts`, `to_ts`)
             VALUES (?, ?, ?, ?, ?)',
            [$nodeId, $reason, $events, $fromTs, $toTs],
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
     * @param string $acceptKey WebSocket accept key
     * @return ?int Connection row id, or null when no opening or attachment was loaded
     * @throws DatabaseException When the lookup fails
     */
    private function findWsConnection(string $acceptKey): ?int
    {
        if (isset($this->cache->wsConnections[$acceptKey])) {
            return $this->cache->wsConnections[$acceptKey]->id;
        }
        Database::sql('SELECT `id` FROM `hilos_analytics_ws_connection` WHERE `accept_key` = ? LIMIT 1', [$acceptKey]);
        $id = Database::field('id');
        return $id === null ? null : (int)$id;
    }

    /**
     * @param string $key Page session key
     * @return ?int Page session row id, or null when its opening was not loaded
     * @throws DatabaseException When the lookup fails
     */
    private function findPageSession(string $key): ?int
    {
        if (isset($this->cache->pageSessions[$key])) {
            return $this->cache->pageSessions[$key];
        }
        Database::sql('SELECT `id` FROM `hilos_analytics_page_session` WHERE `session_key` = UNHEX(?) LIMIT 1', [$key]);
        $id = Database::field('id');
        if ($id !== null) {
            $this->cache->pageSessions[$key] = (int)$id;
        }
        return $id === null ? null : (int)$id;
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
     * Reads a session under its exact token, without interpreting a cached alias.
     *
     * @param string $token Browser session token
     * @return ?BrowserSessionState Session row, or null when absent
     * @throws DatabaseException When the lookup fails
     */
    private function loadBrowserSessionDirect(string $token): ?BrowserSessionState
    {
        Database::sql(
            'SELECT `id`, `current_user_agent_id`, `current_accept_language_id`
             FROM `hilos_analytics_browser_session` WHERE `session_token` = ? LIMIT 1',
            [$token],
        );
        $row = Database::row();
        return $row === null ? null : new BrowserSessionState(
            (int)$row['id'],
            isset($row['current_user_agent_id']) ? (int)$row['current_user_agent_id'] : null,
            isset($row['current_accept_language_id']) ? (int)$row['current_accept_language_id'] : null,
        );
    }

    /**
     * Follows token renames recorded by earlier files, even when their source session did not exist yet.
     *
     * @param string $token Browser session token
     * @return string Final token, or the original when the chain is invalid
     * @throws DatabaseException When an alias lookup fails
     */
    private function resolveBrowserSessionToken(string $token): string
    {
        $original = $token;
        $seen = [];
        for ($hop = 0; $hop <= self::MAX_TOKEN_ALIAS_HOPS; $hop++) {
            if (isset($seen[$token])) {
                Logger::warning('Analytics browser-session alias cycle');
                return $original;
            }
            $seen[$token] = true;
            Database::sql(
                'SELECT `new_token` FROM `hilos_analytics_browser_session_alias` WHERE `old_token` = ? LIMIT 1',
                [$token],
            );
            $next = Database::field('new_token');
            if ($next === null) {
                return $token;
            }
            if ($hop === self::MAX_TOKEN_ALIAS_HOPS) {
                Logger::warning('Analytics browser-session alias chain exceeded its limit');
                return $original;
            }
            $token = (string)$next;
        }

        return $original;
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
     * Resolves any cause already loaded, with one lookup per key column for the portion.
     * An absent cause keeps its key and null number; its later insert fills that number.
     *
     * @param list<array<string, int|string|null>> $rows Fact rows of one table
     * @return list<array<string, int|string|null>> Rows with known cause numbers
     * @throws DatabaseException When a lookup fails
     */
    private function linkCauses(array $rows): array
    {
        foreach ([
            [self::COLUMN_USER_ACTION_KEY, self::COLUMN_USER_ACTION_ID, 'hilos_analytics_user_action', 'action_key'],
            [self::COLUMN_API_REQUEST_KEY, self::COLUMN_API_REQUEST_ID, 'hilos_analytics_api_request', 'request_key'],
        ] as [$causeKeyColumn, $causeIdColumn, $table, $sourceKeyColumn]) {
            $wanted = array_values(array_unique(array_filter(
                array_column($rows, $causeKeyColumn), static fn(?string $key): bool => $key !== null,
            )));
            if ($wanted === []) {
                continue;
            }
            Database::sql(
                "SELECT `id`, `{$sourceKeyColumn}` FROM `{$table}` WHERE `{$sourceKeyColumn}` IN ("
                . implode(', ', array_fill(0, count($wanted), '?')) . ')',
                $wanted,
            );
            $found = [];
            foreach (Database::rows() as $source) {
                $found[$source[$sourceKeyColumn]] = (int)$source['id'];
            }
            foreach ($rows as &$row) {
                $key = $row[$causeKeyColumn] ?? null;
                if ($key !== null && isset($found[$key])) {
                    $row[$causeIdColumn] = $found[$key];
                }
            }
            unset($row);
        }
        return $rows;
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
