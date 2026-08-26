<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\API\Router\Exception\GroupSubscriptionNotFoundException;
use Hilos\API\Router\Exception\PageSubscriptionMismatchException;
use Hilos\API\Router\Exception\PageSubscriptionNotFoundException;
use Hilos\API\Router\HttpRouter;
use Hilos\Backup\BackupSchedule;
use Hilos\Backup\Exception\BackupScheduleException;
use Hilos\Cluster\AgentSignalSink;
use Hilos\Cluster\ClientMesh;
use Hilos\Cluster\ClientSignalSink;
use Hilos\Cluster\ClusterCommandConstants;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\Connections\ClusterClientLocation;
use Hilos\Cluster\LeadershipObserver;
use Hilos\Cluster\MembershipObserver;
use Hilos\Cluster\NodeLifecycleState;
use Hilos\Cluster\Peer\DTO\PeerDbSyncDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSyncDTO;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\AgentLocationKind;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementObserver;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\DbSyncMesh;
use Hilos\Cluster\DbSyncSink;
use Hilos\Cluster\RtReplicaInspector;
use Hilos\Cluster\RtSyncMesh;
use Hilos\Cluster\RtSyncSink;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentNotFoundException;
use Hilos\Core\Agent\Exception\AgentNotLinkedToWorkerException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Core\Agent\Exception\WorkerClientNotFoundException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Core\Daemon\Module\DaemonModule;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\MissingRequiredParameterException;
use Hilos\Core\Http\RootInfoHandler;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\DTO\PageAccessReassessConnectionsSignalData;
use Hilos\Core\Page\DTO\PageAccessReassessUserSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\AllClientsDestination;
use Hilos\Core\Router\Destination\SessionClientsDestination;
use Hilos\Core\Router\Destination\CommandReplyDestination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\Destination\RemoteClientDestination;
use Hilos\Core\Router\Destination\UnknownAgentDestination;
use Hilos\Core\Router\Destination\RemoteFanoutDestination;
use Hilos\Core\Router\Destination\WebSocketDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\PageAgentAddress;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketEnvelopeAware;
use Hilos\Core\Sync\DTO\DbReHydrateSignalData;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\SyncSignalDataInterface;
use Hilos\Database\DatabaseException;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\ReHydrateRound;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\DTO\ProtectedModeStateSignalData;
use Hilos\ProtectedMode\Exception\ProtectedModeFreezeUnreadableException;
use Hilos\ProtectedMode\ProtectedModeAgentFreezer;
use Hilos\ProtectedMode\ProtectedModeClientNotifier;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\ProtectedMode\ProtectedModeFreezeStore;
use Hilos\ProtectedMode\ProtectedModeReadyRelay;
use Hilos\ProtectedMode\ProtectedModeWatchdog;
use Hilos\ProtectedMode\StandaloneProtectedMode;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\RtBaseException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\TruthSource\RtNodeSourceMap;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\ServerInterface;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\SocketException;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\DaemonWorkerSignalDTO;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydrateMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReReadMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncClearedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessConnectionsMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\ClientReadFailureLog;
use Hilos\Utils\Logger;
use Random\RandomException;
use Throwable;

/**
 * Abstract base for daemon processes. run() owns the main loop: it drains ready
 * socket events through the libevent event loop, ticks registered servers,
 * dispatches due cron rules, and flushes accumulated signals once per
 * non-blocking iteration.
 *
 * Child classes supply the signal router and agent manager daemon through
 * createSignalRouter() and createAgentManagerDaemon(); the per-iteration work is
 * dispatched by node lifecycle phase (see {@see dispatchRoleTick()}), with an
 * optional no-op hook per phase that child classes may override.
 *
 * The manager is also the cluster membership, leadership, and placement observer: it
 * registers itself on the cluster context at start and exposes {@see onNodeJoined()} /
 * {@see onNodeLeft()} hooks so a project can react to nodes joining and leaving the
 * mesh, plus {@see onBecameLeader()} / {@see onLostLeadership()} /
 * {@see onQuorumGained()} / {@see onQuorumLost()} for the consensus transitions, the
 * broad {@see onClusterWorkStop()} directive on quorum loss or a planned graceful-leave,
 * and {@see onPlacementDegraded()} when failover cannot re-place an orphaned agent. The
 * node up/down defaults also drive placement failover, so an override must call the parent.
 */
