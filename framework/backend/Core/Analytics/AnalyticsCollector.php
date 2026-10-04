<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Closure;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Logger;

/**
 * Collects raw analytics data - the facade every process reaches as `Hilos::$ac`.
 *
 * Every process, including the master, builds records of the analytics journal
 * ({@see AnalyticsJournalRecord}). They gather in {@see AnalyticsJournalOutbox} and go to the
 * journal agent of the node in batches; one writer per cluster loads them into the tables.
 * The collector touches no database. The master remembers its live connections and their page
 * keys; correlation keys are captured only during synchronous action or request dispatch.
 *
 * A worker session and an agent session are named by a key the process draws in memory
 * (`RandomHelper::hex()`, the tolerant axis: the key only has to not collide, nobody guesses it),
 * and the writer gives the row its number. The sessions of the two analytics agents and the
 * signals delivered to them are not recorded: the journal does not write about itself.
 *
 * The collector lives in every process and is in no agent roster, so the protected-mode freeze
 * cannot stop it; it answers the freeze itself (HIL-910). While this node's freeze silences the
 * writers the roster walk leaves running (activating or active) it records nothing and throws
 * the gathered batch away; an agent that stops meanwhile is remembered and its stop handed over,
 * with its own moment, once the freeze lets go. Nothing is re-opened after a swap: the
 * descriptions of the sessions travel in every batch that names them.
 */
final class AnalyticsCollector
{
    public const string META_API_REQUEST_KEY = 'apiRequestKey';
    public const string META_USER_ACTION_KEY = 'userActionKey';

    private const string IDENTITY_TYPE_USER_ID = 'user_id';

    /** @var int Random bytes of a session key; its hex is twice as long */
    private const int SESSION_KEY_BYTES = 16;

    /** @var list<string> Agents the journal does not record: its own two */
    private const array UNRECORDED_AGENT_TYPES = [
        HilosAgentType::HILOS_ANALYTICS_JOURNAL,
        HilosAgentType::HILOS_ANALYTICS_WRITER,
    ];

    private readonly AnalyticsJournalOutbox $outbox;

    /** @var array<string, ?string> WebSocket accept key to current page key, or null */
    private array $connections = [];

    /** @var ?AnalyticsJournalSession The worker session of this process, null while none is live */
    private ?AnalyticsJournalSession $workerSession = null;

    /** @var array<string, AnalyticsJournalSession> Agents alive in this process, keyed by {@see self::buildAgentKey()} */
    private array $agentSessions = [];

    /** @var list<AnalyticsHeldRecord> Agent stops the freeze kept back, in the order they happened */
    private array $heldStops = [];

    /** @var ?string Active API request key during synchronous handler dispatch */
    private ?string $activeApiRequestKey = null;

    /** @var ?string Active user action key during synchronous signal dispatch */
    private ?string $activeUserActionKey = null;

    public function __construct()
    {
        $this->outbox = new AnalyticsJournalOutbox($this->nowTs());
    }

