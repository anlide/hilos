<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\API\Router\HttpRouter;
use Hilos\BaseDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
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
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncUpdatedMessageDTO;
use Hilos\Utils\Logger;

/**
 * DaemonManager - Abstract base class for daemon process management.
 *
 * Provides simple interface for creating daemons:
 * - run() - Main daemon startup method with epoll-based event loop
 * - onTick() - Abstract method called regularly (must be implemented in child classes)
 * - processEventLoop() - Handles epoll events for registered servers
 */
abstract class DaemonManager extends BaseManager
{
    /** @var ServerInterface[] Array of registered servers */
    protected array $servers = [];

    /** @var ?HttpRouter HTTP router instance */
    protected ?HttpRouter $httpRouter = null;

    /** @var EventLoop Event loop for epoll */
    protected EventLoop $eventLoop;

    /** @var AgentManagerDaemon Agent manager daemon instance */
    protected AgentManagerDaemon $agentManagerDaemon;

    /**
     * Get agent manager daemon instance.
     *
     * @return AgentManagerDaemon Agent manager daemon instance
     */
    public function getAgentManagerDaemon(): AgentManagerDaemon
    {
        return $this->agentManagerDaemon;
    }

    /** @var ?float Shutdown start time (null if not shutting down) */
    private ?float $shutdownStartTime = null;

    /** @var float Shutdown timeout in seconds */
    protected float $shutdownTimeout = 20.0;

    /** @var CronRule[] Array of cron rules */
    private array $cronRules = [];

    /** @var int Last cron check minute timestamp (minute-level, from floor(time() / 60)) */
    private int $lastCronCheckMinute = -1;

    /** @var bool Flag indicating if initial workers are ready */
    private bool $workersReady = false;

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
     * @return SignalRouter
     */
    abstract protected function createSignalRouter(): SignalRouter;

    /**
     * Create agent manager daemon instance.
     *
     * Must be implemented in child classes to create specific agent manager daemon.
     *
     * @return AgentManagerDaemon
     */
    abstract protected function createAgentManagerDaemon(): AgentManagerDaemon;

    /**
     * Run daemon - main method
     *
     * Starts the daemon main loop with error handling, signal processing
     * and precise timing control. Runs until shutdown signal is received
     * and all servers are ready to shutdown (or timeout expires).
     * @throws AgentDaemonCreationFailedException
     * @throws NoSuitableWorkerException
     */
    public function run(): void
    {
        // Initialize event loop
        $this->eventLoop = new EventLoop();

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

            // Check cron jobs (not more than once per minute)
            $this->checkCronJobs();

            // Dispatch accumulated signals
            $this->dispatchSignals();

            // Process signals
            pcntl_signal_dispatch();

            // Sleep for precise timing
            $this->sleepWithPreciseTiming($loopStartTime);
        }

        // Cleanup
        $this->eventLoop->cleanup();
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

        // Check if all servers are ready
        return array_any($this->servers, fn(ServerInterface $server) => !$server->isReadyToShutdown());

