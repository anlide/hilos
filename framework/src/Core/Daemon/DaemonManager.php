<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Router\SignalRouter;
use Hilos\Exception\Worker\AgentDaemonCreationFailedException;
use Hilos\Socket\Server\ServerInterface;
use Hilos\Socket\Server\WorkerServer;
use Hilos\API\Router\HttpRouter;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Logging\Logger\Logger;

/**
 * DaemonManager - Abstract base class for daemon process management
 *
 * Provides simple interface for creating daemons:
 * - run() - Main daemon startup method with epoll-based event loop
 * - onTick() - Abstract method called regularly (must be implemented in child classes)
 * - processEventLoop() - Handles epoll events for registered servers
 *
 * @abstract
 */
abstract class DaemonManager extends BaseManager
{
    /** @var ServerInterface[] Array of registered servers */
    protected array $servers = [];

    /** @var ?HttpRouter HTTP router instance */
    protected ?HttpRouter $httpRouter = null;

    /** @var EventLoop Event loop for epoll */
    protected EventLoop $eventLoop;

    /** @var SignalRouter Signal router instance */
    protected SignalRouter $signalRouter;

    /** @var ?float Shutdown start time (null if not shutting down) */
    private ?float $shutdownStartTime = null;

    /** @var float Shutdown timeout in seconds */
    protected float $shutdownTimeout = 20.0;

    /**
     * Constructor
     *
     * Creates default signal router. Can be overridden in child classes
     * to create custom signal router.
     */
    public function __construct()
    {
        $this->signalRouter = new SignalRouter();
    }

    /**
     * Run daemon - main method
     *
     * Starts the daemon main loop with error handling, signal processing
     * and precise timing control. Runs until shutdown signal is received
     * and all servers are ready to shutdown (or timeout expires).
     * @throws AgentDaemonCreationFailedException
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
                $server->tick();
            }

            // Call tick method
            $this->onTick();

            // Dispatch accumulated signals
            $this->dispatchSignals();

            $this->sleepWithPreciseTiming($loopStartTime);

            // Process signals
            pcntl_signal_dispatch();
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
        return array_any($this->servers, fn($server) => !$server->isReadyToShutdown());

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
        Logger::info("Shutdown initiated, preparing servers for graceful shutdown");

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
     * Get signal router instance
     *
     * @return SignalRouter Signal router instance
     */
    public function getSignalRouter(): SignalRouter
    {
        return $this->signalRouter;
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
     * Start all registered servers
     *
     * Attempts to start servers that are not running and registers them in event loop.
     */
    protected function startServers(): void
    {
        foreach ($this->servers as $server) {
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
     * Called at the end of each loop iteration.
     * @throws AgentDaemonCreationFailedException
     */
    private function dispatchSignals(): void
    {
        // Get queued signals from router
        $queuedSignals = $this->signalRouter->getAndClearQueuedSignals();

        if (empty($queuedSignals)) {
            return;
        }

        // Find WorkerServer
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

        // Dispatch all queued signals
        foreach ($queuedSignals as $signal) {
            $route = $signal['route'];
            if ($route === null) {
                // No route found, skip
                continue;
            }

            // Send signal to agent via worker server
            $workerServer->sendSignalToAgent(
                $route['agentType'],
                $route['agentIndex'],
                [
                    'signalType' => $signal['signalType'],
                    'data' => $signal['dto']->toArray(),
                ]
            );
        }
    }

    /**
     * Tick method - called regularly in main loop
     *
     * Must be implemented in child classes to define daemon-specific
     * work logic. Called on each loop iteration with precise timing.
     */
    abstract protected function onTick(): void;

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