    /**
     * Records the identity a browser session signed in with.
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

        $this->record(AnalyticsJournalRecord::browserSessionIdentity($sessionToken, $type, $value, $this->nowTs()), null);
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
     * A token whose session was never opened becomes an alias, and a token already
     * taken by another session joins the visit: the writer decides both
     * ({@see AnalyticsStore::renameBrowserSession()}).
     *
     * The batch leaves at once rather than within the second. The writer's token alias makes
     * arrival order safe; the flush keeps the visit's change visible promptly.
     *
     * @param string $oldToken Token the session answered to before the rotation
     * @param string $newToken Token the session answers to now
     */
    public function renameBrowserSession(string $oldToken, string $newToken): void
    {
        if ($oldToken === '' || $newToken === '' || $oldToken === $newToken) {
            return;
        }

        $this->record(AnalyticsJournalRecord::browserSessionRename($oldToken, $newToken, $this->nowTs()), null);
        $this->flush();
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
     * Remembers a WebSocket connection and records its opening.
     *
     * Records the opening client IP and nothing about the visitor: the record is built on
     * the master's accept loop, where resolving a browser session would cost a SELECT and
     * an INSERT (docs/agents/antipatterns/heavy-work-in-master.md). The worker attaches it
     * to a session on the handshake signal - see
     * {@see attachWsConnectionToBrowserSession()}.
     *
     * Opening stays here rather than moving to the worker with the attach, because every
     * later event of this connection - its close, its page sessions, its IP changes - needs
     * the process-local memory established here.
     *
     * @param string $acceptKey WebSocket accept key; empty is ignored
     * @param ?string $clientIp Client IP address (IPv4 or IPv6), or null when unknown
     */
    public function openWsConnection(string $acceptKey, ?string $clientIp): void
    {
        if ($acceptKey === '') {
            return;
        }

        $this->connections[$acceptKey] = null;
        $this->record(AnalyticsJournalRecord::wsConnectionOpen($acceptKey, $clientIp, $this->nowTs()), null);
    }

    /**
     * Gives an already opened WebSocket connection the browser session it belongs to.
     *
     * This is the worker half of the handshake: the master recorded the connection without
     * an owner, and the handshake signal carries the session token it resolved there, so the
     * two are joined by the writer, off the accept loop. The writer applies a file in order, so
     * the identify an agent's handshake hook may record after this finds the session.
     *
     * @param string $acceptKey WebSocket accept key; empty is ignored
     * @param string $sessionToken Browser session token resolved on the handshake; empty is ignored
     * @param ?string $userAgent Raw User-Agent header, or null
     * @param ?string $acceptLanguage Raw Accept-Language header, or null
     */
    public function attachWsConnectionToBrowserSession(
        string $acceptKey,
        string $sessionToken,
        ?string $userAgent,
        ?string $acceptLanguage,
    ): void {
        if ($acceptKey === '' || $sessionToken === '') {
            return;
        }

        $this->record(
            AnalyticsJournalRecord::wsConnectionAttach($acceptKey, $sessionToken, $userAgent, $acceptLanguage, $this->nowTs()),
            null,
        );
    }

    /**
     * Records an address change; the writer compares it with the current stored address.
     *
     * Nothing calls this, and that is what HIL-706 settled. An address cannot change
     * inside a TCP connection, so the per-frame hook this method once had compared the
     * handshake address against the value that same address had filled - the two tables
     * could not receive a row. They are kept, and this record builder with them: a caller arrives
     * together with a source where one connection's address can really change, and both
     * candidates - MPTCP paths, or the visitor's address read from the handshake header -
     * bring their own, so neither grows on top of this code.
     *
     * @param string $acceptKey WebSocket accept key; empty is ignored
     * @param ?string $clientIp Current client IP address; null is ignored
     */
    public function trackWsConnectionIpChange(string $acceptKey, ?string $clientIp): void
    {
        if ($acceptKey === '' || $clientIp === null || !array_key_exists($acceptKey, $this->connections)) {
            return;
        }

        $this->record(AnalyticsJournalRecord::wsConnectionIpChange($acceptKey, $clientIp, $this->nowTs()), null);
    }

    /**
     * Marks an open WS connection closed; no-op when the key is unknown.
     *
     * @param string $acceptKey WebSocket accept key; empty is ignored
     */
    public function closeWsConnection(string $acceptKey): void
    {
        if (!array_key_exists($acceptKey, $this->connections)) {
            return;
        }

        $pageKey = $this->connections[$acceptKey];
        if ($pageKey !== null) {
            $this->record(AnalyticsJournalRecord::pageSessionClose($pageKey, $this->nowTs()), null);
        }
        $this->record(AnalyticsJournalRecord::wsConnectionClose($acceptKey, $this->nowTs()), null);
        unset($this->connections[$acceptKey]);
    }

    /**
     * Closes the master's remembered connections before the node stops its journal agent.
     * The stop wave calls this before routing the agent_stop frame, so this batch reaches it first.
     */
    public function closeOpenConnections(): void
    {
        $ts = $this->nowTs();
        foreach ($this->connections as $acceptKey => $pageKey) {
            if ($pageKey !== null) {
                $this->record(AnalyticsJournalRecord::pageSessionClose($pageKey, $ts), null);
            }
            $this->record(AnalyticsJournalRecord::wsConnectionClose($acceptKey, $ts), null);
        }
        $this->connections = [];
        $this->flush();
    }

    /**
     * Opens a page session on a WS connection, closing any prior one first.
     *
     * @param string $acceptKey WebSocket accept key; unknown is ignored
     * @param string $pageName Page name being opened; empty is ignored
     * @param ?array<string, mixed> $params Page route params, or null
     */
    public function openPageSession(string $acceptKey, string $pageName, ?array $params = null): void
    {
        if (!array_key_exists($acceptKey, $this->connections) || $pageName === '') {
            return;
        }

        $this->closePageSession($acceptKey);
        $key = RandomHelper::hex(self::SESSION_KEY_BYTES);
        $this->connections[$acceptKey] = $key;
        $this->record(AnalyticsJournalRecord::pageSessionOpen($key, $acceptKey, $pageName, $params, $this->nowTs()), null);
    }

    /**
     * Updates the route params of the current page session.
     *
     * @param string $acceptKey WebSocket accept key; empty is ignored
     * @param ?array<string, mixed> $params New page route params, or null
     */
    public function updatePageSession(string $acceptKey, ?array $params): void
    {
        $key = $this->connections[$acceptKey] ?? null;
        if ($key === null) {
            return;
        }

        $this->record(AnalyticsJournalRecord::pageSessionUpdate($key, $params, $this->nowTs()), null);
    }

    /**
     * Marks the current page session closed and drops it from the connection map.
     *
     * @param string $acceptKey WebSocket accept key; empty is ignored
     */
    public function closePageSession(string $acceptKey): void
    {
        $key = $this->connections[$acceptKey] ?? null;
        if ($key === null) {
            return;
        }

        $this->record(AnalyticsJournalRecord::pageSessionClose($key, $this->nowTs()), null);
        $this->connections[$acceptKey] = null;
    }

    /**
     * Opens the worker session of this process under a fresh key and describes it to the next batch.
     *
     * @param int $workerIndex Worker index within the daemon
     * @param bool $isMonopolistic Whether the worker runs monopolistic agents
     */
    public function openWorkerSession(int $workerIndex, bool $isMonopolistic): void
    {
        $key = RandomHelper::hex(self::SESSION_KEY_BYTES);
        $this->workerSession = new AnalyticsJournalSession(
            $key,
            AnalyticsJournalRecord::workerSession($key, $workerIndex, $isMonopolistic, $this->nowTs()),
        );

        if ($this->recordable()) {
            $this->toOutbox(fn() => $this->outbox->describe($this->sessionsNamedBy(null), $this->nowTs()));
        }
    }

    /**
     * Records the worker session stopped; nothing when none is open.
     *
     * A worker that shuts down while the freeze holds the collector leaves its session open.
     */
    public function closeWorkerSession(): void
    {
        $session = $this->workerSession;
        if ($session === null) {
            return;
        }

        $this->record(AnalyticsJournalRecord::workerSessionStop($session->key, $this->nowTs()), null, $this->sessionsNamedBy(null));
        $this->workerSession = null;
    }

    /**
     * Opens an agent session under the worker session, under a fresh key, and describes it to the next batch.
     *
     * Nothing for an agent with no worker session under it, and nothing for the two analytics agents.
     *
     * @param string $agentType Agent type identifier; empty is ignored
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     */
    public function openAgentSession(string $agentType, ?string $agentIndex): void
    {
        if ($agentType === '' || $this->workerSession === null || in_array($agentType, self::UNRECORDED_AGENT_TYPES, true)) {
            return;
        }

        $key = RandomHelper::hex(self::SESSION_KEY_BYTES);
        $session = new AnalyticsJournalSession(
            $key,
            AnalyticsJournalRecord::agentSession($key, $this->workerSession->key, $agentType, $agentIndex, $this->nowTs()),
        );
        $this->agentSessions[$this->buildAgentKey($agentType, $agentIndex)] = $session;

        if ($this->recordable()) {
            $this->toOutbox(fn() => $this->outbox->describe($this->sessionsNamedBy($session), $this->nowTs()));
        }
    }

    /**
     * Records an agent session stopped and forgets it.
     *
     * Under the freeze the stop is kept back with its own moment and handed over once the freeze
     * lets the collector go.
     *
     * @param string $agentType Agent type identifier; empty is ignored
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     */
    public function closeAgentSession(string $agentType, ?string $agentIndex): void
    {
        $agentKey = $this->buildAgentKey($agentType, $agentIndex);
        $session = $this->agentSessions[$agentKey] ?? null;
        if ($session === null) {
            return;
        }

        unset($this->agentSessions[$agentKey]);
        $stop = new AnalyticsHeldRecord(
            AnalyticsJournalRecord::agentSessionStop($session->key, $this->nowTs()),
            $this->sessionsNamedBy($session),
        );

        if ($this->isHeld()) {
            $this->outbox->clear();
            $this->heldStops[] = $stop;

            return;
        }

        $this->record($stop->record, null, $stop->sessions);
    }

    /**
     * Records a user action against the remembered WS connection and current page key.
     *
     * The payload is masked here, before anything stores it: the secret fields the action's DTO
     * declares are written as {@see SecretPayloadMask::MASK}, and an action the topology does not
     * know keeps its name but loses its payload, since nobody declared what in it is secret.
     *
     * @param string $acceptKey WebSocket accept key; unknown yields null
     * @param string $actionName Client action name; empty yields null
     * @param ?array<string, mixed> $payload Raw action payload, or null
     * @return ?string User action key, or null when the connection is unknown
     */
    public function logUserAction(string $acceptKey, string $actionName, ?array $payload): ?string
    {
        if (!array_key_exists($acceptKey, $this->connections) || $actionName === '') {
            return null;
        }

        $key = RandomHelper::hex(self::SESSION_KEY_BYTES);
        $this->record(AnalyticsJournalRecord::userAction($key, $acceptKey, $this->connections[$acceptKey],
            $actionName, $this->maskActionPayload($actionName, $payload), $this->nowTs()), null);
        return $key;
    }

    /**
     * Records an agent reaction to a user action.
     *
     * The payload is masked here, before anything stores it - the journal file is storage too -
     * by the same rule as {@see self::logUserAction()}: the signal name is the action's name, and
     * the action lies under `data` of the envelope, where the mask looks too.
     *
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     * @param ?string $userActionKey Originating user action key, or null when uncorrelated
     * @param string $signalName Signal name handled by the agent; empty is ignored
     * @param ?array<string, mixed> $payload Signal payload, or null
     */
    public function logAgentUserAction(string $agentType, ?string $agentIndex, ?string $userActionKey, string $signalName, ?array $payload): void
    {
        $session = $this->agentSessions[$this->buildAgentKey($agentType, $agentIndex)] ?? null;
        if ($signalName === '' || $session === null) {
            return;
        }

        $this->record(AnalyticsJournalRecord::agentUserAction(
            $session->key,
            $userActionKey,
            $signalName,
            $this->maskActionPayload($signalName, $payload),
            $this->nowTs(),
        ), $session);
    }

    /**
     * Records a system signal delivered to an agent.
     *
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     * @param string $signalName System signal name; empty is ignored
     * @param ?array<string, mixed> $payload Signal payload, or null
     */
    public function logAgentSystemSignal(string $agentType, ?string $agentIndex, string $signalName, ?array $payload): void
    {
        $session = $this->agentSessions[$this->buildAgentKey($agentType, $agentIndex)] ?? null;
        if ($signalName === '' || $session === null) {
            return;
        }

        $this->record(AnalyticsJournalRecord::agentSystemSignal($session->key, $signalName, $payload, $this->nowTs()), $session);
    }

    /**
     * Records a cron signal delivered to an agent.
     *
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     * @param string $cronName Cron job name; empty is ignored
     * @param ?array<string, mixed> $payload Signal payload, or null
     */
    public function logAgentCronSignal(string $agentType, ?string $agentIndex, string $cronName, ?array $payload): void
    {
        $session = $this->agentSessions[$this->buildAgentKey($agentType, $agentIndex)] ?? null;
        if ($cronName === '' || $session === null) {
            return;
        }

        $this->record(AnalyticsJournalRecord::agentCronSignal($session->key, $cronName, $payload, $this->nowTs()), $session);
    }

    /**
     * Records a system signal delivered to the worker itself.
     *
     * @param string $signalName System signal name; empty is ignored
     * @param ?array<string, mixed> $payload Signal payload, or null
     */
    public function logWorkerSystemSignal(string $signalName, ?array $payload): void
    {
        if ($signalName === '' || $this->workerSession === null) {
            return;
        }

        $this->record(
            AnalyticsJournalRecord::workerSystemSignal($this->workerSession->key, $signalName, $payload, $this->nowTs()),
            null,
            $this->sessionsNamedBy(null),
        );
    }

    /**
     * Remembers a request description until its single journal record can be written at the end.
     *
     * @param ?string $sessionToken Browser session token, or null for anonymous
     * @param string $method HTTP method
     * @param string $path Request path
     * @param ?array<string, mixed> $params Request params, or null
     * @param ?string $userAgent Raw User-Agent header, or null
     * @param ?string $acceptLanguage Raw Accept-Language header, or null
     * @return AnalyticsApiRequest Description held by the caller until the request finishes
     */
    public function startApiRequest(
        ?string $sessionToken,
        string $method,
        string $path,
        ?array $params,
        ?string $userAgent,
        ?string $acceptLanguage,
    ): AnalyticsApiRequest {
        return new AnalyticsApiRequest(RandomHelper::hex(self::SESSION_KEY_BYTES),
            $sessionToken === '' ? null : $sessionToken, $method, $path, $params,
            $userAgent, $acceptLanguage, $this->nowTs());
    }

    /**
     * Writes the completed request as one journal record.
     *
     * @param ?AnalyticsApiRequest $request Request description; null is ignored
     * @param ?int $statusCode HTTP status code, or null
     * @param ?int $durationMs Request duration in milliseconds, or null
     */
    public function finishApiRequest(?AnalyticsApiRequest $request, ?int $statusCode, ?int $durationMs): void
    {
        if ($request === null) {
            return;
        }

        $this->record(AnalyticsJournalRecord::apiRequest($request, $statusCode, $durationMs, $this->nowTs()), null);
    }

    /**
     * Records an agent action triggered by an API request.
     *
     * The payload is masked here, before anything stores it, when the signal name is an action
     * the topology knows. System, cron and agent signals pass this way too; theirs is no action,
     * and their payload is written as it came.
     *
     * @param string $apiRequestKey Originating API request key
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent instance index, or null for a singleton agent
     * @param string $signalName Signal name dispatched to the agent; empty is ignored
     * @param ?array<string, mixed> $payload Signal payload, or null
     */
    public function logApiAgentAction(string $apiRequestKey, string $agentType, ?string $agentIndex, string $signalName, ?array $payload): void
    {
        $session = $this->agentSessions[$this->buildAgentKey($agentType, $agentIndex)] ?? null;
        if ($signalName === '' || $session === null) {
            return;
        }

        $this->record(AnalyticsJournalRecord::apiAgentAction(
            $apiRequestKey,
            $session->key,
            $signalName,
            $this->maskSignalPayload($signalName, $payload),
            $this->nowTs(),
        ), $session);
    }

    /**
     * Sets the active user action key used to correlate subsequent signals.
     *
     * @param ?string $userActionKey User action key to correlate, or null to clear
     */
    public function startUserActionCapture(?string $userActionKey): void
    {
        $this->activeUserActionKey = $userActionKey;
    }

    /**
     * Clears the active user action correlation key.
     */
    public function clearUserActionCapture(): void
    {
        $this->activeUserActionKey = null;
    }

    /**
     * Captures a request key only while its synchronous handler dispatches signals.
     *
     * @param ?AnalyticsApiRequest $request Request being handled, or null
     */
    public function startApiRequestCapture(?AnalyticsApiRequest $request): void
    {
        $this->activeApiRequestKey = $request?->key;
    }

    /** Clears the active request key before another frame is routed. */
    public function clearApiRequestCapture(): void
    {
        $this->activeApiRequestKey = null;
    }

    /**
     * Builds correlation metadata for an outgoing signal from active synchronous captures.
     *
     * @return array<string, string> Meta keyed by META_* constants; empty when nothing is active
     */
    public function captureSignalMeta(): array
    {
        $meta = [];

        if ($this->activeApiRequestKey !== null) {
            $meta[self::META_API_REQUEST_KEY] = $this->activeApiRequestKey;
        }

        if ($this->activeUserActionKey !== null) {
            $meta[self::META_USER_ACTION_KEY] = $this->activeUserActionKey;
        }

        return $meta;
    }

    /**
     * Reads a process-drawn correlation key from signal metadata.
     *
     * @param SignalDTO $signal Signal carrying meta
     * @param string $key Meta key (a META_* constant)
     * @return ?string Valid key, or null when absent or malformed
     */
    public function getSignalMetaKey(SignalDTO $signal, string $key): ?string
    {
        $value = $signal->meta[$key] ?? null;
        return is_string($value) && preg_match(AnalyticsJournalRecord::SESSION_KEY_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * Sends the gathered batch once a second.
     *
     * Runs under the gate even when nothing is due, so the stops the freeze kept back are
     * handed over on the first tick after it.
     */
    public function tick(): void
    {
        if (!$this->recordable()) {
            return;
        }

        $this->toOutbox(fn() => $this->outbox->flushIfDue($this->nowTs()));
    }

    /**
     * Sends what was gathered now.
     */
    public function flush(): void
    {
        if (!$this->recordable()) {
            return;
        }

        $this->toOutbox(fn() => $this->outbox->flush($this->nowTs()));
    }

    /**
     * Sends the last worker batch and clears active correlation keys: the end of the process.
     */
    public function shutdown(): void
    {
        $this->flush();
        $this->activeApiRequestKey = null;
        $this->activeUserActionKey = null;
    }

    /**
     * Forgets the master's live connection map, active captures and gathered batch after a swap.
     *
     * Called by each process at its answer to the re-hydrate round. The batch gathered so far
     * belongs to the replaced database, and the browser connections reconnect after the lift.
     *
     * What is alive in the process - the worker and its agents - is kept, with the stops the
     * freeze kept back: their descriptions travel with the next batch that names them, and the
     * writer inserts them into the restored database. Connections and pages are not: the lift
     * reloads every browser, which then connects anew.
     *
     * Memory only; cannot fail.
     */
    public function forgetReplacedDatabase(): void
    {
        $this->outbox->clear();
        $this->activeApiRequestKey = null;
        $this->activeUserActionKey = null;
        $this->connections = [];
    }

    /**
     * Adds a record to the batch, with the sessions it names; nothing while the freeze holds.
     *
     * @param array<string, mixed> $record Record built by {@see AnalyticsJournalRecord}
     * @param ?AnalyticsJournalSession $agentSession Agent session the record names, null for none
     * @param ?array<string, array<string, mixed>> $sessions Sessions the record names, when not derived from the agent session
     */
    private function record(array $record, ?AnalyticsJournalSession $agentSession, ?array $sessions = null): void
    {
        if (!$this->recordable()) {
            return;
        }

        $named = $sessions ?? ($agentSession === null ? [] : $this->sessionsNamedBy($agentSession));
        $this->toOutbox(fn() => $this->outbox->add($record, $named, $this->nowTs()));
    }

    /**
     * Whether a record may be gathered now; under the freeze the gathered batch is thrown away.
     *
     * The first call after the freeze hands over the stops it kept back.
     *
     * @return bool True when the freeze does not hold the collector
     */
    private function recordable(): bool
    {
        if ($this->isHeld()) {
            $this->outbox->clear();

            return false;
        }

        $held = $this->heldStops;
        $this->heldStops = [];
        foreach ($held as $stop) {
            $this->toOutbox(fn() => $this->outbox->add($stop->record, $stop->sessions, $this->nowTs()));
        }

        return true;
    }

    /**
     * The descriptions a record naming the agent session - or only the worker - carries with it, the worker first.
     *
     * @param ?AnalyticsJournalSession $agentSession Agent session named, or null for the worker alone
     * @return array<string, array<string, mixed>> Session key to its description
     */
    private function sessionsNamedBy(?AnalyticsJournalSession $agentSession): array
    {
        $sessions = [];
        if ($this->workerSession !== null) {
            $sessions[$this->workerSession->key] = $this->workerSession->description;
        }
        if ($agentSession !== null) {
            $sessions[$agentSession->key] = $agentSession->description;
        }

        return $sessions;
    }

    /**
     * Runs a change of the outbox, containing the one failure it can raise.
     *
     * @param Closure(): void $change The change
     */
    private function toOutbox(Closure $change): void
    {
        try {
            $change();
        } catch (InvalidArgumentException $failure) {
            Logger::error('Analytics batch could not be queued for the journal: ' . $failure->getMessage());
        }
    }

    /**
     * Builds the cache key for an agent type/index pair.
     *
     * A singleton agent keys apart from an agent whose index happens to be empty:
     * collapsed onto one key, the second {@see self::openAgentSession()} would
     * overwrite the first entry, every later `logAgent*` of both agents would land
     * under one session, and the first to stop would stop the other's session and
     * clear the key — leaving the survivor logging nothing at all for the rest of its
     * life, and the first agent's session open forever.
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
     * Returns a user action's payload as analytics may keep it: masked, or nothing at all for an
     * action the topology does not know - that one never declared which of its fields are secret.
     *
     * @param string $actionName Action name the payload came with
     * @param ?array<string, mixed> $payload Action payload, or null
     * @return ?array<string, mixed> Masked payload, or null when there is none or the action is unknown
     */
    private function maskActionPayload(string $actionName, ?array $payload): ?array
    {
        $secretFields = Hilos::$sr?->actionSecretFields($actionName);
        if ($payload === null || $secretFields === null) {
            return null;
        }

        return SecretPayloadMask::apply($payload, $secretFields);
    }

    /**
     * Returns a signal's payload as analytics may keep it: masked when the name is an action the
     * topology knows, as it came when it is not an action at all.
     *
     * @param string $signalName Signal name the payload came with
     * @param ?array<string, mixed> $payload Signal payload, or null
     * @return ?array<string, mixed> Masked payload, or the payload unchanged for a name that is no action
     */
    private function maskSignalPayload(string $signalName, ?array $payload): ?array
    {
        $secretFields = Hilos::$sr?->actionSecretFields($signalName);
        if ($payload === null || $secretFields === null) {
            return $payload;
        }

        return SecretPayloadMask::apply($payload, $secretFields);
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
     * Whether this node's protected-mode freeze holds the collector.
     *
     * The freeze row answers it ({@see ProtectedModeRuntime::silencesUnstoppedWriters()}), the
     * same question the mail pool's durable half asks (HIL-1060): activating or active. Active
     * is where the initiator may replace the database on the leader or a single node; a
     * follower reaches active only on its leader's word that every node has stopped (HIL-1128),
     * so on a follower the database may change under it while its row reads activating. No
     * freeze row mounted means nothing holds.
     *
     * @return bool True while the freeze silences the unstopped writers
     */
    private function isHeld(): bool
    {
        return Hilos::$rt?->hilosProtectedModeRuntime?->silencesUnstoppedWriters() === true;
    }

}