abstract class DaemonManager extends BaseManager implements
    MembershipObserver,
    LeadershipObserver,
    PlacementObserver,
    ConnectionDropper,
    LiveConnectionRoster,
    ContainedFailureSink,
    MasterSignalSender,
    ProtectedModeSnapshotSource,
    ProtectedModeAdmissionRecorder,
    ProtectedModeClientNotifier,
    RtSyncSink,
    RtReplicaInspector,
    DbSyncSink,
    ClientSignalSink
{
    /** @var string Error code a browser is answered with when the node serving its page cannot be reached */
    private const string SUBSCRIPTION_NODE_UNREACHABLE_CODE = 'node_unreachable';

    /** @var string Message a browser is answered with when the node serving its page cannot be reached */
    private const string SUBSCRIPTION_NODE_UNREACHABLE_MESSAGE = 'This page is temporarily unavailable. Please try again.';

    /** @var string How the master facade's log line names "every worker of this node" as an addressee */
    private const string MASTER_SIGNAL_WORKERS_LABEL = 'workers';

    /**
     * @var string Why a signal to an agent was refused when no node is known to host it.
     *
     * One reason covers every way the address can be missing - a follower with no placement view
     * yet, a cluster mid-election with no leader to name, an agent nothing has placed. They are
     * one outcome to the sender and a second reason code would only invite treating them apart,
     * which nothing here can do (HIL-670).
     */
    private const string AGENT_NO_KNOWN_NODE_REASON = 'no known node';

    /** @var string Address of the loop iteration on the card of a failure contained there */
    private const string LOOP_FAILURE_ADDRESS = 'daemon loop';

    /** @var string Journal line for a failure the project's hook raised: class, file, line, message */
    private const string HOOK_FAILURE_FORMAT = 'Failure in the contained-failure hook: %s in %s:%d - %s';

    /** @var float Seconds between stuck-readiness log lines while the WebSocket server waits for startup agents */
    private const float READINESS_LOG_INTERVAL = 60.0;

    /**
     * @var float Seconds the re-hydrate barrier waits when its configured timeout cannot be read
     *
     * Not the catalog default under another name: that one is the value an operator may tune, this
     * one is what a node falls back on when the tuning itself is unreadable, and the two would move
     * for different reasons.
     */
    private const float DB_REHYDRATE_TIMEOUT_FALLBACK_SECONDS = 30.0;

    /** @var string What a restored freeze whose row names no operation is called in the startup line */
    private const string UNNAMED_FROZEN_OPERATION = '(unnamed)';

    /**
     * @var int Milliseconds a subscription waits for the identity behind its connection before
     *     it is routed on whatever is known (HIL-627)
     *
     * Deliberately its own number and not the worker's {@see PageSignalRouter} wait under a shared
     * name. The two happen to be equal today and are answerable to different things: the worker
     * waits for an RT row to reach the worker that judges a frame, this one waits for it to reach
     * the master that addresses a subscription. Either may move without the other.
     */
    private const int SUBSCRIPTION_IDENTITY_WAIT_TIMEOUT_MS = 500;

    /** @var float Seconds between attempts at a policy placement that has not taken */
    private const float POLICY_PLACEMENT_RETRY_SEC = 5.0;

    /** @var list<ServerInterface> registered servers */
    protected array $servers = [];

    /** @var ?HttpRouter HTTP router instance */
    protected ?HttpRouter $httpRouter = null;

    /** @var EventLoop Event loop for epoll */
    protected EventLoop $eventLoop;

    /** @var AgentManagerDaemon Agent manager daemon instance */
    protected AgentManagerDaemon $agentManagerDaemon;

    /** @var ?float Shutdown start time (null if not shutting down) */
    private ?float $shutdownStartTime = null;

    /** @var float Shutdown timeout in seconds */
    protected float $shutdownTimeout = 20.0;

    /** @var array<string, CronRule> cron rules by name */
    private array $cronRules = [];

    /** @var int Last cron check minute timestamp (minute-level, from floor(time() / 60)) */
    private int $lastCronCheckMinute = -1;

    /** @var bool Flag indicating if initial workers are ready */
    private bool $workersReady = false;

    /** @var float Microtime a policy placement that has not taken may be attempted again */
    private float $policyPlacementRetryAt = 0.0;

    /**
     * @var bool True once this node's cluster-singleton agents have been started for
     *     the current leadership term. Set by the leader-gated ensure-once
     *     ({@see ensureSingletonsStarted()}); cleared on leadership loss by
     *     {@see stopClusterSingletons()} so a later promotion re-runs the start.
     */
    private bool $singletonsStarted = false;

    /** @var bool True once the WebSocket server has been started */
    private bool $webSocketStarted = false;

    /** @var ?float microtime when the readiness wait began; null until workers are ready */
    private ?float $readinessWaitSince = null;

    /** @var list<ParkedSignal> Signals held until the identity behind their connection arrives (HIL-627) */
    private array $parkedSignals = [];

    /** @var ?float microtime of the last stuck-readiness log line; null until the first one */
    private ?float $lastReadinessLogAt = null;

    /** @var ?string What this node owned when it last offered its RT state; null before the first pass */
    private ?string $rtOwnershipSignature = null;

    /** @var int Remote RT frames this node has applied to the copy it holds */
    private int $rtFramesApplied = 0;

    /** @var int Remote RT frames this node refused because it writes what they carried */
    private int $rtFramesRefused = 0;

    /** @var ?float Seconds to wait for required agents before opening the WebSocket degraded; null = wait forever */
    protected ?float $readinessTimeout = null;

    /**
     * @var ProtectedModeWatchdog Watcher over a freeze that stops moving (HIL-482).
     *
     * Built with the manager rather than when a freeze begins, because one of the things it has to
     * notice - the initiator agent stopping - is an event it can only catch by being there when it
     * happens. It costs nothing while no node is frozen: a tick over an inactive row returns at its
     * first branch.
     */
    private ProtectedModeWatchdog $protectedModeWatchdog;

    /**
     * Initializes daemon manager.
     *
     * Initializes signal router via Hilos::initSignalRouter() and creates
     * agent manager daemon. Child classes must implement createSignalRouter()
     * and createAgentManagerDaemon().
     */
    public function __construct()
    {
        Hilos::initSignalRouter($this->createSignalRouter());
        $this->agentManagerDaemon = $this->createAgentManagerDaemon();
        $this->protectedModeWatchdog = new ProtectedModeWatchdog();
        // The freeze watchdog has to hear an agent stop as it happens: the agent-start gate lets an
        // initiator's type start again under the freeze it left behind, so a later look at the
        // roster would find a fresh instance and read a dead operation as a live one (HIL-482).
        $this->agentManagerDaemon->registerAgentStopSink($this->protectedModeWatchdog);
    }

    /**
     * Create signal router instance.
     *
     * Must be implemented in child classes to create specific signal router.
     * The created instance is registered globally via Hilos::$sr.
     *
     * @return SignalRouter Signal router instance
     */
    abstract protected function createSignalRouter(): SignalRouter;

    /**
     * Create agent manager daemon instance.
     *
     * Must be implemented in child classes to create specific agent manager daemon.
     *
     * @return AgentManagerDaemon Agent manager daemon instance
     */
    abstract protected function createAgentManagerDaemon(): AgentManagerDaemon;

    /**
     * Get agent manager daemon instance.
     *
     * @return AgentManagerDaemon Agent manager daemon instance
     */
    public function getAgentManagerDaemon(): AgentManagerDaemon
    {
        return $this->agentManagerDaemon;
    }

    /**
     * Composes the daemon from its declarative hooks before the main loop runs.
     *
     * Called once by {@see DaemonApplication} after the facade is initialized: it
     * registers the servers from {@see createServers()}, builds the HTTP router with a
     * default GET / hint ({@see RootInfoHandler}) plus the routes from {@see httpRoutes()}
     * (a demo route on / overrides the hint), and registers each active module from
     * {@see modules()}. The
     * order is fixed — core servers, then router, then opt-in modules — so a module can
     * rely on the core servers already being present. Finally it registers the
     * daemon-mechanism backup cron rules ({@see registerBackupCronRules()}). A hook or module
     * failure propagates to the entrypoint, which logs it and exits (the daemon refuses to start).
     *
     * @param DaemonContext $context Resolved path context passed to every hook
     * @throws BackupScheduleException When the project backup schedule is malformed
     * @throws EnvException When a backup env value or the daemon log path cannot be read
     * @throws HilosException When a daemon module fails its activation check or its registration
     * @throws ProtectedModeFreezeUnreadableException When a freeze was left on disk and cannot be read
     * @throws RtActionsCollectionNameNullException When the freeze row has no collection name to sync under
     * @throws RtTruthSourceWriteNotAllowedException When this master may not write the freeze row it restores
     */
    public function boot(DaemonContext $context): void
    {
        foreach ($this->createServers($context) as $server) {
            $this->registerServer($server);
        }

        $router = new HttpRouter();
        $router->addRoute(HttpConstants::METHOD_GET, HttpConstants::PATH_ROOT, new RootInfoHandler());
        foreach ($this->httpRoutes($context) as [$method, $path, $handler]) {
            $router->addRoute($method, $path, $handler);
        }
        $this->registerHttpRouter($router);

        foreach ($this->modules($context) as $module) {
            if ($module->isActive()) {
                $module->register($this, $context);
            }
        }

        $this->registerBackupCronRules();
        $this->registerProtectedModeTruthSource();
        $this->registerSessionRotationTruthSource();
        $this->restoreProtectedModeFreeze();
    }

    /**
     * The core servers this daemon binds (http-status, worker, websocket, command, ...).
     *
     * Built from env and the resolved {@see DaemonContext}. A manager stashes any server it
     * needs later (e.g. the worker server for the status route) into a typed field while
     * building. Default is empty; a daemon overrides to declare its server set.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<ServerInterface> Servers to register, in bind order
     * @throws HilosException Whatever the daemon's read of env raises while building its servers
     */
    protected function createServers(DaemonContext $context): iterable
    {
        return [];
    }

    /**
     * The HTTP routes this daemon serves, as [method, path, handler] triples.
     *
     * Handlers stay callable, so an invokable handler object is allowed. Default is empty;
     * a daemon overrides to declare its routes.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<array{0: string, 1: string, 2: callable}> Route triples [method, path, handler]
     */
    protected function httpRoutes(DaemonContext $context): iterable
    {
        return [];
    }

    /**
     * The opt-in subsystem modules this daemon enables.
     *
     * Each module registers its own server(s) and reads its own env when active, so adding
     * a subsystem is a new entry here rather than an edit to the composition. Default is
     * empty; a daemon overrides to list its modules.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<DaemonModule> Modules to consider, checked via isActive() before register()
     * @throws HilosException Whatever the daemon's read of env raises while listing its modules
     */
    protected function modules(DaemonContext $context): iterable
    {
        return [];
    }

    /**
     * Run daemon - main method.
     *
     * Starts the daemon main loop with error handling, signal processing
     * and precise timing control. Runs until shutdown signal is received
     * and all servers are ready to shutdown (or timeout expires).
     *
     * What an iteration of the loop throws does not leave here: it is logged and turned
     * into a requested stop, so the node departs by the SIGTERM path rather than by an
     * uncaught throw. Only the startup above the loop still refuses outright - there is
     * no node yet to announce a departure for.
     *
     * @throws MissingRequiredParameterException When required process functions are unavailable
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    public function run(): void
    {
        // Initialize event loop
        $this->eventLoop = new EventLoop();

        // Check the availability of required functions
        $this->checkRequiredFunctions(self::PROCESS_FUNCTIONS);

        // Setup error handling and signal handlers
        $this->setupErrorHandling();
        $this->setupSignalHandlers();

        // Receive membership transitions (onNodeJoined/onNodeLeft) from the peer transport
        Hilos::$cluster?->registerMembershipObserver($this);

        // Receive leadership/quorum transitions from the consensus coordinator
        Hilos::$cluster?->registerLeadershipObserver($this);

        // Receive placement-degradation events from the placement failover coordinator
        Hilos::$cluster?->registerPlacementObserver($this);

        // Expose the worker server as the placement executor so the peer transport can
        // launch and stop placed agents on this node (registered before the loop so it is
        // present when the peer server builds its placement coordinator at start).
        $workerServer = $this->findWorkerServer();
        if ($workerServer instanceof PlacementExecutor) {
            Hilos::$cluster?->registerPlacementExecutor($workerServer);
        }
        // Expose the worker server as the delivery sink for signals forwarded from other
        // nodes, so the peer transport can hand a received cross-node signal to its agent.
        if ($workerServer instanceof AgentSignalSink) {
            Hilos::$cluster?->registerAgentSignalSink($workerServer);
        }
        // Expose the worker server as the relay the protected-mode executor uses to hand the
        // leader's ready to the worker hosting the initiator agent on this node.
        if ($workerServer instanceof ProtectedModeReadyRelay) {
            Hilos::$cluster?->registerProtectedModeReadyRelay($workerServer);
        }
        // Expose the worker server as the port the protected-mode executor uses to stop this
        // node's agents (leaving the initiator running) while the freeze holds.
        if ($workerServer instanceof ProtectedModeAgentFreezer) {
            Hilos::$cluster?->registerProtectedModeAgentFreezer($workerServer);
        }
        // Expose the agent manager as the place a node's answer to a re-hydrate announcement is
        // credited: the barrier is one, but its answers arrive on two transports (HIL-436).
        $this->findPeerServer()?->registerReHydrateBarrier($this->agentManagerDaemon);
        // Expose this daemon as the port the protected-mode executor tells the browser
        // connections through: the WebSocket server it broadcasts over is ours, not the
        // worker server's.
        Hilos::$cluster?->registerProtectedModeClientNotifier($this);
        // Expose this daemon as the port an RT replica from another node is applied through: the
        // copy a receiving node holds lives in the master, and the workers are fed from here.
        Hilos::$cluster?->registerRtSyncSink($this);
        // And as the port the inspect command reads that copy back through - the same state, the
        // other direction, and no link involved (HIL-589).
        Hilos::$cluster?->registerRtReplicaInspector($this);
        // And the same for a DB replica (HIL-670). Two ports rather than one because the two
        // facts answer to different rules on arrival, not because they arrive differently.
        Hilos::$cluster?->registerDbSyncSink($this);
        // Build this node's half of the cluster connection index and expose the daemon as the
        // port its own browser connections are reached through (HIL-668). Both are the daemon's
        // because the sockets are: the diff that keeps the index true reads the WebSocket server
        // registered here, and the delivery it enables ends at that same server. Only on a
        // clustered node - off-cluster there is nowhere for a connection to be but here, so
        // leaving both slots empty is what keeps the router's post-pass inert.
        if (Hilos::$cluster !== null && Hilos::$cluster->isEnabled()) {
            $clientConnections = new ClusterClientLocation();
            Hilos::$cluster->registerClientConnections($clientConnections);
            // The index doubles as the router's read-only connection lookup, so a signal to a
            // browser can ask which node holds it.
            Hilos::$cluster->registerClientLocation($clientConnections);
            Hilos::$cluster->registerClientSignalSink($this);
        }
        // Off-cluster there is no peer transport to build a freeze coordinator, and this is the one
        // start-up path both topologies run, so the single-node freeze is built here. The clustered
        // one is built by PeerServer::onStart(); the two are mutually exclusive by construction.
        if (Hilos::$cluster !== null && !Hilos::$cluster->isEnabled()) {
            Hilos::$cluster->registerProtectedMode(new StandaloneProtectedMode(new DaemonProtectedModeExecutor()));
        }

        Logger::info("Daemon started with epoll");

        // Main loop
        while ($this->shouldContinueRunning()) {
            $loopStartTime = microtime(true);

            $this->runGuardedIteration($loopStartTime);

            // Sleep for precise timing. Outside the guard on purpose: the iterations that
            // follow a failure are the shutdown ones, and a loop spinning without its pause
            // would burn the deadline it is being given to leave cleanly.
            $this->sleepWithPreciseTiming($loopStartTime);
        }

        // Cleanup
        $this->eventLoop->cleanup();
        Hilos::$ac?->shutdown();
        Logger::info("Daemon stopped");
    }

    /**
     * Runs one iteration of the main loop, and contains whatever it fails with.
     *
     * Its own method rather than a block inside {@see run()} because the iteration is
     * the unit of work the guard below is about, and a unit of work is something a test
     * can drive: the loop around it opens the event base, which asks the platform for
     * libevent and is a poor thing to require of a test about the order of two lines.
     *
     * @param float $loopStartTime Time this iteration started, as the loop read it
     */
    private function runGuardedIteration(float $loopStartTime): void
    {
        try {
            // If shutdown requested but not started yet, initiate shutdown
            if ($this->shouldExit && $this->shutdownStartTime === null) {
                $this->shutdownStartTime = microtime(true);
                $this->initiateShutdown();
            }

            // Process epoll events for all servers
            $this->processEventLoop();

            // Tick all servers (process clients)
            $this->tickServers();

            // Dispatch the per-iteration hook for the current node lifecycle phase
            $this->dispatchRoleTick();

            // Singleton duties run on exactly one node cluster-wide: the leader, or the
            // sole node when cluster mode is off. A follower starts no cluster-singleton
            // agents, runs no cron, and keeps its WebSocket closed until it is promoted.
            if ($this->amLeader()) {
                // Start this node's cluster-singleton agents once per leadership term
                $this->ensureSingletonsStarted();

                // Put the cluster-wide agents whose node the policy picks where the policy
                // picks them; unlike the line above this is a per-tick reconciliation, not
                // an ensure-once, because a placement can find no capable node yet.
                $this->ensurePolicyAgentsPlaced();

                // Open the WebSocket server once the required startup agents are ready
                $this->tickReadiness();

                // Check cron jobs (not more than once per minute)
                $this->checkCronJobs();

                // Notice a freeze that has stopped moving and tell a person about it. Under
                // the leader gate for the same reason cron is: one stuck freeze must not
                // produce one alert per node. It never lifts anything (HIL-482).
                $this->protectedModeWatchdog->tick(time());
            }

            // Tell the other nodes which browser connections this node has gained and lost
            // (HIL-668). Ahead of the dispatch on purpose: a signal resolved below is addressed
            // through the peers' copy of this index, so the closer that copy is to this tick,
            // the fewer answers go to a node that no longer holds the socket.
            $this->announceConnectionChanges($this->findPeerServer());

            // And offer what this node has just started owning to nodes already linked to it
            // (HIL-589).
            $this->offerRtSnapshotsOnOwnershipChange($this->findPeerServer());

            // Dispatch accumulated signals
            $this->dispatchSignals();

            // Report the re-hydrate barrier once everyone has answered, or the deadline passed
            $this->tickReHydrateRound();

            // Flush buffered analytics rows on schedule
            Hilos::$ac?->tick();

            // Close the windows the client-read limiter has been counting in, so a
            // stream of refusals that stopped still reports how much it held back.
            ClientReadFailureLog::flushClosedWindows($loopStartTime);

            // Process signals
            pcntl_signal_dispatch();
        } catch (Throwable $failure) {
            // A failure that got this far is the node's own: one iteration of the loop
            // did not finish, and nothing below this frame is left to make sense of it.
            // The node leaves the way SIGTERM makes it leave - the departure announced
            // to the cluster, every server given its prepareShutdown(), the exit held to
            // shutdownTimeout by shouldContinueRunning() - instead of exiting from under
            // the exception with the workers unaware and the mesh still counting this
            // node in. A node that fell is a failover the cluster has to notice by
            // timeout; a node that left is one it is told about.
            $this->logException(sprintf(
                'Failure in the daemon loop: %s in %s:%d - %s',
                get_class($failure),
                basename($failure->getFile()),
                $failure->getLine(),
                $failure->getMessage()
            ));

            // Before the exit flag, not after: this is the project's last chance to
            // say anything outwards while the node is still serving, and the leaving
            // itself is held to shutdownTimeout, so a frame handed over here still
            // makes it into the socket.
            $this->reportContainedFailure(new ContainedFailure(
                MasterFailureUnit::LOOP_ITERATION,
                self::LOOP_FAILURE_ADDRESS,
                $failure
            ));

            $this->shouldExit = true;
        }
    }

    /**
     * Tick every registered server, and stop the node when one of them reports that
     * the platform's secure random source refused (HIL-568).
     *
     * This is one of the manager's two ways into client code, and the rarer one: a
     * client is normally read from the epoll callback ({@see onClientRead()}), and
     * reaches a server tick only with bytes that arrived between the two. Both catch
     * the refusal, because a node that cannot mint secrets must not be left serving
     * by whichever path happened to read the handshake.
     */
    private function tickServers(): void
    {
        try {
            foreach ($this->servers as $server) {
                $server->onTick();
            }
        } catch (RandomException $exception) {
            $this->requestEntropyStop($exception);
        }
    }

    /**
     * Asks the node to stop because the platform's secure random source refused.
     *
     * A node without entropy cannot mint the secrets a handshake hands out, and from
     * the outside it is indistinguishable from a healthy one - so it leaves instead
     * of staying to serve. The stop is requested, not executed: shouldExit takes the
     * path SIGTERM already takes, so the next iteration announces the departure to the
     * cluster and lets every server close its clients ({@see initiateShutdown()}). An
     * exit() from under the exception would skip both.
     *
     * One line is logged per refusal, and no storm can follow it: the node is on its
     * way out by the time the next connection would ask.
     *
     * @param RandomException $exception Refusal to name in the log line
     */
    private function requestEntropyStop(RandomException $exception): void
    {
        Logger::error('Secure random source refused; stopping this node: ' . $exception->getMessage());
        $this->shouldExit = true;
    }

    /**
     * Check if daemon should continue running
     *
     * @return bool True if should continue running
     */
    private function shouldContinueRunning(): bool
    {
        // Continue if not requested to exit
        if (!$this->shouldExit) {
            return true;
        }

        // If shutdown not started yet, continue
        if ($this->shutdownStartTime === null) {
            return true;
        }

        // Check timeout
        $elapsed = microtime(true) - $this->shutdownStartTime;
        if ($elapsed >= $this->shutdownTimeout) {
            Logger::info("Shutdown timeout expired, forcing exit");
            return false;
        }

        // Continue running while any server is not yet ready to shut down
        return array_any($this->servers, fn(ServerInterface $server) => !$server->isReadyToShutdown());
    }

    /**
     * Initiate shutdown sequence
     *
     * Called when shouldExit becomes true.
     * Prepares all servers for shutdown.
     *
     * Every step of the departure stands on its own: it runs once, nothing re-enters this
     * method afterwards, and a step that refuses must not take the remaining ones with it.
     */
    private function initiateShutdown(): void
    {
        // Planned graceful-leave: fire the broad work-stop directive locally so the
        // project can persist and stop its in-flight work before the node departs the
        // cluster. The peer transport announces the departure with a NodeLeaving frame
        // in its own prepareShutdown(). Gated on cluster mode so a standalone daemon is
        // unchanged. Distinct from onLostLeadership: a leaving follower loses no
        // leadership yet must still halt business work. The hook is a project's own code
        // and it is invited to persist, so it is contained: a throw from it would leave
        // every server below without its prepareShutdown, and the cluster without the
        // announcement that PeerServer sends from there.
        if ($this->clusterEnabled()) {
            try {
                $this->onClusterWorkStop();
            } catch (Throwable $failure) {
                $this->logException(sprintf(
                    'Work-stop directive failed on the way out: %s - %s',
                    get_class($failure),
                    $failure->getMessage()
                ));
            }
        }

        // Tell all servers to prepare for shutdown. Each on its own, because the point of
        // the step is that every server gets to close its clients: letting the first one
        // that refuses end the loop would take that chance from the ones behind it, and
        // the failure of a server on its way out changes nothing about the departure.
        foreach ($this->servers as $server) {
            try {
                $server->prepareShutdown();
            } catch (Throwable $failure) {
                $this->logException(sprintf(
                    'Server %s failed to prepare for shutdown: %s - %s',
                    $server->getServerName(),
                    get_class($failure),
                    $failure->getMessage()
                ));
            }
        }
    }

    /**
     * Register server
     *
     * @param ServerInterface $server Server instance
     */
    public function registerServer(ServerInterface $server): void
    {
        $this->servers[] = $server;

        // Every server, whatever it is: a failure this one contains is a failure the
        // project is entitled to hear about, and handing the seam out by type would
        // leave a server outside the hierarchy silently unable to report one.
        $server->setContainedFailureSink($this);

        // The command channel needs the master to force-close a WebSocket connection for the
        // test-only drop command; wire this manager in as the dropper so the handler stays
        // decoupled from the concrete manager.
        if ($server instanceof CommandServer) {
            $server->setConnectionDropper($this);
            $server->setProtectedModeSnapshotSource($this);
        }

        // The same seam, for the same reason, one layer down: a handshake that trades a
        // rotation ticket has to drop the other connections of the session it just moved
        // (HIL-582), and the client that serves that handshake reaches them through here.
        if ($server instanceof WebSocketServer) {
            $server->setConnectionDropper($this);
            // And the seam that lets a verifier in: the pass is decided on the 101, but the
            // freeze row it is decided against is the daemon's to write.
            $server->setProtectedModeAdmissionRecorder($this);
        }

        // The same shape once more, for the server that starts agents: an agent coming up has
        // to be told which sockets are still on the wire (HIL-664), and only the master knows.
        if ($server instanceof WorkerServer) {
            $server->setLiveConnectionRoster($this);
        }
    }

    /**
     * Takes a failure a guard contained and hands it to the project.
     *
     * The card arrives from wherever the failure was caught - a server's tick, the read
     * callback, the accept handler, the loop itself - and this is the one place all of
     * them meet, so a project overrides one hook and not four.
     *
     * The hook runs under a guard of its own, because it is the project's code and can
     * fail like any other; unguarded, its failure would end the very iteration this
     * machinery exists to survive. That failure is written as the hook's own and the
     * hook is not called again with it: a hook that fails while answering a failure
     * would answer its way into a loop.
     *
     * @param ContainedFailure $failure Failure, the unit it belongs to and where it happened
     */
    public function reportContainedFailure(ContainedFailure $failure): void
    {
        try {
            $this->onContainedFailure($failure);
        } catch (Throwable $hookFailure) {
            $this->logException(sprintf(
                self::HOOK_FAILURE_FORMAT,
                get_class($hookFailure),
                basename($hookFailure->getFile()),
                $hookFailure->getLine(),
                $hookFailure->getMessage()
            ));
        }
    }

    /**
     * Force-closes the live WebSocket connection whose acceptKey matches, if any.
     *
     * Mirrors the disconnect cleanup {@see onClientRead()} performs on a dead socket:
     * unregister from the event loop before closing (so libevent drops its reference first),
     * then close - which runs {@see WebSocketClient::onClose()} reconcile - and remove the
     * client from its server.
     *
     * @param string $acceptKey Daemon-minted identifier of the connection to close
     * @return bool True when a matching live connection was found and closed, false otherwise
     * @throws SocketException When closing the matched connection's socket fails
     */
    public function dropWebSocketConnection(string $acceptKey): bool
    {
        foreach ($this->servers as $server) {
            if (!$server instanceof WebSocketServer) {
                continue;
            }

            foreach ($server->getClients() as $client) {
                if (!$client instanceof WebSocketClient || $client->acceptKey !== $acceptKey) {
                    continue;
                }

                $socket = $client->getSocket();
                if ($socket !== null) {
                    $this->eventLoop->unregister($socket);
                }
                $client->close();
                $server->removeClient($client);

                return true;
            }
        }

        return false;
    }

    /**
     * Names the accept keys of the WebSocket connections this node holds open.
     *
     * Implements {@see LiveConnectionRoster}. The walk is the one
     * {@see dropWebSocketConnection()} makes, stopping at the accept key instead of closing
     * the socket, and it stays on the master loop for the same reason: it reads memory the
     * daemon already has and touches nothing outside the process.
     *
     * A client that has not handshaked yet still carries the empty accept key it was
     * constructed with, and is left out - it is not a connection anyone could have a runtime
     * row for.
     *
     * @return list<string> Accept keys live at the moment of the call, empty when the node holds no socket
     */
    public function liveAcceptKeys(): array
    {
        $acceptKeys = [];
        foreach ($this->servers as $server) {
            if (!$server instanceof WebSocketServer) {
                continue;
            }

            foreach ($server->getClients() as $client) {
                if ($client instanceof WebSocketClient && $client->acceptKey !== '') {
                    $acceptKeys[] = $client->acceptKey;
                }
            }
        }

        return $acceptKeys;
    }

    /**
     * Sends a signal to one agent, wherever in the cluster it is running.
     *
     * Implements {@see MasterSignalSender}. Placement answers where the agent lives and the
     * branch that follows is the one {@see dispatchSignals()} takes for a routed signal: here
     * delivers over this node's worker link, a named node forwards over the peer channel, and
     * an unknown address delivers nowhere. The branch matters rather than being a formality -
     * delivering locally to an agent placed elsewhere would START a second copy of it here,
     * which for a singleton agent is the one outcome placement exists to prevent. That is also
     * why an unknown address is refused instead of falling back to local (HIL-670): the fallback
     * is indistinguishable from the case it is meant to serve.
     *
     * Nothing is raised out of here, whatever the delivery path decides: this runs on the
     * master loop, where an escaping exception ends run() and takes the node down. A refusal
     * is written instead, as an error normally and as info while the node is leaving - during
     * shutdown workers are gone by design and the line is not news, exactly as
     * {@see dispatchSignals()} treats the same case.
     *
     * @param string $agentType Agent type to address
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param string $signalName Signal name the receiving agent dispatches on
     * @param SignalDataInterface $data Signal payload
     */
    public function sendToAgent(string $agentType, ?string $agentIndex, string $signalName, SignalDataInterface $data): void
    {
        // The type is always named here, so the id is always built - the cast records that,
        // the same way {@see dispatchSignals()} takes the id as it comes for the same reason.
        $agentId = (string)$this->agentManagerDaemon->buildAgentId($agentType, $agentIndex);
        $agentLabel = "agent {$agentId}";
        if ($signalName === '') {
            $this->reportMasterSignalDropped($signalName, $agentLabel, 'empty signal name');

            return;
        }

        try {
            $signal = new SignalDTO(
                new SignalSource(SignalSource::DAEMON),
                new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName($signalName),
                new AgentSignalData($data),
                Hilos::$ac?->captureSignalMeta() ?? [],
            );

            $location = Hilos::$cluster?->workerPlacement()?->locate($agentType, $agentIndex);
            if ($location?->kind === AgentLocationKind::Unknown) {
                $this->reportMasterSignalDropped($signalName, $agentLabel, self::AGENT_NO_KNOWN_NODE_REASON);

                return;
            }

            $nodeId = $location?->nodeId;
            if ($nodeId !== null) {
                $this->forwardMasterSignalToNode($nodeId, $agentType, $agentIndex, $signal, $agentLabel);

                return;
            }

            $workerServer = $this->findWorkerServer();
            if ($workerServer === null) {
                $this->reportMasterSignalDropped($signalName, $agentLabel, 'no worker server');

                return;
            }

            $workerServer->sendSignalToAgent(
                $agentType,
                $agentIndex,
                new DaemonAgentMessageDTO(
                    agentId: $agentId,
                    signal: $signal,
                ),
            );
        } catch (Throwable $e) {
            $this->reportMasterSignalDropped($signalName, $agentLabel, get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Sends a signal to every worker of this node, including monopolistic ones.
     *
     * Implements {@see MasterSignalSender}. Writes one {@see DaemonWorkerSignalDTO} to each
     * worker link and returns; the receiving side is {@see WorkerManager::onDaemonSignal()}.
     * Agents living inside those workers are not handed the signal - {@see sendToAgent()} is
     * how an agent is addressed.
     *
     * Failures are swallowed and written for the same reason as in {@see sendToAgent()}.
     *
     * @param string $signalName Signal name the receiving workers dispatch on
     * @param SignalDataInterface $data Signal payload
     */
    public function sendToWorkers(string $signalName, SignalDataInterface $data): void
    {
        if ($signalName === '') {
            $this->reportMasterSignalDropped($signalName, self::MASTER_SIGNAL_WORKERS_LABEL, 'empty signal name');

            return;
        }

        try {
            $workerServer = $this->findWorkerServer();
            if ($workerServer === null) {
                $this->reportMasterSignalDropped($signalName, self::MASTER_SIGNAL_WORKERS_LABEL, 'no worker server');

                return;
            }

            $this->writeFrameToWorkers($workerServer, new DaemonWorkerSignalDTO($signalName, $data));
        } catch (Throwable $e) {
            $this->reportMasterSignalDropped(
                $signalName,
                self::MASTER_SIGNAL_WORKERS_LABEL,
                get_class($e) . ': ' . $e->getMessage(),
            );
        }
    }

    /**
     * Reports this node's own view of protected mode for the test-only inspector.
     *
     * Joins the two halves of the answer that only the master holds together: the runtime row
     * ({@see StateProtectedModeRuntime}, the freeze's own account of itself) and this node's
     * agent roster ({@see WorkerServer::getProtectedModeStoppedAgents()}, what the freeze
     * actually did here). A test asserting that the mode took hold needs both - the row can say
     * active on a node whose roster the freeze never reached.
     *
     * A project with no runtime context answers an explicit false flag rather than an error or
     * an empty reply, because "protected mode is not taken" and "this project has no protected
     * mode at all" are different verdicts and an assertion cannot tell them apart otherwise.
     *
     * Every read here is in-memory by construction - the row is runtime state and the roster is
     * a private array - which is what makes the call safe on the master's accept path.
     *
     * @return array<string, mixed> Snapshot keyed by {@see ProtectedModeCommandConstants} fields
     */
    public function protectedModeSnapshot(): array
    {
        $freeze = Hilos::$rt?->hilosProtectedModeRuntime;
        $stopped = $this->findWorkerServer()?->getProtectedModeStoppedAgents() ?? [];

        if ($freeze === null) {
            return [
                ProtectedModeCommandConstants::FIELD_RT_MOUNTED => false,
                ProtectedModeCommandConstants::FIELD_PHASE => StateProtectedModeRuntime::PHASE_INACTIVE,
                ProtectedModeCommandConstants::FIELD_OPERATION => null,
                ProtectedModeCommandConstants::FIELD_INITIATOR_AGENT_TYPE => null,
                ProtectedModeCommandConstants::FIELD_INITIATOR_AGENT_INDEX => null,
                ProtectedModeCommandConstants::FIELD_INITIATOR_NODE_ID => null,
                ProtectedModeCommandConstants::FIELD_STARTED_AT => null,
                ProtectedModeCommandConstants::FIELD_ACTIVATED_AT => null,
                ProtectedModeCommandConstants::FIELD_PROGRESS_AT => null,
                ProtectedModeCommandConstants::FIELD_STOPPED_AGENTS => $stopped,
                ProtectedModeCommandConstants::FIELD_AGENT_START_GATE_CLOSED => false,
                ProtectedModeCommandConstants::FIELD_PASS_COUNT => 0,
            ];
        }

        return [
            ProtectedModeCommandConstants::FIELD_RT_MOUNTED => true,
            ProtectedModeCommandConstants::FIELD_PHASE => $freeze->phase,
            ProtectedModeCommandConstants::FIELD_OPERATION => $freeze->operation,
            ProtectedModeCommandConstants::FIELD_INITIATOR_AGENT_TYPE => $freeze->initiatorAgentType,
            ProtectedModeCommandConstants::FIELD_INITIATOR_AGENT_INDEX => $freeze->initiatorAgentIndex,
            ProtectedModeCommandConstants::FIELD_INITIATOR_NODE_ID => $freeze->initiatorNodeId,
            ProtectedModeCommandConstants::FIELD_STARTED_AT => $freeze->startedAt,
            ProtectedModeCommandConstants::FIELD_ACTIVATED_AT => $freeze->activatedAt,
            ProtectedModeCommandConstants::FIELD_PROGRESS_AT => $freeze->progressAt,
            ProtectedModeCommandConstants::FIELD_STOPPED_AGENTS => $stopped,
            // The same verdict the agent-start gate reaches, and deliberately derived from the
            // phase the way the gate derives it: every non-inactive phase holds the gate shut,
            // including deactivating - except the verification window, which exists to bring
            // the agents back.
            ProtectedModeCommandConstants::FIELD_AGENT_START_GATE_CLOSED
                => $freeze->phase !== StateProtectedModeRuntime::PHASE_INACTIVE
                    && $freeze->phase !== StateProtectedModeRuntime::PHASE_VERIFYING,
            // The count, never a hash: a snapshot goes to CI output, and a hash in a log is a
            // hash in a log forever. How many passes are outstanding is all an assertion needs.
            ProtectedModeCommandConstants::FIELD_PASS_COUNT => count($freeze->passHashes),
        ];
    }

    /**
     * Records the connection holding this accept key as admitted for the verification in flight.
     *
     * The master half of the admission: {@see WebSocketClient} has already matched the presented
     * pass against the row, and this writes the verdict where the workers can read it. A process
     * holding no runtime row records nothing - there is no freeze there to be let into.
     *
     * A refused write is logged and swallowed rather than raised, because the caller is the
     * connection-accept path: an exception there tears down a handshake that was otherwise fine,
     * and the failure it would report has a safe reading already - the verifier stays on the
     * maintenance stub and can present the code again.
     *
     * @param string $acceptKey Daemon-minted identifier of the admitted connection
     */
    public function admitProtectedModeConnection(string $acceptKey): void
    {
        try {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->admitConnection($acceptKey);
        } catch (RtBaseException $exception) {
            Logger::error('Protected mode: failed to admit a verifier: ' . $exception->getMessage());
        }
    }

    /**
     * Tells every open browser connection on this node that protected mode turned on or off.
     *
     * Queues the state as a broadcast signal rather than writing to the sockets here: the
     * WS_ALL_CONNECTED type resolves to {@see AllClientsDestination} and the routing pass
     * of the same loop fans it out through {@see sendToAllClients()}, so the freeze frame
     * leaves the daemon by the one path every other broadcast uses. The excluded accept key
     * is the initiator's — it drives the operation and must keep seeing the real app.
     *
     * @param ProtectedModeStateSignalData $state State to announce, with the copy already resolved
     * @param ?string $excludeAcceptKey Accept key kept out of the broadcast, or null to tell everyone
     * @throws InvalidArgumentException When the protected-mode signal cannot be named
     */
    public function notifyProtectedModeState(ProtectedModeStateSignalData $state, ?string $excludeAcceptKey): void
    {
        Hilos::$sr?->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
            new SignalName(SignalTypeConstants::PROTECTED_MODE),
            new WebSocketSignalData(data: $state, excludeAcceptKey: $excludeAcceptKey),
        );
    }

    /**
     * Register HTTP router
     *
     * @param HttpRouter $router HTTP router instance
     */
    public function registerHttpRouter(HttpRouter $router): void
    {
        $this->httpRouter = $router;
    }

    /**
     * Start all registered servers (except WebSocket server)
     *
     * Attempts to start servers that are not running and registers them in event loop.
     * WebSocket server is started separately when workers are ready.
     *
     * @throws SocketException When a server socket cannot be created, bound or listened on
     * @throws HilosException Whatever the concrete server's start hook raises
     */
    protected function startServers(): void
    {
        foreach ($this->servers as $server) {
            // Skip WebSocket server - it will be started when workers are ready
            if ($server instanceof WebSocketServer) {
                continue;
            }

            if (!$server->isRunning()) {
                if ($server->start()) {
                    Logger::info($server->getServerName() . " started");

                    // Register server socket in event loop
                    $this->registerServerSocket($server);
                } else {
                    Logger::info("Failed to start " . $server->getServerName());
                }
            }
        }
    }

    /**
     * Start WebSocket server
     *
     * Starts WebSocket server when workers are ready.
     *
     * @throws SocketException When the WebSocket socket cannot be created, bound or listened on
     */
    private function startWebSocketServer(): void
    {
        foreach ($this->servers as $server) {
            if ($server instanceof WebSocketServer && !$server->isRunning()) {
                if ($server->start()) {
                    Logger::info($server->getServerName() . " started");

                    // Register server socket in event loop
                    $this->registerServerSocket($server);
                } else {
                    Logger::info("Failed to start " . $server->getServerName());
                }
            }
        }
    }

    /**
     * Agent ids whose onStart must finish before the WebSocket server opens.
     *
     * Default is empty: the WebSocket server opens as soon as the workers are ready. A project
     * overrides this to gate the socket on its critical startup agents, so no connection is
     * accepted before those agents have built their state.
     *
     * @return list<string> Agent ids to wait for; empty opens the socket as soon as workers are ready
     */
    protected function getRequiredReadinessAgents(): array
    {
        return [];
    }

    /**
     * Opens the WebSocket server once the required startup agents are ready.
     *
     * Runs every main-loop iteration after the workers are ready. Opens the socket when every
     * required agent has reported agent_started; while they are pending it logs at most once per
     * READINESS_LOG_INTERVAL, and opens the socket degraded once readinessTimeout elapses.
     *
     * @throws SocketException When opening the WebSocket server fails; a daemon without its only
     *     client entry point must not keep running
     */
    private function tickReadiness(): void
    {
        if (!$this->workersReady || $this->webSocketStarted) {
            return;
        }

        $pending = $this->pendingReadinessAgents();

        if ($pending === []) {
            $this->startWebSocketServer();
            $this->webSocketStarted = true;
            return;
        }

        $waited = microtime(true) - ($this->readinessWaitSince ?? microtime(true));

        if ($this->readinessTimeout !== null && $waited >= $this->readinessTimeout) {
            Logger::error(sprintf(
                'WebSocket readiness timed out after %.0fs; opening degraded, agents still not started: %s',
                $waited,
                implode(', ', $pending),
            ));
            $this->startWebSocketServer();
            $this->webSocketStarted = true;
            return;
        }

        $this->logReadinessStuck($pending, $waited);
    }

    /**
     * @return list<string> Required startup agent ids that have not reported agent_started yet
     */
    private function pendingReadinessAgents(): array
    {
        $pending = [];
        foreach ($this->getRequiredReadinessAgents() as $agentId) {
            if (!$this->agentManagerDaemon->isAgentStarted($agentId)) {
                $pending[] = $agentId;
            }
        }

        return $pending;
    }

    /**
     * Logs a stuck startup at most once per READINESS_LOG_INTERVAL.
     *
     * @param list<string> $pending Required agent ids still not started
     * @param float $waited Seconds elapsed since the readiness wait began
     */
    private function logReadinessStuck(array $pending, float $waited): void
    {
        $lastLog = $this->lastReadinessLogAt ?? $this->readinessWaitSince ?? microtime(true);
        if (microtime(true) - $lastLog < self::READINESS_LOG_INTERVAL) {
            return;
        }

        $this->lastReadinessLogAt = microtime(true);
        Logger::error(sprintf(
            'WebSocket not opened: waited %.0fs for startup agents to report ready: %s',
            $waited,
            implode(', ', $pending),
        ));
    }

    /**
     * Register server socket in event loop
     *
     * @param ServerInterface $server Server instance
     */
    protected function registerServerSocket(ServerInterface $server): void
    {
        $socket = $server->getSocket();
        if ($socket === null) {
            return;
        }

        // Register server socket for accept events
        $this->eventLoop->registerRead($socket, function($socket, $flags) use ($server) {
            $this->onServerAccept($server, $socket);
        });
    }

    /**
     * Handle server accept event
     *
     * @param ServerInterface $server Server instance
     * @param resource $socket Server socket
     */
    protected function onServerAccept(ServerInterface $server, $socket): void
    {
        try {
            $client = $server->acceptConnection();
            if ($client === null) {
                return;
            }

            // If it's HttpClient and we have a router, set it
            if ($this->httpRouter !== null && method_exists($client, 'setRouter')) {
                $client->setRouter($this->httpRouter);
            }

            // Register client socket in event loop
            $this->registerClientSocket($server, $client);
        } catch (Throwable $e) {
            // Log exception in server accept handler
            $this->logException(
                sprintf("Error in server accept handler for %s: %s in %s:%d - %s",
                    $server->getServerName(),
                    get_class($e),
                    basename($e->getFile()),
                    $e->getLine(),
                    $e->getMessage()
                )
            );

            $this->reportContainedFailure(new ContainedFailure(
                MasterFailureUnit::CONNECTION_ACCEPT,
                $server->getServerName(),
                $e
            ));
            // Don't rethrow - continue processing other connections
        }
    }

    /**
     * Register client socket in event loop
     *
     * @param ServerInterface $server Server instance
     * @param ClientInterface $client Client instance
     */
    protected function registerClientSocket(ServerInterface $server, ClientInterface $client): void
    {
        $socket = $client->getSocket();
        if ($socket === null) {
            return;
        }

        // Register client socket for read events
        // NOTE: Don't use $socket parameter from callback - it may be different (file descriptor)
        $this->eventLoop->registerRead($socket, function($eventSocket, $flags) use ($server, $client) {
            $this->onClientRead($server, $client);
        });
    }

    /**
     * Handle client read event
     *
     * @param ServerInterface $server Server instance
     * @param ClientInterface $client Client instance
     */
    protected function onClientRead(ServerInterface $server, ClientInterface $client): void
    {
        try {
            $client->read();

            // Write any pending data from write buffer
            $client->write();

            // Check if client should be closed
            if ($client->shouldClose()) {
                // CRITICAL: Unregister from event loop BEFORE closing socket
                // Event loop must not reference the socket after it's closed
                $socket = $client->getSocket();
                if ($socket !== null) {
                    $this->eventLoop->unregister($socket);
                }

                // Now safe to close the socket
                $client->close();
                $server->removeClient($client);
            }
        } catch (RandomException $exception) {
            // The connection asked for a secret and the secure source refused, so this
            // is the node's business and not the connection's: the client is discarded
            // like any other failed read, and the node stops (see requestEntropyStop()).
            // Caught ahead of the catch-all below, which would log the refusal and leave
            // the node serving handshakes it cannot mint secrets for.
            $this->requestEntropyStop($exception);
            $this->discardClient($server, $client);
        } catch (Throwable $e) {
            // Log exception and close client connection on error. The line goes through
            // the shared writer rather than logException(), which would make an error of
            // every failure: the same refusal reaching the other reader of this client
            // (AbstractServer::onTick()) has always been told apart by what it is, and
            // which of the two paths a connection is read by says nothing about that.
            ClientReadFailureLog::write(
                $server->getServerName(),
                ClientReadFailureLog::READER_EVENT_LOOP,
                $e,
                microtime(true)
            );

            // The other reader of the same connection reports the same card, so a project
            // counting connection failures does not have to know which path caught one.
            $this->reportContainedFailure(new ContainedFailure(
                MasterFailureUnit::CONNECTION,
                ClientReadFailureLog::connectionAddress($server->getServerName(), $client),
                $e
            ));

            $this->discardClient($server, $client);
        }
    }

    /**
     * Drops a client the manager is done with: out of the event loop first, because
     * it must not reference the socket after the close, then closed and forgotten by
     * its server. Errors on the way out are ignored - the socket may be gone already,
     * and this runs on a path that is already handling a failure.
     *
     * @param ServerInterface $server Server the client belongs to
     * @param ClientInterface $client Client to discard
     */
    private function discardClient(ServerInterface $server, ClientInterface $client): void
    {
        try {
            $socket = $client->getSocket();
            if ($socket !== null) {
                $this->eventLoop->unregister($socket);
            }
            $client->close();
            $server->removeClient($client);
        } catch (Throwable $cleanupError) {
            // Ignore errors during cleanup - socket may be already closed
        }
    }

    /**
     * Process event loop for all registered servers
     *
     * Handles epoll events for all registered servers.
     * Automatically manages server lifecycle and client connections.
     *
     * @throws SocketException When a server socket cannot be created, bound or listened on
     * @throws HilosException Whatever the concrete server's start hook raises
     */
    protected function processEventLoop(): void
    {
        // Start servers if not running (registers them in event loop)
        $this->startServers();

        // Process all ready events (non-blocking)
        $this->eventLoop->tick();
    }

    /**
     * Dispatch accumulated signals
     *
     * Processes all queued signals from SignalRouter and sends them to appropriate agents via WorkerServer.
     * Signals are processed one by one in while-do loop.
     * Called at the end of each loop iteration.
     *
     * @throws AgentException When routing a signal to its agent fails (no suitable
     *     worker, daemon creation, agent lookup, or worker-link failure)
     * @throws InvalidArgumentException When the unsubscribe of a replaced subscription cannot be named
     * @throws EnvException When resolving a destination reads cluster configuration and it is invalid
     * @throws HilosException Whatever the project's agent-daemon factory raises reaching an agent
     */
    private function dispatchSignals(): void
    {
        $workerServer = $this->findWorkerServer();
        if ($workerServer === null) {
            // No WorkerServer available
            return;
        }

        // Find WebSocket server once (for WebSocket destinations)
        $webSocketServer = $this->findWebSocketServer();

        // Find Command server once (for command reply destinations)
        $commandServer = null;
        foreach ($this->servers as $server) {
            if ($server instanceof CommandServer) {
                $commandServer = $server;
                break;
            }
        }

        // Find Peer server once (for cross-node agent destinations)
        $peerServer = null;
        foreach ($this->servers as $server) {
            if ($server instanceof PeerServer) {
                $peerServer = $server;
                break;
            }
        }

        // Sync signals: always send to workers and daemon
        $syncTypes = [
            SignalTypeConstants::DB_SYNC_CREATED,
            SignalTypeConstants::DB_SYNC_UPDATED,
            SignalTypeConstants::DB_SYNC_DELETED,
            SignalTypeConstants::DB_SYNC_CLEARED,
            SignalTypeConstants::DB_REHYDRATE,
            SignalTypeConstants::RT_SYNC_CREATED,
            SignalTypeConstants::RT_SYNC_UPDATED,
            SignalTypeConstants::RT_SYNC_DELETED,
        ];

        // Signals held for an identity that has since arrived (or run out of time) rejoin the
        // ordinary path here, ahead of the queue, so a subscription that waited one tick is
        // still served before whatever the browser sent after it (HIL-627). A released signal
        // has already served its wait, so it is NOT offered to the park again - re-parking it
        // would hand it a fresh deadline every pass and hold it forever behind an answer that
        // is never coming, which is the one outcome the deadline exists to prevent.
        $released = $this->releaseParkedSignals();

        // Process signals one by one in while-do loop
        while (true) {
            $wasParked = $released !== [];
            $signal = $wasParked ? array_shift($released) : Hilos::$sr->getNextQueuedSignal();
            if ($signal === null) {
                break;
            }

            if (!$wasParked && $this->parkUntilIdentified($signal)) {
                continue;
            }

            if ($this->refusePageInstanceMove($signal)) {
                continue;
            }

            // Update subscriptions BEFORE routing (routing may depend on current subscriptions)
            $this->updateSubscriptions($signal);

            if (in_array($signal->signalType->getType(), $syncTypes, true)) {
                // The barrier opens BEFORE the announcement goes out, so that the roster is the
                // set of processes actually told to re-read and no early answer arrives at a
                // round that does not exist yet (HIL-436). Answers are read from sockets in a
                // later phase of the loop, so nothing can slip in between the two calls below.
                if ($signal->signalType->getType() === SignalTypeConstants::DB_REHYDRATE) {
                    $this->openReHydrateRound($workerServer, $peerServer, $signal);
                }
                $this->sendSyncToWorkers($workerServer, $signal);
                $this->handleDaemonSignal($signal);
                $this->broadcastRtSyncToPeers($peerServer, $signal);
                $this->broadcastDbSyncToPeers($peerServer, $signal);
            }

            // The access re-decision announcement is fanned out and nothing more (HIL-644): the
            // master resolves nobody, so it neither applies the fact to itself nor tells its
            // peers - a tab on another node is not reached by the other half of the operation
            // either. It sits beside the sync branch rather than inside it because that branch
            // also self-applies and announces to the mesh, and both would be wrong here.
            if ($signal->signalType->getType() === SignalTypeConstants::PAGE_ACCESS_REASSESS_USER) {
                if ($signal->data instanceof PageAccessReassessUserSignalData) {
                    $this->writeFrameToWorkers(
                        $workerServer,
                        new WorkerPageAccessReassessMessageDTO($signal->data->userId),
                    );
                } else {
                    Logger::error(
                        'dispatchSignals - access re-decision carries invalid data: ' . get_class($signal->data),
                    );
                }
            }

            // Its by-connection twin is fanned out on exactly the same terms (HIL-652). Order is
            // what makes it correct: the runtime write that un-points the connections was queued
            // ahead of it and is drained ahead of it, so every worker already holds "this
            // connection belongs to nobody" by the time it is asked to re-judge.
            if ($signal->signalType->getType() === SignalTypeConstants::PAGE_ACCESS_REASSESS_CONNECTIONS) {
                if ($signal->data instanceof PageAccessReassessConnectionsSignalData) {
                    $this->writeFrameToWorkers(
                        $workerServer,
                        new WorkerPageAccessReassessConnectionsMessageDTO($signal->data->acceptKeys),
                    );
                } else {
                    Logger::error(
                        'dispatchSignals - by-connection re-decision carries invalid data: ' . get_class($signal->data),
                    );
                }
            }

            // Get destinations for signal
            $destinations = Hilos::$sr->getDestinations($signal);
            $signalName = $signal->signalName->getName();
            $signalType = $signal->signalType->getType();

            // Handle workers ready signal internally (before routing to agents)
            if ($signalType === SignalTypeConstants::SYSTEM && $signalName === SignalConstants::WORKERS_READY) {
                $this->workersReady = true;
                $this->readinessWaitSince = microtime(true);
                Logger::info("Workers ready - cron jobs are now enabled");

                // The WebSocket server no longer opens here: tickReadiness() opens it once the
                // required startup agents have finished onStart (or the readiness timeout fires).
            }

            if (empty($destinations) && Hilos::$sr->expectsDestination($signal)) {
                // A signal whose route is declared, not subscribed to, was supposed to reach
                // somebody: an empty list means it is being dropped, and the only way anyone
                // ever notices is this line. The router decides which types those are.
                $source = $this->describeSignalSource($signal->signalSource);
                Logger::error(
                    "Signal {$signalName} (type {$signalType}) from {$source} has no destination"
                    . " - no route is declared for this signal name",
                );
            }

            // Deliver signal to each destination
            $skipSignal = false;
            $agentsDelivered = [];
            while (($destination = array_shift($destinations)) !== null) {
                if ($skipSignal) {
                    break;
                }

                if ($destination instanceof AgentDestination) {
                    $agentsDelivered[] = $destination;
                    $skipSignal = $this->sendSignalToAgentDestination($workerServer, $destination, $signal);
                } elseif ($destination instanceof UnknownAgentDestination) {
                    // Nobody on this node knows where that agent runs, so there is no delivery to
                    // attempt - not even the local one, which is what used to happen here and is
                    // the defect (HIL-670): it succeeds against workers running no such agent.
                    // A subscription waiting on the answer is told, exactly as an unreachable
                    // node's would be; anything else stays dropped with this line.
                    Logger::warning("Signal dropped: {$signalType}/{$signalName}"
                        . " -> agent {$destination->agentType} has no known node");
                    $this->answerUnreachableSubscription($signal);
                } elseif ($destination instanceof RemoteAgentDestination) {
                    // Forward the signal to the agent on its host node over the peer channel.
                    // Best-effort: no live link (offline node / no peer server) drops and logs,
                    // matching the local path that skips when no worker hosts the agent. Durable
                    // delivery to an offline node is out of scope.
                    if ($peerServer === null) {
                        Logger::error("Peer signal dropped: {$signalType}/{$signalName}"
                            . " -> node {$destination->nodeId} agent {$destination->agentType} - no peer server");
                        $this->answerUnreachableSubscription($signal);
                        continue;
                    }

                    $delivered = $peerServer->sendSignalToNode(
                        $destination->nodeId,
                        $destination->agentType,
                        $destination->agentIndex,
                        $signal,
                    );
                    if (!$delivered) {
                        Logger::warning("Peer signal dropped: {$signalType}/{$signalName}"
                            . " -> node {$destination->nodeId} agent {$destination->agentType} - no live link");
                        $this->answerUnreachableSubscription($signal);
                    }
                } elseif ($destination instanceof RemoteClientDestination) {
                    // Forward the signal to the browser on the node holding its socket. Same
                    // best-effort contract as the agent forward above: no live link drops and
                    // logs, matching the local path that writes to nobody when the socket is
                    // already gone.
                    if ($peerServer === null) {
                        Logger::error("Peer client signal dropped: {$signalType}/{$signalName}"
                            . " -> node {$destination->nodeId} client {$destination->acceptKey} - no peer server");
                        continue;
                    }

                    $delivered = $peerServer->sendSignalToClientNode(
                        $destination->nodeId,
                        $destination->acceptKey,
                        $signal,
                    );
                    if (!$delivered) {
                        Logger::warning("Peer client signal dropped: {$signalType}/{$signalName}"
                            . " -> node {$destination->nodeId} client {$destination->acceptKey} - no live link");
                    }
                } elseif ($destination instanceof RemoteFanoutDestination) {
                    // Carry the fan-out to the rest of the cluster. This marker travels
                    // ALONGSIDE the local destinations, not instead of them: the browsers of
                    // this node are served by those, and the other nodes are served by this one
                    // frame, which each of them expands against its own subscription registry.
                    if ($peerServer === null) {
                        Logger::error("Peer client fanout dropped: {$signalType}/{$signalName} - no peer server");
                        continue;
                    }

                    $peerServer->broadcastClientFanout($signal);
                } elseif ($destination instanceof WebSocketDestination) {
                    // Send signal to WebSocket client
                    if ($webSocketServer === null) {
                        continue;
                    }

                    $acceptKey = $destination->acceptKey;
                    if ($acceptKey === '') {
                        Logger::error("Accept key is missing in WebSocket destination");
                        continue;
                    }

                    $this->sendSignalToWebSocketClient($webSocketServer, $signal, $acceptKey);
                } elseif ($destination instanceof AllClientsDestination) {
                    // Broadcast signal to every connected WebSocket client
                    if ($webSocketServer === null) {
                        continue;
                    }

                    $broadcastFrame = $this->encodeSignalFrame($signal);
                    if ($broadcastFrame === null) {
                        continue;
                    }

                    $this->sendToAllClients(
                        $webSocketServer,
                        $broadcastFrame,
                        $destination->excludeAcceptKey,
                    );
                } elseif ($destination instanceof SessionClientsDestination) {
                    // Deliver to every connection of one browser session held by this node
                    if ($webSocketServer === null) {
                        continue;
                    }

                    $sessionFrame = $this->encodeSignalFrame($signal);
                    if ($sessionFrame === null) {
                        continue;
                    }

                    $this->sendToSessionClients(
                        $webSocketServer,
                        $sessionFrame,
                        $destination->sessionTokenHash,
                    );
                } elseif ($destination instanceof CommandReplyDestination) {
                    // Write the agent reply back to the held CLI command connection
                    if ($commandServer === null) {
                        continue;
                    }

                    $reply = $signal->data;
                    if ($reply instanceof CommandReplyDTO) {
                        $commandServer->deliver($destination->correlationId, $reply);
                    }
                } else {
                    // Unknown destination type, skip
                    Logger::error("Unknown destination type: " . get_class($destination) . " for signal: {$signalType}/{$signalName}");
                }
            }

            $this->fanOutConnectionCloseToPageAgents($workerServer, $signal, $agentsDelivered);
            $this->forgetSubscriptionsAfterRouting($signal);
        }
    }

    /**
     * Renders the sender of a lost signal for the log line.
     *
     * The source alone answers "which kind of process", which is rarely enough to find the
     * sender again; the type and index the sender may have set are what name the instance.
     * They are optional, so they are appended only when present.
     *
     * @param SignalSourceInterface $source Source the lost signal was queued from
     * @return string Source, narrowed by its type and index when the sender set them
     */
    private function describeSignalSource(SignalSourceInterface $source): string
    {
        $description = $source->getSource();

        $sourceType = $source->getType();
        if ($sourceType !== null && $sourceType !== '') {
            $description .= "/{$sourceType}";
        }

        $sourceIndex = $source->getIndex();
        if ($sourceIndex !== null && $sourceIndex !== '') {
            $description .= "#{$sourceIndex}";
        }

        return $description;
    }

    /**
     * Send sync signal to all worker clients.
     *
     * @param WorkerServer $workerServer Worker server instance
     * @param SignalDTO $signal Signal DTO to send
     */
    private function sendSyncToWorkers(
        WorkerServer $workerServer,
        SignalDTO $signal,
        ?string $originNodeId = null,
    ): void {
        $signalName = $signal->signalName->getName();

        $dto = match ($signalName) {
            SignalConstants::DB_SYNC_CREATED => new WorkerDbSyncCreatedMessageDTO(
                self::syncSignalData($signal->data, DbSyncCreatedSignalData::class),
                $originNodeId,
            ),
            SignalConstants::DB_SYNC_UPDATED => new WorkerDbSyncUpdatedMessageDTO(
                self::syncSignalData($signal->data, DbSyncUpdatedSignalData::class),
                $originNodeId,
            ),
            SignalConstants::DB_SYNC_DELETED => new WorkerDbSyncDeletedMessageDTO(
                self::syncSignalData($signal->data, DbSyncDeletedSignalData::class),
                $originNodeId,
            ),
            SignalConstants::DB_SYNC_CLEARED => new WorkerDbSyncClearedMessageDTO(
                self::syncSignalData($signal->data, DbSyncClearedSignalData::class),
                $originNodeId,
            ),
            // The one sync-family frame with no collection to unwrap: the database was replaced
            // (HIL-479), and all the signal names is the agent waiting to hear that everybody
            // re-read it (HIL-436).
            SignalConstants::DB_REHYDRATE => new WorkerDbReHydrateMessageDTO(
                self::syncSignalData($signal->data, DbReHydrateSignalData::class)->agentId,
            ),
            SignalConstants::RT_SYNC_CREATED => new WorkerRtSyncCreatedMessageDTO(
                self::syncSignalData($signal->data, RtSyncCreatedSignalData::class),
            ),
            SignalConstants::RT_SYNC_UPDATED => new WorkerRtSyncUpdatedMessageDTO(
                self::syncSignalData($signal->data, RtSyncUpdatedSignalData::class),
            ),
            SignalConstants::RT_SYNC_DELETED => new WorkerRtSyncDeletedMessageDTO(
                self::syncSignalData($signal->data, RtSyncDeletedSignalData::class),
            ),
            default => null,
        };

        if ($dto === null) {
            return;
        }

        $this->writeFrameToWorkers($workerServer, $dto);
    }

    /**
     * Writes one already-built frame to every worker of this node.
     *
     * The frame is packed once per broadcast rather than once per link, because it is the same
     * string for every worker: the DTO is built by the caller before the loop and knows nothing
     * about which worker receives it, and {@see WorkerClient::send()} only appends the string to
     * an output buffer. This method sits on the master loop, which every sync signal passes
     * through, so a second json_encode of an identical frame is work the master pays for nothing.
     * It is the same shape the WebSocket side already uses here: {@see encodeSignalFrame()} packs
     * once and {@see sendToAllClients()} / {@see sendToSessionClients()} carry the ready string.
     *
     * Packing is lazy instead of unconditional before the loop so that a node with no registered
     * worker link yet - daemon startup - does not pay for a frame nobody will receive.
     *
     * @param WorkerServer $workerServer Worker server instance
     * @param WorkerDTO $dto Frame to write to each worker link
     */
    private function writeFrameToWorkers(WorkerServer $workerServer, WorkerDTO $dto): void
    {
        $frame = null;

        foreach ($workerServer->getClients() as $client) {
            if ($client instanceof WorkerClient) {
                $frame ??= $dto->toJson();
                $client->send($frame);
            }
        }
    }

    /**
     * Announces a local RT sync fact to the rest of the mesh, and nothing else.
     *
     * Sits in the sync branch of {@see dispatchSignals()}, which carries the DB sync family as
     * well, so the RT filter is the point of the method rather than a formality: the nodes share
     * one database, and re-announcing a DB fact over the mesh would be a second copy of a write
     * they already have. Off-cluster there is no peer server and nothing is announced.
     *
     * What this node has no business announcing is filtered out by {@see announcesRtCollection()},
     * and a fact whose payload does not say which collection it belongs to is not announced at
     * all: the wire carries facts a receiving node applies by their owner's word, so an
     * unattributable one has no owner to speak for it.
     *
     * The frame says how completely this node owns what it wrote, and a claim by keys counts as
     * partial there for the same reason a claim short of an operation does: the rest of the
     * collection belongs to somebody, and the receiving node must not read this fact as a claim
     * over it.
     *
     * Protected rather than private so that what this node tells the mesh can be observed from a
     * subclass; the port it announces through is an interface for the same reason.
     *
     * @param ?RtSyncMesh $mesh Peer server found for this dispatch pass, or null off-cluster
     * @param SignalDTO $signal Signal being dispatched
     */
    protected function broadcastRtSyncToPeers(?RtSyncMesh $mesh, SignalDTO $signal): void
    {
        if ($mesh === null) {
            return;
        }

        $signalType = $signal->signalType->getType();
        if (!in_array($signalType, PeerRtSyncDTO::SIGNAL_TYPES, true)) {
            return;
        }

        $syncData = $signal->data instanceof SyncSignalDataInterface ? $signal->data : null;
        if ($syncData === null || !$this->announcesRtCollection($syncData->collectionKey)) {
            return;
        }

        $map = $this->agentManagerDaemon->rtNodeSourceMap();
        $mesh->broadcastRtSync(
            $signalType,
            $signal,
            $map->owns($syncData->collectionKey) && !$map->ownsFully($syncData->collectionKey),
        );
    }

    /**
     * Announces a local DB sync fact to the rest of the mesh, and nothing else.
     *
     * Sits in the same branch as {@see broadcastRtSyncToPeers()} and filters the other half of
     * it. There is no ownership question to ask here, which is the whole difference between the
     * two: an RT collection has a truth source and this node may have no business speaking for
     * it, whereas a DB row was just written to the database every node reads - the fact is not
     * this node's opinion, it is what the disk now says.
     *
     * The re-hydrate fact is not among the four and does not travel: replacing the database is a
     * restore, which has its own peer protocol and its own barrier. Off-cluster there is no peer
     * server and nothing is announced.
     *
     * Protected for the reason {@see broadcastRtSyncToPeers()} is: it is how a subclass sees
     * what this node tells the mesh.
     *
     * @param ?DbSyncMesh $mesh Peer server found for this dispatch pass, or null off-cluster
     * @param SignalDTO $signal Signal being dispatched
     */
    protected function broadcastDbSyncToPeers(?DbSyncMesh $mesh, SignalDTO $signal): void
    {
        if ($mesh === null) {
            return;
        }

        $signalType = $signal->signalType->getType();
        if (!in_array($signalType, PeerDbSyncDTO::SIGNAL_TYPES, true)) {
            return;
        }

        $mesh->broadcastDbSync($signalType, $signal);
    }

    /**
     * Hands what this node owns of every RT collection to one other node of the mesh.
     *
     * Only the owner offers anything, and only to the node it just linked to: everybody else has
     * been kept current by the deltas. What this node merely holds a copy of is not offered at
     * all — passing on somebody else's collection would make this node a second source of it,
     * which is the very thing the map is here to prevent.
     *
     * What "the owner" covers is answered on both axes of the right. The whole owner offers the
     * collection with no scope, and the frame is the collection. An owner of named rows offers
     * those rows under their own scope: the collection around them is other nodes' to write, and
     * claiming it would delete their rows on the receiver. An owner short of an OPERATION offers
     * nothing at all, because even about the rows it writes, its copy may be missing what the
     * co-owner wrote (that case belongs to HIL-696).
     *
     * Without the scoped half of this, a fleet of one-row owners never converged: nothing was
     * ever handed over, delivery is best-effort with no retries (HIL-183), and so everything
     * written during a broken link was lost for good.
     *
     * Protected for the reason {@see broadcastRtSyncToPeers()} is: it is how a subclass sees
     * what this node hands over.
     *
     * @param ?RtSyncMesh $mesh Peer server of this node, or null off-cluster
     * @param string $nodeId Node the collections go to
     */
    protected function sendRtSnapshotsToNode(?RtSyncMesh $mesh, string $nodeId): void
    {
        if ($mesh === null) {
            return;
        }

        $map = $this->agentManagerDaemon->rtNodeSourceMap();
        foreach ($map->fullyOwnedCollections() as $collectionKey) {
            if (!self::isHandedOverInSnapshot($collectionKey)) {
                continue;
            }

            $mesh->sendRtSnapshotToNode($nodeId, $collectionKey, RtSnapshot::rows($collectionKey));
        }

        foreach ($map->keyScopedCollections() as $collectionKey => $scopeKeys) {
            if (!self::isHandedOverInSnapshot($collectionKey)) {
                continue;
            }

            $mesh->sendRtSnapshotToNode(
                $nodeId,
                $collectionKey,
                array_intersect_key(RtSnapshot::rows($collectionKey), array_flip($scopeKeys)),
                $scopeKeys,
            );
        }
    }

    /**
     * Handle daemon-internal signal (e.g. start/stop agents, DB/RT sync)
     *
     * Default dispatches DB/RT sync and the DB re-hydrate signal to their apply methods.
     *
     * @param SignalDTO $signal Signal DTO
     * @param ?string $originNodeId Node the write happened on, or null when it was this one
     * @throws HilosException When a collection refuses to be re-read from the replaced database
     */
    protected function handleDaemonSignal(SignalDTO $signal, ?string $originNodeId = null): void
    {
        $signalType = $signal->signalType->getType();
        match ($signalType) {
            SignalTypeConstants::DB_SYNC_CREATED => DbSyncApplicator::applyCreated(
                self::syncSignalData($signal->data, DbSyncCreatedSignalData::class),
                originNodeId: $originNodeId,
            ),
            SignalTypeConstants::DB_SYNC_UPDATED => DbSyncApplicator::applyUpdated(
                self::syncSignalData($signal->data, DbSyncUpdatedSignalData::class),
                originNodeId: $originNodeId,
            ),
            SignalTypeConstants::DB_SYNC_DELETED => DbSyncApplicator::applyDeleted(
                self::syncSignalData($signal->data, DbSyncDeletedSignalData::class),
                originNodeId: $originNodeId,
            ),
            SignalTypeConstants::DB_SYNC_CLEARED => DbSyncApplicator::applyCleared(
                self::syncSignalData($signal->data, DbSyncClearedSignalData::class),
                originNodeId: $originNodeId,
            ),
            SignalTypeConstants::DB_REHYDRATE => $this->applyReHydrateContained(),
            SignalTypeConstants::RT_SYNC_CREATED => RtSyncApplicator::applyCreated(
                self::syncSignalData($signal->data, RtSyncCreatedSignalData::class),
            ),
            SignalTypeConstants::RT_SYNC_UPDATED => RtSyncApplicator::applyUpdated(
                self::syncSignalData($signal->data, RtSyncUpdatedSignalData::class),
            ),
            SignalTypeConstants::RT_SYNC_DELETED => RtSyncApplicator::applyDeleted(
                self::syncSignalData($signal->data, RtSyncDeletedSignalData::class),
            ),
            default => null,
        };
    }

    /**
     * Applies one RT sync fact written on another node and fans it out to this node's workers.
     *
     * Implements {@see RtSyncSink}. What arrives is the very signal the owning node's worker
     * produced, so it takes the same two steps a locally-written fact does — the workers of this
     * node, then the copy this master holds — and stops there. Nothing is announced back to the
     * mesh: the owner already told everyone, and a second announcement is the echo that this
     * transport is built to make impossible rather than to filter.
     *
     * A frame whose inner signal is not the RT sync type it declares is dropped: the seam is
     * reachable from the wire, and applying an arbitrary sync signal by another node's word is
     * the one thing it must not do. The name is checked beside the type because the two steps
     * below read different fields — {@see sendSyncToWorkers()} builds its frame from the signal
     * NAME — so a signal named after another sync fact would reach the workers as that fact
     * however well its type checked out. A payload that is no sync payload at all goes the same
     * way, and it has to: it names no collection, so the ownership question below cannot even be
     * asked of it, and the apply step would be left converting it blind.
     *
     * A replica for a collection an agent of this node owns FULLY is dropped too, and that line
     * in the log is the machine-readable form of the defect this whole ticket is about: one collection
     * with a truth source on two nodes. Applying it would let the two owners overwrite each other
     * for as long as both keep running, and there is no arbitration in the model to decide between
     * them - so the node keeps what it wrote itself and says whose write it refused. The one
     * collection every master co-writes is exempt for the reason given on
     * {@see isMasterCoWritten()}: there the second writer is not a split, it is the design.
     *
     * Two things narrow that refusal since HIL-688, and both say the same thing from one side
     * each. A frame marked as coming from a PARTIAL owner is applied whatever this node holds:
     * the sender wrote what it is entitled to write, and refusing it would break the very
     * arrangement the operation axis exists for. And a node that owns the collection only partly
     * refuses nothing: it does not hold the whole truth, so it has no standing to call another
     * node's write a split. Accepting a partial owner's frame is normal traffic and is not
     * logged - a line per legitimate write would drown the one line that means something.
     *
     * What is judged is the ROW the frame carries, not the collection it belongs to (HIL-589).
     * An agent claiming keys owns those entities and nothing around them, so the fleet pattern -
     * every node writing its own rows of one shared collection - is ordinary traffic rather than
     * a split, while a frame about a row this node does own is refused exactly as before, with
     * the same line. A payload naming no row is judged by the collection, as everything was
     * before there were rows to judge: it says nothing this node could narrow the question with.
     *
     * @param string $originNodeId Id of the node the write happened on
     * @param string $signalType RT sync signal type the frame carried
     * @param SignalDTO $signal RT sync signal to apply and fan out locally
     * @param bool $partialOwner Whether the origin holds only part of the right over the collection
     * @throws HilosException When a collection refuses to be re-read from the replaced database
     */
    public function applyRemoteRtSync(
        string $originNodeId,
        string $signalType,
        SignalDTO $signal,
        bool $partialOwner = false,
    ): void {
        $carriedType = $signal->signalType->getType();
        $carriedName = $signal->signalName->getName();
        if (
            $carriedType !== $signalType
            || $carriedName !== $signalType
            || !in_array($carriedType, PeerRtSyncDTO::SIGNAL_TYPES, true)
        ) {
            Logger::warning(
                "Dropping peer RT sync from node '{$originNodeId}': declared '{$signalType}',"
                . " carried type '{$carriedType}' name '{$carriedName}'"
                . ' - only a matching RT sync fact is applied',
            );

            return;
        }

        $syncData = $signal->data instanceof SyncSignalDataInterface ? $signal->data : null;
        if ($syncData === null) {
            Logger::warning(
                "Dropping peer RT sync from node '{$originNodeId}': its payload names no collection"
                . ' - there is nothing to check ownership against',
            );

            return;
        }

        $stateId = $syncData instanceof RtSyncSignalDataInterface ? $syncData->stateId : null;
        if (
            !$partialOwner
            && $this->agentManagerDaemon->rtNodeSourceMap()->ownsFully($syncData->collectionKey, $stateId)
            && !self::isMasterCoWritten($syncData->collectionKey)
        ) {
            $this->rtFramesRefused++;
            Logger::warning(
                "RT collection {$syncData->collectionKey} has truth sources on two nodes:"
                . " local and {$originNodeId}",
            );

            return;
        }

        $this->rtFramesApplied++;

        $workerServer = $this->findWorkerServer();
        if ($workerServer !== null) {
            $this->sendSyncToWorkers($workerServer, $signal);
        }

        $this->handleDaemonSignal($signal);
    }

    /**
     * Applies one DB sync fact written on another node to the rows this node holds.
     *
     * Implements {@see DbSyncSink}. The same two steps as {@see applyRemoteRtSync()} — feed this
     * node's workers, then apply to the master's own copy — and the same seam check on the way
     * in: a frame whose inner signal is not the DB sync type it declares is dropped, because the
     * seam is reachable from the wire and applying an arbitrary sync signal by another node's
     * word is the one thing it must not do. The name is checked beside the type because
     * {@see sendSyncToWorkers()} builds its frame from the signal NAME, so a signal named after
     * another sync fact would reach the workers as that fact however well its type checked out.
     *
     * What is deliberately absent is any ownership refusal. The row was written to the database
     * both nodes read, so refusing the news of it would not undo the write - it would leave this
     * node's copy disagreeing with the disk, which is the very defect being closed. The guard
     * against two writers stands where the write happens.
     *
     * The origin travels with the fact all the way through, and that is what makes the border of
     * the apply expressible: a created row is a row this node has never seen, and only a
     * collection that claims to hold the full set has any reason to take it.
     *
     * The acceptance counter is bumped once the frame has passed the seam check and before
     * anything is applied, because what it exists to report is arrival: on a stand whose nodes
     * carry different schemas, whether the row landed is not a question that can be asked.
     *
     * @param string $originNodeId Id of the node the write happened on
     * @param string $signalType DB sync signal type the frame carried
     * @param SignalDTO $signal DB sync signal to apply and fan out locally
     * @throws HilosException When a collection refuses the fact it is handed
     */
    public function applyRemoteDbSync(string $originNodeId, string $signalType, SignalDTO $signal): void
    {
        $carriedType = $signal->signalType->getType();
        $carriedName = $signal->signalName->getName();
        if (
            $carriedType !== $signalType
            || $carriedName !== $signalType
            || !in_array($carriedType, PeerDbSyncDTO::SIGNAL_TYPES, true)
        ) {
            Logger::warning(
                "Dropping peer DB sync from node '{$originNodeId}': declared '{$signalType}',"
                . " carried type '{$carriedType}' name '{$carriedName}'"
                . ' - only a matching DB sync fact is applied',
            );

            return;
        }

        $syncData = $signal->data instanceof SyncSignalDataInterface ? $signal->data : null;
        Hilos::$cluster?->noteDbReplica($syncData?->collectionKey);

        $workerServer = $this->findWorkerServer();
        if ($workerServer !== null) {
            $this->sendSyncToWorkers($workerServer, $signal, $originNodeId);
        }

        $this->handleDaemonSignal($signal, $originNodeId);
    }

    /**
     * Stops believing this node's own copies of database rows, on a link being established.
     *
     * Implements {@see DbSyncSink}. Nothing is asked of the peer and nothing is asked about the
     * gap: the node re-reads from the database, which is where a row comes from in the first
     * place, so the answer is right whatever it missed while the link was down. Lazy collections
     * simply forget and fetch on the next read; eager ones re-read at once.
     *
     * The whole node re-reads, not just the master. Every process of it holds its own copies and
     * every one of them was equally deaf, so the workers are told through a frame of their own -
     * a write into each link's buffer, which is all the master does here besides re-reading what
     * it holds itself.
     *
     * No barrier, unlike a restore's re-hydrate: a barrier exists to hold a freeze until every
     * process has re-read, and here nobody is waiting on the answer. That is also why the
     * failure is contained rather than raised, on the same grounds as
     * {@see applyReHydrateContained()}: this runs on the master loop, from the peer handshake
     * handler, and an exception leaving here would end run() and take the node down the moment
     * a peer links. A node that could not re-read keeps rows it should not trust, which is bad;
     * a node that is dead is worse.
     *
     * @param string $nodeId Node this one has just linked to
     */
    public function reReadAfterLink(string $nodeId): void
    {
        Logger::info("Re-reading database-backed collections after linking to node '{$nodeId}'");

        $workerServer = $this->findWorkerServer();
        if ($workerServer !== null) {
            $this->writeFrameToWorkers($workerServer, new WorkerDbReReadMessageDTO());
        }

        try {
            Hilos::$db?->reHydrateDbBackedCollections();
        } catch (HilosException $e) {
            Logger::error(
                "Could not re-read database-backed collections after linking to node '{$nodeId}':"
                . " {$e->getMessage()}",
            );
        }
    }

    /**
     * Replaces this node's copy of one RT collection, or of the rows named, with the owner's.
     *
     * Implements {@see RtSyncSink}. Replacement, not merge: the owner's copy is the whole truth
     * about what it sent, so what this node held there and the snapshot does not carry is gone.
     * The workers are told the same thing the only way the wire says it — every row that goes is
     * deleted, then every row the owner sent is created — because a create alone leaves a row a
     * worker already has untouched, and this node's copy would then agree with the owner while
     * its workers did not.
     *
     * The scope says what "what it sent" covers. Empty, it is the collection, and this is the
     * hand-over as it has always been. Named, the frame speaks for those rows only: they are
     * swept and rewritten, and every other row of the collection — written by other nodes of a
     * fleet, or by this one — is left untouched, workers and all. Replacing the collection on a
     * scoped frame would delete exactly the rows the sender never claimed.
     *
     * A row carried outside the declared scope is dropped before anything is written, here and
     * not only in the runtime: the two-owner question below is asked of the SCOPE, so a row
     * reaching past it would be one nobody judged - and this node's workers would be told to
     * create it even where the row belongs to this node itself.
     *
     * A snapshot for what this node owns is refused exactly as a delta is, and for the same
     * reason: two owners is the defect, not an input. Refused by the ROW where the frame names
     * rows (HIL-589) — the question is whether this node owns any row the frame speaks for, so a
     * fleet member accepts its neighbours' rows and still refuses a frame reaching for its own.
     * The exemption a delta of the co-written collection gets ({@see isMasterCoWritten()}) has no
     * place here: a snapshot is only ever offered by the node whose own agent owns what it sends
     * ({@see sendRtSnapshotsToNode()}), so one arriving for what this node's agent owns is that
     * split and not a master's half of a shared store.
     *
     * @param string $originNodeId Id of the node that owns the collection
     * @param string $collectionKey RT collection being replaced
     * @param array<string, array<string, mixed>> $rows Rows by state id, as the owner holds them
     * @param list<string> $scopeKeys Rows the snapshot speaks for; empty for the whole collection
     * @throws HilosException Whatever a subscriber to the collection's announcement raises
     */
    public function applyRemoteRtSnapshot(
        string $originNodeId,
        string $collectionKey,
        array $rows,
        array $scopeKeys = [],
    ): void {
        if ($this->ownsAnyOfSnapshotScope($collectionKey, $scopeKeys)) {
            Logger::warning(
                "RT collection {$collectionKey} has truth sources on two nodes:"
                . " local and {$originNodeId}",
            );

            return;
        }

        if ($scopeKeys === []) {
            $held = RtSnapshot::rows($collectionKey);
            RtSnapshot::replace($collectionKey, $rows);
        } else {
            $scope = array_flip($scopeKeys);
            $held = array_intersect_key(RtSnapshot::rows($collectionKey), $scope);
            $rows = array_intersect_key($rows, $scope);
            RtSnapshot::replaceScope($collectionKey, $scopeKeys, $rows);
        }

        $workerServer = $this->findWorkerServer();
        if ($workerServer === null) {
            return;
        }

        foreach (array_keys($held) as $stateId) {
            $this->writeFrameToWorkers($workerServer, new WorkerRtSyncDeletedMessageDTO(
                new RtSyncDeletedSignalData($collectionKey, (string)$stateId),
            ));
        }

        foreach ($rows as $stateId => $row) {
            $this->writeFrameToWorkers($workerServer, new WorkerRtSyncCreatedMessageDTO(
                new RtSyncCreatedSignalData($collectionKey, (string)$stateId, $row),
            ));
        }
    }

    /**
     * Offers this node's RT state to every linked peer when what it owns has just changed.
     *
     * The hand-over hangs off the handshake ({@see handOverRtSnapshots()}), which answers "a node
     * appeared" and cannot answer the other order: an OWNER appearing while the nodes are already
     * linked. Both orders are ordinary. An agent registers seconds after its node joined, and a
     * placed agent moves to a node that has been in the mesh all along.
     *
     * Without this, that second order loses the row for good. The very first write of a row is
     * a CREATE, and it is announced only if the master already knows its collection is owned here
     * - which is a report travelling from the same worker, in the same breath. Lose that race and
     * the create is never announced; every write after it is an UPDATE, and a node without the row
     * drops updates for it ({@see RtSyncApplicator::applyUpdated()}). Nothing else would ever
     * bring it, because delivery has no retries (HIL-183) and the next hand-over is the next
     * handshake, which may never come.
     *
     * The signature is what this node owns, not the rows: an offer per write would be a snapshot
     * per delta. So the check costs one walk over a handful of claims per loop pass, and the
     * hand-over itself happens on the rare pass where an agent started, stopped or moved.
     *
     * Protected for the reason {@see sendRtSnapshotsToNode()} is: it is how a subclass sees what
     * this node hands over.
     *
     * @param ?RtSyncMesh $mesh Peer server of this node, or null off-cluster
     */
    protected function offerRtSnapshotsOnOwnershipChange(?RtSyncMesh $mesh): void
    {
        $map = $this->agentManagerDaemon->rtNodeSourceMap();
        $signature = json_encode([$map->fullyOwnedCollections(), $map->keyScopedCollections()]);
        if ($signature === $this->rtOwnershipSignature) {
            return;
        }

        $this->rtOwnershipSignature = $signature;
        if ($mesh === null) {
            return;
        }

        foreach ($mesh->linkedNodeIds() as $nodeId) {
            $this->sendRtSnapshotsToNode($mesh, $nodeId);
        }
    }

    /**
     * Whether a snapshot reaches for anything an agent of this node owns wholly.
     *
     * The two-owner question, asked of a hand-over. A frame naming no rows claims the collection,
     * so the collection is what it is judged by; a frame naming rows is judged row by row, and
     * one row held here is enough to refuse the whole frame — the sender believes it owns what
     * this node writes, and no part of that belief is safe to act on.
     *
     * @param string $collectionKey RT collection the snapshot is for
     * @param list<string> $scopeKeys Rows the snapshot speaks for; empty for the whole collection
     * @return bool True when this node owns the collection, or any row the frame speaks for
     */
    private function ownsAnyOfSnapshotScope(string $collectionKey, array $scopeKeys): bool
    {
        $map = $this->agentManagerDaemon->rtNodeSourceMap();
        if ($scopeKeys === []) {
            return $map->ownsFully($collectionKey);
        }

        foreach ($scopeKeys as $stateId) {
            if ($map->ownsFully($collectionKey, $stateId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports what this node holds of every replicated RT collection, and how it judged frames.
     *
     * Implements {@see RtReplicaInspector}. Every mounted collection is named, not just the ones
     * this node owns: what a scenario asks is whether a write made on another node arrived here,
     * and that is a collection this node holds a copy of and owns nothing in. Ownership is asked
     * of the node map and the rows of the copy itself, so the two halves of the answer come from
     * the same places the replication logic reads.
     *
     * The rows travel with their values, not as a list of ids: whether a replica ARRIVED is the
     * smaller half of the question, and whether it arrived CURRENT is the half a converging
     * cluster is actually judged by. A scenario watching a link go down and come back compares
     * the same field over time, which a list of ids cannot answer. The ids stay beside them
     * because most checks are about presence and read plainly that way.
     *
     * The two counters are about DELTAS. Ordinary traffic is what carries a split into the open -
     * frame after frame, for as long as both owners keep writing - so a refusal count of zero over
     * a run is the assertion worth making, and it is also how the absence of an echo is seen: a
     * node that passed replicas on would be refusing its own writes back within seconds.
     *
     * @return array<string, mixed> Collections by key, plus the applied and refused counts
     */
    public function inspectRtReplicas(): array
    {
        $map = $this->agentManagerDaemon->rtNodeSourceMap();
        $collections = [];
        foreach (Hilos::$rt?->collectionKeys() ?? [] as $collectionKey) {
            $rows = RtSnapshot::rows($collectionKey);
            $collections[$collectionKey] = [
                ClusterCommandConstants::FIELD_RT_OWNED => $map->owns($collectionKey),
                ClusterCommandConstants::FIELD_RT_FULLY_OWNED => $map->ownsFully($collectionKey),
                ClusterCommandConstants::FIELD_RT_ROW_IDS => array_map(strval(...), array_keys($rows)),
                ClusterCommandConstants::FIELD_RT_ROWS => $rows,
            ];
        }

        return [
            ClusterCommandConstants::FIELD_RT_COLLECTIONS => $collections,
            ClusterCommandConstants::FIELD_RT_APPLIED => $this->rtFramesApplied,
            ClusterCommandConstants::FIELD_RT_REFUSED => $this->rtFramesRefused,
        ];
    }

    /**
     * Hands this node's own RT collections to a node the peer transport has just linked to.
     *
     * Implements {@see RtSyncSink}. The transport calls it off the completed handshake rather
     * than off the join, because a join only says the other node is a member of the mesh -
     * learned, on three nodes and up, from a third node long before there is a link to send
     * anything over. Nothing re-asks later: the handshake that finally opens the link merges no
     * membership change, so a hand-over hung off the join would simply never happen there.
     *
     * @param string $nodeId Node this one can now reach
     */
    public function handOverRtSnapshots(string $nodeId): void
    {
        $this->sendRtSnapshotsToNode($this->findPeerServer(), $nodeId);
    }

    /**
     * Hands the browser connections this node holds to a node the mesh has just linked to.
     *
     * Implements {@see ClientSignalSink}. The set is taken from the index rather than from the
     * sockets, so the snapshot says exactly what this node's deltas have been saying; the two
     * agree at every point a hand-over can happen, and taking the same source keeps them
     * agreeing even where they might not.
     *
     * @param string $nodeId Node this one can now reach
     */
    public function handOverConnections(string $nodeId): void
    {
        $this->sendConnectionsToNode($this->findPeerServer(), $nodeId);
    }

    /**
     * Writes one signal forwarded from another node to the browser it is addressed to.
     *
     * Implements {@see ClientSignalSink}. The routing was done on the sending node, so this end
     * only encodes and writes — through the very method a locally resolved signal goes through,
     * which is what keeps a forwarded frame indistinguishable from a local one at the socket.
     *
     * @param string $acceptKey Accept key of the connection to deliver to
     * @param SignalDTO $signal Signal to write to that connection
     */
    public function deliverSignalToClient(string $acceptKey, SignalDTO $signal): void
    {
        // Counted on acceptance, before the socket is looked for: what this records is that the
        // mesh reached this node, which is what a cluster scenario asserts on and what the
        // headless demo has no other way of seeing.
        Hilos::$cluster?->clientConnections()?->noteAddressedDelivery($acceptKey);

        $webSocketServer = $this->findWebSocketServer();
        if ($webSocketServer === null) {
            Logger::warning(
                "Forwarded client signal dropped for '{$acceptKey}': this node serves no browser connections",
            );
            return;
        }

        $this->sendSignalToWebSocketClient($webSocketServer, $signal, $acceptKey);
    }

    /**
     * Answers the browser when a page subscription could not be carried to the node that serves
     * it (HIL-668).
     *
     * A subscription is the one dropped signal with somebody waiting on it: the browser has a
     * loading flag up and nothing else will ever come. This node is also the only one that can
     * say so — it holds that socket, and the node that would have answered is the one that could
     * not be reached. So it sends the ordinary subscription error, the very frame a page raises
     * by refusing a subscription, and the frontend clears its flag and shows a page it can
     * retry. The alternative is a tab that spins forever.
     *
     * Everything else dropped here stays dropped with its log line. A late push has no addressee
     * to be answered on behalf of, and inventing an error for it would put a failure on screen
     * for something the person never asked for.
     *
     * TODO: signals recently sent to a node that fell over could be held and retried after
     * failover instead of dropped. That is durable delivery and belongs to the cluster phase
     * (HIL-347), not here.
     *
     * Protected rather than private so a subclass can observe what this node answers, the same
     * way {@see announceConnectionChanges()} is.
     *
     * @param SignalDTO $signal Signal that could not be carried to the node hosting its agent
     * @throws InvalidArgumentException When the subscription-error signal cannot be named
     */
    protected function answerUnreachableSubscription(SignalDTO $signal): void
    {
        if ($signal->signalType->getType() !== SignalTypeConstants::PAGE_SUBSCRIBE) {
            return;
        }

        $data = $signal->data;
        if (!$data instanceof WebSocketPageSubscribeSignalDTO) {
            return;
        }

        // The page name travels in the payload or, when the route named it, in the signal name -
        // the same pair every other reader of a subscribe signal takes it from.
        $page = $data->page ?? $signal->signalName->getName();
        Logger::warning(
            "Page subscription to '{$page}' refused for '{$data->acceptKey}': the node serving it is unreachable",
        );

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::DAEMON),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(SignalConstants::SUBSCRIPTION_PAGE_ERROR),
            signalData: new WebSocketSignalData(
                data: new PageSubscriptionErrorSignalData(
                    page: $page,
                    httpCode: HttpConstants::HTTP_SERVICE_UNAVAILABLE,
                    errorCode: self::SUBSCRIPTION_NODE_UNREACHABLE_CODE,
                    message: self::SUBSCRIPTION_NODE_UNREACHABLE_MESSAGE,
                ),
                targetAcceptKey: $data->acceptKey,
            ),
        );
    }

    /**
     * Fans one signal forwarded from another node out to the browsers this node holds.
     *
     * Implements {@see ClientSignalSink}. Nothing arrived resolved, because nothing could be:
     * who receives a fan-out is answered by a subscription registry that only ever knew this
     * node's own browsers. So the resolving happens here, through the router's local-only
     * entry point, and the writing goes through the very helpers a locally raised fan-out uses
     * — which is what makes a forwarded frame indistinguishable from a local one at the socket.
     *
     * @param string $originNodeId Id of the node the fan-out started on (trace only)
     * @param SignalDTO $signal Signal to expand against this node's subscriptions
     */
    public function deliverFanoutToClients(string $originNodeId, SignalDTO $signal): void
    {
        Hilos::$cluster?->clientConnections()?->noteFanoutDelivery();

        $webSocketServer = $this->findWebSocketServer();
        if ($webSocketServer === null) {
            Logger::warning(
                "Forwarded client fanout from node '{$originNodeId}' dropped:"
                . " this node serves no browser connections",
            );
            return;
        }

        foreach (Hilos::$sr->localClientDestinations($signal) as $destination) {
            if ($destination instanceof WebSocketDestination) {
                $this->sendSignalToWebSocketClient($webSocketServer, $signal, $destination->acceptKey);
            } elseif ($destination instanceof AllClientsDestination) {
                $broadcastFrame = $this->encodeSignalFrame($signal);
                if ($broadcastFrame === null) {
                    continue;
                }

                $this->sendToAllClients($webSocketServer, $broadcastFrame, $destination->excludeAcceptKey);
            } elseif ($destination instanceof SessionClientsDestination) {
                $sessionFrame = $this->encodeSignalFrame($signal);
                if ($sessionFrame === null) {
                    continue;
                }

                $this->sendToSessionClients($webSocketServer, $sessionFrame, $destination->sessionTokenHash);
            }
        }
    }

    /**
     * Hands one node the browser connections this node holds.
     *
     * Protected, and taking the port rather than finding it, for the reason
     * {@see sendRtSnapshotsToNode()} is: it is how a subclass sees what this node hands over.
     *
     * @param ?ClientMesh $mesh Peer server of this node, or null off-cluster
     * @param string $nodeId Node the connections go to
     */
    protected function sendConnectionsToNode(?ClientMesh $mesh, string $nodeId): void
    {
        $connections = Hilos::$cluster?->clientConnections();
        if ($mesh === null || $connections === null) {
            return;
        }

        $mesh->sendConnectionsSnapshotToNode($nodeId, $connections->announcedLocalKeys());
    }

    /**
     * Re-reads every DB-backed collection of the daemon after the database was replaced (HIL-479).
     *
     * The failure is contained here and nowhere else. {@see DbSyncApplicator::applyReHydrate()}
     * stays throwing because the agent that announced the swap is about to read the new database
     * and has to learn that it could not; the daemon has nothing to do with that answer and no
     * error path to put it in - an exception raised here would leave {@see dispatchSignals()} and
     * end the run loop, killing the master in the minute after a restore. That is also why the
     * sibling arms of the same match look safe: {@see DbSyncApplicator::applyCleared()} already
     * contains its own re-read.
     *
     * Contained is not the same as unreported (HIL-436): the outcome is the daemon's own answer to
     * the barrier it just opened, so a master that could not re-read keeps the node closed instead
     * of quietly counting itself as ready.
     */
    private function applyReHydrateContained(): void
    {
        try {
            DbSyncApplicator::applyReHydrate();
            $this->agentManagerDaemon->ackReHydrateParticipant(ReHydrateRound::daemonParticipant(), true, null);
        } catch (DatabaseException | LogicException $e) {
            Logger::error('DB re-hydrate apply could not re-read the database', ['error' => $e->getMessage()]);
            $this->agentManagerDaemon->ackReHydrateParticipant(
                ReHydrateRound::daemonParticipant(),
                false,
                $e->getMessage(),
            );
        }
    }

    /**
     * Opens the re-hydrate barrier over every process about to be told the database was replaced.
     *
     * The roster is this master plus its registered workers - exactly the set
     * {@see sendSyncToWorkers()} is about to reach. A worker that has connected but not registered
     * yet is left out on purpose: it has no index to answer under, and by the time it finishes
     * registering it is opening the database that is already in place.
     *
     * In a cluster the roster also carries the other masters, and the announcement is passed on to
     * them: the nodes share one database, so a restore run here leaves them answering out of caches
     * of a database that no longer exists. Only the node where the swap was announced passes it on -
     * an announcement that arrived over the mesh is answered, never re-broadcast, or the mesh would
     * echo it back and forth forever.
     *
     * @param WorkerServer $workerServer Worker server holding this node's worker links
     * @param ?PeerServer $peerServer Peer server holding this node's mesh links, or null outside a cluster
     * @param SignalDTO $signal Re-hydrate announcement naming whoever awaits the verdict
     */
    private function openReHydrateRound(WorkerServer $workerServer, ?PeerServer $peerServer, SignalDTO $signal): void
    {
        $announcement = self::syncSignalData($signal->data, DbReHydrateSignalData::class);

        $participants = [ReHydrateRound::daemonParticipant()];
        foreach ($workerServer->getClients() as $client) {
            if ($client instanceof WorkerClient && $client->isRegistered()) {
                $participants[] = ReHydrateRound::workerParticipant($client->getWorkerIndex());
            }
        }

        $announcedHere = $announcement->replyToNodeId === null;
        if ($announcedHere && $peerServer !== null) {
            foreach ($peerServer->followerMasterNodeIds() as $nodeId) {
                $participants[] = ReHydrateRound::nodeParticipant($nodeId);
            }
        }

        $this->agentManagerDaemon->openReHydrateRound(
            $announcement->agentId,
            $announcement->replyToNodeId,
            $participants,
            microtime(true) + $this->reHydrateTimeoutSeconds(),
        );

        if ($announcedHere) {
            $peerServer?->broadcastDbReHydrate();
        }
    }

    /**
     * Sends the re-hydrate verdict to the announcing agent once the barrier ends.
     *
     * Driven from the main loop rather than from a timer of its own: the deadline only has to be
     * noticed within one iteration, and a barrier that outlives the daemon has nobody to report to.
     */
    private function tickReHydrateRound(): void
    {
        $verdict = $this->agentManagerDaemon->pollReHydrateVerdict(microtime(true));
        if ($verdict === null) {
            return;
        }

        if (!$verdict->complete) {
            Logger::error(
                'DB re-hydrate barrier did not close',
                ['problems' => implode('; ', $verdict->problems)],
            );
        }

        if ($verdict->replyToNodeId !== null) {
            $this->findPeerServer()?->sendDbReHydrated(
                $verdict->replyToNodeId,
                $verdict->complete,
                $verdict->problems,
            );

            return;
        }

        $this->findWorkerServer()?->deliverDbReHydrateComplete(
            new DbReHydrateCompleteDTO($verdict->agentId, $verdict->complete, $verdict->problems),
        );
    }

    /**
     * Forwards a master-facade signal to the agent's host node over the peer channel.
     *
     * Split out of {@see sendToAgent()} so the remote branch reads as one step there. Delivery
     * is best-effort, exactly as the routed cross-node path is: an unlinked node drops and is
     * written, buffering for an offline node is out of scope.
     *
     * @param string $nodeId Id of the node hosting the target agent
     * @param string $agentType Agent type to address
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param SignalDTO $signal Signal to deliver on the target node
     * @param string $agentLabel Addressee as the log line names it
     */
    private function forwardMasterSignalToNode(
        string $nodeId,
        string $agentType,
        ?string $agentIndex,
        SignalDTO $signal,
        string $agentLabel,
    ): void {
        $signalName = $signal->signalName->getName();
        $peerServer = $this->findPeerServer();
        if ($peerServer === null) {
            $this->reportMasterSignalDropped($signalName, $agentLabel, "no peer server for node {$nodeId}");

            return;
        }

        if (!$peerServer->sendSignalToNode($nodeId, $agentType, $agentIndex, $signal)) {
            $this->reportMasterSignalDropped($signalName, $agentLabel, "no live link to node {$nodeId}");
        }
    }

    /**
     * Writes the one line a caller of the master facade ever gets about a failed delivery.
     *
     * The facade returns void on purpose, so this line is the whole report: it names the
     * signal, who it was for, and why it did not arrive. The level drops to info while the
     * node is leaving, because workers and links disappearing during shutdown is the design
     * and not a fault - the same distinction {@see dispatchSignals()} already draws.
     *
     * @param string $signalName Signal name the caller addressed
     * @param string $addressee Addressee, already spelled the way the line names it
     * @param string $reason Why the signal did not arrive
     */
    private function reportMasterSignalDropped(string $signalName, string $addressee, string $reason): void
    {
        $line = "Master signal '{$signalName}' to {$addressee} dropped: {$reason}";

        if ($this->shouldExit) {
            Logger::info($line);

            return;
        }

        Logger::error($line);
    }

    /**
     * @return ?PeerServer Registered peer server, or null when cluster mode is off
     */
    private function findPeerServer(): ?PeerServer
    {
        return array_find($this->servers, fn($server) => $server instanceof PeerServer);
    }

    /**
     * @return ?WebSocketServer Registered WebSocket server, or null when this daemon serves no browsers
     */
    private function findWebSocketServer(): ?WebSocketServer
    {
        return array_find($this->servers, fn($server) => $server instanceof WebSocketServer);
    }

    /**
     * Announces what changed in this node's browser connections since it last spoke (HIL-668).
     *
     * A diff of the socket set, taken once per loop iteration, rather than a hook on open and
     * close: a connection ends in the master from three different places, and the path that got
     * no hook would leave a ghost in every other node's index — signals addressed forever to a
     * socket that is gone. The diff cannot miss a path because it never looks at them, and it
     * batches a reconnect storm into one frame for free.
     *
     * Silent off-cluster and while nothing changed: there is no peer to tell, and an unchanged
     * set is not news. The whole step costs one pass over the connected clients.
     *
     * Protected rather than private so that what this node tells the mesh can be observed from a
     * subclass, the same way {@see broadcastRtSyncToPeers()} is.
     *
     * @param ?ClientMesh $mesh Peer server of this node, or null off-cluster
     */
    protected function announceConnectionChanges(?ClientMesh $mesh): void
    {
        $connections = Hilos::$cluster?->clientConnections();
        if ($mesh === null || $connections === null) {
            return;
        }

        $delta = $connections->diffLocal($this->connectedAcceptKeys());
        if ($delta['opened'] === [] && $delta['closed'] === []) {
            return;
        }

        $mesh->broadcastConnectionsDelta($delta['opened'], $delta['closed']);
    }

    /**
     * Lists the accept keys of the browser connections this node holds right now.
     *
     * A socket that has not finished its handshake carries no accept key yet and is left out:
     * it has no address to be reached at, so announcing it would index a connection nothing
     * can address. It is picked up by the next diff, one tick after it is addressable.
     *
     * @return list<string> Accept keys of the connected clients
     */
    private function connectedAcceptKeys(): array
    {
        $webSocketServer = $this->findWebSocketServer();
        if ($webSocketServer === null) {
            return [];
        }

        $acceptKeys = [];
        foreach ($webSocketServer->getClients() as $client) {
            if ($client instanceof WebSocketClient && $client->acceptKey !== '') {
                $acceptKeys[] = $client->acceptKey;
            }
        }

        return $acceptKeys;
    }

    /**
     * How long the barrier waits before writing off whoever is still silent.
     *
     * The env read is contained here for the same reason the re-read above is: this runs inside
     * the daemon loop, and an exception escaping it would end the run loop - killing the master in
     * the minute after a restore, which is the worst possible moment. A misconfigured timeout
     * degrades to the catalog's own default instead.
     *
     * @return float Seconds to wait for the barrier
     */
    private function reHydrateTimeoutSeconds(): float
    {
        try {
            return (float)Hilos::$env->int(EnvConstants::HILOS_DB_REHYDRATE_TIMEOUT);
        } catch (EnvException $e) {
            Logger::error('DB re-hydrate timeout is unreadable', ['error' => $e->getMessage()]);

            return self::DB_REHYDRATE_TIMEOUT_FALLBACK_SECONDS;
        }
    }

    /**
     * @template T of SignalDataInterface
     *
     * @param class-string<T> $expectedClass
     * @return T
     */
    private static function syncSignalData(SignalDataInterface $data, string $expectedClass): SignalDataInterface
    {
        if ($data instanceof $expectedClass) {
            return $data;
        }

        return $expectedClass::fromArray($data->toArray());
    }

    /**
     * Inject envelope-level metadata (outcome, time) from an
     * {@see WebSocketEnvelopeAware} DTO into the
     * outgoing WebSocket frame array.
     *
     * Order of keys is preserved: type first, then data, then optional
     * metadata. Consumers rely on this for log readability.
     *
     * @param array<string, mixed> $message Frame array (mutated in place)
     * @param SignalDataInterface $inner Inner signal data
     */
    private function mergeEnvelopeMetadata(array &$message, SignalDataInterface $inner): void
    {
        if (!$inner instanceof WebSocketEnvelopeAware) {
            return;
        }
        $outcome = $inner->getEnvelopeOutcome();
        if ($outcome !== null) {
            $message['outcome'] = $outcome;
        }
        $requestId = $inner->getEnvelopeRequestId();
        if ($requestId !== null) {
            $message[SignalPayloadConstants::FIELD_REQUEST_ID] = $requestId;
        }
        $time = $inner->getEnvelopeTime();
        if ($time !== null) {
            $message['time'] = $time;
        }
    }

    /**
     * Send a signal to one WebSocket client by accept key.
     *
     * @param WebSocketServer $server WebSocket server
     * @param SignalDTO $signal Signal DTO
     * @param string $acceptKey Accept key
     */
    private function sendSignalToWebSocketClient(WebSocketServer $server, SignalDTO $signal, string $acceptKey): void
    {
        $message = $this->encodeSignalFrame($signal);
        if ($message === null) {
            return;
        }

        $this->sendToClient($server, $acceptKey, $message);
    }

    /**
     * Serialize a signal into the outgoing WebSocket frame JSON.
     *
     * Unwraps WebSocketSignalData targeting metadata down to the inner payload,
     * then builds the type/data frame with optional envelope metadata. Shared by
     * single-client and all-clients delivery so both send an identical frame.
     *
     * Protected rather than private only so a test subclass can call it. Building
     * such a subclass is how this class is already exercised — DaemonManagerRoleTickTest,
     * DaemonManagerClusterReactionTest, DaemonManagerEntropyFailureTest and
     * DaemonManagerSubscriptionUpdateTest each carry one — and the widening is what
     * lets that subclass reach the encoder. No override is expected of anyone. It is
     * not the standing answer either: the neighboring tickServers is left private on
     * purpose and taken by reflection from its own test, so the difference between
     * the two is named here rather than read as an oversight.
     *
     * @param SignalDTO $signal Signal DTO
     * @return ?string Frame JSON ready for sendFrame(), or null when it cannot be encoded
     */
    protected function encodeSignalFrame(SignalDTO $signal): ?string
    {
        $signalData = $signal->data;

        // Extract inner data from WebSocketSignalData if present
        $innerData = $signalData instanceof WebSocketSignalData
            ? $signalData->data
            : $signalData;

        $message = [
            'type' => $signal->signalName->getName(),
            'data' => $innerData->toArray(),
        ];
        $this->mergeEnvelopeMetadata($message, $innerData);

        $messageJson = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($messageJson === false) {
            Logger::error("Signal frame dropped: {$signal->signalName->getName()} cannot be encoded - " . json_last_error_msg());

            return null;
        }

        return $messageJson;
    }

    /**
     * Send message to specific client
     *
     * @param WebSocketServer $server WebSocket server
     * @param string $acceptKey Accept key
     * @param string $message Message JSON
     */
    private function sendToClient(WebSocketServer $server, string $acceptKey, string $message): void
    {
        foreach ($server->getClients() as $client) {
            if ($client instanceof WebSocketClient && $client->acceptKey === $acceptKey) {
                try {
                    $client->sendFrame($message);
                    return;
                } catch (Throwable $e) {
                    Logger::error("Failed to send message to acceptKey {$acceptKey}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Broadcast a pre-serialized frame to every connected WebSocket client in a
     * single pass, skipping excludeAcceptKey. Per-client send failures are logged
     * by accept key and do not stop the broadcast.
     *
     * @param WebSocketServer $server WebSocket server
     * @param string $message Message JSON
     * @param ?string $excludeAcceptKey Accept key to exclude, or null to send to all
     */
    private function sendToAllClients(WebSocketServer $server, string $message, ?string $excludeAcceptKey = null): void
    {
        foreach ($server->getClients() as $client) {
            if ($client instanceof WebSocketClient) {
                if ($excludeAcceptKey !== null && $client->acceptKey === $excludeAcceptKey) {
                    continue;
                }

                try {
                    $client->sendFrame($message);
                } catch (Throwable $e) {
                    Logger::error("Failed to send message to acceptKey {$client->acceptKey}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Deliver a pre-serialized frame to every connection of one browser session held here.
     *
     * The same single pass as the broadcast above, with the session hash as the filter instead of
     * the excluded key: a browser is a set of sockets, and the whole point of addressing one is
     * that the tab which asked may no longer be the tab that is watching. A connection carrying no
     * session is skipped rather than compared - it has nothing this frame could be meant for, and
     * a null on both sides must never read as a match.
     *
     * The compare is {@see hash_equals()} because both sides are derived from a session token,
     * which is the credential this whole address stands in for.
     *
     * @param WebSocketServer $server WebSocket server
     * @param string $message Message JSON
     * @param string $sessionTokenHash Hash of the session whose connections receive the frame
     */
    private function sendToSessionClients(WebSocketServer $server, string $message, string $sessionTokenHash): void
    {
        foreach ($server->getClients() as $client) {
            if (!$client instanceof WebSocketClient || $client->sessionTokenHash === null) {
                continue;
            }
            if (!hash_equals($sessionTokenHash, $client->sessionTokenHash)) {
                continue;
            }

            try {
                $client->sendFrame($message);
            } catch (Throwable $e) {
                Logger::error("Failed to send message to acceptKey {$client->acceptKey}: " . $e->getMessage());
            }
        }
    }

    /**
     * Send message to group
     *
     * @param WebSocketServer $server WebSocket server
     * @param string $group Group name
     * @param string $message Message JSON
     * @param ?string $excludeAcceptKey Accept key to exclude (optional)
     */
    private function sendToGroup(WebSocketServer $server, string $group, string $message, ?string $excludeAcceptKey = null): void
    {
        // TODO: Implement proper group subscription tracking
        // For now, send to all clients (basic implementation)
        foreach ($server->getClients() as $client) {
            if ($client instanceof WebSocketClient) {
                if ($excludeAcceptKey !== null && $client->acceptKey === $excludeAcceptKey) {
                    continue;
                }

                // Basic implementation: send to all clients
                // In future, check if client is subscribed to group
                try {
                    $client->sendFrame($message);
                } catch (Throwable $e) {
                    Logger::error("Failed to send message to acceptKey {$client->acceptKey}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Update user subscriptions based on signal type
     *
     * Updates subscriptions in Hilos::$sr for subscribe/unsubscribe/update_subscription signals.
     * This must be called BEFORE routing, as routing may depend on current subscriptions.
     *
     * An update aimed at a subscription the client does not hold is logged and dropped:
     * the signal arrives from the browser, so it must not escape into the daemon loop.
     * Only the three subscription exceptions are contained here - a broader catch would
     * hide real router failures.
     *
     * @param SignalDTO $signal Signal DTO
     * @throws AgentDaemonCreationFailedException When the replaced subscription's agent daemon cannot be created
     * @throws AgentNotFoundException When the replaced subscription's agent is gone after a start attempt
     * @throws AgentNotLinkedToWorkerException When the replaced subscription's agent is not linked to a worker
     * @throws HilosException Whatever the project's agent-daemon factory raises reaching the replaced agent
     * @throws InvalidArgumentException When the unsubscribe of a replaced subscription cannot be named
     * @throws WorkerClientNotFoundException When the worker hosting the replaced subscription's agent is gone
     */
    protected function updateSubscriptions(SignalDTO $signal): void
    {
        $signalType = $signal->signalType->getType();
        $signalName = $signal->signalName->getName();

        switch ($signalType) {
            case SignalTypeConstants::PAGE_SUBSCRIBE:
                if (!($signal->data instanceof WebSocketPageSubscribeSignalDTO)) {
                    return;
                }
                $page = $signal->data->page ?? $signalName;
                $address = $this->settledPageAgentAddress($page, $signal->data->acceptKey, $signal->data->params);
                $this->unsubscribeReplacedPageAgent($signal->data->acceptKey, $address);
                Hilos::$sr->subscribeToPage($page, $signal->data);
                if ($address !== null) {
                    Hilos::$sr->bindPageAgent(
                        $signal->data->acceptKey,
                        $page,
                        $address->agentType,
                        $address->agentIndex,
                    );
                }
                Hilos::$ac?->openPageSession($signal->data->acceptKey, $page, $signal->data->params);
                break;

            // A re-decision is where a live subscription may change hands: a guest who signed in
            // is the same connection on the same page, now belonging to somebody (HIL-627).
            case SignalTypeConstants::PAGE_ACCESS_REASSESS:
                if (!($signal->data instanceof WebSocketPageSubscribeSignalDTO)) {
                    return;
                }
                $reassessedPage = $signal->data->page ?? $signalName;
                // A re-decision is built from a WORKER's mirror of the subscriptions
                // ({@see PageAccessReassessment::sweepThisWorker()}), so it can name a page this
                // connection has already navigated away from. Re-addressing on it would take the
                // page the connection IS on away from the agent serving it and point that record
                // at the agent of the page it left - a live page dead with nothing to notice it.
                if (Hilos::$sr->pageSubscription($signal->data->acceptKey)?->page !== $reassessedPage) {
                    return;
                }
                $reassessedAddress = $this->settledPageAgentAddress(
                    $reassessedPage,
                    $signal->data->acceptKey,
                    $signal->data->params,
                );
                if ($reassessedAddress !== null) {
                    $this->unsubscribeReplacedPageAgent($signal->data->acceptKey, $reassessedAddress);
                    Hilos::$sr->bindPageAgent(
                        $signal->data->acceptKey,
                        $reassessedPage,
                        $reassessedAddress->agentType,
                        $reassessedAddress->agentIndex,
                    );
                }
                break;

            case SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION:
                if (!($signal->data instanceof WebSocketPageUpdateSubscriptionSignalDTO)) {
                    return;
                }
                $updatedPage = $signal->data->page ?? $signalName;
                try {
                    Hilos::$sr->updatePageSubscription($updatedPage, $signal->data);
                } catch (PageSubscriptionMismatchException | PageSubscriptionNotFoundException $e) {
                    Logger::error("Dropped {$signalType} for page '{$updatedPage}': {$e->getMessage()}");
                    return;
                }
                Hilos::$ac?->updatePageSession($signal->data->acceptKey, $signal->data->params);
                break;

            case SignalTypeConstants::PAGE_UNSUBSCRIBE:
                if (!($signal->data instanceof WebSocketPageUnsubscribeSignalDTO)) {
                    return;
                }
                // The registry entry itself is dropped after routing, by
                // {@see forgetSubscriptionsAfterRouting()}: it is what names the addressee.
                Hilos::$ac?->closePageSession($signal->data->acceptKey);
                break;

            case SignalTypeConstants::GROUP_SUBSCRIBE:
                if (!($signal->data instanceof WebSocketGroupSubscribeSignalDTO)) {
                    return;
                }
                Hilos::$sr->subscribeToGroup($signal->data->group ?? $signalName, $signal->data);
                break;

            case SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION:
                if (!($signal->data instanceof WebSocketGroupUpdateSubscriptionSignalDTO)) {
                    return;
                }
                $updatedGroup = $signal->data->group ?? $signalName;
                try {
                    Hilos::$sr->updateGroupSubscription($updatedGroup, $signal->data);
                } catch (GroupSubscriptionNotFoundException $e) {
                    Logger::error("Dropped {$signalType} for group '{$updatedGroup}': {$e->getMessage()}");
                    return;
                }
                break;

            case SignalTypeConstants::GROUP_UNSUBSCRIBE:
                if (!($signal->data instanceof WebSocketGroupUnsubscribeSignalDTO)) {
                    return;
                }
                Hilos::$sr->unsubscribeFromGroup($signal->data->group ?? $signalName, $signal->data);
                break;
        }
    }

    /**
     * Resolves which agent instance serves a subscription to one page.
     *
     * Null for a page that declares no per-instance route: it is served by its agent type,
     * nothing is bound, and routing takes the path it always took. A page that declares one
     * resolves to the instance named by its source, or - when the source names nothing usable -
     * to the fallback agent the page itself declared, which then answers the client the way any
     * page agent does. The master never answers a client itself.
     *
     * The value form matches what an indexed agent signal accepts for its index
     * ({@see SignalRouter}): a positive int or a non-empty string. Whether the row behind that
     * index exists is not asked here - the master must not read the database, and the ordinary
     * DB_EXISTS guards ask it inside the agent. Topology is read through the router, which is
     * where the project facade is named.
     *
     * @param string $page Page the subscription names
     * @param string $acceptKey Connection holding the subscription
     * @param array<string, mixed> $params Subscription params
     * @return ?PageAgentAddress Address to bind, the pending state, or null when the page is not per-instance
     */
    private function resolvePageAgentAddress(string $page, string $acceptKey, array $params): ?PageAgentAddress
    {
        $route = Hilos::$sr->pageAgentIndexRoute($page);
        if ($route === null) {
            return null;
        }

        $value = match ($route->source) {
            PageAgentIndexSource::PARAM => $route->param === null ? null : ($params[$route->param] ?? null),
            PageAgentIndexSource::SESSION_USER => $this->sessionUserIndexValue($acceptKey),
        };

        if ($value instanceof PageAgentAddress) {
            return $value;
        }

        $agentIndex = self::pageAgentIndexValue($value);

        return $agentIndex === null
            ? PageAgentAddress::to($route->fallbackAgentType, null)
            : PageAgentAddress::to($this->pageAgentTypeFor($page, $route->fallbackAgentType), $agentIndex);
    }

    /**
     * Reads a raw value as the instance index it names, or as naming none.
     *
     * The one definition of the form, shared by the resolution that addresses a subscription
     * and by the veto that keeps an update from moving it: two readings of "which instance is
     * this" would eventually disagree, and the disagreement would show up as a subscription
     * addressed to one instance while the client is shown another. The form itself is the one
     * an indexed agent signal accepts for its index field ({@see SignalRouter}).
     *
     * @param mixed $value Raw value carried by a subscription param or read off the connection
     * @return ?string Index as it is addressed, or null when the value names no instance
     */
    private static function pageAgentIndexValue(mixed $value): ?string
    {
        return match (true) {
            is_int($value) && $value > 0 => (string) $value,
            is_string($value) && $value !== '' => $value,
            default => null,
        };
    }

    /**
     * Resolves an address fit to be written onto a subscription record.
     *
     * Everything that mutates a record goes through here rather than through
     * {@see resolvePageAgentAddress()} directly, so a pending address can never be bound. A
     * pending one only ever reaches this point once its wait is over
     * ({@see releaseParkedSignals()}), and an identity that never arrived is answered the same
     * way an absent one is: the page's fallback agent takes the subscription and refuses, or
     * serves, the client itself.
     *
     * @param string $page Page the subscription names
     * @param string $acceptKey Connection holding the subscription
     * @param array<string, mixed> $params Subscription params
     * @return ?PageAgentAddress Settled address to bind, or null when the page is not per-instance
     */
    private function settledPageAgentAddress(string $page, string $acceptKey, array $params): ?PageAgentAddress
    {
        $address = $this->resolvePageAgentAddress($page, $acceptKey, $params);
        if ($address === null || !$address->pending) {
            return $address;
        }

        $route = Hilos::$sr->pageAgentIndexRoute($page);

        return $route === null ? null : PageAgentAddress::to($route->fallbackAgentType, null);
    }

    /**
     * Reads the durable user behind a connection out of the master's own runtime copy.
     *
     * The very seam the access guards are judged through ({@see BrowserContext::connectionIdentity}),
     * so what the router is allowed to look at stays one row of one connection - the master reads
     * no database and keeps no second copy of who a person is.
     *
     * @param string $acceptKey Connection holding the subscription
     * @return int|PageAgentAddress|null User id, the pending address when the identity has not arrived, null for a guest
     */
    private function sessionUserIndexValue(string $acceptKey): int|PageAgentAddress|null
    {
        $identity = Hilos::$browser?->connectionIdentity($acceptKey);
        if ($identity === null) {
            return null;
        }

        return $identity->pending ? PageAgentAddress::pending() : $identity->userId;
    }

    /**
     * Names the agent type that serves a page, falling back to what the page declared for the
     * case where no instance can be named.
     *
     * @param string $page Page the subscription names
     * @param string $fallbackAgentType Agent type the page declared for an undeterminable instance
     * @return string Agent type to address
     */
    private function pageAgentTypeFor(string $page, string $fallbackAgentType): string
    {
        $agentType = Hilos::$sr->getPageSubscriptionAgentType($page);

        return is_string($agentType) && $agentType !== '' ? $agentType : $fallbackAgentType;
    }

    /**
     * Tells the agent that used to serve this connection that its subscription is gone.
     *
     * Delivered straight to the previous addressee instead of being queued, because a queued
     * frame is routed after the current one - by then the record already carries the new
     * address, and the unsubscribe would be delivered to the very agent that just took over.
     *
     * No-op when the connection held nothing, when nothing was bound (the worker-side
     * replacement handles that case as it always has), or when the address has not moved.
     *
     * @param string $acceptKey Connection whose subscription is being replaced
     * @param ?PageAgentAddress $address Settled address the subscription is moving to, null when the new page is not per-instance
     */
    private function unsubscribeReplacedPageAgent(string $acceptKey, ?PageAgentAddress $address): void
    {
        $previous = Hilos::$sr->pageSubscription($acceptKey);
        if ($previous === null || $previous->agentType === null) {
            return;
        }

        if ($address !== null
            && $previous->agentType === $address->agentType
            && $previous->agentIndex === $address->agentIndex
        ) {
            return;
        }

        $workerServer = $this->findWorkerServer();
        if ($workerServer === null) {
            return;
        }

        $this->sendSignalToAgentDestination(
            $workerServer,
            new AgentDestination($previous->agentType, $previous->agentIndex),
            new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET),
                new SignalType(SignalTypeConstants::PAGE_UNSUBSCRIBE),
                new SignalName($previous->page),
                new WebSocketPageUnsubscribeSignalDTO(acceptKey: $acceptKey),
            ),
        );
    }

    /**
     * Refuses a subscription update that would move the connection to a different instance.
     *
     * Another instance is another page, and a page change arrives as a subscribe. The
     * refusal is a veto on the whole signal and not only on the record: a frame let through
     * is applied on the far side, where the accepted params settle into the subscription
     * record and the page is re-rendered from them
     * ({@see PageSignalRouter::dispatchPageUpdateSubscription}).
     * Keeping the record and delivering the frame would leave the master addressing one
     * instance while the client is shown another - a disagreement nothing later resolves.
     *
     * @param SignalDTO $signal Signal about to be routed
     * @return bool Whether the signal was refused instead of routed
     */
    private function refusePageInstanceMove(SignalDTO $signal): bool
    {
        if ($signal->signalType->getType() !== SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION) {
            return false;
        }

        $data = $signal->data;
        if (!$data instanceof WebSocketPageUpdateSubscriptionSignalDTO) {
            return false;
        }

        $page = $data->page ?? $signal->signalName->getName();
        $route = Hilos::$sr->pageAgentIndexRoute($page);
        if ($route === null || $route->source !== PageAgentIndexSource::PARAM || $route->param === null) {
            return false;
        }

        if (!array_key_exists($route->param, $data->params)) {
            return false;
        }

        $subscription = Hilos::$sr->pageSubscription($data->acceptKey);
        if ($subscription === null || $subscription->page !== $page) {
            return false;
        }

        // Judged on the index the two param sets NAME, not on the raw values: a subscription
        // that named no instance is served by the fallback agent, and an update handing it one
        // moves it just as surely as an update handing it a different one. Both sides are read
        // through the one form the address is resolved by, so a value that names nothing on
        // either side moves nothing and is let through.
        $current = self::pageAgentIndexValue($subscription->params[$route->param] ?? null);
        $updated = self::pageAgentIndexValue($data->params[$route->param]);
        if ($current === $updated) {
            return false;
        }

        Logger::error(
            "Refused page_update_subscription for page '{$page}': it would move the subscription"
            . ' from ' . ($current === null ? 'no instance' : "instance '{$current}'")
            . ' to ' . ($updated === null ? 'no instance' : "instance '{$updated}'")
            . ' - another instance is another page and arrives as a subscribe',
        );

        return true;
    }

    /**
     * Tells every agent instance holding a subscription of this connection that it is gone.
     *
     * The ordinary route sends connection_close to one lifecycle agent of a type; an instance
     * living in another worker would never hear of the disconnect and would keep a subscription
     * that has no socket behind it. Delivered before the records are dropped, because the records
     * are what name the addressees.
     *
     * Skipped when the ordinary walk already reached that very agent, which is the ordinary
     * shape rather than a corner: a subscription that could name no instance is served by the
     * page's fallback agent, and a project usually names its lifecycle agent there. Delivering
     * again would run the agent's close hook twice for one disconnect.
     *
     * @param WorkerServer $workerServer Worker server the delivery goes through
     * @param SignalDTO $signal Signal just routed
     * @param list<AgentDestination> $agentsDelivered Agent destinations the ordinary walk already reached
     */
    private function fanOutConnectionCloseToPageAgents(
        WorkerServer $workerServer,
        SignalDTO $signal,
        array $agentsDelivered,
    ): void {
        if ($signal->signalType->getType() !== SignalTypeConstants::CONNECTION_CLOSE) {
            return;
        }

        $data = $signal->data;
        if (!$data instanceof WebSocketCloseSignalDTO || $data->acceptKey === '') {
            return;
        }

        $subscription = Hilos::$sr->pageSubscription($data->acceptKey);
        if ($subscription === null || $subscription->agentType === null) {
            return;
        }

        foreach ($agentsDelivered as $delivered) {
            if ($delivered->agentType === $subscription->agentType
                && $delivered->agentIndex === $subscription->agentIndex
            ) {
                return;
            }
        }

        $this->sendSignalToAgentDestination(
            $workerServer,
            new AgentDestination($subscription->agentType, $subscription->agentIndex),
            $signal,
        );
    }

    /**
     * Drops the subscription records a routed signal has ended.
     *
     * Runs after routing and not before it, which is the whole point: the record carries the
     * address the unsubscribe has to reach, and a record removed first takes that address with
     * it (HIL-627).
     *
     * @param SignalDTO $signal Signal just routed
     */
    private function forgetSubscriptionsAfterRouting(SignalDTO $signal): void
    {
        $data = $signal->data;

        if ($signal->signalType->getType() === SignalTypeConstants::PAGE_UNSUBSCRIBE
            && $data instanceof WebSocketPageUnsubscribeSignalDTO
        ) {
            Hilos::$sr->unsubscribeFromPage($signal->signalName->getName(), $data);

            return;
        }

        if ($signal->signalType->getType() === SignalTypeConstants::CONNECTION_CLOSE
            && $data instanceof WebSocketCloseSignalDTO
            && $data->acceptKey !== ''
        ) {
            Hilos::$sr->unsubscribeFromAll($data->acceptKey);
            $this->dropParkedSignals($data->acceptKey);
        }
    }

    /**
     * Forgets whatever this connection was waiting to have addressed.
     *
     * A subscribe held for an identity outlives the connection otherwise: it would sit out its
     * deadline, then bind a subscription record for a dead accept key and be delivered to an
     * agent that answers a socket nobody is listening on - and nothing would clean that record,
     * because the close that would have is already past. The worker's held frames are dropped
     * on the same event and for the same reason ({@see PageSignalRouter::dropPendingFrames}).
     *
     * @param string $acceptKey Connection that closed
     */
    private function dropParkedSignals(string $acceptKey): void
    {
        $this->parkedSignals = array_values(array_filter(
            $this->parkedSignals,
            static function (ParkedSignal $parked) use ($acceptKey): bool {
                $data = $parked->signal->data;

                return !$data instanceof WebSocketPageSubscribeSignalDTO || $data->acceptKey !== $acceptKey;
            },
        ));
    }

    /**
     * Holds a subscription signal whose address cannot be resolved until the identity arrives.
     *
     * Only a page whose instance IS the person behind the connection can be undecidable this
     * way, so every other signal passes straight through and nothing about their timing changes.
     * A held signal keeps its place ahead of the queue when it is released
     * ({@see releaseParkedSignals()}).
     *
     * @param SignalDTO $signal Signal about to be routed
     * @return bool Whether the signal was held instead of routed
     */
    private function parkUntilIdentified(SignalDTO $signal): bool
    {
        $signalType = $signal->signalType->getType();
        if ($signalType !== SignalTypeConstants::PAGE_SUBSCRIBE
            && $signalType !== SignalTypeConstants::PAGE_ACCESS_REASSESS
        ) {
            return false;
        }

        $data = $signal->data;
        if (!$data instanceof WebSocketPageSubscribeSignalDTO) {
            return false;
        }

        $page = $data->page ?? $signal->signalName->getName();
        $address = $this->resolvePageAgentAddress($page, $data->acceptKey, $data->params);
        if ($address === null || !$address->pending) {
            return false;
        }

        $this->parkedSignals[] = new ParkedSignal(
            $signal,
            microtime(true) + self::SUBSCRIPTION_IDENTITY_WAIT_TIMEOUT_MS / 1000,
        );

        return true;
    }

    /**
     * Returns the held signals that are ready to be routed, and keeps holding the rest.
     *
     * A signal is ready once the identity behind its connection has arrived, or once its
     * deadline has passed - the latter is routed on what is known, with a line saying so,
     * rather than held forever behind an answer that may never come.
     *
     * @return list<SignalDTO> Signals to route in this pass, in the order they were held
     */
    private function releaseParkedSignals(): array
    {
        if ($this->parkedSignals === []) {
            return [];
        }

        $now = microtime(true);
        $released = [];
        $stillParked = [];
        foreach ($this->parkedSignals as $parked) {
            $data = $parked->signal->data;
            if (!$data instanceof WebSocketPageSubscribeSignalDTO) {
                $released[] = $parked->signal;
                continue;
            }

            $pending = Hilos::$browser?->connectionIdentity($data->acceptKey)->pending ?? false;
            if ($pending && $parked->deadline > $now) {
                $stillParked[] = $parked;
                continue;
            }

            if ($pending) {
                Logger::error(
                    "Subscription of connection {$data->acceptKey} routed without its identity:"
                    . ' it did not arrive within ' . self::SUBSCRIPTION_IDENTITY_WAIT_TIMEOUT_MS . 'ms',
                );
            }

            $released[] = $parked->signal;
        }

        $this->parkedSignals = $stillParked;

        return $released;
    }

    /**
     * Sends one signal to one agent instance through the worker server.
     *
     * The single door to an agent from the master loop: the ordinary destination walk uses it,
     * and so do the three deliveries that must not go through the queue - the unsubscribe of a
     * replaced subscription, the one of a subscription that moved, and the connection-close
     * fan-out ({@see unsubscribeReplacedPageAgent()}, {@see fanOutConnectionCloseToPageAgents()}).
     *
     * @param WorkerServer $workerServer Worker server hosting the agents
     * @param AgentDestination $destination Agent instance to reach
     * @param SignalDTO $signal Signal to deliver
     * @return bool True when the daemon is shutting down and the signal was dropped instead of delivered
     * @throws AgentException When the agent cannot be reached and the daemon is not shutting down
     */
    private function sendSignalToAgentDestination(
        WorkerServer $workerServer,
        AgentDestination $destination,
        SignalDTO $signal,
    ): bool {
        $agentType = $destination->agentType;
        $agentIndex = $destination->agentIndex;
        // An agent destination names its agent type, so the id is always
        // built; there is nothing here for a fallback to stand in for.
        $agentId = $this->agentManagerDaemon->buildAgentId($agentType, $agentIndex);
        $agentLabel = $agentIndex !== null ? "{$agentType} (index: {$agentIndex})" : $agentType;
        $signalType = $signal->signalType->getType();
        $signalName = $signal->signalName->getName();

        try {
            $workerServer->sendSignalToAgent(
                $agentType,
                $agentIndex,
                new DaemonAgentMessageDTO(agentId: $agentId, signal: $signal),
            );
        } catch (NoSuitableWorkerException $e) {
            // During shutdown, workers may be unavailable - ignore this error
            if ($this->shouldExit) {
                Logger::info("Signal skipped during shutdown: {$signalType}/{$signalName}"
                    . " -> agent: {$agentLabel} - no suitable worker available");

                return true;
            }
            // Re-throw if not shutting down
            Logger::error("Failed to send signal: {$signalType}/{$signalName}"
                . " -> agent: {$agentLabel} - no suitable worker available");
            throw $e;
        }

        return false;
    }

    /**
     * Dispatches the per-iteration hook for the current node lifecycle phase.
     *
     * Reads the phase from the cluster context (Standalone when cluster mode is
     * off) and calls the matching hook. A masters-without-quorum node and a
     * master-that-is-not-leader both run {@see onTickNotLeaderMaster()}; in this
     * slice a clustered master is always the former until HIL-339 adds real
     * leadership.
     */
    private function dispatchRoleTick(): void
    {
        $this->runPhaseTick($this->resolveLifecycleState());
    }

    /**
     * Resolves the current node lifecycle phase, defaulting to Standalone.
     *
     * A cluster misconfiguration must not tear down the daemon loop, so any
     * failure reading the phase is logged and treated as Standalone (the same
     * defensive stance the peer transport takes on the registry).
     *
     * @return NodeLifecycleState Current lifecycle phase, or Standalone on failure
     */
    private function resolveLifecycleState(): NodeLifecycleState
    {
        try {
            return Hilos::$cluster?->lifecycleState() ?? NodeLifecycleState::Standalone;
        } catch (Throwable $e) {
            Logger::error("Cluster lifecycle state unavailable, falling back to standalone: {$e->getMessage()}");
            return NodeLifecycleState::Standalone;
        }
    }

    /**
     * Whether the local node currently holds cluster leadership.
     *
     * True for a standalone daemon (its own leader when cluster mode is off), true
     * for a clustered master that won the election, false for a follower or a master
     * without quorum. A failure reading the seam must not silence a single-node
     * daemon's duties, so it is treated as leader — the same defensive fallback
     * {@see resolveLifecycleState()} uses.
     *
     * @return bool True when the node is the leader, cluster mode is off, or leadership is unreadable
     */
    private function amLeader(): bool
    {
        try {
            return Hilos::$cluster?->amLeader() ?? true;
        } catch (Throwable $e) {
            Logger::error("Cluster leadership unavailable, assuming standalone leader: {$e->getMessage()}");
            return true;
        }
    }

    /**
     * Starts this node's cluster-singleton agents once per leadership term.
     *
     * The ensure-once for the "leader AND workers ready" start condition: called only
     * on the leader (or standalone) node, it fires {@see WorkerServer::onBecameSingletonHost()}
     * the first time workers are ready and remembers it in {@see $singletonsStarted}.
     * The two conditions may arrive in any order — a node promoted before its workers
     * register, or workers ready before promotion — and this covers both without a
     * per-tick liveness scan. {@see stopClusterSingletons()} clears the flag on
     * leadership loss so a later promotion re-runs the start.
     */
    private function ensureSingletonsStarted(): void
    {
        if ($this->singletonsStarted || !$this->workersReady) {
            return;
        }

        $workerServer = $this->findWorkerServer();
        if ($workerServer === null) {
            return;
        }

        $workerServer->onBecameSingletonHost();
        $this->singletonsStarted = true;
    }

    /**
     * Places every cluster-wide, unindexed agent the registry hands to the placement policy.
     *
     * The framework half of the two placement axes: an agent declared
     * {@see AgentScope::CLUSTER} with {@see AgentPlacement::POLICY} exists exactly once
     * cluster-wide, on the node best-fit picks, and nothing but this pass would ever ask for
     * it — the start gate refuses it everywhere else. Indexed pools are left alone: how many
     * members a pool has and under which indexes is known only to the project that declared
     * it.
     *
     * A reconciliation rather than an ensure-once, because {@see ClusterPlacement::placeAgentOnBestNode()}
     * places nothing and records nothing when no online node clears the hard gate; without a
     * per-tick re-check such an agent would never come up. A tracked record in any state
     * suppresses placement, so this never fights failover or double-places — except a record
     * left {@see PlacementState::Failed}, which nothing else retries and this pass re-places
     * once per {@see POLICY_PLACEMENT_RETRY_SEC}.
     *
     * With cluster mode off there is no placement view and no other node: the single node is
     * its own leader and its own data plane, so the same declaration lands the agent here,
     * through the same local start a remote placement ends in. Repeating that start is free
     * once the agent is linked to a worker, and it is throttled all the same so a node that
     * cannot host it yet does not say so every iteration of the loop.
     *
     * Runs on the master loop, so the whole read/write is guarded: a registry hiccup or a
     * rejected placement is logged, never propagated, and the next tick retries from where
     * this one stopped.
     */
    private function ensurePolicyAgentsPlaced(): void
    {
        try {
            $placement = Hilos::$cluster?->placement();
            if ($placement === null && !$this->workersReady) {
                return;
            }

            $now = microtime(true);
            $mayRetry = $now >= $this->policyPlacementRetryAt;
            if ($mayRetry) {
                $this->policyPlacementRetryAt = $now + self::POLICY_PLACEMENT_RETRY_SEC;
            }

            $tracked = [];
            foreach ($placement?->registry()->all() ?? [] as $record) {
                if ($record->agentIndex !== null) {
                    continue;
                }
                if ($mayRetry && $record->state === PlacementState::Failed) {
                    continue;
                }

                $tracked[$record->agentType] = true;
            }

            foreach (Hilos::appClass()::AGENTS as $agentType => $registryEntry) {
                if (
                    AgentRegistry::scope($registryEntry) !== AgentScope::CLUSTER
                    || AgentRegistry::placement($registryEntry) !== AgentPlacement::POLICY
                    || AgentRegistry::requiresIndex($registryEntry)
                    || isset($tracked[$agentType])
                ) {
                    continue;
                }

                if ($placement !== null) {
                    $placement->placeAgentOnBestNode($agentType, null);
                } elseif ($mayRetry) {
                    $this->findWorkerServer()?->executePlacement($agentType, null);
                }
            }
        } catch (Throwable $e) {
            Logger::warning("Could not place the policy-placed agents: {$e->getMessage()}");
        }
    }

    /**
     * Stops this node's cluster-singleton agents and re-arms the ensure-once.
     *
     * The reaction side of leadership loss: it fires {@see WorkerServer::onLostSingletonHost()}
     * to stop every leader-only agent, then clears {@see $singletonsStarted} so a later
     * promotion re-runs {@see ensureSingletonsStarted()} from scratch. No-op when no worker
     * server is registered. A truth source therefore never outlives the term it was
     * elected for.
     */
    private function stopClusterSingletons(): void
    {
        $this->findWorkerServer()?->onLostSingletonHost();
        $this->singletonsStarted = false;
    }

    /**
     * Whether cluster mode is enabled, defended against a misconfiguration.
     *
     * A failure reading the flag must not derail the shutdown sequence, so any error is
     * swallowed and treated as off — the graceful-leave work-stop simply does not fire.
     *
     * @return bool True when cluster mode is enabled
     */
    private function clusterEnabled(): bool
    {
        try {
            return Hilos::$cluster?->isEnabled() ?? false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return ?WorkerServer The registered worker server, or null when none is registered
     */
    private function findWorkerServer(): ?WorkerServer
    {
        return array_find($this->servers, fn($server) => $server instanceof WorkerServer);
    }

    /**
     * Routes one main-loop tick to the hook for the given lifecycle phase.
     *
     * @param NodeLifecycleState $state Lifecycle phase to dispatch
     */
    private function runPhaseTick(NodeLifecycleState $state): void
    {
        match ($state) {
            NodeLifecycleState::Standalone => $this->onTickStandalone(),
            NodeLifecycleState::MasterLeader => $this->onTickLeaderMaster(),
            NodeLifecycleState::MasterFollowerOrCandidate,
            NodeLifecycleState::MasterNoQuorum => $this->onTickNotLeaderMaster(),
            NodeLifecycleState::Slave => $this->onTickSlave(),
        };
    }

    /**
     * Per-iteration hook for a single-node (non-clustered) daemon.
     *
     * This is the app-specific daemon tick: child classes override it for their
     * own per-loop logic. Default implementation does nothing.
     */
    protected function onTickStandalone(): void
    {
        // Default: do nothing, child classes should override
    }

    /**
     * Per-iteration hook for a clustered master that holds leadership.
     *
     * A project override point for leader-only per-loop logic. The framework's own
     * leader duties (cluster-singleton start, cron, WebSocket readiness) are gated on
     * {@see amLeader()} in the main loop rather than here, so they also cover the
     * standalone phase; the default is a no-op.
     */
    protected function onTickLeaderMaster(): void
    {
        // Default: do nothing, child classes may override for leader-only per-loop logic
    }

    /**
     * Per-iteration hook for a clustered master that is not the leader.
     *
     * Covers both the no-quorum and follower/candidate phases. A no-op scaffold
     * for now; filled by later cluster slices.
     */
    protected function onTickNotLeaderMaster(): void
    {
        // Default: do nothing, filled by later cluster slices
    }

    /**
     * Per-iteration hook for a clustered slave (data-plane) node.
     *
     * A slave hosts the agents the leader places on it (HIL-179); that work is
     * event-driven — a placed agent launches when a place-agent frame arrives over the
     * peer channel, not on a timer — so there is no periodic slave work in this phase. The
     * base stays a no-op; a project overrides it for its own per-loop slave logic. Runs on
     * the daemon master loop, so an override must stay non-blocking.
     */
    protected function onTickSlave(): void
    {
        // Default: do nothing; placement execution is event-driven, not per-tick. Child classes may override.
    }

    /**
     * Hook called when a node joins the cluster mesh (or comes back online).
     *
     * Invoked by the cluster context after the peer transport merged the join into the
     * master registry. The framework default drives placement recovery: a returning node
     * cancels a pending failover for its agents, and a newly-capable node lets the leader
     * retry any agent that failover had left unplaced. A project may override to react, calling
     * parent::onNodeJoined() first. Runs on the daemon master loop, so it must stay
     * non-blocking.
     *
     * Handing that node this one's RT state is NOT done here, though a node that has just come
     * up needs it: a join says the node is a member, not that this node can reach it. See
     * {@see handOverRtSnapshots()}, which the transport calls off the handshake instead.
     *
     * @param ClusterNode $node Node that joined
     */
    public function onNodeJoined(ClusterNode $node): void
    {
        Hilos::$cluster?->placement()?->noteNodeOnline($node->nodeId, microtime(true));
    }

    /**
     * Hook called when a node leaves the cluster mesh (goes offline).
     *
     * Invoked by the cluster context after the peer transport marked the node offline in the
     * master registry. The framework default drives placement failover: the leader arms
     * re-placement of the agents the node hosted, and a node isolated from its placing leader
     * arms its self-fence (both after their grace). A project may override to react, calling
     * parent::onNodeLeft() first. Runs on the daemon master loop, so it must stay
     * non-blocking.
     *
     * @param ClusterNode $node Node that left
     */
    public function onNodeLeft(ClusterNode $node): void
    {
        Hilos::$cluster?->placement()?->noteNodeOffline($node->nodeId, microtime(true));
        // A node that left mid-round is taken off the re-hydrate barrier for the same reason a
        // dead worker is: it cannot answer, and whatever rejoins reads the database already in
        // place. Waiting would spend the whole deadline on a node that is gone (HIL-436).
        $this->agentManagerDaemon->dropReHydrateParticipant(ReHydrateRound::nodeParticipant($node->nodeId));
        // The browser connections that node held go with it (HIL-668). Driven by membership
        // rather than by the link dropping, because a dropped link is re-dialed and forgetting
        // its clients meanwhile would blind this node to every browser attached there.
        Hilos::$cluster?->clientConnections()?->forgetNode($node->nodeId);
    }

    /**
     * Hook called when this node wins cluster leadership for a term.
     *
     * Fired by the consensus coordinator once a majority of the master set elected
     * this node. The framework default rebuilds the leader-side placement view from the
     * mesh (placement tracking is soft-state). A project may override to take up its own
     * singleton duties, calling parent::onBecameLeader() first. Runs on the daemon master
     * loop, so it must stay non-blocking.
     *
     * @param int $term Election term in which leadership was won
     * @throws HilosException Whatever the project's own leadership duties raise
     */
    public function onBecameLeader(int $term): void
    {
        // Placement tracking is soft-state: a fresh leader rebuilds its view from the mesh.
        Hilos::$cluster?->placement()?->onBecameLeader();

        // The new leader takes up the protected-mode freeze orchestration.
        Hilos::$cluster?->protectedModeLeadership()?->onBecameLeader();
    }

    /**
     * Hook called when this node loses cluster leadership.
     *
     * Fired when the coordinator steps down — on a newer observed term or on losing
     * quorum (anti-split-brain). Narrow and ex-leader only. The framework default
     * relinquishes the singleton duties this node held as leader: it stops the
     * cluster-singleton agents and resets the ensure-once so a later promotion re-runs
     * the start (the mirror of {@see WorkerServer::onBecameSingletonHost()}), and drops the
     * leader-side placement view (the next leader rebuilds it from the mesh). Only the agents
     * declared {@see AgentScope::CLUSTER} with {@see AgentPlacement::LEADER} are stopped: a
     * policy-placed one lives on the node the policy picked and outlives the term, and a
     * replica was never tied to it. A project
     * may override to add its own teardown, calling parent::onLostLeadership() first.
     * Runs on the daemon master loop, so overrides must stay non-blocking.
     *
     * @param int $term Election term in which leadership was held and then lost
     */
    public function onLostLeadership(int $term): void
    {
        $this->stopClusterSingletons();

        // Drop the leader-side placement view; the placed agents keep running (data-plane)
        // and the next leader rebuilds the view from the mesh.
        Hilos::$cluster?->placement()?->onLostLeadership();

        // Drop any protected-mode freeze this node was orchestrating as leader; the follower-side
        // state stays, so a freeze the new leader ordered against this node is still honoured.
        Hilos::$cluster?->protectedModeLeadership()?->onLostLeadership();
    }

    /**
     * Hook called when this node gains a quorum of the master set.
     *
     * Default implementation does nothing; child classes override to react. Runs on
     * the daemon master loop, so it must stay non-blocking.
     */
    public function onQuorumGained(): void
    {
        // Default: do nothing, child classes may override
    }

    /**
     * Hook called when this node loses its quorum of the master set.
     *
     * Fired on every node of a minority partition (leader and followers alike). The
     * framework default fires the broad {@see onClusterWorkStop()} directive: without a
     * quorum a node must halt all in-flight business work immediately until quorum
     * returns, so a split cluster never runs the same work on both sides. A project may
     * override to add its own reaction, calling parent::onQuorumLost() first. Runs on the
     * daemon master loop, so overrides must stay non-blocking.
     */
    public function onQuorumLost(): void
    {
        $this->onClusterWorkStop();
    }

    /**
     * Hook called when this node must stop all in-flight cluster business work.
     *
     * The broad, cause-agnostic project directive to halt and (if it wishes) persist
     * business work: fired on quorum loss (every node of a minority partition) and on a
     * planned graceful-leave, before the node departs. Distinct from
     * {@see onLostLeadership()}, which is the ex-leader's narrow singleton teardown — a
     * follower loses no leadership yet must still stop working. The framework only
     * delivers this event; persisting and resurrecting the work is project code, which
     * resumes through the existing {@see onQuorumGained()} / {@see onBecameLeader()}
     * hooks and the slave work-grant. Default is a no-op. May fire more than once
     * (quorum lost then shutdown), so an override must be idempotent, and it runs on the
     * daemon master loop, so it must stay non-blocking.
     */
    public function onClusterWorkStop(): void
    {
        // Default: do nothing, child classes may override to persist and stop their work
    }

    /**
     * Hook called when failover could not re-place an orphaned agent on any capable node.
     *
     * Fired on the leader when a node hosting a placed agent went down and no other
     * capable+online node was available, so the agent is degraded to unplaced. The framework
     * only reports it and retries automatically once a capable node joins; a project overrides
     * to alert or shed dependent work. Default is a no-op. Runs on the daemon master loop, so
     * an override must stay non-blocking.
     *
     * @param string $agentType Agent type left unplaced
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    public function onPlacementDegraded(string $agentType, ?string $agentIndex): void
    {
        // Default: do nothing, child classes may override
    }

    /**
     * Called when a cron job should be executed
     *
     * Default implementation queues a DAEMON/CRON signal for routing via SignalRouter.
     * Override only when cron work must run directly in the daemon process.
     *
     * @param CronRule $rule Cron rule that should be executed
     * @throws InvalidArgumentException When the cron rule carries an empty name
     */
    protected function onCron(CronRule $rule): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::CRON),
            new SignalName($rule->name),
            new CronSignalDTO(cronName: $rule->name),
        );
    }

    /**
     * Add cron rule.
     *
     * @param string $name Cron job name (unique identifier)
     * @param string $expression Cron expression (minute hour day month weekday), e.g., "*\/5 * * * *"
     */
    protected function addCronRule(string $name, string $expression): void
    {
        $this->cronRules[$name] = new CronRule($name, $expression);
    }

    /**
     * Update cron rule expression.
     *
     * @param string $name Cron job name
     * @param string $expression New cron expression (minute hour day month weekday)
     * @return bool True if rule was found and updated
     */
    protected function updateCronRule(string $name, string $expression): bool
    {
        if (!isset($this->cronRules[$name])) {
            return false;
        }

        $this->cronRules[$name]->expression = $expression;
        return true;
    }

    /**
     * Remove cron rule.
     *
     * @param string $name Cron job name
     * @return bool True if rule was found and removed
     */
    protected function removeCronRule(string $name): bool
    {
        if (!isset($this->cronRules[$name])) {
            return false;
        }

        unset($this->cronRules[$name]);
        return true;
    }

    /**
     * Get all cron rules.
     *
     * @return array<string, CronRule> Cron rules by name
     */
    public function getCronRules(): array
    {
        return $this->cronRules;
    }

    /**
     * Registers a daemon cron rule for each daemon-mechanism backup schedule entry.
     *
     * The framework owns backup scheduling: the daemon-mechanism entries of the project backup
     * schedule ({@see BackupSchedule}) become daemon cron rules here, so when one fires
     * {@see onCron()} routes its named DAEMON/CRON signal to the backup agent. Gated on
     * {@see EnvConstants::BACKUP_ENABLED} so a disabled subsystem registers nothing; the default
     * schedule is entirely agent-mechanism, so a project that opts into no daemon entries also
     * registers nothing. Delivering the named signal to the backup agent is the project's
     * routing concern (schedule-name ownership via topology, or a forward from its default cron
     * owner).
     *
     * @throws BackupScheduleException When the project backup schedule is malformed
     */
    private function registerBackupCronRules(): void
    {
        if (!Hilos::$env->bool(EnvConstants::BACKUP_ENABLED)) {
            return;
        }

        foreach (BackupSchedule::fromCatalog()->daemonEntries() as $entry) {
            $this->addCronRule($entry->name, $entry->expression);
        }
    }

    /**
     * Registers the daemon master as the non-agent truth source for the protected-mode
     * runtime singleton, on every process that carries runtime state.
     *
     * Protected mode is a daemon-owned framework singleton: the leader master writes the
     * freeze row by its own decision and each follower master writes it in reaction to the
     * peer QUIESCE/LIFT frames, so no owner agent stands behind the write. The RT write-guard
     * accepts such an agent-less writer only for a collection-wide source, so every node's
     * master registers one here (this runs on the master, so the guard checks against this
     * process's registry). The early return is not a project opting out - the framework mounts
     * the row for every project that has an RT context, so it means Hilos::$rt is null and there
     * is no runtime state in this process to own.
     */
    private function registerProtectedModeTruthSource(): void
    {
        if (Hilos::$rt?->hilosProtectedModeRuntime === null) {
            return;
        }

        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
    }

    /**
     * Puts back the freeze this node went down under, before a single socket is bound.
     *
     * Runtime state is memory only, so without this a daemon restarted in the middle of a restore
     * comes back open and serves clients over a half-written database - the one failure protected
     * mode exists to prevent, reached by the mundane route of a restart. The read happens here, at
     * the end of composition and before {@see run()} starts any server, so the first handshake after
     * the restart is already locked out. It has to run after
     * {@see registerProtectedModeTruthSource()}: the write it makes is refused until this master is
     * the row's registered writer.
     *
     * The node comes back frozen with nothing running behind it, which is exactly what
     * {@see ProtectedModeWatchdog} reports on its first tick - so the operator hears about it
     * through the one channel that already exists, and the way out stays the operator ladder.
     *
     * A file that cannot be read refuses the startup rather than degrading to "no freeze": it is
     * there because this node was frozen, and guessing the other way opens it.
     *
     * @throws EnvException When the daemon log path the freeze is stored beside cannot be read
     * @throws ProtectedModeFreezeUnreadableException When a freeze was left on disk and cannot be read
     * @throws RtActionsCollectionNameNullException When the freeze row has no collection name to sync under
     * @throws RtTruthSourceWriteNotAllowedException When this master may not write the freeze row
     */
    private function restoreProtectedModeFreeze(): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            return;
        }

        $row = new ProtectedModeFreezeStore()->load();
        if ($row === null || $row->phase === StateProtectedModeRuntime::PHASE_INACTIVE) {
            return;
        }

        $view->actions->restoreFromDisk($row);
        $this->protectedModeWatchdog->markRestoredFromDisk();

        Logger::warning(
            "Protected mode: this node came up still frozen for '"
            . ($row->operation ?? self::UNNAMED_FROZEN_OPERATION)
            . "' on phase '{$row->phase}'; the operation behind it did not survive the restart",
        );
    }

    /**
     * Registers the daemon master as a truth source for the pending token rotations, so it
     * may burn the row whose ticket a handshake just traded (HIL-582).
     *
     * The one runtime collection with two writers, and the split is by act rather than by
     * row: the agent owning the session seam announces a rotation from a worker, and the
     * master ends it on the 101 that spends its ticket. Neither can do the other's half -
     * the announcement needs the session ORM the master must not touch, and the burn has to
     * happen in the same breath as the Set-Cookie, or a ticket would buy a second handshake.
     * Both registrations are process-local, so this one covers the master alone and does not
     * hand any worker a licence to write.
     *
     * The early return means this process carries no runtime state at all - the framework
     * mounts the collection for every project that has an RT context - and a project whose
     * agent never claims the store simply never has a row here to burn.
     */
    private function registerSessionRotationTruthSource(): void
    {
        if (Hilos::$rt === null) {
            return;
        }

        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
    }

    /**
     * Names the one runtime collection every node's master writes beside its owning agent.
     *
     * The two registrations above are what the node-level ownership map cannot see: it is built
     * from agent reports, and these two writers are masters. They are opposites, which is why the
     * exception is this narrow. The protected-mode freeze row is node-local by design - each
     * master writes its own - so it is nobody's to announce and is refused as any other write of
     * a collection this node writes itself. The rotation store is the opposite: one cluster-wide
     * store, written by the agent that owns the session seam and by whichever master trades a
     * ticket on a 101 (HIL-582). That second writer is a master, on any node, so the burn has to
     * travel and has to be applied where the agent lives - or the spent ticket survives there and
     * buys a second handshake inside its TTL.
     *
     * @param string $collectionKey Runtime collection a write belongs to
     * @return bool True for the collection masters co-write with its owner
     */
    private static function isMasterCoWritten(string $collectionKey): bool
    {
        return $collectionKey === StateHilosSessionRotation::RT_COLLECTION;
    }

    /**
     * Names the runtime collections a node hands over when another node links to it.
     *
     * The rotation store is the one that is not, and its reason is its own (HIL-589): a ticket
     * lives for seconds and is spent once, so a node that just came up has no use for the ones
     * outstanding before it existed - the deltas bring it whatever is issued from now on, which
     * is everything it can ever trade. Handing the store over would replace a live store with a
     * list of tickets about to expire, for no reader.
     *
     * A separate predicate from {@see isMasterCoWritten()}, which names the same collection
     * today. They answer different questions - who may write it, and what is offered on a new
     * link - and a single predicate serving both would answer one of them wrongly for the very
     * next store that needs only one of the two. If a third such list appears, that is the
     * signal to stop naming stores here and give the rows tombstones instead.
     *
     * @param string $collectionKey Runtime collection a hand-over would carry
     * @return bool True when a node linking to this one is offered the collection
     */
    private static function isHandedOverInSnapshot(string $collectionKey): bool
    {
        return $collectionKey !== StateHilosSessionRotation::RT_COLLECTION;
    }

    /**
     * Tells whether a write to a runtime collection is this node's to announce to the mesh.
     *
     * Announcing is a claim of ownership, and this node may make it for what its own agents
     * registered - {@see RtNodeSourceMap} is that list - plus the store every master co-writes.
     * Everything else it holds is somebody else's, kept current by that owner's announcements:
     * passing such a write on would make this node a second source of it, which is the split the
     * receiving side warns about rather than an update.
     *
     * @param string $collectionKey Runtime collection the write belongs to
     * @return bool True when the mesh should hear about the write
     */
    private function announcesRtCollection(string $collectionKey): bool
    {
        return $this->agentManagerDaemon->rtNodeSourceMap()->owns($collectionKey)
            || self::isMasterCoWritten($collectionKey);
    }

    /**
     * Check cron jobs and execute if needed.
     *
     * Checks all cron rules and executes jobs that are due.
     * This method is called on each loop iteration but only
     * performs actual checks once per minute for performance.
     * Optimized: first checks if minute changed, only then checks rules.
     * Cron jobs are only executed after workers are ready.
     * Checks happen at the start of each minute for precise timing.
     */
    private function checkCronJobs(): void
    {
        // Don't run cron jobs until workers are ready
        if (!$this->workersReady) {
            return;
        }

        // Lightweight check: only check cron rules when minute changes
        // Use minute-level timestamp for precise minute-level comparison
        $currentMinuteTimestamp = (int)floor(time() / 60);

        // Early return if still in the same minute - no need to check rules
        if ($this->lastCronCheckMinute === $currentMinuteTimestamp) {
            return;
        }

        $this->lastCronCheckMinute = $currentMinuteTimestamp;

        // Check each cron rule when minute changed
        foreach ($this->cronRules as $rule) {
            if ($rule->shouldRun()) {
                $this->onCron($rule);
            }
        }
    }

    /**
     * Returns manager name for logging.
     *
     * @return string Manager name for logging.
     */
    protected function getManagerName(): string
    {
        return "Daemon";
    }

    /**
     * Logs error message.
     *
     * @param string $message Error message to log.
     */
    protected function logError(string $message): void
    {
        Logger::error($message);
    }

    /**
     * Logs exception message.
     *
     * @param string $message Exception message to log.
     */
    protected function logException(string $message): void
    {
        Logger::error($message);
    }

    /**
     * Logs shutdown message.
     *
     * @param string $message Shutdown message to log.
     */
    protected function logShutdown(string $message): void
    {
        Logger::error($message);
    }

    /**
     * Answers a failure the master contained, for the project to react to.
     *
     * Empty by default: a node that swallows a bad connection and keeps serving is the
     * framework's behaviour, and this is where a project adds its own on top of it. The
     * units that reach here are the master's ({@see MasterFailureUnit}) - a connection,
     * an accept, an iteration of the loop, and the hook itself.
     *
     * Called AFTER the journal line and never instead of it. The record of a contained
     * failure is not overridable, because a guard whose record a project can replace is
     * the silent place the guard was built to prevent.
     *
     * Called on EVERY contained failure, not in step with the journal's rate limiter.
     * The limiter keeps a storm out of the log; a project counting failures needs the
     * storm counted honestly, since the storm is the thing worth reacting to. In one it
     * is called hundreds of times a minute.
     *
     * It must not throw - a failure raised here is written as the hook's own and the
     * hook is not called with it again ({@see reportContainedFailure()}).
     *
     * It runs on the master loop, which serves every connection of this node, so it does
     * no database, no file, no network and no waiting: nothing costlier than a line or a
     * counter. Anything above that leaves through {@see MasterSignalSender} - a signal to
     * an agent or to the workers of this node, where blocking is allowed
     * (docs/agents/architecture/daemon-lifecycle.md).
     *
     * Not to be confused with {@see onException()}: that one answers a failure PHP could
     * not place anywhere and asks the process to leave, while this one is a report in the
     * other direction - the failure was caught and life goes on.
     *
     * @param ContainedFailure $failure Failure, the unit it belongs to and where it happened
     */
    protected function onContainedFailure(ContainedFailure $failure): void
    {
    }

    /**
     * Handles error event.
     *
     * Sets exit flag to stop daemon loop.
     */
    protected function onError(): void
    {
        $this->shouldExit = true;
    }

    /**
     * Handles exception event.
     *
     * Sets exit flag to stop daemon loop.
     */
    protected function onException(): void
    {
        $this->shouldExit = true;
    }

    /**
     * Handles shutdown event.
     *
     * Sets exit flag to stop daemon loop.
     */
    protected function onShutdown(): void
    {
        $this->shouldExit = true;
    }

    /**
     * Handles shutdown signal event.
     *
     * Daemon-specific shutdown logic (none needed).
     */
    protected function onShutdownSignal(): void
    {
        // Daemon-specific shutdown logic (none needed)
    }

    /**
     * Handles restart signal event.
     *
     * Daemon-specific restart logic (none needed).
     */
    protected function onRestartSignal(): void
    {
        // Daemon-specific restart logic (none needed)
    }
}
