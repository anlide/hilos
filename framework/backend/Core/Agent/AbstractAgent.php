<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\ActionHostInterface;
use Hilos\Core\Action\ActionReply;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbReHydrateSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\DTO\DbReHydrateOutcome;
use Hilos\Database\DbSyncApplicator;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Random\RandomException;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeRefreezeSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeVerifySignalData;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use Throwable;

/**
 * AbstractAgent - Abstract base class for agents running in worker processes.
 *
 * Provides base implementation for agents. Child classes should define:
 * - AGENT_TYPE constant - agent type identifier
 * - $agentIndex property - for multi-instance agents (set by the indexed constructor)
 * - onTick() - agent work logic run on each worker loop iteration
 * - Signal handling methods (can override onSignal* methods for specific signal types).
 */
abstract class AbstractAgent implements AgentInterface, PageAgentInterface, ActionHostInterface
{
    /** @var string Agent type identifier. Override in child classes. */
    public const string AGENT_TYPE = '';

    /** @var list<string> Agent signal names owned directly by this agent. */
    public const array AGENT_SIGNALS = [];

    /**
     * @var list<string> RT collection keys this agent reads. Declared on the class and not
     *     registered from onStart(), because the worker has to raise the interest and wait for
     *     the state to arrive BEFORE the instance exists - an agent asked to start without its
     *     data would have to know how to run without it. Writing stays imperative
     *     ({@see self::registerRtTruthSource()}): a claim is made by the agent that holds it,
     *     while what it reads is a fact about the class.
     *
     *     A collection this agent claims does not belong here: a claim holds the copy already, and
     *     two lists for one fact would have to be kept in step. What belongs here is what the agent
     *     reads out of somebody else's collection.
     */
    public const array READS_RT = [];

    /** @var list<string> CLI command names owned directly by this agent. */
    public const array AGENT_COMMANDS = [];

    /**
     * @var array<string, class-string<ActionPayloadDTO>> Client-action payload DTOs owned directly by this
     *     agent, keyed by action name. The page-independent counterpart to Page::ACTIONS: an action listed
     *     here is routed straight to {@see self::onAgentAction()} from any subscribed page (or none).
     */
    public const array AGENT_ACTIONS = [];

    /**
     * @var list<string> Action names of this agent that require an authenticated session.
     *     Read by the action dispatcher exactly as a page's AUTH_ACTIONS is: a listed action
     *     invoked from an anonymous session is denied 401 before the handler runs.
     */
    public const array AUTH_ACTIONS = [];

    /**
     * @var list<string> Action names of this agent the anti-abuse layer rate-limits (HIL-420).
     *     Read by the action dispatcher exactly as a page's THROTTLED_ACTIONS is; the agent
     *     must also declare HILOS_AUTH_THROTTLE_VERDICT in AGENT_SIGNALS, because the verdict
     *     is addressed back to whoever parked the action.
     */
    public const array THROTTLED_ACTIONS = [];

    /** @var ?string Agent index for multi-instance agents (null for singletons) */
    protected ?string $agentIndex = null;

    /** @var bool Flag indicating agent should stop */
    private bool $shouldStop = false;

    /**
     * The node that answers this agent's actions, built on first use.
     *
     * Lazily, because the node takes the agent itself and subclasses own their constructors.
     */
    private ?ActionReply $actionReply = null;

    /**
     * @return string Agent type from AGENT_TYPE constant
     */
    public function getType(): string
    {
        return static::AGENT_TYPE;
    }

    /**
     * @return ?string Agent index or null for singleton agents
     */
    public function getIndex(): ?string
    {
        return $this->agentIndex;
    }

    /**
     * Get agent unique identifier (type + index).
     *
     * Default implementation: "type:index" or "type" if index is null.
     *
     * @return string Agent ID
     */
    public function getId(): string
    {
        $index = $this->getIndex();
        if ($index === null) {
            return $this->getType();
        }
        return $this->getType() . AgentConstants::ID_SEPARATOR . $index;
    }

    /**
     * @return SignalSourceInterface Agent signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(
            source: SignalSource::AGENT,
            type: $this->getType(),
            index: $this->getIndex(),
        );
    }

    /**
     * Operations this agent's claims carry unless a claim was made with its own set.
     *
     * The one place a kind of agent says what it may do to the rows it owns, so that
     * changing the answer for a whole kind costs this one line and no walk of the call
     * sites. An ordinary agent owns its rows outright and may do anything with them.
     *
     * @return list<TruthSourceOperation> Operations every claim of this agent gets
     */
    protected function defaultTruthSourceOperations(): array
    {
        return TruthSourceOperation::ALL;
    }

