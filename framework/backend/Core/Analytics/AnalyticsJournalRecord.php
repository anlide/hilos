<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * The wire of the analytics journal: one record is one JSON object on one line of a journal file.
 *
 * The journal is an internal contract of two agents - the journal agent of a node writes the
 * lines a process handed it, the writer reads them back and loads them into the analytics tables
 * ({@see AnalyticsJournalLoader}) - and of the processes that build the records. This class is the
 * one place the names of the keys live, for the side that builds a record and the side that reads
 * it alike; the catalog itself is in docs/agents/architecture/analytics.md.
 *
 * A session of a worker or of an agent is named by a key its process drew in memory: 32 lowercase
 * hex characters, stored as `UNHEX()` of it. Every moment is the unix time in milliseconds at the
 * source, not at the writer. New action and signal records carry no payload; the nullable
 * payload field remains for older journal files.
 *
 * The builders return raw arrays on purpose: a record is handed to `json_encode()` and back, and a
 * value object in between would only be taken apart again.
 */
final class AnalyticsJournalRecord
{
    public const int VERSION = 2;

    public const string KEY_TYPE = 't';
    public const string KEY_VERSION = 'v';
    public const string KEY_NODE = 'node';
    public const string KEY_OPENED_TS = 'openedTs';
    public const string KEY_KEY = 'key';
    public const string KEY_WORKER_INDEX = 'workerIndex';
    public const string KEY_MONOPOLISTIC = 'monopolistic';
    public const string KEY_STARTED_TS = 'startedTs';
    public const string KEY_TS = 'ts';
    public const string KEY_WORKER_KEY = 'workerKey';
    public const string KEY_AGENT_TYPE = 'agentType';
    public const string KEY_AGENT_INDEX = 'agentIndex';
    public const string KEY_AGENT_KEY = 'agentKey';
    public const string KEY_SIGNAL = 'signal';
    public const string KEY_CRON = 'cron';
    public const string KEY_PAYLOAD = 'payload';
    public const string KEY_ACCEPT_KEY = 'acceptKey';
    public const string KEY_SESSION_TOKEN = 'sessionToken';
    public const string KEY_USER_AGENT = 'userAgent';
    public const string KEY_ACCEPT_LANGUAGE = 'acceptLanguage';
    public const string KEY_OLD_TOKEN = 'oldToken';
    public const string KEY_NEW_TOKEN = 'newToken';
    public const string KEY_IDENTITY_TYPE = 'identityType';
    public const string KEY_IDENTITY_VALUE = 'identityValue';
    public const string KEY_USER_ACTION_KEY = 'userActionKey';
    public const string KEY_API_REQUEST_KEY = 'apiRequestKey';
    public const string KEY_PAGE_KEY = 'pageKey';
    public const string KEY_IP = 'ip';
    public const string KEY_PAGE = 'page';
    public const string KEY_PARAMS = 'params';
    public const string KEY_ACTION = 'action';
    public const string KEY_METHOD = 'method';
    public const string KEY_PATH = 'path';
    public const string KEY_STATUS = 'status';
    public const string KEY_DURATION_MS = 'durationMs';
    public const string KEY_REASON = 'reason';
    public const string KEY_EVENTS = 'events';
    public const string KEY_FROM_TS = 'fromTs';
    public const string KEY_TO_TS = 'toTs';
    public const string KEY_CLOSED_TS = 'closedTs';
    public const string KEY_USER_ID = 'userId';
    public const string KEY_SUBJECT_USER_ID = 'subjectUserId';
    public const string KEY_SESSION_ID = 'sessionId';
    public const string KEY_EVENT_KIND = 'eventKind';

