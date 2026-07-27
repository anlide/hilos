<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\API\Router\HttpRouter;
use Hilos\Backup\BackupSchedule;
use Hilos\Backup\Exception\BackupScheduleException;
use Hilos\BaseDTO;
use Hilos\Cluster\AgentSignalSink;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\LeadershipObserver;
use Hilos\Cluster\MembershipObserver;
use Hilos\Cluster\NodeLifecycleState;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementObserver;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Daemon\Module\DaemonModule;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\MissingRequiredParameterException;
use Hilos\Core\Http\RootInfoHandler;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\AllClientsDestination;
use Hilos\Core\Router\Destination\CommandReplyDestination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\Destination\WebSocketDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketEnvelopeAware;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Database\DbSyncApplicator;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\ServerInterface;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncClearedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncUpdatedMessageDTO;
use Hilos\Utils\Logger;

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
abstract class DaemonManager extends BaseManager implements MembershipObserver, LeadershipObserver, PlacementObserver
{
    /** @var list<string> Anchor signal set plus the proc_* functions WorkerServer uses to spawn workers */
    private const array REQUIRED_FUNCTIONS = [
        'pcntl_signal',
        'pcntl_signal_dispatch',
        'proc_open',
        'proc_get_status',
        'proc_terminate',
    ];

    /** @var float Seconds between stuck-readiness log lines while the WebSocket server waits for startup agents */
    private const float READINESS_LOG_INTERVAL = 60.0;

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

    /** @var ?float microtime of the last stuck-readiness log line; null until the first one */
    private ?float $lastReadinessLogAt = null;

