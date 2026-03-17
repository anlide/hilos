<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Database\Database;
use Hilos\Utils\Logger;

/**
 * Collects raw analytics data into normalized SQL tables.
 */
final class AnalyticsCollector
{
    public const string META_API_REQUEST_ID = 'apiRequestId';
    public const string META_USER_ACTION_ID = 'userActionId';

    private const int BUFFER_SIZE = 100;
    private const int FLUSH_INTERVAL_MS = 5000;

    private bool $enabled = true;
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

    /** @var array<string, array{id: int, currentUserAgentId: ?int, currentAcceptLanguageId: ?int}> */
    private array $browserSessions = [];

    /** @var array<string, array{id: int, browserSessionId: ?int, currentIpv4: ?int, currentIpv6Hex: ?string}> */
    private array $wsConnections = [];

    /** @var array<string, int> */
    private array $pageSessions = [];

    private ?int $workerSessionId = null;

    /** @var array<string, int> */
    private array $agentSessions = [];

    private ?int $activeApiRequestId = null;
    private ?int $activeUserActionId = null;

    public function __construct()
    {
        $this->lastFlushTs = $this->nowTs();
    }

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
                        (`session_token`, `user_identity_type`, `user_identity_value`, `current_user_agent_id`, `current_accept_language_id`, `first_seen_ts`, `last_seen_ts`)
                     VALUES (?, NULL, NULL, ?, ?, ?, ?)',
                    [$sessionToken, $userAgentId, $acceptLanguageId, $nowTs, $nowTs],
                );

                $id = Database::lastInsertId();
                $this->browserSessions[$sessionToken] = [
                    'id' => $id,
                    'currentUserAgentId' => $userAgentId,
                    'currentAcceptLanguageId' => $acceptLanguageId,
                ];

                return $id;
            }

            $updates = ['`last_seen_ts` = ?'];
            $params = [$nowTs];

            if ($existing['currentUserAgentId'] !== $userAgentId && $userAgentId !== null) {
                Database::sql(
                    'INSERT INTO `hilos_analytics_browser_session_user_agent_change`
                        (`browser_session_id`, `old_user_agent_id`, `new_user_agent_id`, `changed_ts`)
                     VALUES (?, ?, ?, ?)',
                    [$existing['id'], $existing['currentUserAgentId'], $userAgentId, $nowTs],
                );
                $updates[] = '`current_user_agent_id` = ?';
                $params[] = $userAgentId;
                $existing['currentUserAgentId'] = $userAgentId;
            }

            if ($existing['currentAcceptLanguageId'] !== $acceptLanguageId && $acceptLanguageId !== null) {
                Database::sql(
                    'INSERT INTO `hilos_analytics_browser_session_accept_language_change`
                        (`browser_session_id`, `old_accept_language_id`, `new_accept_language_id`, `changed_ts`)
                     VALUES (?, ?, ?, ?)',
                    [$existing['id'], $existing['currentAcceptLanguageId'], $acceptLanguageId, $nowTs],
                );
                $updates[] = '`current_accept_language_id` = ?';
                $params[] = $acceptLanguageId;
                $existing['currentAcceptLanguageId'] = $acceptLanguageId;
            }

            $params[] = $existing['id'];
            Database::sql(
                'UPDATE `hilos_analytics_browser_session` SET ' . implode(', ', $updates) . ' WHERE `id` = ?',
                $params,
            );

            $this->browserSessions[$sessionToken] = $existing;
            return $existing['id'];
        });
    }

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

    public function openWsConnection(string $acceptKey, ?string $sessionToken, string $clientIp): ?int
    {
        if ($acceptKey === '') {
            return null;
        }

        return $this->runSafely(function () use ($acceptKey, $sessionToken, $clientIp): ?int {
            $browserSessionId = $sessionToken !== null && $sessionToken !== ''
                ? ($this->browserSessions[$sessionToken]['id'] ?? $this->ensureBrowserSession($sessionToken, null, null))
                : null;

            $ip = $this->parseIp($clientIp);
            $nowTs = $this->nowTs();

            Database::sql(
                'INSERT INTO `hilos_analytics_ws_connection`
                    (`browser_session_id`, `accept_key`, `opened_ipv4`, `opened_ipv6`, `opened_ts`, `closed_ts`)
                 VALUES (?, ?, ?, UNHEX(?), ?, NULL)',
                [$browserSessionId, $acceptKey, $ip['ipv4'], $ip['ipv6Hex'], $nowTs],
            );

            $id = Database::lastInsertId();
            $this->wsConnections[$acceptKey] = [
                'id' => $id,
                'browserSessionId' => $browserSessionId,
                'currentIpv4' => $ip['ipv4'],
                'currentIpv6Hex' => $ip['ipv6Hex'],
            ];

            return $id;
        });
    }

    public function trackWsConnectionIpChange(string $acceptKey, string $clientIp): void
    {
        if ($acceptKey === '' || $clientIp === '') {
            return;
        }

        $this->runSafely(function () use ($acceptKey, $clientIp): void {
            $connection = $this->wsConnections[$acceptKey] ?? null;
            if ($connection === null) {
                return;
            }

            $parsed = $this->parseIp($clientIp);
            $nowTs = $this->nowTs();

            if ($connection['currentIpv4'] !== $parsed['ipv4']) {
                Database::sql(
                    'INSERT INTO `hilos_analytics_ws_connection_ipv4_change`
                        (`ws_connection_id`, `old_ipv4`, `new_ipv4`, `changed_ts`)
                     VALUES (?, ?, ?, ?)',
                    [$connection['id'], $connection['currentIpv4'], $parsed['ipv4'], $nowTs],
                );
                $connection['currentIpv4'] = $parsed['ipv4'];
            }

            if ($connection['currentIpv6Hex'] !== $parsed['ipv6Hex']) {
                Database::sql(
                    'INSERT INTO `hilos_analytics_ws_connection_ipv6_change`
                        (`ws_connection_id`, `old_ipv6`, `new_ipv6`, `changed_ts`)
                     VALUES (?, UNHEX(?), UNHEX(?), ?)',
                    [$connection['id'], $connection['currentIpv6Hex'], $parsed['ipv6Hex'], $nowTs],
                );
                $connection['currentIpv6Hex'] = $parsed['ipv6Hex'];
            }

            $this->wsConnections[$acceptKey] = $connection;
        });
    }

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
                [$this->nowTs(), $connection['id']],
            );
        });
    }

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
                [$connection['id'], $pageId, $pageParamsId, $this->nowTs()],
            );

            $id = Database::lastInsertId();
            $this->pageSessions[$acceptKey] = $id;

            return $id;
        });
    }

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
                    $connection['id'],
                    $this->pageSessions[$acceptKey] ?? null,
                    $this->ensureActionName($actionName),
                    $this->ensurePayloadJson($payload),
                    $this->nowTs(),
                ],
            );

            return Database::lastInsertId();
        });
    }

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

    public function startApiRequest(?string $sessionToken, string $method, string $path, ?array $params, ?string $userAgent, ?string $acceptLanguage): ?int
    {
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

    public function ensurePageParams(?array $params): ?int
    {
        return $this->ensureJsonDictionaryValue($params, 'hilos_analytics_page_params', 'params_json', $this->pageParamsIds);
    }

    public function ensureActionName(string $name): int
    {
        return $this->ensureNamedDictionaryValue($name, 'hilos_analytics_action_name', $this->actionNameIds);
    }

    public function ensureSignalName(string $name): int
    {
        return $this->ensureNamedDictionaryValue($name, 'hilos_analytics_signal_name', $this->signalNameIds);
    }

    public function ensureCronName(string $name): int
    {
        return $this->ensureNamedDictionaryValue($name, 'hilos_analytics_cron_name', $this->cronNameIds);
    }

    public function ensurePayloadJson(?array $payload): ?int
    {
        return $this->ensureJsonDictionaryValue($payload, 'hilos_analytics_payload_json', 'payload_json', $this->payloadIds);
    }

    public function startUserActionCapture(?int $userActionId): void
    {
        $this->activeUserActionId = $userActionId;
    }

    public function clearUserActionCapture(): void
    {
        $this->activeUserActionId = null;
    }

    /**
     * @return array<string, int>
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

    public function getSignalMetaInt(SignalDTO $signal, string $key): ?int
    {
        $value = $signal->meta[$key] ?? null;
        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        return is_numeric($value) ? (int)$value : null;
    }

    public function tick(): void
    {
        if (!$this->enabled) {
            return;
        }

        if (($this->nowTs() - $this->lastFlushTs) >= self::FLUSH_INTERVAL_MS) {
            $this->flush();
        }
    }

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

    public function shutdown(): void
    {
        $this->flush();
        $this->activeApiRequestId = null;
        $this->activeUserActionId = null;
    }

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
     * @param array<string, int> $cache
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
     * @param array<string, int> $cache
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
     * @return ?array{id: int, currentUserAgentId: ?int, currentAcceptLanguageId: ?int}
     */
    private function loadBrowserSession(string $sessionToken): ?array
    {
        return $this->runSafely(function () use ($sessionToken): ?array {
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

            $result = [
                'id' => (int)$row['id'],
                'currentUserAgentId' => isset($row['current_user_agent_id']) ? (int)$row['current_user_agent_id'] : null,
                'currentAcceptLanguageId' => isset($row['current_accept_language_id']) ? (int)$row['current_accept_language_id'] : null,
            ];
            $this->browserSessions[$sessionToken] = $result;

            return $result;
        });
    }

    private function getAgentSessionId(string $agentType, ?string $agentIndex): ?int
    {
        return $this->agentSessions[$this->buildAgentKey($agentType, $agentIndex)] ?? null;
    }

    private function buildAgentKey(string $agentType, ?string $agentIndex): string
    {
        return $agentType . '::' . ($agentIndex ?? '');
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function queueBufferedInsert(string $table, array $row): void
    {
        $this->buffers[$table][] = $row;

        if ($this->getBufferedRowsCount() >= self::BUFFER_SIZE) {
            $this->flush();
        }
    }

    private function getBufferedRowsCount(): int
    {
        $count = 0;
        foreach ($this->buffers as $rows) {
            $count += count($rows);
        }
        return $count;
    }

    /**
     * @param list<array<string, int|string|null>> $rows
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
     * @return array{ipv4: ?int, ipv6Hex: ?string}
     */
    private function parseIp(string $ip): array
    {
        if ($ip === '') {
            return ['ipv4' => null, 'ipv6Hex' => null];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipv4 = ip2long($ip);
            return [
                'ipv4' => $ipv4 === false ? null : (int)sprintf('%u', $ipv4),
                'ipv6Hex' => null,
            ];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $binary = inet_pton($ip);
            return [
                'ipv4' => null,
                'ipv6Hex' => $binary === false ? null : bin2hex($binary),
            ];
        }

        return ['ipv4' => null, 'ipv6Hex' => null];
    }

    private function nowTs(): int
    {
        return (int)floor(microtime(true) * 1000);
    }

    private function normalizeJson(?array $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        $normalized = $this->sortRecursive($value);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

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