    public const string TYPE_JOURNAL = 'journal';
    public const string TYPE_JOURNAL_END = 'journal_end';
    public const string TYPE_LOSS = 'loss';
    public const string TYPE_WORKER_SESSION = 'worker_session';
    public const string TYPE_WORKER_SESSION_STOP = 'worker_session_stop';
    public const string TYPE_AGENT_SESSION = 'agent_session';
    public const string TYPE_AGENT_SESSION_STOP = 'agent_session_stop';
    public const string TYPE_AGENT_USER_ACTION = 'agent_user_action';
    public const string TYPE_AGENT_SYSTEM_SIGNAL = 'agent_system_signal';
    public const string TYPE_AGENT_CRON_SIGNAL = 'agent_cron_signal';
    public const string TYPE_WORKER_SYSTEM_SIGNAL = 'worker_system_signal';
    public const string TYPE_API_AGENT_ACTION = 'api_agent_action';
    public const string TYPE_WS_CONNECTION_ATTACH = 'ws_connection_attach';
    public const string TYPE_BROWSER_SESSION_RENAME = 'browser_session_rename';
    public const string TYPE_BROWSER_SESSION_IDENTITY = 'browser_session_identity';
    public const string TYPE_WS_CONNECTION_OPEN = 'ws_connection_open';
    public const string TYPE_WS_CONNECTION_CLOSE = 'ws_connection_close';
    public const string TYPE_WS_CONNECTION_IP_CHANGE = 'ws_connection_ip_change';
    public const string TYPE_PAGE_SESSION_OPEN = 'page_session_open';
    public const string TYPE_PAGE_SESSION_UPDATE = 'page_session_update';
    public const string TYPE_PAGE_SESSION_CLOSE = 'page_session_close';
    public const string TYPE_USER_ACTION = 'user_action';
    public const string TYPE_API_REQUEST = 'api_request';
    public const string TYPE_PERSON_EVENT = 'person_event';

    /** @var string A session key as it travels: 16 random bytes in lowercase hex */
    public const string SESSION_KEY_PATTERN = '/^[0-9a-f]{32}$/';

    /**
     * A journal line must fit in a portion before it crosses the 8 MiB peer link buffer.
     * A browser action has no comparable size limit, so its payload may exceed this by itself.
     */
    public const int MAX_LINE_BYTES = 131072;

    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * The first line of every journal file, written by the journal agent when it opens the file.
     *
     * @param string $node Cluster node id of the file's node, '' outside a cluster
     * @param int $openedTs Moment the file was opened, in milliseconds
     * @return array<string, int|string> Record
     */
    public static function journal(string $node, int $openedTs): array
    {
        return [
            self::KEY_TYPE => self::TYPE_JOURNAL,
            self::KEY_VERSION => self::VERSION,
            self::KEY_NODE => $node,
            self::KEY_OPENED_TS => $openedTs,
        ];
    }

    /**
     * @param AnalyticsLossReason $reason Why events were lost
     * @param int $events Number of events lost
     * @param int $fromTs First loss moment in milliseconds
     * @param int $toTs Last loss moment in milliseconds
     * @return array<string, int|string> Loss record
     */
    public static function loss(AnalyticsLossReason $reason, int $events, int $fromTs, int $toTs): array
    {
        return [
            self::KEY_TYPE => self::TYPE_LOSS,
            self::KEY_REASON => $reason->value,
            self::KEY_EVENTS => $events,
            self::KEY_FROM_TS => $fromTs,
            self::KEY_TO_TS => $toTs,
        ];
    }

    /**
     * @param int $events Events the file accepted
     * @param int $closedTs Closing moment in milliseconds
     * @return array<string, int|string> Last record of a ready file
     */
    public static function journalEnd(int $events, int $closedTs): array
    {
        return [
            self::KEY_TYPE => self::TYPE_JOURNAL_END,
            self::KEY_EVENTS => $events,
            self::KEY_CLOSED_TS => $closedTs,
        ];
    }

    /**
     * @param string $type Journal record type
     * @return bool Whether the record counts as an event
     */
    public static function isEvent(string $type): bool
    {
        return !in_array($type, [
            self::TYPE_JOURNAL,
            self::TYPE_JOURNAL_END,
            self::TYPE_WORKER_SESSION,
            self::TYPE_AGENT_SESSION,
            self::TYPE_LOSS,
        ], true);
    }