        // All servers ready, can exit
    }

    /**
     * Initiate shutdown sequence
     *
     * Called when shouldExit becomes true.
     * Prepares all servers for shutdown.
     */
    private function initiateShutdown(): void
    {
        Logger::debug("Shutdown initiated, preparing servers for graceful shutdown");

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
     * @throws AgentDaemonCreationFailedException
     * @throws NoSuitableWorkerException
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
            SignalTypeConstants::RT_SYNC_CREATED,
            SignalTypeConstants::RT_SYNC_UPDATED,
            SignalTypeConstants::RT_SYNC_DELETED,
        ];

        // Process signals one by one in while-do loop
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            Logger::debug('getNextQueuedSignal called for signal: ' . $signal->toJson());

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
                Logger::info("Workers ready - cron jobs are now enabled");

                // Start WebSocket server when workers are ready
                $this->startWebSocketServer();

                // Continue to allow signal to be routed if needed, but flag is already set
            }

            if (empty($destinations)) {
                // No destinations found, skip
                Logger::debug("No destinations found for signal: {$signalType}/{$signalName}");
                continue;
            }

            // Deliver signal to each destination
            Logger::debug("Found " . count($destinations) . " destination(s) for signal: {$signalType}/{$signalName}");
            $skipSignal = false;
            while (($destination = array_shift($destinations)) !== null) {
                if ($skipSignal) {
                    break;
                }

                $destinationType = $destination['type'] ?? 'agent';

                switch ($destinationType) {
                    case 'agent':
                        // Send signal to agent via worker server
                        $agentType = $destination['agentType'] ?? 'unknown';
                        $agentIndex = $destination['agentIndex'] ?? null;
                        $agentId = $agentIndex !== null ? $agentType . ':' . $agentIndex : $agentType;
                        $indexInfo = $agentIndex !== null ? " (index: {$agentIndex})" : '';
                        Logger::debug("Dispatching signal to agent: {$signalType}/{$signalName} -> agent: {$agentType}{$indexInfo}");

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
                                break;
                            }
                            // Re-throw if not shutting down
                            Logger::error("Failed to send signal: {$signalType}/{$signalName} -> agent: {$agentType}{$indexInfo} - no suitable worker available");
                            throw $e;
                        }
                        break;

                    case 'websocket':
                        // Send signal to WebSocket client
                        if ($webSocketServer === null) {
                            Logger::debug("No WebSocket server available for routing signal to client");
                            break;
                        }

                        $acceptKey = $destination['acceptKey'] ?? '';
                        if ($acceptKey === '') {
                            Logger::error("Accept key is missing in WebSocket destination");
                            break;
                        }

                        Logger::debug("Dispatching signal to websocket: {$signalType}/{$signalName} -> WebSocket acceptKey: {$acceptKey}");

                        $this->sendSignalToWebSocketClient($webSocketServer, $signal, $acceptKey);
                        break;

                    default:
                        // Unknown destination type, skip
                        Logger::error("Unknown destination type: {$destinationType} for signal: {$signalType}/{$signalName}");
                        break;
                }
            }
        }
    }

    /**
     * Send sync signal to all worker clients
     */
    private function sendSyncToWorkers(WorkerServer $workerServer, SignalDTO $signal): void
    {
        $signalName = $signal->signalName->getName();
        $signalData = $signal->data->toArray();

        $dto = match ($signalName) {
            SignalConstants::DB_SYNC_CREATED => new WorkerDbSyncCreatedMessageDTO($signalData),
            SignalConstants::DB_SYNC_UPDATED => new WorkerDbSyncUpdatedMessageDTO($signalData),
            SignalConstants::DB_SYNC_DELETED => new WorkerDbSyncDeletedMessageDTO($signalData),
            SignalConstants::RT_SYNC_CREATED => new WorkerRtSyncCreatedMessageDTO($signalData),
            SignalConstants::RT_SYNC_UPDATED => new WorkerRtSyncUpdatedMessageDTO($signalData),
            SignalConstants::RT_SYNC_DELETED => new WorkerRtSyncDeletedMessageDTO($signalData),
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
            SignalTypeConstants::DB_SYNC_CREATED => DbSyncApplicator::applyCreated($signal->data->toArray()),
            SignalTypeConstants::DB_SYNC_UPDATED => DbSyncApplicator::applyUpdated($signal->data->toArray()),
            SignalTypeConstants::DB_SYNC_DELETED => DbSyncApplicator::applyDeleted($signal->data->toArray()),
            SignalTypeConstants::RT_SYNC_CREATED => RtSyncApplicator::applyCreated($signal->data->toArray()),
            SignalTypeConstants::RT_SYNC_UPDATED => RtSyncApplicator::applyUpdated($signal->data->toArray()),
            SignalTypeConstants::RT_SYNC_DELETED => RtSyncApplicator::applyDeleted($signal->data->toArray()),
            default => null,
        };
    }

    /**
     * Send signal to WebSocket client
     *
     * Serializes signal data and sends it to specific WebSocket client.
     *
     * @param WebSocketServer $server WebSocket server
     * @param SignalDTO $signal Signal DTO
     * @param string $acceptKey Accept key
     */
    private function sendSignalToWebSocketClient(WebSocketServer $server, SignalDTO $signal, string $acceptKey): void
    {
        $signalName = $signal->signalName->getName();
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
            'type' => $signalName,
            'data' => $dataArray,
        ];

        $messageJson = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Send to specific client
        $this->sendToClient($server, $acceptKey, $messageJson);
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
                    Logger::debug("Sending message to acceptKey {$acceptKey}: {$message}");
                    $client->sendFrame($message);
                    return;
                } catch (\Throwable $e) {
                    Logger::error("Failed to send message to acceptKey {$acceptKey}: " . $e->getMessage());
                }
            }
        }

        Logger::debug("Accept key not found: {$acceptKey}");
    }

    /**
     * Send message to all clients
     *
     * @param WebSocketServer $server WebSocket server
     * @param string $message Message JSON
     * @param ?string $excludeAcceptKey Accept key to exclude (optional)
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
                break;

            case SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION:
                if (!($signal->data instanceof WebSocketPageUpdateSubscriptionSignalDTO)) {
                    return;
                }
                Hilos::$sr->updatePageSubscription($signal->data->page, $signal->data);
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
     * Must be implemented in child classes to define daemon-specific
     * work logic. Called on each loop iteration with precise timing.
     */
    abstract protected function onTick(): void;

    /**
     * Called when a cron job should be executed
     *
     * Child classes can override this method to handle cron job execution.
     * Default implementation does nothing.
     *
     * @param CronRule $rule Cron rule that should be executed
     */
    protected function onCron(CronRule $rule): void
    {
        // Default: do nothing, child classes should override
    }

    /**
     * Add cron rule
     *
     * @param string $name Cron job name (unique identifier)
     * @param string $expression Cron expression (minute hour day month weekday), e.g., "*\/5 * * * *"
     */
    protected function addCronRule(string $name, string $expression): void
    {
        $this->cronRules[$name] = new CronRule($name, $expression);
    }

    /**
     * Update cron rule expression
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
     * Remove cron rule
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
     * Get all cron rules
     *
     * @return CronRule[] Array of cron rules
     */
    public function getCronRules(): array
    {
        return $this->cronRules;
    }

    /**
     * Check cron jobs and execute if needed
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

    /** @return string Manager name for logging */
    protected function getManagerName(): string
    {
        return "Daemon";
    }

    /** @param string $message Error message to log */
    protected function logError(string $message): void
    {
        Logger::error($message);
    }

    /** @param string $message Exception message to log */
    protected function logException(string $message): void
    {
        Logger::error($message);
    }

    /** @param string $message Shutdown message to log */
    protected function logShutdown(string $message): void
    {
        Logger::error($message);
    }

    /** Handle error event - sets exit flag */
    protected function onError(): void
    {
        $this->shouldExit = true;
    }

    /** Handle exception event - sets exit flag */
    protected function onException(): void
    {
        $this->shouldExit = true;
    }

    /** Handle shutdown event - sets exit flag */
    protected function onShutdown(): void
    {
        $this->shouldExit = true;
    }

    /** Handle shutdown signal event - no additional logic needed */
    protected function onShutdownSignal(): void
    {
        // Daemon-specific shutdown logic (none needed)
    }

    /** Handle restart signal event - no additional logic needed */
    protected function onRestartSignal(): void
    {
        // Daemon-specific restart logic (none needed)
    }
}
