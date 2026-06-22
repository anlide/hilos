<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\API\Router\HttpRouter;
use Hilos\BaseDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\MissingRequiredParameterException;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\AllClientsDestination;
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
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\Client\WebSocketClient;
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
 * createSignalRouter() and createAgentManagerDaemon(); onTick() is an optional
 * per-iteration hook that does nothing by default.
 */
abstract class DaemonManager extends BaseManager
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

            // Call tick method
            $this->onTick();

            // Open the WebSocket server once the required startup agents are ready
            $this->tickReadiness();

            // Check cron jobs (not more than once per minute)
            $this->checkCronJobs();

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

        // Sync signals: always send to workers and daemon
        $syncTypes = [
            SignalTypeConstants::DB_SYNC_CREATED,
            SignalTypeConstants::DB_SYNC_UPDATED,
            SignalTypeConstants::DB_SYNC_DELETED,
            SignalTypeConstants::DB_SYNC_CLEARED,
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
     * Default dispatches DB/RT sync to 6 apply methods.
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
     * Tick method - called regularly in main loop
     *
     * Child classes can override this method for app-specific daemon logic.
     * Default implementation does nothing.
     */
    protected function onTick(): void
    {
        // Default: do nothing, child classes should override
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