    /**
     * Describes a worker session; the writer inserts it by its key, or finds the row it already wrote.
     *
     * @param string $key Session key of the worker
     * @param int $workerIndex Worker index within the daemon
     * @param bool $monopolistic Whether the worker runs monopolistic agents
     * @param int $startedTs Moment the worker session started, in milliseconds
     * @return array<string, bool|int|string> Record
     */
    public static function workerSession(string $key, int $workerIndex, bool $monopolistic, int $startedTs): array
    {
        return [
            self::KEY_TYPE => self::TYPE_WORKER_SESSION,
            self::KEY_KEY => $key,
            self::KEY_WORKER_INDEX => $workerIndex,
            self::KEY_MONOPOLISTIC => $monopolistic,
            self::KEY_STARTED_TS => $startedTs,
        ];
    }

    /**
     * Marks a worker session stopped.
     *
     * @param string $key Session key of the worker
     * @param int $ts Moment the worker stopped, in milliseconds
     * @return array<string, int|string> Record
     */
    public static function workerSessionStop(string $key, int $ts): array
    {
        return [
            self::KEY_TYPE => self::TYPE_WORKER_SESSION_STOP,
            self::KEY_KEY => $key,
            self::KEY_TS => $ts,
        ];
    }

    /**
     * Describes an agent session under a worker session.
     *
     * @param string $key Session key of the agent
     * @param string $workerKey Session key of the worker the agent lives on
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     * @param int $startedTs Moment the agent session started, in milliseconds
     * @return array<string, int|string|null> Record
     */
    public static function agentSession(string $key, string $workerKey, string $agentType, ?string $agentIndex, int $startedTs): array
    {
        return [
            self::KEY_TYPE => self::TYPE_AGENT_SESSION,
            self::KEY_KEY => $key,
            self::KEY_WORKER_KEY => $workerKey,
            self::KEY_AGENT_TYPE => $agentType,
            self::KEY_AGENT_INDEX => $agentIndex,
            self::KEY_STARTED_TS => $startedTs,
        ];
    }

    /**
     * Marks an agent session stopped.
     *
     * @param string $key Session key of the agent
     * @param int $ts Moment the agent stopped, in milliseconds
     * @return array<string, int|string> Record
     */
    public static function agentSessionStop(string $key, int $ts): array
    {
        return [
            self::KEY_TYPE => self::TYPE_AGENT_SESSION_STOP,
            self::KEY_KEY => $key,
            self::KEY_TS => $ts,
        ];
    }

    /**
     * An agent's reaction to a user action.
     *
     * @param string $agentKey Session key of the agent
     * @param ?string $userActionKey Key of the user action, or null when uncorrelated
     * @param string $signal Signal name handled by the agent
     * @param ?array<string, mixed> $payload Masked payload, or null
     * @param int $ts Moment of the event, in milliseconds
     * @return array<string, mixed> Record
     */
    public static function agentUserAction(string $agentKey, ?string $userActionKey, string $signal, ?array $payload, int $ts): array
    {
        return [
            self::KEY_TYPE => self::TYPE_AGENT_USER_ACTION,
            self::KEY_AGENT_KEY => $agentKey,
            self::KEY_USER_ACTION_KEY => $userActionKey,
            self::KEY_SIGNAL => $signal,
            self::KEY_PAYLOAD => $payload,
            self::KEY_TS => $ts,
        ];
    }

    /**
     * A system signal delivered to an agent.
     *
     * @param string $agentKey Session key of the agent
     * @param string $signal System signal name
     * @param ?array<string, mixed> $payload Payload, or null
     * @param int $ts Moment of the event, in milliseconds
     * @return array<string, mixed> Record
     */
    public static function agentSystemSignal(string $agentKey, string $signal, ?array $payload, int $ts): array
    {
        return [
            self::KEY_TYPE => self::TYPE_AGENT_SYSTEM_SIGNAL,
            self::KEY_AGENT_KEY => $agentKey,
            self::KEY_SIGNAL => $signal,
            self::KEY_PAYLOAD => $payload,
            self::KEY_TS => $ts,
        ];
    }

