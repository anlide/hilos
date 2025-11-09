<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\DTO\Worker\AgentStartDTO;
use Hilos\DTO\Worker\AgentStopDTO;
use Hilos\DTO\Worker\WorkerAgentMessageDTO;
use Hilos\DTO\Worker\WorkerDTO;
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
 */
abstract class WorkerManager extends BaseManager
{
    /** @var int Worker index */
    protected int $workerIndex;

    /** @var bool Whether this worker is monopolistic */
    protected bool $isMonopolistic;

    /** @var ?WorkerDaemonClient Client connection to daemon */
    private ?WorkerDaemonClient $daemonClient = null;

    /** @var AgentManager Agent manager instance */
    protected AgentManager $agentManager;

    /** @var SignalRouter Signal router instance */
    protected SignalRouter $signalRouter;

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
        $this->signalRouter = $this->createSignalRouter();
        $this->agentManager = $this->createAgentManager($this->signalRouter);
    }

    /**
     * Create signal router instance
     *
     * Must be implemented in child classes to create specific signal router.
     *
     * @return SignalRouter Signal router instance
     */
    abstract protected function createSignalRouter(): SignalRouter;

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

                // Process messages from daemon queue
                while (($message = $this->daemonClient->getNextMessage()) !== null) {
                    try {
                        $this->handleDaemonMessage($message);
                    } catch (AgentCreationFailedException $e) {
                        $this->logError("Failed to handle daemon message: " . $e->getMessage());
                    }
                }

                // Call tick method (only when connected)
                $this->onTick();

                // Tick all agents
                foreach ($this->agentManager->getAgents() as $agentId => $agent) {
                    $agent->onTick();

                    // Check if agent requested stop
                    if ($agent->shouldStop()) {
                        $this->agentManager->removeAgent($agentId);
                        Logger::info("Agent {$agentId} stopped (self-requested)");
                        Logger::logAgentInfo($agentId, "Agent stopped (self-requested) on worker [workerIndex={$this->workerIndex}]");
                    }
                }

                // Dispatch accumulated signals (send to daemon)
                $this->dispatchSignals();
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
        $this->daemonClient->connect();

        // Send worker registration message (will be sent when connection is established)
        $this->daemonClient->send([
            'type' => WorkerConstants::MESSAGE_WORKER_REGISTER,
            'workerIndex' => $this->workerIndex,
            'monopolistic' => $this->isMonopolistic,
        ]);
    }

    /**
     * Handle message from daemon
     *
     * @param WorkerDTO $data Message data
     * @throws AgentCreationFailedException If agent creation fails
     */
    public function handleDaemonMessage(WorkerDTO $data): void
    {
        $type = $data->getType();
        Logger::debug("Received message from daemon: type={$type}, data=" . json_encode($data->toArray()));

        switch ($type) {
            case WorkerConstants::MESSAGE_WORKER_REGISTERED:
                $this->handleWorkerRegistered($data);
                break;

            case WorkerConstants::MESSAGE_AGENT_START:
                $this->handleAgentStart($data);
                break;

            case WorkerConstants::MESSAGE_AGENT_STOP:
                $this->handleAgentStop($data);
                break;

            case WorkerConstants::MESSAGE_AGENT_MESSAGE:
                $this->handleAgentMessage($data);
                break;

            default:
                // Unknown message type
                Logger::info("Unknown message type received from daemon: {$type}");
                break;
        }
    }

    /**
     * Handle worker registered message
     *
     * @param WorkerDTO $data Message data
     */
    private function handleWorkerRegistered(WorkerDTO $data): void
    {
        // Connection confirmed by daemon
        Logger::info("Connected to daemon");
    }

    /**
     * Handle agent start message
     *
     * @param WorkerDTO $data Message data
     * @throws AgentCreationFailedException If agent creation fails
     */
    private function handleAgentStart(WorkerDTO $data): void
    {
        if (!($data instanceof AgentStartDTO)) {
            return;
        }

        $agentId = $data->agentId;

        if ($agentId === '') {
            return;
        }

        // Check if agent already exists
        if ($this->agentManager->hasAgent($agentId)) {
            return;
        }

        // Parse agentId to extract agentType and agentIndex
        $parsed = $this->agentManager->parseAgentId($agentId);
        $agentType = $parsed['agentType'];
        $agentIndex = $parsed['agentIndex'];

        // Create agent using factory method
        $agent = $this->agentManager->createAndAddAgent($agentType, $agentIndex);

        $agent->onStart();
        Logger::info("Agent '{$agentId}' started");
        // Additional agent log from worker side
        Logger::logAgentInfo($agentId, "Agent started on worker [workerIndex={$this->workerIndex}]");

        // Notify daemon that agent started
        $this->notifyAgentStarted($agentId, $agentType, $agentIndex);
    }

    /**
     * Handle agent stop message
     *
     * @param WorkerDTO $data Message data
     */
    private function handleAgentStop(WorkerDTO $data): void
    {
        if (!($data instanceof AgentStopDTO)) {
            return;
        }

        $agentId = $data->agentId;

        if ($agentId === '') {
            return;
        }

        if (!$this->agentManager->hasAgent($agentId)) {
            return;
        }

        $agent = $this->agentManager->getAgent($agentId);
        if ($agent === null) {
            return;
        }

        $agent->onStop(); // This will call AgentLogger::logStop inside agent
        $this->agentManager->removeAgent($agentId);
        Logger::info("Agent {$agentId} stopped");
        // Additional agent log from worker side
        Logger::logAgentInfo($agentId, "Agent stopped on worker [workerIndex={$this->workerIndex}]");

        // Notify daemon that agent stopped
        $this->notifyAgentStopped($agentId);
    }

    /**
     * Handle agent message
     *
     * @param WorkerDTO $data Message data
     */
    private function handleAgentMessage(WorkerDTO $data): void
    {
        if (!($data instanceof WorkerAgentMessageDTO)) {
            Logger::error("handleAgentMessage - not WorkerAgentMessageDTO, type=" . get_class($data));
            return;
        }

        $agentId = $data->agentId;

        // Parse agentId to extract agentType and agentIndex
        $parsed = $this->agentManager->parseAgentId($agentId);
        $agentType = $parsed['agentType'];
        $agentIndex = $parsed['agentIndex'];

        if ($agentType === '') {
            Logger::error("handleAgentMessage - empty agentType from agentId: {$agentId}");
            return;
        }

        if (!$this->agentManager->hasAgent($agentId)) {
            Logger::error("handleAgentMessage - agent not found: {$agentId}");
            return;
        }

        $agent = $this->agentManager->getAgent($agentId);
        if ($agent === null) {
            Logger::error("handleAgentMessage - agent is null: {$agentId}");
            return;
        }

        // Extract signal and route to appropriate handler in agent based on signal type
        $signal = $data->signal;
        $signalType = $signal->signalType->getType();
        $source = $signal->signalSource->getSource();
        $name = $signal->signalName->getName();
        $signalData = $signal->data;

        Logger::debug('Signal routing: agentId=' . $agentId . ', signalType=' . $signalType . ', signalName=' . $name);
        // Route to appropriate handler in agent based on signal type
        match ($signalType) {
            SignalTypeConstants::SYSTEM => $agent->onSignalSystem($source, $name, $signalData),
            SignalTypeConstants::PAGE_SUBSCRIBE => $agent->onSignalPageSubscribe($source, $name, $signalData),
            SignalTypeConstants::PAGE_UNSUBSCRIBE => $agent->onSignalPageUnsubscribe($source, $name, $signalData),
            SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION => $agent->onSignalPageUpdateSubscription($source, $name, $signalData),
            SignalTypeConstants::GROUP_SUBSCRIBE => $agent->onSignalGroupSubscribe($source, $name, $signalData),
            SignalTypeConstants::GROUP_UNSUBSCRIBE => $agent->onSignalGroupUnsubscribe($source, $name, $signalData),
            SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION => $agent->onSignalGroupUpdateSubscription($source, $name, $signalData),
            SignalTypeConstants::ACTION => $agent->onSignalAction($source, $name, $signalData),
            SignalTypeConstants::CRON => $agent->onSignalCron($source, $name, $signalData),
            default => null, // Unknown signal type - ignore
        };
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
            'type' => WorkerConstants::MESSAGE_AGENT_STARTED,
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
            'type' => WorkerConstants::MESSAGE_AGENT_STOPPED,
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
        foreach ($this->agentManager->getAgents() as $agentId => $agent) {
            $agent->onStop();
            Logger::info("Agent {$agentId} stopped during cleanup");
            Logger::logAgentInfo($agentId, "Agent stopped during worker cleanup [workerIndex={$this->workerIndex}]");
        }
        // Clear all agents
        foreach ($this->agentManager->getAgents() as $agentId => $agent) {
            $this->agentManager->removeAgent($agentId);
        }

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
     * Tick method - called regularly in main loop
     *
     * Must be implemented in child classes to define worker-specific
     * work logic. Called on each loop iteration with precise timing.
     * Only called when connection to daemon is established.
     */
    abstract protected function onTick(): void;

    /**
     * Dispatch accumulated signals
     *
     * Processes all queued signals from SignalRouter and forwards them to daemon.
     * Signals are processed one by one in while-do loop.
     * Called at the end of each loop iteration when connected to daemon.
     *
     * Logic: We simply forward signals to daemon as-is. Daemon will decide what to do with them.
     */
    private function dispatchSignals(): void
    {
        if ($this->daemonClient === null || !$this->daemonClient->isConnected()) {
            return;
        }

        // Process signals one by one in while-do loop
        while (($signal = $this->signalRouter->getNextQueuedSignal()) !== null) {
            // Extract agent type and index from signal source
            $agentType = $signal->signalSource->getType();
            $agentIndex = $signal->signalSource->getIndex();

            $json = json_encode($signal->toArray());
            Logger::debug("Signal going to transmit: {$json}");
            // Send signal to daemon (daemon will handle routing)
            $this->daemonClient->send(new WorkerAgentMessageDTO(
                agentId: $this->agentManager->buildAgentId($agentType, $agentIndex),
                signal: $signal,
            ));
        }
    }

    /**
     * Create agent manager instance
     *
     * Must be implemented in child classes to create specific agent manager.
     *
     * @param SignalRouter $signalRouter Signal router instance
     * @return AgentManager Agent manager instance
     */
    abstract protected function createAgentManager(SignalRouter $signalRouter): AgentManager;

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
