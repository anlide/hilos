<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Sync\DTO\DbReHydrateSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\DTO\DbReHydrateOutcome;
use Hilos\Database\DbSyncApplicator;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
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

/**
 * AbstractAgent - Abstract base class for agents running in worker processes.
 *
 * Provides base implementation for agents. Child classes should define:
 * - AGENT_TYPE constant - agent type identifier
 * - $agentIndex property - for multi-instance agents (set by the indexed constructor)
 * - onTick() - agent work logic run on each worker loop iteration
 * - Signal handling methods (can override onSignal* methods for specific signal types).
 */
abstract class AbstractAgent implements AgentInterface, PageAgentInterface
{
    /** @var string Agent type identifier. Override in child classes. */
    public const string AGENT_TYPE = '';

    /** @var list<string> Agent signal names owned directly by this agent. */
    public const array AGENT_SIGNALS = [];

    /** @var list<string> CLI command names owned directly by this agent. */
    public const array AGENT_COMMANDS = [];

    /**
     * @var array<string, class-string<ActionPayloadDTO>> Client-action payload DTOs owned directly by this
     *     agent, keyed by action name. The page-independent counterpart to Page::ACTIONS: an action listed
     *     here is routed straight to {@see self::onAgentAction()} from any subscribed page (or none).
     */
    public const array AGENT_ACTIONS = [];

    /** @var ?string Agent index for multi-instance agents (null for singletons) */
    protected ?string $agentIndex = null;

    /** @var bool Flag indicating agent should stop */
    private bool $shouldStop = false;

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
     * Register this agent as truth source for a database collection.
     *
     * @param string $collection Collection/table name
     * @param list<string>|true $keys Specific writable keys or true for all keys
     */
    protected function registerDbTruthSource(string $collection, array|true $keys = true): void
    {
        TruthSourceRegistry::register($collection, $keys, $this->getId());
    }

    /**
     * Register this agent as truth source for a runtime collection.
     *
     * @param string $collection Runtime collection name
     * @param list<string>|true $keys Specific writable keys or true for all keys
     */
    protected function registerRtTruthSource(string $collection, array|true $keys = true): void
    {
        RtTruthSourceRegistry::register($collection, $keys, $this->getId());
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
     * Request the cluster to enter protected mode for a destructive operation.
     *
     * The initiator agent (a backup restore agent today, other destructive operations later) runs in
     * a worker and cannot reach the leader directly, so it asks its own master daemon to start the
     * two-phase freeze. This queues a worker-drained {@see SignalTypeConstants::PROTECTED_MODE_ENABLE}
     * signal that {@see WorkerManager} turns into a worker-to-daemon frame; the daemon hands the
     * payload to {@see ProtectedModeSwitch::requestEnable()}.
     * The initiator identity carried here (this agent's type and index, and this node's id when
     * there are nodes to name) is what the freeze leaves running through the lockdown and later
     * authorizes {@see requestProtectedModeDisable()} against.
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
     * @throws EnvException When a cluster environment value cannot be read
     * @throws ClusterConfigurationException When cluster mode is on but the local node config is missing or invalid
     * @throws InvalidArgumentException When the signal name or the queued signal is malformed
     */
    protected function requestProtectedModeEnable(string $operation, string $initiatorAcceptKey): void
    {
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
     * The page-independent action seam: the worker parses the declared payload DTO
     * and dispatches here instead of a page, so a shell-level control (e.g. logout)
     * reaches the agent from any subscribed page or none. Default no-op; agents that
     * declare AGENT_ACTIONS override this and route by action name.
     *
     * @param string $acceptKey Acting connection accept key
     * @param string $action Owned action name from AGENT_ACTIONS
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws HilosException Whatever the concrete agent's owned-action handler raises
     */
    public function onAgentAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        // Default: do nothing
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
}
