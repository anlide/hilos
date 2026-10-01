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
 * source, not at the writer. A payload is an object already masked by the source
 * ({@see SecretPayloadMask}), or null.
 *
 * The builders return raw arrays on purpose: a record is handed to `json_encode()` and back, and a
 * value object in between would only be taken apart again.
 */
final class AnalyticsJournalRecord
{
    public const int VERSION = 1;

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
    public const string KEY_USER_ACTION_ID = 'userActionId';
    public const string KEY_API_REQUEST_ID = 'apiRequestId';
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

    public const string TYPE_JOURNAL = 'journal';
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

    /** @var string A session key as it travels: 16 random bytes in lowercase hex */
    public const string SESSION_KEY_PATTERN = '/^[0-9a-f]{32}$/';

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
     * @param ?int $userActionId Row of the user action the master wrote, or null when uncorrelated
     * @param string $signal Signal name handled by the agent
     * @param ?array<string, mixed> $payload Masked payload, or null
     * @param int $ts Moment of the event, in milliseconds
     * @return array<string, mixed> Record
     */
    public static function agentUserAction(string $agentKey, ?int $userActionId, string $signal, ?array $payload, int $ts): array
    {
        return [
            self::KEY_TYPE => self::TYPE_AGENT_USER_ACTION,
            self::KEY_AGENT_KEY => $agentKey,
            self::KEY_USER_ACTION_ID => $userActionId,
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
     * @param int $apiRequestId Row of the API request the master wrote
     * @param string $agentKey Session key of the agent
     * @param string $signal Signal name dispatched to the agent
     * @param ?array<string, mixed> $payload Masked payload, or null
     * @param int $ts Moment of the event, in milliseconds
     * @return array<string, mixed> Record
     */
    public static function apiAgentAction(int $apiRequestId, string $agentKey, string $signal, ?array $payload, int $ts): array
    {
        return [
            self::KEY_TYPE => self::TYPE_API_AGENT_ACTION,
            self::KEY_API_REQUEST_ID => $apiRequestId,
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
     * A payload that cannot be encoded - a string that is not UTF-8 - costs the record its payload
     * and not the record itself: the record is encoded again with a null payload, the same verdict
     * the payload dictionary gives such a value.
     *
     * @param array<string, mixed> $record Record built by one of the builders above
     * @return ?string The line, or null when even the record without its payload cannot be encoded
     */
    public static function encode(array $record): ?string
    {
        $line = json_encode($record, self::JSON_FLAGS);
        if ($line === false && ($record[self::KEY_PAYLOAD] ?? null) !== null) {
            $record[self::KEY_PAYLOAD] = null;
            $line = json_encode($record, self::JSON_FLAGS);
        }

        return $line === false ? null : $line;
    }
}