    /** @var ?float Seconds to wait for required agents before opening the WebSocket degraded; null = wait forever */
    protected ?float $readinessTimeout = null;

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
     * @throws MissingRequiredParameterException When required process functions are unavailable
     * @throws InvalidArgumentException When the required-function list is empty
     * @throws AgentException When routing a signal to its agent fails (no suitable
     *     worker, daemon creation, agent lookup, or worker-link failure)
     */
    public function run(): void
    {
        // Initialize event loop
        $this->eventLoop = new EventLoop();

        // Check the availability of required functions
        $this->checkRequiredFunctions(self::REQUIRED_FUNCTIONS);

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

        Logger::info("Daemon started with epoll");

        // Main loop
        while ($this->shouldContinueRunning()) {
            $loopStartTime = microtime(true);

            // If shutdown requested but not started yet, initiate shutdown
            if ($this->shouldExit && $this->shutdownStartTime === null) {
                $this->shutdownStartTime = microtime(true);
                $this->initiateShutdown();
            }

            // Process epoll events for all servers
            $this->processEventLoop();

            // Tick all servers (process clients)
            foreach ($this->servers as $server) {
                $server->onTick();
            }

            // Dispatch the per-iteration hook for the current node lifecycle phase
            $this->dispatchRoleTick();

            // Singleton duties run on exactly one node cluster-wide: the leader, or the
            // sole node when cluster mode is off. A follower starts no cluster-singleton
            // agents, runs no cron, and keeps its WebSocket closed until it is promoted.
            if ($this->amLeader()) {
                // Start this node's cluster-singleton agents once per leadership term
                $this->ensureSingletonsStarted();

                // Open the WebSocket server once the required startup agents are ready
                $this->tickReadiness();

                // Check cron jobs (not more than once per minute)
                $this->checkCronJobs();
            }

            // Dispatch accumulated signals
            $this->dispatchSignals();

            // Flush buffered analytics rows on schedule
            Hilos::$ac?->tick();

            // Process signals
            pcntl_signal_dispatch();

            // Sleep for precise timing
            $this->sleepWithPreciseTiming($loopStartTime);
        }

        // Cleanup
        $this->eventLoop->cleanup();
        Hilos::$ac?->shutdown();
        Logger::info("Daemon stopped");
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
     */
    private function initiateShutdown(): void
    {
        // Planned graceful-leave: fire the broad work-stop directive locally so the
        // project can persist and stop its in-flight work before the node departs the
        // cluster. The peer transport announces the departure with a NodeLeaving frame
        // in its own prepareShutdown(). Gated on cluster mode so a standalone daemon is
        // unchanged. Distinct from onLostLeadership: a leaving follower loses no
        // leadership yet must still halt business work.
        if ($this->clusterEnabled()) {
            $this->onClusterWorkStop();
        }

        // Tell all servers to prepare for shutdown
        foreach ($this->servers as $server) {
            $server->prepareShutdown();
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
        } catch (\Throwable $e) {
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
            // Don't rethrow - continue processing other connections
        }
    }

    /**
     * Register client socket in event loop
     *
     * @param ServerInterface $server Server instance
     * @param mixed $client Client instance
     */
    protected function registerClientSocket(ServerInterface $server, $client): void
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
     * @param mixed $client Client instance
     */
    protected function onClientRead(ServerInterface $server, $client): void
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
        } catch (\Throwable $e) {
            // Log exception and close client connection on error
            $this->logException(
                sprintf("Error in client read handler for %s: %s in %s:%d - %s",
                    $server->getServerName(),
                    get_class($e),
                    basename($e->getFile()),
                    $e->getLine(),
                    $e->getMessage()
                )
            );

            // Close and cleanup client on error
            // CRITICAL: Unregister from event loop BEFORE closing socket
            try {
                $socket = $client->getSocket();
                if ($socket !== null) {
                    $this->eventLoop->unregister($socket);
                }
                $client->close();
                $server->removeClient($client);
            } catch (\Throwable $cleanupError) {
                // Ignore errors during cleanup - socket may be already closed
            }
        }
    }

    /**
     * Process event loop for all registered servers
     *
     * Handles epoll events for all registered servers.
     * Automatically manages server lifecycle and client connections.
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
     */
    private function dispatchSignals(): void
    {
        // Find WorkerServer once
        $workerServer = null;
        foreach ($this->servers as $server) {
            if ($server instanceof WorkerServer) {
                $workerServer = $server;
                break;
            }
        }

        if ($workerServer === null) {
            // No WorkerServer available
            return;
        }

        // Find WebSocket server once (for WebSocket destinations)
        $webSocketServer = null;
        foreach ($this->servers as $server) {
            if ($server instanceof WebSocketServer) {
                $webSocketServer = $server;
                break;
            }
        }

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

        // Process signals one by one in while-do loop
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            // Update subscriptions BEFORE routing (routing may depend on current subscriptions)
            $this->updateSubscriptions($signal);

            if (in_array($signal->signalType->getType(), $syncTypes, true)) {
                $this->sendSyncToWorkers($workerServer, $signal);
                $this->handleDaemonSignal($signal);
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

            if (empty($destinations)) {
                // No destinations found, skip
                continue;
            }

            // Deliver signal to each destination
            $skipSignal = false;
            while (($destination = array_shift($destinations)) !== null) {
                if ($skipSignal) {
                    break;
                }

                if ($destination instanceof AgentDestination) {
                    // Send signal to agent via worker server
                    $agentType = $destination->agentType;
                    $agentIndex = $destination->agentIndex;
                    $agentId = $this->agentManagerDaemon->buildAgentId($agentType, $agentIndex) ?? '';
                    $indexInfo = $agentIndex !== null ? " (index: {$agentIndex})" : '';

                    // Wrap signal in DaemonAgentMessageDTO
                    $messageDto = new DaemonAgentMessageDTO(
                        agentId: $agentId,
                        signal: $signal,
                    );

                    try {
                        $workerServer->sendSignalToAgent(
                            $agentType,
                            $agentIndex,
                            $messageDto,
                        );
                    } catch (NoSuitableWorkerException $e) {
                        // During shutdown, workers may be unavailable - ignore this error
                        if ($this->shouldExit) {
                            Logger::info("Signal skipped during shutdown: {$signalType}/{$signalName} -> agent: {$agentType}{$indexInfo} - no suitable worker available");
                            $skipSignal = true;
                            continue;
                        }
                        // Re-throw if not shutting down
                        Logger::error("Failed to send signal: {$signalType}/{$signalName} -> agent: {$agentType}{$indexInfo} - no suitable worker available");
                        throw $e;
                    }
                } elseif ($destination instanceof RemoteAgentDestination) {
                    // Forward the signal to the agent on its host node over the peer channel.
                    // Best-effort: no live link (offline node / no peer server) drops and logs,
                    // matching the local path that skips when no worker hosts the agent. Durable
                    // delivery to an offline node is out of scope.
                    if ($peerServer === null) {
                        Logger::error("Peer signal dropped: {$signalType}/{$signalName} -> node {$destination->nodeId} agent {$destination->agentType} - no peer server");
                        continue;
                    }

                    $delivered = $peerServer->sendSignalToNode(
                        $destination->nodeId,
                        $destination->agentType,
                        $destination->agentIndex,
                        $signal,
                    );
                    if (!$delivered) {
                        Logger::warning("Peer signal dropped: {$signalType}/{$signalName} -> node {$destination->nodeId} agent {$destination->agentType} - no live link");
                    }
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

                    $this->sendToAllClients(
                        $webSocketServer,
                        $this->encodeSignalFrame($signal),
                        $destination->excludeAcceptKey,
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
        }
    }

    /**
     * Send sync signal to all worker clients.
     *
     * @param WorkerServer $workerServer Worker server instance
     * @param SignalDTO $signal Signal DTO to send
     */
    private function sendSyncToWorkers(WorkerServer $workerServer, SignalDTO $signal): void
    {
        $signalName = $signal->signalName->getName();

        $dto = match ($signalName) {
            SignalConstants::DB_SYNC_CREATED => new WorkerDbSyncCreatedMessageDTO(
                self::syncSignalData($signal->data, DbSyncCreatedSignalData::class),
            ),
            SignalConstants::DB_SYNC_UPDATED => new WorkerDbSyncUpdatedMessageDTO(
                self::syncSignalData($signal->data, DbSyncUpdatedSignalData::class),
            ),
            SignalConstants::DB_SYNC_DELETED => new WorkerDbSyncDeletedMessageDTO(
                self::syncSignalData($signal->data, DbSyncDeletedSignalData::class),
            ),
            SignalConstants::DB_SYNC_CLEARED => new WorkerDbSyncClearedMessageDTO(
                self::syncSignalData($signal->data, DbSyncClearedSignalData::class),
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

        foreach ($workerServer->getClients() as $client) {
            if ($client instanceof WorkerClient) {
                $client->send($dto->toJson());
            }
        }
    }

    /**
     * Handle daemon-internal signal (e.g. start/stop agents, DB/RT sync)
     *
     * Default dispatches DB/RT sync and the DB re-hydrate signal to their apply methods.
     *
     * @param SignalDTO $signal Signal DTO
     */
    protected function handleDaemonSignal(SignalDTO $signal): void
    {
        $signalType = $signal->signalType->getType();
        match ($signalType) {
            SignalTypeConstants::DB_SYNC_CREATED => DbSyncApplicator::applyCreated(
                self::syncSignalData($signal->data, DbSyncCreatedSignalData::class),
            ),
            SignalTypeConstants::DB_SYNC_UPDATED => DbSyncApplicator::applyUpdated(
                self::syncSignalData($signal->data, DbSyncUpdatedSignalData::class),
            ),
            SignalTypeConstants::DB_SYNC_DELETED => DbSyncApplicator::applyDeleted(
                self::syncSignalData($signal->data, DbSyncDeletedSignalData::class),
            ),
            SignalTypeConstants::DB_SYNC_CLEARED => DbSyncApplicator::applyCleared(
                self::syncSignalData($signal->data, DbSyncClearedSignalData::class),
            ),
            SignalTypeConstants::DB_REHYDRATE => DbSyncApplicator::applyReHydrate(),
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
        $this->sendToClient($server, $acceptKey, $this->encodeSignalFrame($signal));
    }

    /**
     * Serialize a signal into the outgoing WebSocket frame JSON.
     *
     * Unwraps WebSocketSignalData targeting metadata down to the inner payload,
     * then builds the type/data frame with optional envelope metadata. Shared by
     * single-client and all-clients delivery so both send an identical frame.
     *
     * @param SignalDTO $signal Signal DTO
     * @return string Frame JSON ready for sendFrame(), or '' if encoding fails
     */
    private function encodeSignalFrame(SignalDTO $signal): string
    {
        $signalData = $signal->data;

        // Extract inner data from WebSocketSignalData if present
        $innerData = $signalData instanceof WebSocketSignalData
            ? $signalData->data
            : $signalData;

        // Serialize signal data for sending
        $dataArray = $innerData instanceof BaseDTO
            ? $innerData->toArray()
            : [];

        $message = [
            'type' => $signal->signalName->getName(),
            'data' => $dataArray,
        ];
        $this->mergeEnvelopeMetadata($message, $innerData);

        $messageJson = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $messageJson !== false ? $messageJson : '';
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
                } catch (\Throwable $e) {
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
                } catch (\Throwable $e) {
                    Logger::error("Failed to send message to acceptKey {$client->acceptKey}: " . $e->getMessage());
                }
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
                } catch (\Throwable $e) {
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
     * @param SignalDTO $signal Signal DTO
     */
    private function updateSubscriptions(SignalDTO $signal): void
    {
        $signalType = $signal->signalType->getType();
        $signalName = $signal->signalName->getName();

        switch ($signalType) {
            case SignalTypeConstants::PAGE_SUBSCRIBE:
                if (!($signal->data instanceof WebSocketPageSubscribeSignalDTO)) {
                    return;
                }
                Hilos::$sr->subscribeToPage($signal->data->page, $signal->data);
                Hilos::$ac?->openPageSession($signal->data->acceptKey, $signal->data->page, $signal->data->params);
                break;

            case SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION:
                if (!($signal->data instanceof WebSocketPageUpdateSubscriptionSignalDTO)) {
                    return;
                }
                Hilos::$sr->updatePageSubscription($signal->data->page, $signal->data);
                Hilos::$ac?->updatePageSession($signal->data->acceptKey, $signal->data->params);
                break;

            case SignalTypeConstants::PAGE_UNSUBSCRIBE:
                if (!($signal->data instanceof WebSocketPageUnsubscribeSignalDTO)) {
                    return;
                }
                $page = $signal->signalName->getName();
                if ($page === '') {
                    return;
                }
                Hilos::$sr->unsubscribeFromPage($page, $signal->data);
                Hilos::$ac?->closePageSession($signal->data->acceptKey);
                break;

            case SignalTypeConstants::GROUP_SUBSCRIBE:
                if (!($signal->data instanceof WebSocketGroupSubscribeSignalDTO)) {
                    return;
                }
                Hilos::$sr->subscribeToGroup($signal->data->group, $signal->data);
                break;

            case SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION:
                if (!($signal->data instanceof WebSocketGroupUpdateSubscriptionSignalDTO)) {
                    return;
                }
                Hilos::$sr->updateGroupSubscription($signal->data->group, $signal->data);
                break;

            case SignalTypeConstants::GROUP_UNSUBSCRIBE:
                if (!($signal->data instanceof WebSocketGroupUnsubscribeSignalDTO)) {
                    return;
                }
                Hilos::$sr->unsubscribeFromGroup($signal->data->group, $signal->data);
                break;
        }
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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return ?WorkerServer The registered worker server, or null when none is registered
     */
    private function findWorkerServer(): ?WorkerServer
    {
        foreach ($this->servers as $server) {
            if ($server instanceof WorkerServer) {
                return $server;
            }
        }

        return null;
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
     * retry any agent that failover had left unplaced. A project may override to react,
     * calling parent::onNodeJoined() first. Runs on the daemon master loop, so it must stay
     * non-blocking.
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
     */
    public function onBecameLeader(int $term): void
    {
        // Placement tracking is soft-state: a fresh leader rebuilds its view from the mesh.
        Hilos::$cluster?->placement()?->onBecameLeader();
    }

    /**
     * Hook called when this node loses cluster leadership.
     *
     * Fired when the coordinator steps down — on a newer observed term or on losing
     * quorum (anti-split-brain). Narrow and ex-leader only. The framework default
     * relinquishes the singleton duties this node held as leader: it stops the
     * cluster-singleton agents and resets the ensure-once so a later promotion re-runs
     * the start (the mirror of {@see WorkerServer::onBecameSingletonHost()}), and drops the
     * leader-side placement view (the next leader rebuilds it from the mesh). A project
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
     * runtime singleton, when the project mounts it.
     *
     * Protected mode is a daemon-owned framework singleton: the leader master writes the
     * freeze row by its own decision and each follower master writes it in reaction to the
     * peer QUIESCE/LIFT frames, so no owner agent stands behind the write. The RT write-guard
     * accepts such an agent-less writer only for a collection-wide source, so every node's
     * master registers one here (this runs on the master, so the guard checks against this
     * process's registry). A project that does not mount the runtime item in its RT context
     * opts out and registers nothing.
     */
    private function registerProtectedModeTruthSource(): void
    {
        if (Hilos::$rt?->getStateItem(ProtectedModeRuntime::RT_ITEM) === null) {
            return;
        }

        RtTruthSourceRegistry::registerDaemon(ProtectedModeRuntime::RT_ITEM);
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