    /**
     * A cron signal delivered to an agent.
     *
     * @param string $agentKey Session key of the agent
     * @param string $cron Cron job name
     * @param ?array<string, mixed> $payload Payload, or null
     * @param int $ts Moment of the event, in milliseconds
     * @return array<string, mixed> Record
     */
    public static function agentCronSignal(string $agentKey, string $cron, ?array $payload, int $ts): array
    {
        return [
            self::KEY_TYPE => self::TYPE_AGENT_CRON_SIGNAL,
            self::KEY_AGENT_KEY => $agentKey,
            self::KEY_CRON => $cron,
            self::KEY_PAYLOAD => $payload,
            self::KEY_TS => $ts,
        ];
    }

    /**
     * A system signal delivered to the worker itself.
     *
     * @param string $workerKey Session key of the worker
     * @param string $signal System signal name
     * @param ?array<string, mixed> $payload Payload, or null
     * @param int $ts Moment of the event, in milliseconds
     * @return array<string, mixed> Record
     */
    public static function workerSystemSignal(string $workerKey, string $signal, ?array $payload, int $ts): array
    {
        return [
            self::KEY_TYPE => self::TYPE_WORKER_SYSTEM_SIGNAL,
            self::KEY_WORKER_KEY => $workerKey,
            self::KEY_SIGNAL => $signal,
            self::KEY_PAYLOAD => $payload,
            self::KEY_TS => $ts,
        ];
    }

    /**
     * A signal an agent received inside an HTTP request.
     *
     * @param string $apiRequestKey Key of the originating API request
     * @param string $agentKey Session key of the agent
     * @param string $signal Signal name dispatched to the agent
     * @param ?array<string, mixed> $payload Masked payload, or null
     * @param int $ts Moment of the event, in milliseconds
     * @return array<string, mixed> Record
     */
    public static function apiAgentAction(string $apiRequestKey, string $agentKey, string $signal, ?array $payload, int $ts): array
    {
        return [
            self::KEY_TYPE => self::TYPE_API_AGENT_ACTION,
            self::KEY_API_REQUEST_KEY => $apiRequestKey,
            self::KEY_AGENT_KEY => $agentKey,
            self::KEY_SIGNAL => $signal,
            self::KEY_PAYLOAD => $payload,
            self::KEY_TS => $ts,
        ];
    }

    /**
     * Gives the WebSocket connection the master opened the browser session its handshake resolved.
     *
     * @param string $acceptKey WebSocket accept key of the connection
     * @param string $sessionToken Browser session token
     * @param ?string $userAgent Raw User-Agent header, or null
     * @param ?string $acceptLanguage Raw Accept-Language header, or null
     * @param int $ts Moment of the handshake, in milliseconds
     * @return array<string, int|string|null> Record
     */
    public static function wsConnectionAttach(
        string $acceptKey,
        string $sessionToken,
        ?string $userAgent,
        ?string $acceptLanguage,
        int $ts,
    ): array {
        return [
            self::KEY_TYPE => self::TYPE_WS_CONNECTION_ATTACH,
            self::KEY_ACCEPT_KEY => $acceptKey,
            self::KEY_SESSION_TOKEN => $sessionToken,
            self::KEY_USER_AGENT => $userAgent,
            self::KEY_ACCEPT_LANGUAGE => $acceptLanguage,
            self::KEY_TS => $ts,
        ];
    }

    /**
     * @param string $acceptKey WebSocket accept key
     * @param ?string $ip Client address, or null
     * @param int $ts Opening moment in milliseconds
     * @return array<string, int|string|null> Record
     */
    public static function wsConnectionOpen(string $acceptKey, ?string $ip, int $ts): array
    {
        return [self::KEY_TYPE => self::TYPE_WS_CONNECTION_OPEN, self::KEY_ACCEPT_KEY => $acceptKey, self::KEY_IP => $ip, self::KEY_TS => $ts];
    }

