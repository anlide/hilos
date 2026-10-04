<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * Row numbers {@see AnalyticsStore} already knows, so it does not ask the database twice.
 *
 * One object, so the store can set the whole of it aside while a transaction runs and take it
 * back when the transaction is rolled back: a number learned inside a rolled-back transaction
 * names a row the database never kept. The snapshot is a shallow clone, which is enough because
 * nothing here is changed in place - a cached session state is replaced, never mutated.
 */
final class AnalyticsIdCache
{
    /** @var array<string, int> User-Agent value to its dictionary id */
    public array $userAgentIds = [];

    /** @var array<string, int> Accept-Language value to its dictionary id */
    public array $acceptLanguageIds = [];

    /** @var array<string, int> Page name to its dictionary id */
    public array $pageIds = [];

    /** @var array<string, int> Normalized page params JSON to its dictionary id */
    public array $pageParamsIds = [];

    /** @var array<string, int> Action name to its dictionary id */
    public array $actionNameIds = [];

    /** @var array<string, int> Signal name to its dictionary id */
    public array $signalNameIds = [];

    /** @var array<string, int> Cron name to its dictionary id */
    public array $cronNameIds = [];

    /** @var array<string, int> Normalized payload JSON to its dictionary id */
    public array $payloadIds = [];

    /** @var array<string, BrowserSessionState> Session token to the browser session row */
    public array $browserSessions = [];

    /** @var array<string, WsConnectionState> Accept key to the WebSocket connection row */
    public array $wsConnections = [];

    /** @var array<string, int> Page session key to its row id */
    public array $pageSessions = [];

    /** @var array<string, int> Worker session key to its row id */
    public array $workerSessionIds = [];

    /** @var array<string, int> Agent session key to its row id */
    public array $agentSessionIds = [];
}
