<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Agent\AgentInterface;
use Hilos\Exception\MissingEnvironmentVariableException;
use Hilos\Exception\SocketException;
use Hilos\Exception\Worker\AgentCreationFailedException;
use Hilos\Logging\Logger\Logger;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Utils\Helpers\ArgumentHelper;

/**
 * WorkerManager - Base worker process manager
 *
 * Main loop for worker processes. Extends BaseManager for error handling,
 * signal management and logging infrastructure.
 * Manages daemon connection and agents.
 *
 * @abstract
 */
abstract class WorkerManager extends BaseManager
{
    /** @var int Worker index */
    protected int $workerIndex;

    /** @var bool Whether this worker is monopolistic */
    protected bool $isMonopolistic;

    /** @var ?WorkerDaemonClient Client connection to daemon */
    private ?WorkerDaemonClient $daemonClient = null;

    /** @var AgentInterface[] Active agents indexed by agent ID */
    protected array $agents = [];

    /**
     * WorkerManager constructor
     *
     * @param int $workerIndex Worker index
     * @param array<string> $argv Command line arguments
     */
    public function __construct(int $workerIndex, array $argv = [])
    {
        $this->workerIndex = $workerIndex;
        $this->isMonopolistic = ArgumentHelper::isMonopolistic($argv);
    }

    /**
     * Run worker - main method
     *
     * Starts the worker main loop with error handling and signal processing.
     * Connects to daemon asynchronously and runs until shutdown signal is received.
     * Worker tick() is only called when connection to daemon is established.
     */
    public function run(): void
    {
        // Setup error handling and signal handlers
        $this->setupErrorHandling();
        $this->setupSignalHandlers();

        Logger::info("Worker #{$this->workerIndex} started");

        // Start connection to daemon (non-blocking)
        try {
            $this->connectToDaemon();
        } catch (\Throwable $e) {
            $this->logError("Failed to start daemon connection: " . $e->getMessage());
            $this->cleanup();
            return;
        }

        // Main loop
        while (!$this->shouldExit) {
            $loopStartTime = microtime(true);

            // Check connection status if not yet connected
            if ($this->daemonClient !== null && !$this->daemonClient->isConnected()) {
                try {
                    $this->daemonClient->checkConnection();
                } catch (SocketException $e) {
                    $this->logError("Connection check failed: " . $e->getMessage());
                    $this->shouldExit = true;
                    break;
                }
            }

            // Only tick when connected to daemon
            if ($this->daemonClient !== null && $this->daemonClient->isConnected()) {
                // Process daemon connection
                try {
                    $this->daemonClient->read();
                    $this->daemonClient->write();
                } catch (SocketException $e) {
                    // Connection error - check if connection is lost
                    if (!$this->daemonClient->isConnected()) {
                        $this->logError("Connection to daemon lost: " . $e->getMessage());
                        $this->shouldExit = true;
                        break;
                    }
                    $this->logError("Daemon client error: " . $e->getMessage());
                }

                // Call tick method (only when connected)
                $this->onTick();

                // Tick all agents and collect those that requested stop
                $agentsToRemove = [];
                foreach ($this->agents as $agentId => $agent) {
                    $agent->tick();

                    // Check if agent requested stop (after tick which calls onStop)
                    if ($agent->shouldStop()) {
                        $agentsToRemove[] = $agentId;
                    }
                }

                // Remove agents that requested stop
                foreach ($agentsToRemove as $agentId) {
                    unset($this->agents[$agentId]);
                    Logger::info("Agent {$agentId} stopped (self-requested) [worker side]");
                    Logger::logAgentInfo($agentId, "Agent stopped (self-requested) on worker [workerIndex={$this->workerIndex}]");
                }
            }

            $this->sleepWithPreciseTiming($loopStartTime);

            // Process signals
            pcntl_signal_dispatch();
        }

        // Cleanup
        $this->cleanup();

        Logger::info("Worker #{$this->workerIndex} stopped");
    }

    /**
     * Connect to daemon WorkerServer (non-blocking)
     *
     * Starts connection attempt. Connection will be checked asynchronously in run() loop.
     *
     * @throws SocketException If connection fails
     * @throws MissingEnvironmentVariableException If required env variables are missing
     */
    private function connectToDaemon(): void
    {
        $this->daemonClient = new WorkerDaemonClient();
        $this->daemonClient->setMessageHandler([$this, 'handleDaemonMessage']);
        $this->daemonClient->connect();

        // Send worker registration message (will be sent when connection is established)
        $this->daemonClient->send([
            'type' => 'worker_register',
            'workerIndex' => $this->workerIndex,
            'monopolistic' => $this->isMonopolistic,
        ]);
    }

    /**
     * Handle message from daemon
     *
     * @param array $data Message data
     * @throws AgentCreationFailedException If agent creation fails
     */
    public function handleDaemonMessage(array $data): void
    {
        $type = $data['type'] ?? '';

        switch ($type) {
            case 'worker_registered':
                // Connection confirmed by daemon
                Logger::info("Connected to daemon");
                break;

            case 'agent_start':
                $this->handleAgentStart($data);
                break;

            case 'agent_stop':
                $this->handleAgentStop($data);
                break;

            case 'agent_message':
                $this->handleAgentMessage($data);
                break;

            default:
                // Unknown message type
                break;
        }
    }