    /**
     * @param string $acceptKey WebSocket accept key
     * @param int $ts Closing moment in milliseconds
     * @return array<string, int|string> Record
     */
    public static function wsConnectionClose(string $acceptKey, int $ts): array
    {
        return [self::KEY_TYPE => self::TYPE_WS_CONNECTION_CLOSE, self::KEY_ACCEPT_KEY => $acceptKey, self::KEY_TS => $ts];
    }

    /**
     * @param string $acceptKey WebSocket accept key
     * @param string $ip New client address
     * @param int $ts Change moment in milliseconds
     * @return array<string, int|string> Record
     */
    public static function wsConnectionIpChange(string $acceptKey, string $ip, int $ts): array
    {
        return [self::KEY_TYPE => self::TYPE_WS_CONNECTION_IP_CHANGE, self::KEY_ACCEPT_KEY => $acceptKey, self::KEY_IP => $ip, self::KEY_TS => $ts];
    }

    /**
     * @param string $key Page session key
     * @param string $acceptKey WebSocket accept key
     * @param string $page Page name
     * @param ?array<string, mixed> $params Page route params
     * @param int $ts Opening moment in milliseconds
     * @return array<string, mixed> Record
     */
    public static function pageSessionOpen(string $key, string $acceptKey, string $page, ?array $params, int $ts): array
    {
        return [self::KEY_TYPE => self::TYPE_PAGE_SESSION_OPEN, self::KEY_KEY => $key, self::KEY_ACCEPT_KEY => $acceptKey,
            self::KEY_PAGE => $page, self::KEY_PARAMS => $params, self::KEY_TS => $ts];
    }

    /**
     * @param string $key Page session key
     * @param ?array<string, mixed> $params Page route params
     * @param int $ts Update moment in milliseconds
     * @return array<string, mixed> Record
     */
    public static function pageSessionUpdate(string $key, ?array $params, int $ts): array
    {
        return [self::KEY_TYPE => self::TYPE_PAGE_SESSION_UPDATE, self::KEY_KEY => $key, self::KEY_PARAMS => $params, self::KEY_TS => $ts];
    }

    /**
     * @param string $key Page session key
     * @param int $ts Closing moment in milliseconds
     * @return array<string, int|string> Record
     */
    public static function pageSessionClose(string $key, int $ts): array
    {
        return [self::KEY_TYPE => self::TYPE_PAGE_SESSION_CLOSE, self::KEY_KEY => $key, self::KEY_TS => $ts];
    }

    /**
     * @param string $key User action key
     * @param string $acceptKey WebSocket accept key
     * @param ?string $pageKey Current page key, or null
     * @param string $action Action name
     * @param ?array<string, mixed> $payload Masked action payload
     * @param int $ts Action moment in milliseconds
     * @return array<string, mixed> Record
     */
    public static function userAction(string $key, string $acceptKey, ?string $pageKey, string $action, ?array $payload, int $ts): array
    {
        return [self::KEY_TYPE => self::TYPE_USER_ACTION, self::KEY_KEY => $key, self::KEY_ACCEPT_KEY => $acceptKey,
            self::KEY_PAGE_KEY => $pageKey, self::KEY_ACTION => $action, self::KEY_PAYLOAD => $payload, self::KEY_TS => $ts];
    }

    /**
     * @param AnalyticsPersonEvent $event Event with a proven authenticated actor
     * @return array<string, mixed> Record
     */
    public static function personEvent(AnalyticsPersonEvent $event): array
    {
        return [
            self::KEY_TYPE => self::TYPE_PERSON_EVENT,
            self::KEY_SESSION_TOKEN => $event->sessionToken,
            self::KEY_USER_ID => $event->userId,
            self::KEY_SUBJECT_USER_ID => $event->subjectUserId,
            self::KEY_SESSION_ID => $event->sessionId,
            self::KEY_EVENT_KIND => $event->eventKind,
            self::KEY_ACTION => $event->action,
            self::KEY_PAGE => $event->page,
            self::KEY_PARAMS => $event->params,
            self::KEY_IP => $event->ip,
            self::KEY_TS => $event->ts,
        ];
    }