    /**
     * Register this agent as truth source for a database collection.
     *
     * @param string $collection Collection/table name
     * @param list<string>|true $keys Specific writable keys or true for all keys
     */
    protected function registerDbTruthSource(string $collection, array|true $keys = true): void
    {
        TruthSourceRegistry::register($collection, $keys, $this->getId(), $this->defaultTruthSourceOperations());
    }

    /**
     * Register this agent as truth source for a runtime collection.
     *
     * The claim is its own reader interest, and a ready one (HIL-717): a writer holds the copy of
     * what it writes, so there is no state on its way here for it to wait for. Raised at the
     * claim rather than at the report that follows it, because an agent publishing its first row
     * inside onStart() reads the collection before that report is even built - the report goes
     * out once the hook has returned ({@see WorkerManager::handleAgentStart()}).
     *
     * @param string $collection Runtime collection name
     * @param list<string>|true $keys Specific writable keys or true for all keys
     */
    protected function registerRtTruthSource(string $collection, array|true $keys = true): void
    {
        RtTruthSourceRegistry::register($collection, $keys, $this->getId(), $this->defaultTruthSourceOperations());

        SourceInterestRegistry::register(SourceChange::KIND_RT, $collection, SourceConsumer::agent($this->getId()));
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, $collection);
    }

    /**
     * Log an info message under this agent id.
     *
     * @param string $message Message to log
     */
    protected function logAgentInfo(string $message): void
    {
        Logger::logAgentInfo($this->getId(), $message);
    }

    /**
     * Log an error message under this agent id.
     *
     * @param string $message Error message to log
     */
    protected function logAgentError(string $message): void
    {
        Logger::logAgentError($this->getId(), $message);
    }

    /**
     * Log a warning message under this agent id.
     *
     * @param string $message Warning message to log
     */
    protected function logAgentWarning(string $message): void
    {
        Logger::logAgentWarning($this->getId(), $message);
    }

    /**
     * Log a debug message under this agent id when debug logging is enabled.
     *
     * @param string $message Debug message to log
     */
    protected function logAgentDebug(string $message): void
    {
        Logger::logAgentDebug($this->getId(), $message);
    }

    /**
     * Sets the backend-authored success sentence for the action currently being handled.
     *
     * Call from an owned-action handler to have the success ack carry outcome text the
     * frontend surfaces as a toast; the domain sentence lives on the backend because Hilos
     * i18n does. The message is consumed by the ack that immediately follows the handler and
     * does not carry over to a later action.
     *
     * @param string $message Backend-authored, already-localized success sentence
     */
    protected function setActionSuccessMessage(string $message): void
    {
        $this->actionReply()->setSuccessMessage($message);
    }

    /**
     * Send signal to a specific user (WebSocket connection by acceptKey).
     *
     * @param string $signalName Signal name (e.g. ChatSignalConstants::HANDSHAKE_RESPONSE)
     * @param string $targetAcceptKey Target connection acceptKey
     * @param SignalDataInterface $data Signal payload
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function sendToUser(string $signalName, string $targetAcceptKey, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $targetAcceptKey),
        );
    }

    /**
     * Send signal to every connection of one browser session.
     *
     * The address {@see sendToUser()} cannot express: a session is a set of sockets, and a browser
     * that reloaded is watching from one the sender never saw. Reach for it when what is being
     * answered belongs to the person rather than to the tab - the progress of an operation they
     * started, and which outlives the socket they started it from.
     *
     * It is also the only address that survives a protected-mode freeze: the registry a named
     * accept key would come from is written by an agent the freeze has stopped, so a tab opened
     * during the operation is known to nobody but the master that accepted it. The master matches
     * the hash against its own connections, so no lookup is needed of a registry standing still.
     *
     * @param string $signalName Signal name
     * @param string $targetSessionTokenHash Hash of the session token whose connections receive the signal
     * @param SignalDataInterface $data Signal payload
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function sendToSession(string $signalName, string $targetSessionTokenHash, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_SESSION),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, targetSessionTokenHash: $targetSessionTokenHash),
        );
    }

    /**
     * Reply to a CLI command routed to this agent.
     *
     * Queues a COMMAND_REPLY signal carrying the reply; the daemon writes it back
     * to the held CLI connection addressed by the reply's correlation id.
     *
     * @param CommandReplyDTO $reply Command reply (use CommandReplyDTO::ok() / error())
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     */
    public function replyToCommand(CommandReplyDTO $reply): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::COMMAND_REPLY),
            signalName: new SignalName($reply->correlationId),
            signalData: $reply,
        );
    }

    /**
     * Send signal to all users (broadcast). Optionally exclude one connection.
     *
     * @param string $signalName Signal name
     * @param SignalDataInterface $data Signal payload
     * @param ?string $excludeAcceptKey Optional acceptKey to exclude from delivery
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function sendToAllUsers(string $signalName, SignalDataInterface $data, ?string $excludeAcceptKey = null): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_ALL),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, excludeAcceptKey: $excludeAcceptKey),
        );
    }

    /**
     * Broadcast signal to every connected WebSocket client, including connections
     * not subscribed to any page. Use sendToAllUsers() for the usual page-subscriber
     * broadcast; reserve this for the rare all-connections case.
     *
     * @param string $signalName Signal name
     * @param SignalDataInterface $data Signal payload
     * @param ?string $excludeAcceptKey Optional acceptKey to exclude from delivery
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function sendToAllConnected(string $signalName, SignalDataInterface $data, ?string $excludeAcceptKey = null): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, excludeAcceptKey: $excludeAcceptKey),
        );
    }

    /**
     * Send signal to all users subscribed to a group.
     *
     * @param string $signalName Signal name
     * @param string $targetGroup Group name
     * @param SignalDataInterface $data Signal payload
     * @param ?string $excludeAcceptKey Optional acceptKey to exclude from delivery
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function sendToGroup(string $signalName, string $targetGroup, SignalDataInterface $data, ?string $excludeAcceptKey = null): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_GROUP),
            signalName: new SignalName($signalName),
            signalData: new WebSocketSignalData(data: $data, targetGroup: $targetGroup, excludeAcceptKey: $excludeAcceptKey),
        );
    }

    /**
     * Send signal to another agent (agent-to-agent).
     *
     * Target agent is determined by the application router. Static direct agent
     * routes should be declared in AGENT_SIGNALS and imported by the router.
     *
     * @param string $signalName Signal name (used for routing)
     * @param SignalDataInterface $data Signal payload
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function sendToAgent(string $signalName, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            signalName: new SignalName($signalName),
            signalData: new AgentSignalData(data: $data),
        );
    }

    /**
     * Reads the session token of the connection that asked and returns the hash the freeze stores.
     *
     * The token is resolved from the runtime connection roster rather than taken from the request:
     * the request carries the socket, and the socket is exactly what a reload replaces. Failing to
     * resolve it is a degradation and not a refusal - the operation goes ahead recognized by its
     * accept key alone, the way it behaved before this existed - so it is written down rather than
     * thrown, or nobody would ever learn why the reload stopped working.
     *
     * @param ?string $acceptKey Accept key of the connection that asked, or null when nothing with a socket did
     * @return ?string Hash of that connection's session token, or null when there is none to read
     */
    protected function resolveInitiatorSessionTokenHash(?string $acceptKey): ?string
    {
        if ($acceptKey === null || $acceptKey === '') {
            return null;
        }

        $sessionToken = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->sessionToken;
        if ($sessionToken === null) {
            $this->logAgentWarning(
                "Protected mode could not read the session of the connection that asked ({$acceptKey}); "
                . 'the freeze will recognize that one socket only'
            );

            return null;
        }

        return ProtectedModeRuntime::hashSessionToken($sessionToken);
    }

    /**
     * Request the cluster to enter protected mode for a destructive operation.
     *
     * The initiator agent (a backup restore agent today, other destructive operations later) runs in
     * a worker and cannot reach the leader directly, so it asks its own master daemon to start the
     * two-phase freeze. This queues a worker-drained {@see SignalTypeConstants::PROTECTED_MODE_ENABLE}
     * signal that {@see WorkerManager} turns into a worker-to-daemon frame; the daemon hands the
     * payload to {@see ProtectedModeSwitch::requestEnable()}.
     * The initiator identity carried here (this agent's type and index, and this node's id when
     * there are nodes to name) is what the freeze leaves running through the lockdown and later
     * authorizes {@see requestProtectedModeDisable()} against. The connection identity beside it is
     * carried twice over - the socket that asked and the browser session behind it - because the
     * socket dies on a reload and the browser is what the operator keeps watching from (HIL-655).
     *
     * The agent asks the same way whether or not the installation clusters: which machinery answers
     * is a topology decision that lives in the daemon. A single node names no node id - it has none,
     * and asking for one off-cluster throws.
     *
     * This is the only entry into the mode, and no second one is to be added: there is no facade
     * method, no static switch and no env lever, in production or in a test. A CLI command never
     * enters the mode itself either - it asks the agent that owns the destructive operation over the
     * command channel, the way `backup:restore-request` reaches BackupAgent. The initiator identity
     * recorded here is why: it authorizes the later release and is what the agent-start gate lets
     * through, so a synthetic caller would exercise a path production does not have. The whole
     * contract is in docs/agents/architecture/protected-mode.md.
     *
     * @param string $operation Operation name the freeze protects (for example a restore)
     * @param string $initiatorAcceptKey Accept key of the connection driving the operation
     * @param ?string $initiatorSessionTokenHash Hash of the session token behind that connection, or null when
     *                                           the operation was asked for by something with no browser at all
     * @throws EnvException When a cluster environment value cannot be read
     * @throws ClusterConfigurationException When cluster mode is on but the local node config is missing or invalid
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    protected function requestProtectedModeEnable(
        string $operation,
        string $initiatorAcceptKey,
        ?string $initiatorSessionTokenHash,
    ): void {
        $cluster = Hilos::$cluster;
        $clustered = $cluster !== null && $cluster->isEnabled();

        $index = $this->getIndex();
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::PROTECTED_MODE_ENABLE),
            signalName: new SignalName(SignalTypeConstants::PROTECTED_MODE_ENABLE),
            signalData: new ProtectedModeEnableSignalData(
                operation: $operation,
                initiatorAcceptKey: $initiatorAcceptKey,
                initiatorSessionTokenHash: $initiatorSessionTokenHash,
                initiatorAgentType: $this->getType(),
                initiatorAgentIndex: $index === null ? null : (int)$index,
                initiatorNodeId: $clustered ? $cluster->identity()->nodeId : null,
            ),
        );
    }

    /**
     * Request the cluster to leave protected mode once the destructive operation is done.
     *
     * The mirror of {@see requestProtectedModeEnable()}: queues a worker-drained
     * {@see SignalTypeConstants::PROTECTED_MODE_DISABLE} signal that {@see WorkerManager} sends to
     * this node's daemon, which routes it to {@see ProtectedModeSwitch::requestDisable()}.
     * The agent names itself in the payload: a leader lifts only a freeze the requesting node
     * initiated, and a single node - where every request comes from the same node - authorizes the
     * release by this identity instead. Nothing here depends on the topology, so unlike
     * {@see requestProtectedModeEnable()} this side reads no cluster state at all.
     *
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    protected function requestProtectedModeDisable(): void
    {
        $index = $this->getIndex();
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::PROTECTED_MODE_DISABLE),
            signalName: new SignalName(SignalTypeConstants::PROTECTED_MODE_DISABLE),
            signalData: new ProtectedModeDisableSignalData(
                initiatorAgentType: $this->getType(),
                initiatorAgentIndex: $index === null ? null : (int)$index,
            ),
        );
    }

    /**
     * Request the verification window once the destructive operation is done.
     *
     * What an initiator asks for instead of {@see requestProtectedModeDisable()} the moment its
     * operation ends: the system stays closed to everyone and a hand-picked circle is let in by
     * pass to confirm it really came back. Opening to all is a separate, explicit operator
     * command, so nothing opens without a human.
     *
     * Same path and same authorization as the disable it replaces - queued as a worker-drained
     * {@see SignalTypeConstants::PROTECTED_MODE_VERIFY} signal that reaches this node's daemon and
     * {@see ProtectedModeSwitch::requestVerify()}.
     *
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    protected function requestProtectedModeVerify(): void
    {
        $index = $this->getIndex();
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::PROTECTED_MODE_VERIFY),
            signalName: new SignalName(SignalTypeConstants::PROTECTED_MODE_VERIFY),
            signalData: new ProtectedModeVerifySignalData(
                initiatorAgentType: $this->getType(),
                initiatorAgentIndex: $index === null ? null : (int)$index,
            ),
        );
    }

    /**
     * Report that the operation this agent froze the node for has moved.
     *
     * The proof of life a stuck-freeze watchdog reads, and the obligation belongs to whoever
     * froze the node: what has to be shown is that the WORK advanced, which no framework-written
     * "the agent still ticks" mark could ever assert - an initiator that spawns a child and waits
     * for it goes on ticking whether or not that child is doing anything at all. Call it from the
     * places where something of the operation's own genuinely happened: a new phase, a line of
     * output from the child, a batch that landed.
     *
     * Marking is cheap and skipping it is not: too many marks cost one row write each, while an
     * operation that never marks raises a false alarm on an honest long run. The alarm is only a
     * message, so that is the direction the error is allowed to lean.
     *
     * Same path and same authorization as the requests around it - a worker-drained
     * {@see SignalTypeConstants::PROTECTED_MODE_PROGRESS} signal reaching this node's daemon and
     * {@see ProtectedModeSwitch::requestProgress()} - and it moves no phase: an agent that reports
     * progress under no freeze at all is simply telling nobody.
     *
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    protected function reportProtectedModeProgress(): void
    {
        $index = $this->getIndex();
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::PROTECTED_MODE_PROGRESS),
            signalName: new SignalName(SignalTypeConstants::PROTECTED_MODE_PROGRESS),
            signalData: new ProtectedModeProgressSignalData(
                initiatorAgentType: $this->getType(),
                initiatorAgentIndex: $index === null ? null : (int)$index,
            ),
        );
    }

    /**
     * Record one more pass for the verification window in flight.
     *
     * Only the hash of the minted key travels: the caller keeps the clear value and hands it to
     * the operator, so nothing that opens the system is ever written to the row or to a log.
     *
     * @param string $passHash SHA-256 of the pass the caller minted
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    protected function requestProtectedModePass(string $passHash): void
    {
        $index = $this->getIndex();
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::PROTECTED_MODE_PASS),
            signalName: new SignalName(SignalTypeConstants::PROTECTED_MODE_PASS),
            signalData: new ProtectedModePassSignalData(
                initiatorAgentType: $this->getType(),
                initiatorAgentIndex: $index === null ? null : (int)$index,
                passHash: $passHash,
            ),
        );
    }

    /**
     * Close the system again from the verification window, voiding every pass.
     *
     * The exit an operator takes when the verifiers found something wrong: the freeze returns to
     * active, this node's agents are stopped once more, and another destructive operation may run.
     *
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    protected function requestProtectedModeRefreeze(): void
    {
        $index = $this->getIndex();
        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::PROTECTED_MODE_REFREEZE),
            signalName: new SignalName(SignalTypeConstants::PROTECTED_MODE_REFREEZE),
            signalData: new ProtectedModeRefreezeSignalData(
                initiatorAgentType: $this->getType(),
                initiatorAgentIndex: $index === null ? null : (int)$index,
            ),
        );
    }

    /**
     * Announce that the database under this node was replaced (HIL-479).
     *
     * The counterpart of the protected-mode pair for the swap itself: an agent that has just
     * put a different database under the running node tells every process holding DB-backed
     * collections to drop what it cached and re-read. This process re-hydrates on the spot,
     * because the caller is normally about to read the new database in the same method, and
     * the queued {@see SignalTypeConstants::DB_REHYDRATE} signal reaches the daemon and the
     * other workers over the worker link ({@see WorkerManager}), where each applies it through
     * {@see DbSyncApplicator::applyReHydrate()}.
     *
     * Without it the node still recovers, but only lazily: {@see DbContext::reHydrateIfDbChanged()}
     * notices the swap at the first id collision, which is one collision too late for a reader
     * that trusts what it reads in between.
     *
     * The announcement names this agent, because it is also a question (HIL-436): the daemon
     * collects one answer per process it fanned the frame out to and reports back through
     * {@see onDbReHydrateComplete()}. This process re-hydrates synchronously all the same - the
     * caller is normally about to read the new database in the same method - so the wait is only
     * ever about the *others*.
     *
     * @throws LogicException When a represented collection entity class is not configured (eager reload)
     * @throws DatabaseException If reloading an eager collection from the fresh database fails
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     * @throws HilosException When a collection refuses to be re-read from the replaced database
     */
    protected function requestDbReHydrate(): void
    {
        DbSyncApplicator::applyReHydrate();

        Hilos::$sr->queueSignal(
            signalSource: $this->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::DB_REHYDRATE),
            signalName: new SignalName(SignalTypeConstants::DB_REHYDRATE),
            signalData: new DbReHydrateSignalData($this->getId()),
        );
    }

    /**
     * Request agent to stop itself.
     *
     * Sets internal flag that will cause onStop() to be called at the start
     * of the next tick. Agent will be removed from worker after onStop() is called.
     *
     * Child classes can call this method to request their own termination.
     */
    protected function selfStop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * @return bool True if agent has requested stop
     */
    public function shouldStop(): bool
    {
        return $this->shouldStop;
    }

    /**
     * Default implementation - no action on start.
     *
     * Child classes can override this method.
     *
     * @throws HilosException Whatever the concrete agent's start raises
     */
    public function onStart(): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no action on tick.
     *
     * Child classes can override this method.
     *
     * @throws HilosException Whatever the concrete agent's tick raises
     */
    public function onTick(): void
    {
        // Default: do nothing
    }

    /**
     * Called when agent is stopped.
     *
     * Must be implemented in child classes to handle cleanup.
     *
     * @throws HilosException Whatever the concrete agent's stop raises
     * @throws InvalidArgumentException Whatever the concrete agent's stop raises from SPL
     */
    abstract public function onStop(): void;

    /**
     * Default implementation - no action when the cluster is ready for a protected operation.
     *
     * The initiator agent overrides this to run its destructive operation once the cluster has
     * frozen. Reaches this node over the daemon->worker ready relay driven by
     * {@see requestProtectedModeEnable()}; a no-op for agents that never request protected mode.
     *
     * @throws HilosException Whatever the concrete agent's protected operation raises
     * @throws InvalidArgumentException Whatever the concrete agent's protected operation raises from SPL
     */
    public function onProtectedModeReady(): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no action when the node has finished re-reading a replaced database.
     *
     * The announcing agent overrides this to finish whatever it was doing around the swap: the
     * outcome says whether every other process confirmed, and names those that did not. Reaches
     * this node over the daemon->worker verdict relay driven by {@see requestDbReHydrate()}; a
     * no-op for agents that never replace a database.
     *
     * @param DbReHydrateOutcome $outcome Whether the barrier closed, and who is missing from it
     * @throws HilosException Whatever the concrete agent finishes the swap with
     * @throws InvalidArgumentException Whatever the concrete agent finishes the swap with from SPL
     */
    public function onDbReHydrateComplete(DbReHydrateOutcome $outcome): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no system signal handling
     *
     * Child classes can override this method.
     *
     * @param SignalDataInterface $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's system-signal handler raises
     */
    public function onSignalSystem(SignalDataInterface $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no handshake signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketHandshakeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's handshake handler raises, a payload that fails validation among them
     */
    public function onSignalHandshake(WebSocketHandshakeSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no connection close signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketCloseSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's connection-close handler raises
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no page subscribe signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketPageSubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's page-subscribe handler raises
     */
    public function onSignalPageSubscribe(WebSocketPageSubscribeSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no page unsubscribe signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketPageUnsubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's page-unsubscribe handler raises
     */
    public function onSignalPageUnsubscribe(WebSocketPageUnsubscribeSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no page update subscription signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketPageUpdateSubscriptionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's page-update-subscription handler raises
     */
    public function onSignalPageUpdateSubscription(WebSocketPageUpdateSubscriptionSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no group subscribe signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketGroupSubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's group-subscribe handler raises
     */
    public function onSignalGroupSubscribe(WebSocketGroupSubscribeSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no group unsubscribe signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketGroupUnsubscribeSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's group-unsubscribe handler raises
     */
    public function onSignalGroupUnsubscribe(WebSocketGroupUnsubscribeSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no group update subscription signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketGroupUpdateSubscriptionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's group-update-subscription handler raises
     */
    public function onSignalGroupUpdateSubscription(WebSocketGroupUpdateSubscriptionSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no action signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketActionSignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's action handler raises
     */
    public function onSignalAction(WebSocketActionSignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Handle a client action this agent owns through AGENT_ACTIONS.
     *
     * The page-independent action seam: the dispatcher parses the declared payload DTO
     * and comes here instead of to a page, so a shell-level control (e.g. logout)
     * reaches the agent from any subscribed page or none. Default no-op; agents that
     * declare AGENT_ACTIONS override this and route by action name.
     *
     * A returned reply rides the action's success ack, the same way a page's does, and
     * so needs the client to have minted a request id; an untracked action that returns
     * one has nothing to correlate it to and the dispatcher drops it with a warning.
     *
     * @param string $acceptKey Acting connection accept key
     * @param string $action Owned action name from AGENT_ACTIONS
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     * @throws AgentUnknownActionException When the agent does not support the action
     * @throws HilosException Whatever the concrete agent's owned-action handler raises
     * @throws RandomException When a concrete agent's handler cannot draw from the CSPRNG
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        // Default: do nothing
        return null;
    }

    /**
     * Runs this agent's handler for one action the dispatcher routed here.
     *
     * The action-host spelling of {@see self::onAgentAction()}: the dispatcher serves pages
     * and agents through one contract, and each names its own handler.
     *
     * @param string $acceptKey Acting connection accept key
     * @param string $action Owned action name from AGENT_ACTIONS
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     * @throws AgentUnknownActionException When the agent does not support the action
     * @throws HilosException Whatever the concrete agent's owned-action handler raises
     * @throws RandomException When a concrete agent's handler cannot draw from the CSPRNG
     */
    public function runAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        return $this->onAgentAction($acceptKey, $action, $dto);
    }

    /**
     * @return string Agent id, under which the action dispatcher logs this host
     */
    public function actionHostName(): string
    {
        return $this->getId();
    }

    /**
     * @return list<string> Action names of this agent the anti-abuse layer judges before they run
     */
    public function throttledActions(): array
    {
        return static::THROTTLED_ACTIONS;
    }

    /**
     * @return list<string> Action names of this agent that require an authenticated session
     */
    public function authActions(): array
    {
        return static::AUTH_ACTIONS;
    }

    /**
     * Empties the per-action slots before a handler of this agent runs.
     *
     * @param ?string $requestId Client-minted request id of this dispatch, or null when untracked
     */
    public function beginActionDispatch(?string $requestId = null): void
    {
        $this->actionReply()->beginDispatch($requestId);
    }

    /**
     * Ends the dispatch of one action, leaving no per-dispatch state readable behind it.
     *
     * Called by the dispatcher whichever way the action went - answered, deferred or thrown -
     * so that a frame built between dispatches cannot quote an answered request id.
     */
    public function endActionDispatch(): void
    {
        $this->actionReply()->endDispatch();
    }

    /**
     * Returns the request id of the action dispatch running right now.
     *
     * @return ?string Request id of the running dispatch, or null when the caller did not track it
     */
    public function currentActionRequestId(): ?string
    {
        return $this->actionReply()->requestId();
    }

    /**
     * Hands the answer to this action to whoever the handler passed the ending to.
     *
     * The one thing a handler may say about its own reply, and it is a negative: the
     * dispatcher must NOT ack, because an ack is on its way from another process and a
     * second one would tell the browser the command finished before it did. Narrow on
     * purpose - the reply node itself stays private, so a handler cannot compose an answer
     * of its own behind the dispatcher's back (HIL-622).
     */
    protected function deferActionReply(): void
    {
        $this->actionReply()->defer();
    }

    /**
     * Whether the handler that just ran handed the answer to another process.
     *
     * @return bool True when this agent owes no ack for the running action
     */
    public function actionReplyDeferred(): bool
    {
        return $this->actionReply()->isDeferred();
    }

    /**
     * Sends the framework action-success reply for a tracked action of this agent.
     *
     * @param string $acceptKey Accept key of the initiating connection
     * @param string $action Action name that committed
     * @param string $requestId Client-minted request id echoed back for correlation
     * @param ?ActionReplyDTO $reply Domain reply the handler returned, or null when it answered with nothing
     * @throws InvalidArgumentException When the action-success signal cannot be named
     */
    public function sendActionSuccess(
        string $acceptKey,
        string $action,
        string $requestId,
        ?ActionReplyDTO $reply = null,
    ): void {
        $this->actionReply()->sendSuccess($acceptKey, $action, $requestId, $reply);
    }

    /**
     * Sends the framework action-failure reply for a tracked action of this agent.
     *
     * @param string $acceptKey Accept key of the initiating connection
     * @param string $action Action name that failed
     * @param string $requestId Client-minted request id echoed back for correlation
     * @param string $reason Human-readable error message exposed to the client
     * @param ?string $errorCode Machine-readable error code, or null when unclassified
     * @param ?int $retryAfter Seconds the caller should wait before retrying, or null
     * @throws InvalidArgumentException When the action-error signal cannot be named
     */
    public function sendActionFail(
        string $acceptKey,
        string $action,
        string $requestId,
        string $reason,
        ?string $errorCode = null,
        ?int $retryAfter = null,
    ): void {
        $this->actionReply()->sendFail($acceptKey, $action, $requestId, $reason, $errorCode, $retryAfter);
    }

    /**
     * Reports an untracked action's failure to the connection that sent it.
     *
     * Override only when the agent has a more specific user-facing error contract; a
     * tracked action never reaches here, its failure goes out as the correlated fail ack.
     *
     * @param string $acceptKey Acting connection accept key
     * @param string $action Action name that failed
     * @param ActionPayloadDTO $dto Parsed action payload (unused by the default contract)
     * @param Throwable $e Action failure exposed to the client
     * @throws InvalidArgumentException When the action-error signal cannot be named
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        $this->actionReply()->sendException($acceptKey, $action, $e);
    }

    /**
     * Default implementation - no binary frame signal handling
     *
     * Child classes can override this method.
     *
     * @param WebSocketFrameBinarySignalDTO $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's binary-frame handler raises
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no cron signal handling
     *
     * Child classes can override this method.
     *
     * @param SignalDataInterface $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's cron handler raises
     * @throws InvalidArgumentException Whatever the concrete agent's cron handler raises from SPL
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no agent-to-agent signal handling
     *
     * Child classes can override this method.
     * Use $data->data to access the inner payload (e.g. ModerationRequestSignalData).
     *
     * @param AgentSignalData $data Signal data (container with inner payload)
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws AgentUnknownSignalException When the handler is reached by a signal it does not know
     * @throws HilosException Whatever the concrete agent's agent-signal handler raises
     * @throws InvalidArgumentException When the handler cannot name the signal it answers with
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no CLI command handling.
     *
     * Child classes override this to handle a command routed to the agent and
     * answer with replyToCommand().
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's command handler raises
     * @throws InvalidArgumentException When the handler cannot name its reply to the command
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no DB sync signal handling
     *
     * Child classes can override these methods.
     *
     * @param DbSyncCreatedSignalData $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's DB-create handler raises
     */
    public function onSignalDbSyncCreated(DbSyncCreatedSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no DB sync signal handling
     *
     * Child classes can override these methods.
     *
     * @param DbSyncUpdatedSignalData $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's DB-update handler raises
     */
    public function onSignalDbSyncUpdated(DbSyncUpdatedSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no DB sync signal handling
     *
     * Child classes can override these methods.
     *
     * @param DbSyncDeletedSignalData $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's DB-delete handler raises
     */
    public function onSignalDbSyncDeleted(DbSyncDeletedSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no RT sync signal handling
     *
     * Child classes can override these methods.
     *
     * @param RtSyncCreatedSignalData $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's RT-create handler raises
     */
    public function onSignalRtSyncCreated(RtSyncCreatedSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no RT sync signal handling
     *
     * Child classes can override these methods.
     *
     * @param RtSyncUpdatedSignalData $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's RT-update handler raises
     */
    public function onSignalRtSyncUpdated(RtSyncUpdatedSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Default implementation - no RT sync signal handling
     *
     * Child classes can override these methods.
     *
     * @param RtSyncDeletedSignalData $data Signal data
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws HilosException Whatever the concrete agent's RT-delete handler raises
     */
    public function onSignalRtSyncDeleted(RtSyncDeletedSignalData $data, string $source, string $name): void
    {
        // Default: do nothing
    }

    /**
     * Returns this agent's reply node, building it on first use.
     *
     * @return ActionReply Node that answers this agent's actions
     */
    private function actionReply(): ActionReply
    {
        return $this->actionReply ??= new ActionReply($this);
    }
}