    /**
     * Handle agent start message
     *
     * @param array $data Message data
     * @throws AgentCreationFailedException If agent creation fails
     */
    private function handleAgentStart(array $data): void
    {
        $agentId = $data['agentId'] ?? '';
        $agentType = $data['agentType'] ?? '';
        $agentIndex = $data['agentIndex'] ?? null;

        if ($agentId === '' || $agentType === '') {
            return;
        }

        // Check if agent already exists
        if (isset($this->agents[$agentId])) {
            return;
        }

        // Create agent using factory method
        $agent = $this->createAgent($agentType, $agentIndex);

        $agent->setMessageSender([$this, 'sendAgentMessage']);
        $agent->onStart();
        $this->agents[$agentId] = $agent;
        Logger::info("Agent {$agentId} started [worker side]");
        // Additional agent log from worker side
        Logger::logAgentInfo($agentId, "Agent started on worker [workerIndex={$this->workerIndex}]");
        
        // Notify daemon that agent started
        $this->notifyAgentStarted($agentId, $agentType, $agentIndex);
    }

    /**
     * Handle agent stop message
     *
     * @param array $data Message data
     */
    private function handleAgentStop(array $data): void
    {
        $agentId = $data['agentId'] ?? '';

        if ($agentId === '' || !isset($this->agents[$agentId])) {
            return;
        }

        $agent = $this->agents[$agentId];
        $agent->onStop(); // This will call AgentLogger::logStop inside agent
        unset($this->agents[$agentId]);
        Logger::info("Agent {$agentId} stopped [worker side]");
        // Additional agent log from worker side
        Logger::logAgentInfo($agentId, "Agent stopped on worker [workerIndex={$this->workerIndex}]");

        // Notify daemon that agent stopped
        $this->notifyAgentStopped($agentId);
    }

    /**
     * Handle agent message
     *
     * @param array $data Message data
     */
    private function handleAgentMessage(array $data): void
    {
        $agentId = $data['agentId'] ?? '';
        $source = $data['source'] ?? 'daemon';

        if ($agentId === '' || !isset($this->agents[$agentId])) {
            return;
        }

        $agent = $this->agents[$agentId];

        // Extract signal data and call onSignal handler
        $signalData = $data['data'] ?? [];
        $this->onSignal($agentId, $signalData);
    }

    /**
     * Handle signal from daemon to agent
     *
     * Must be implemented in child classes to handle specific signal types.
     *
     * @param string $agentId Agent ID
     * @param array $signalData Signal data
     */
    abstract protected function onSignal(string $agentId, array $signalData): void;

    /**
     * Send message from agent to daemon
     *
     * @param string $agentId Agent ID
     * @param array $data Message data
     */
    public function sendAgentMessage(string $agentId, array $data): void
    {
        if ($this->daemonClient === null || !$this->daemonClient->isConnected()) {
            return;
        }

        // Find agent to get type
        $agent = $this->agents[$agentId] ?? null;
        if ($agent === null) {
            return;
        }

        $message = [
            'type' => 'agent_message',
            'agentId' => $agentId,
            'agentType' => $agent->getType(),
            'data' => $data,
        ];

        $this->daemonClient->send($message);
    }

    /**
     * Notify daemon that agent started
     *
     * @param string $agentId Agent ID
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     */
    private function notifyAgentStarted(string $agentId, string $agentType, ?string $agentIndex): void
    {
        if ($this->daemonClient === null || !$this->daemonClient->isConnected()) {
            return;
        }

        $message = [
            'type' => 'agent_started',
            'agentId' => $agentId,
            'agentType' => $agentType,
        ];

        if ($agentIndex !== null) {
            $message['agentIndex'] = $agentIndex;
        }

        $this->daemonClient->send($message);
    }

    /**
     * Notify daemon that agent stopped
     *
     * @param string $agentId Agent ID
     */
    private function notifyAgentStopped(string $agentId): void
    {
        if ($this->daemonClient === null || !$this->daemonClient->isConnected()) {
            return;
        }

        $message = [
            'type' => 'agent_stopped',
            'agentId' => $agentId,
        ];

        $this->daemonClient->send($message);
    }

    /**
     * Cleanup on shutdown
     */
    private function cleanup(): void
    {
        // Stop all agents
        foreach ($this->agents as $agentId => $agent) {
            $agent->onStop();
            Logger::info("Agent {$agentId} stopped during cleanup [worker side]");
            Logger::logAgentInfo($agentId, "Agent stopped during worker cleanup [workerIndex={$this->workerIndex}]");
        }
        $this->agents = [];

        // Close daemon connection
        if ($this->daemonClient !== null) {
            try {
                $this->daemonClient->close();
            } catch (SocketException $e) {
                // Ignore errors during cleanup
            }
            $this->daemonClient = null;
        }
    }

    /**
     * Create agent instance (factory method)
     *
     * Must be implemented in child classes to create specific agent types.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentInterface Agent instance
     * @throws AgentCreationFailedException If agent cannot be created
     */
    abstract protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface;

    /**
     * Tick method - called regularly in main loop
     *
     * Must be implemented in child classes to define worker-specific
     * work logic. Called on each loop iteration with precise timing.
     * Only called when connection to daemon is established.
     */
    abstract protected function onTick(): void;

    /** @return string Manager name for logging */
    protected function getManagerName(): string
    {
        return "Worker #{$this->workerIndex}";
    }

    /** @param string $message Error message to log */
    protected function logError(string $message): void
    {
        Logger::errorLog($message);
    }

    /** @param string $message Exception message to log */
    protected function logException(string $message): void
    {
        Logger::errorLog($message);
    }

    /** @param string $message Shutdown message to log */
    protected function logShutdown(string $message): void
    {
        Logger::errorLog($message);
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
        // Worker-specific shutdown logic (none needed)
    }

    /** Handle restart signal event - no additional logic needed */
    protected function onRestartSignal(): void
    {
        // Worker-specific restart logic (none needed)
    }
}