    /**
     * @param AnalyticsApiRequest $request Request description
     * @param ?int $status HTTP status, or null
     * @param ?int $durationMs Elapsed milliseconds, or null
     * @param int $ts Completion moment in milliseconds
     * @return array<string, mixed> Record
     */
    public static function apiRequest(AnalyticsApiRequest $request, ?int $status, ?int $durationMs, int $ts): array
    {
        return [self::KEY_TYPE => self::TYPE_API_REQUEST, self::KEY_KEY => $request->key,
            self::KEY_SESSION_TOKEN => $request->sessionToken, self::KEY_METHOD => $request->method,
            self::KEY_PATH => $request->path, self::KEY_PARAMS => $request->params,
            self::KEY_USER_AGENT => $request->userAgent, self::KEY_ACCEPT_LANGUAGE => $request->acceptLanguage,
            self::KEY_STARTED_TS => $request->startedTs, self::KEY_STATUS => $status,
            self::KEY_DURATION_MS => $durationMs, self::KEY_TS => $ts];
    }

    /**
     * Moves a browser session onto the token a login rotated it to.
     *
     * @param string $oldToken Token the session answered to before
     * @param string $newToken Token the session answers to now
     * @param int $ts Moment of the rotation, in milliseconds
     * @return array<string, int|string> Record
     */
    public static function browserSessionRename(string $oldToken, string $newToken, int $ts): array
    {
        return [
            self::KEY_TYPE => self::TYPE_BROWSER_SESSION_RENAME,
            self::KEY_OLD_TOKEN => $oldToken,
            self::KEY_NEW_TOKEN => $newToken,
            self::KEY_TS => $ts,
        ];
    }

    /**
     * Gives a browser session the identity it signed in with.
     *
     * @param string $sessionToken Browser session token
     * @param string $identityType Identity type tag
     * @param string $identityValue Identity value
     * @param int $ts Moment of the identification, in milliseconds
     * @return array<string, int|string> Record
     */
    public static function browserSessionIdentity(string $sessionToken, string $identityType, string $identityValue, int $ts): array
    {
        return [
            self::KEY_TYPE => self::TYPE_BROWSER_SESSION_IDENTITY,
            self::KEY_SESSION_TOKEN => $sessionToken,
            self::KEY_IDENTITY_TYPE => $identityType,
            self::KEY_IDENTITY_VALUE => $identityValue,
            self::KEY_TS => $ts,
        ];
    }

    /**
     * Encodes a record into the line it is written as, without the line break.
     *
     * A payload that cannot be encoded or makes the line longer than the limit costs the record
     * its payload, not the event. The record is encoded again with a null payload. A record still
     * too long or unencodable without its payload is dropped.
     *
     * @param array<string, mixed> $record Record built by one of the builders above
     * @return ?string The line, or null when it cannot fit or be encoded even without its payload
     */
    public static function encode(array $record): ?string
    {
        return self::encodeEvent($record)->line;
    }

    /**
     * Encodes an event and reports whether its payload was removed to fit the journal line.
     *
     * @param array<string, mixed> $record Record built by one of the builders above
     * @return AnalyticsJournalEncoding Encoded line and payload-loss outcome
     */
    public static function encodeEvent(array $record): AnalyticsJournalEncoding
    {
        $line = json_encode($record, self::JSON_FLAGS);
        $payloadDropped = false;
        if (($line === false || strlen($line) > self::MAX_LINE_BYTES) && ($record[self::KEY_PAYLOAD] ?? null) !== null) {
            $record[self::KEY_PAYLOAD] = null;
            $line = json_encode($record, self::JSON_FLAGS);
            $payloadDropped = true;
        }

        if ($line === false || strlen($line) > self::MAX_LINE_BYTES) {
            return new AnalyticsJournalEncoding(null, false);
        }

        return new AnalyticsJournalEncoding($line, $payloadDropped);
    }
}
