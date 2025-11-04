<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Core\Agent\Daemon\AbstractAgentDaemonFactory;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Process;
use Hilos\Core\Router\SignalRouter;
use Hilos\Exception\MissingEnvironmentVariableException;
use Hilos\Exception\Process\CouldNotStartException;
use Hilos\Exception\Process\FailedToClosePipeException;
use Hilos\Exception\Process\FailedToGetStatusException;
use Hilos\Exception\Process\FailedToReadStdOutException;
use Hilos\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Exception\Process\FailedToSetStdErrException;
use Hilos\Exception\Process\FailedToTerminateProcessExceptionException;
use Hilos\Exception\SocketException;
use Hilos\Exception\Worker\AgentDaemonCreationFailedException;
use Hilos\Utils\DTO\Worker\AgentMessageDTO;
use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Utils\Constants\EnvConstants;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Utils\Env;
use Hilos\Logging\Logger\Logger;

/**
 * WorkerServer - Worker communication server implementation
 *
 * Manages worker server socket and accepts incoming connections from workers.
 * Also manages worker processes lifecycle - starts, monitors and stops them.
 * Works with epoll in daemon main loop.
 *
 * This is an abstract class - child classes must implement onStart() and optionally
 * getAgentDaemonFactoryClass() to provide the factory for creating agent daemon instances.
 */
abstract class WorkerServer extends AbstractServer
{
    /** @var array<int, Process> Regular worker processes indexed by worker index */
    private array $regularWorkers = [];

    /** @var array<int, Process> Monopolistic worker processes indexed by worker index */
    private array $monopolisticWorkers = [];

    /** @var array<int> Available regular worker indices (sorted, can be reused) */
    private array $availableRegularIndices = [];

    /** @var array<int> Available monopolistic worker indices (sorted, can be reused) */
    private array $availableMonopolisticIndices = [];

    /** @var int Next regular worker index to assign if no available */
    private int $nextRegularWorkerIndex = 1;

    /** @var int Next monopolistic worker index to assign if no available */
    private int $nextMonopolisticWorkerIndex = 1;

    /** @var array<int, float> Regular worker indices waiting for graceful shutdown [workerIndex => startTime] */
    private array $regularWorkersWaitingShutdown = [];

    /** @var array<int, float> Monopolistic worker indices waiting for graceful shutdown [workerIndex => startTime] */
    private array $monopolisticWorkersWaitingShutdown = [];

    /** @var float Graceful shutdown timeout in seconds */
    private const float SHUTDOWN_TIMEOUT = 5.0;

    /** @var string Path to worker bootstrap script */
    private string $workerScript;

    /** @var string Working directory for worker processes */
    private string $workingDirectory;

    /** @var int Minimum number of regular workers */
    private int $minRegular;

    /** @var int Minimum number of monopolistic workers */
    private int $minMonopolistic;

    /** @var int Maximum number of regular workers */
    private int $maxRegular;

    /** @var array<string, AgentDaemonInterface> Active agent daemons indexed by agent ID */
    private array $agentDaemons = [];

    /** @var array<string, array{agentType: string, agentIndex: ?string}> Agents waiting to be started */
    private array $pendingAgents = [];

    /** @var array<string, array{array{agentType: string, agentIndex: ?string, data: array}}> Queued signals for agents not linked to workers */
    private array $queuedSignals = [];

    /**
     * WorkerServer constructor
     *
     * @param string $host Host to bind
     * @param int $port Port to bind
     * @param string $workerScript Path to worker bootstrap script
     * @param string $workingDirectory Working directory for worker processes
     * @throws MissingEnvironmentVariableException
     */
    public function __construct(string $host, int $port, string $workerScript, string $workingDirectory, SignalRouter $signalRouter)
    {
        parent::__construct($host, $port, $signalRouter);

        $this->workerScript = $workerScript;
        $this->workingDirectory = $workingDirectory;

        // Get worker configuration from environment
        $this->minRegular = Env::getInt(EnvConstants::WORKER_MIN_REGULAR, 3);
        $this->minMonopolistic = Env::getInt(EnvConstants::WORKER_MIN_MONOPOLISTIC, 2);
        $this->maxRegular = Env::getInt(EnvConstants::WORKER_MAX_REGULAR, 10);
    }
    /**
     * Accept new worker connection
     *
     * @return ?WorkerClientInterface New worker client or null
     * @throws SocketException
     */
    public function acceptConnection(): ?WorkerClientInterface
    {
        /** @var ?WorkerClientInterface */
        return parent::acceptConnection();
    }

    /**
     * Called when a new worker client connection is accepted
     *
     * @param resource $socket Client socket
     * @return WorkerClientInterface Client instance
     */
    protected function onCreateClient($socket, SignalRouter $signalRouter): WorkerClientInterface
    {
        $workerClient = new WorkerClient($socket, $signalRouter);

        // Set agent message handler to track agent lifecycle
        $workerClient->setAgentMessageHandler(function(WorkerClient $client, array $data) {
            $this->handleAgentMessage($client, $data);
        });

        // Set worker registration handler
        $workerClient->setWorkerRegistrationHandler(function(WorkerClient $client) {
            $this->handleWorkerRegistered($client);
        });

        return $workerClient;
    }

    /**
     * Handle worker registration
     *
     * Called when a worker registers. Marks worker as registered.
     * Actual pending processing happens in tickPending().
     *
     * @param WorkerClient $workerClient Registered worker client
     */
    private function handleWorkerRegistered(WorkerClient $workerClient): void
    {
        // Worker is now marked as registered in WorkerClient::handleWorkerRegister()
        // Processing of pending agents will happen in tickPending()
    }

    /**
     * Process queued signals for agents that are now linked to workers
     */
    private function processQueuedSignals(): void
    {
        foreach ($this->queuedSignals as $agentId => $signals) {
            if (!isset($this->agentDaemons[$agentId])) {
                continue; // Agent doesn't exist yet
            }

            $agentDaemon = $this->agentDaemons[$agentId];
            $workerClient = $agentDaemon->getWorkerClient();
            if ($workerClient === null) {
                continue; // Agent not linked yet
            }

            // Send all queued signals
            foreach ($signals as $signal) {
                $parts = explode(':', $agentId, 2);
                $agentTypeFromId = $parts[0];
                $agentIndexFromId = $parts[1] ?? null;

                $dto = new AgentMessageDTO(
                    type: AgentMessageDTO::TYPE_AGENT_SIGNAL,
                    agentId: $agentId,
                    agentType: $agentTypeFromId,
                    agentIndex: $agentIndexFromId,
                    data: $signal['data'],
                );

                $workerClient->send($dto->toJson());
            }

            // Clear queued signals for this agent
            unset($this->queuedSignals[$agentId]);
        }
    }

    /**
     * Handle agent lifecycle signals from workers
     *
     * @param WorkerClient $workerClient Worker client that sent the signal
     * @param array $data Signal data
     */
    private function handleAgentMessage(WorkerClient $workerClient, array $data): void
    {
        $type = $data['type'] ?? '';

        switch ($type) {
            case 'agent_started':
                $this->handleAgentStarted($workerClient, $data);
                break;
            case 'agent_stopped':
                $this->handleAgentStopped($workerClient, $data);
                break;
        }
    }

    /**
     * Handle agent_started signal from worker
     *
     * @param WorkerClient $workerClient Worker client
     * @param array $data Signal data
     * @throws AgentDaemonCreationFailedException
     */
    protected function handleAgentStarted(WorkerClient $workerClient, array $data): void
    {
        $agentId = $data['agentId'] ?? '';
        $agentType = $data['agentType'] ?? '';
        $agentIndex = $data['agentIndex'] ?? null;

        if ($agentId === '' || $agentType === '') {
            return;
        }

        // Create and link agent daemon if it doesn't exist
        if (!isset($this->agentDaemons[$agentId])) {
            $this->agentDaemons[$agentId] = $this->getAgentDaemonFactoryClass()::createAgentDaemon($agentType, $agentIndex);
        }
        $agentDaemon = $this->agentDaemons[$agentId];
        $agentDaemon->setWorkerClient($workerClient);
        $agentDaemon->onStart();

        // Remove from pending if was there
        unset($this->pendingAgents[$agentId]);

        // Process queued signals for this agent
        $this->processQueuedSignalsForAgent($agentId);

        Logger::info("Agent {$agentId} started on worker #{$workerClient->getWorkerIndex()} [daemon side]");
    }

    /**
     * Process queued signals for a specific agent
     *
     * @param string $agentId Agent ID
     */
    private function processQueuedSignalsForAgent(string $agentId): void
    {
        if (!isset($this->queuedSignals[$agentId])) {
            return; // No queued signals
        }

        if (!isset($this->agentDaemons[$agentId])) {
            return; // Agent doesn't exist
        }

        $agentDaemon = $this->agentDaemons[$agentId];
        $workerClient = $agentDaemon->getWorkerClient();
        if ($workerClient === null) {
            return; // Agent not linked yet
        }

        // Send all queued signals
        foreach ($this->queuedSignals[$agentId] as $signal) {
            $parts = explode(':', $agentId, 2);
            $agentTypeFromId = $parts[0];
            $agentIndexFromId = $parts[1] ?? null;

            $dto = new AgentMessageDTO(
                type: AgentMessageDTO::TYPE_AGENT_SIGNAL,
                agentId: $agentId,
                agentType: $agentTypeFromId,
                agentIndex: $agentIndexFromId,
                data: $signal['data'],
            );

            $workerClient->send($dto->toJson());
        }

        // Clear queued signals
        unset($this->queuedSignals[$agentId]);
    }

    /**
     * Handle agent_stopped signal from worker
     *
     * @param WorkerClient $workerClient Worker client
     * @param array $data Signal data
     */
    protected function handleAgentStopped(WorkerClient $workerClient, array $data): void
    {
        $agentId = $data['agentId'] ?? '';

        if ($agentId === '') {
            return;
        }

        // Remove agent daemon
        if (isset($this->agentDaemons[$agentId])) {
            $agentDaemon = $this->agentDaemons[$agentId];
            $agentDaemon->onStop();
            unset($this->agentDaemons[$agentId]);

            Logger::info("Agent {$agentId} stopped on worker #{$workerClient->getWorkerIndex()} [daemon side]");
        }
    }

    /**
     * Get server name for logging
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return "Worker Server";
    }

    /**
     * Build agent ID from type and index
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return string Agent ID (format: "type" or "type:index")
     */
    final protected function buildAgentId(string $agentType, ?string $agentIndex): string
    {
        return $agentIndex !== null ? $agentType . ':' . $agentIndex : $agentType;
    }

    /**
     * Get agent daemon factory class name
     *
     * Must be implemented by child classes to provide the factory for creating agent daemon instances.
     *
     * @return class-string<AbstractAgentDaemonFactory> Factory class name
     */
    abstract protected function getAgentDaemonFactoryClass(): string;

    /**
     * Get count of active regular worker processes
     *
     * @return int Number of active regular workers
     */
    public function getRegularWorkersCount(): int
    {
        return count($this->regularWorkers);
    }

    /**
     * Get count of active monopolistic worker processes
     *
     * @return int Number of active monopolistic workers
     */
    public function getMonopolisticWorkersCount(): int
    {
        return count($this->monopolisticWorkers);
    }

    /**
     * Get maximum number of regular worker processes
     *
     * @return int Maximum number of regular workers
     */
    public function getMaxRegularWorkers(): int
    {
        return $this->maxRegular;
    }

    /**
     * Tick method - process clients and manage worker processes
     *
     * Overrides parent tick() to also manage worker processes lifecycle.
     * Checks running processes and starts missing ones.
     */
    public function tick(): void
    {
        // Process clients (read/write)
        parent::tick();

        // Check workers waiting for graceful shutdown
        $this->checkGracefulShutdown();

        // Tick all worker processes (check status, read output)
        $this->tickWorkerProcesses();

        // Process pending agents and queued signals
        $this->tickPending();

        // Ensure minimum number of workers are running
        try {
            $this->ensureMinWorkers();
        } catch (CouldNotStartException $e) {
            Logger::error("Failed to start worker process: " . $e->getMessage());
        } catch (FailedToSetNonBlockingException $e) {
            Logger::error("Failed to set non-blocking mode for worker process: " . $e->getMessage());
        }
    }

    /**
     * Process pending agents and queued signals
     *
     * Processes one pending agent per tick and sends queued signals.
     * Called from tick() to handle agents waiting for workers.
     */
    private function tickPending(): void
    {
        // Process one pending agent per tick
        if (!empty($this->pendingAgents)) {
            $this->processPendingAgent();
        }

        // Process queued signals for agents that are now linked
        $this->processQueuedSignals();
    }

    /**
     * Process one pending agent
     *
     * Tries to find a suitable registered worker and start one pending agent.
     */
    private function processPendingAgent(): void
    {
        foreach ($this->pendingAgents as $agentId => $agentInfo) {
            $agentType = $agentInfo['agentType'];
            $agentIndex = $agentInfo['agentIndex'];

            // Create agent daemon if it doesn't exist
            if (!isset($this->agentDaemons[$agentId])) {
                try {
                    $this->agentDaemons[$agentId] = $this->getAgentDaemonFactoryClass()::createAgentDaemon($agentType, $agentIndex);
                } catch (\Throwable $e) {
                    continue; // Skip if creation fails
                }
            }

            $agentDaemon = $this->agentDaemons[$agentId];
            $requiresMonopolistic = $agentDaemon->requiresMonopolisticProcess();

            // Find suitable registered worker
            $workerClient = $this->findSuitableRegisteredWorker($requiresMonopolistic);
            if ($workerClient === null) {
                continue; // No suitable worker available yet
            }

            // Link agent daemon to this worker
            $agentDaemon->setWorkerClient($workerClient);

            // Remove from pending
            unset($this->pendingAgents[$agentId]);

            // Send agent_start signal to worker
            $workerClient->sendAgentStart($agentId, $agentType, $agentIndex);

            // Process only one agent per tick
            break;
        }
    }

    /**
     * Find suitable registered worker for agent
     *
     * @param bool $requiresMonopolistic True if agent requires monopolistic worker
     * @return WorkerClient|null Suitable worker client or null if not found
     */
    private function findSuitableRegisteredWorker(bool $requiresMonopolistic): ?WorkerClient
    {
        $candidates = [];
        $workerAgentCounts = [];

        // Collect suitable registered worker candidates
        foreach ($this->clients as $client) {
            if (!$client instanceof WorkerClient) {
                continue;
            }

            // Worker must be registered
            if (!$client->isRegistered()) {
                continue;
            }

            // Check if worker type matches requirement
            if ($client->isMonopolistic() !== $requiresMonopolistic) {
                continue;
            }

            $workerIndex = $client->getWorkerIndex();
            $agentCount = $this->getAgentCountOnWorker($workerIndex);

            // For monopolistic workers: must have exactly 0 agents
            // For regular workers: collect all (will select by load later)
            if ($requiresMonopolistic) {
                if ($agentCount === 0) {
                    $candidates[] = $client;
                }
            } else {
                $candidates[] = $client;
                $workerAgentCounts[$workerIndex] = $agentCount;
            }
        }

        // If no candidates found, return null
        if (empty($candidates)) {
            return null;
        }

        // For monopolistic: return random candidate (all have 0 agents)
        if ($requiresMonopolistic) {
            return $candidates[array_rand($candidates)];
        }

        // Find minimum agent count
        $minCount = min($workerAgentCounts);

        // Filter candidates to only those with minimum agent count
        $minLoadCandidates = [];
        foreach ($candidates as $client) {
            $workerIndex = $client->getWorkerIndex();
            if ($workerAgentCounts[$workerIndex] === $minCount) {
                $minLoadCandidates[] = $client;
            }
        }

        // Return random worker from minimum load candidates
        return $minLoadCandidates[array_rand($minLoadCandidates)];
    }

    /**
     * Tick all worker processes - check status and read output
     */
    private function tickWorkerProcesses(): void
    {
        // Tick regular workers
        foreach ($this->regularWorkers as $workerIndex => $process) {
            try {
                $process->tick();

                // Read and save stdout/stderr to files
                $this->saveWorkerOutput($process, 'regular', $workerIndex);

                // Check if process is still running
                $status = $process->getStatus();
                if (!$status[Process::STATUS_RUNNING]) {
                    // Worker stopped - read final output before removing
                    $this->saveWorkerOutput($process, 'regular', $workerIndex);

                    // Worker died, remove from list and free index for reuse
                    Logger::info("Worker #{$workerIndex} stopped [type=regular]");

                    unset($this->regularWorkers[$workerIndex]);
                    $this->availableRegularIndices[] = $workerIndex;
                    sort($this->availableRegularIndices); // Keep sorted for min selection
                }
            } catch (FailedToClosePipeException | FailedToTerminateProcessExceptionException $e) {
                unset($this->regularWorkers[$workerIndex]);
                $this->availableRegularIndices[] = $workerIndex;
                sort($this->availableRegularIndices);
            } catch (FailedToGetStatusException | FailedToReadStdOutException | FailedToSetStdErrException $e) {
                try {
                    $process->stop();
                } catch (FailedToGetStatusException | FailedToTerminateProcessExceptionException $e) {
                    // Ignore errors during halt
                }
                unset($this->regularWorkers[$workerIndex]);
                $this->availableRegularIndices[] = $workerIndex;
                sort($this->availableRegularIndices);
            }
        }

        // Tick monopolistic workers
        foreach ($this->monopolisticWorkers as $workerIndex => $process) {
            try {
                $process->tick();

                // Read and save stdout/stderr to files
                $this->saveWorkerOutput($process, 'monopolistic', $workerIndex);

                // Check if process is still running
                $status = $process->getStatus();
                if (!$status[Process::STATUS_RUNNING]) {
                    // Worker stopped - read final output before removing
                    $this->saveWorkerOutput($process, 'monopolistic', $workerIndex);

                    // Worker died, remove from list and free index for reuse
                    Logger::info("Worker #{$workerIndex} stopped [type=monopolistic]");
                    unset($this->monopolisticWorkers[$workerIndex]);
                    $this->availableMonopolisticIndices[] = $workerIndex;
                    sort($this->availableMonopolisticIndices);
                }
            } catch (FailedToClosePipeException | FailedToTerminateProcessExceptionException $e) {
                unset($this->monopolisticWorkers[$workerIndex]);
                $this->availableMonopolisticIndices[] = $workerIndex;
                sort($this->availableMonopolisticIndices);
            } catch (FailedToGetStatusException | FailedToReadStdOutException | FailedToSetStdErrException $e) {
                try {
                    $process->stop();
                } catch (FailedToGetStatusException | FailedToTerminateProcessExceptionException $e) {
                    // Ignore errors during halt
                }
                // Process error, remove from list and free index for reuse
                unset($this->monopolisticWorkers[$workerIndex]);
                $this->availableMonopolisticIndices[] = $workerIndex;
                sort($this->availableMonopolisticIndices);
            }
        }
    }

    /**
     * Save worker stdout and stderr output to files
     *
     * @param Process $process Worker process
     * @param string $workerType Worker type ('regular' or 'monopolistic')
     * @param int $workerIndex Worker index
     */
    private function saveWorkerOutput(Process $process, string $workerType, int $workerIndex): void
    {
        // Determine log directory from daemon log file path (same directory)
        // Fallback to 'data/logs' if DAEMON_LOG_FILE is not set
        try {
            $daemonLogFile = Env::get(EnvConstants::DAEMON_LOG_FILE);
            $logDirectory = dirname($daemonLogFile);
        } catch (MissingEnvironmentVariableException) {
            // Fallback to default log directory if env variable is not set
            $logDirectory = 'data/logs';
        }

        // Ensure log directory exists
        if (!is_dir($logDirectory)) {
            if (!mkdir($logDirectory, 0755, true)) {
                Logger::error("Failed to create log directory: {$logDirectory}");
                return;
            }
        }

        // Read stdout and write to file
        $stdout = $process->getStdOut();
        if (!empty($stdout)) {
            $stdoutFile = $logDirectory . "/worker-{$workerType}-{$workerIndex}.log";
            $this->processWorkerOutput($stdout, $stdoutFile, $logDirectory, false);
        }

        // Read stderr and write to file
        $stderr = $process->getStdErr();
        if (!empty($stderr)) {
            $stderrFile = $logDirectory . "/worker-{$workerType}-{$workerIndex}.error.log";
            $this->processWorkerOutput($stderr, $stderrFile, $logDirectory, true);
        }
    }

    /**
     * Process worker output and extract agent logs
     *
     * Parses stdout/stderr to find agent log lines and writes them to separate agent log files.
     * Format: [AGENT_LOG]agentId|level|message
     *
     * @param string $output Worker output (stdout or stderr)
     * @param string $workerLogFile Worker log file path
     * @param string $logDirectory Log directory for agent logs
     * @param bool $isStderr Whether this is stderr (for error logs)
     */
    private function processWorkerOutput(string $output, string $workerLogFile, string $logDirectory, bool $isStderr): void
    {
        $agentLogMarker = '[AGENT_LOG]';
        $lines = explode("\n", $output);
        $workerLogContent = [];
        $agentLogs = [];

        // Process each line
        foreach ($lines as $line) {
            if (str_starts_with($line, $agentLogMarker)) {
                // This is an agent log line
                $this->parseAgentLogLine($line, $agentLogMarker, $agentLogs);
            } else {
                // Regular worker log line (skip empty lines)
                if ($line !== '') {
                    $workerLogContent[] = $line;
                }
            }
        }

        // Write worker log (without agent log lines)
        if (!empty($workerLogContent)) {
            $workerLogText = implode("\n", $workerLogContent);
            if (!empty(trim($workerLogText))) {
                file_put_contents($workerLogFile, $workerLogText . "\n", FILE_APPEND | LOCK_EX);
            }
        }

        // Write agent logs to separate files
        $this->writeAgentLogs($agentLogs, $logDirectory, $isStderr);
    }

    /**
     * Parse agent log line
     *
     * Format: [AGENT_LOG]agentId|level|message
     *
     * @param string $line Log line
     * @param string $marker Agent log marker
     * @param array<string, array<string, array<string>>> $agentLogs Output array for agent logs [agentId][level][] = message
     */
    private function parseAgentLogLine(string $line, string $marker, array &$agentLogs): void
    {
        // Remove marker
        $content = substr($line, strlen($marker));

        // Split by pipe: agentId|level|message
        $parts = explode('|', $content, 3);
        if (count($parts) < 3) {
            // Invalid format, skip
            return;
        }

        [$agentId, $level, $message] = $parts;
        $agentId = trim($agentId);
        $level = trim($level);
        $message = trim($message);

        if (empty($agentId) || empty($level) || empty($message)) {
            return;
        }

        // Store agent log
        if (!isset($agentLogs[$agentId])) {
            $agentLogs[$agentId] = [];
        }
        if (!isset($agentLogs[$agentId][$level])) {
            $agentLogs[$agentId][$level] = [];
        }
        $agentLogs[$agentId][$level][] = $message;
    }

    /**
     * Write agent logs to separate files
     *
     * @param array<string, array<string, array<string>>> $agentLogs Agent logs [agentId][level][] = message
     * @param string $logDirectory Log directory
     * @param bool $isStderr Whether this is from stderr
     */
    private function writeAgentLogs(array $agentLogs, string $logDirectory, bool $isStderr): void
    {
        foreach ($agentLogs as $agentId => $levels) {
            // Sanitize agent ID for filename (replace : and other special chars)
            $safeAgentId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agentId);

            foreach ($levels as $level => $messages) {
                // Determine log file extension
                // ERROR level or stderr -> .error.log, otherwise -> .log
                $extension = ($level === 'ERROR' || $isStderr) ? '.error.log' : '.log';
                $agentLogFile = $logDirectory . "/agent-{$safeAgentId}{$extension}";

                // Write messages
                foreach ($messages as $message) {
                    file_put_contents($agentLogFile, $message . "\n", FILE_APPEND | LOCK_EX);
                }
            }
        }
    }

    /**
     * Ensure minimum number of workers are running
     *
     * Starts missing workers one at a time (not more than one per tick).
     * Process startup takes about a second, so we start one and wait for next tick.
     *
     * @throws CouldNotStartException If worker cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     */
    private function ensureMinWorkers(): void
    {
        // Don't start new workers if preparing for shutdown
        if ($this->preparingShutdown) {
            return;
        }

        // Start one regular worker if needed (not more than one per tick)
        if (count($this->regularWorkers) < $this->minRegular && count($this->regularWorkers) < $this->maxRegular) {
            $this->startRegularWorker();
            return; // Exit after starting one - next tick will check again
        }

        // Start one monopolistic worker if needed (not more than one per tick)
        if (count($this->monopolisticWorkers) < $this->minMonopolistic && $this->minMonopolistic > 0) {
            $this->startMonopolisticWorker();
            return; // Exit after starting one - next tick will check again
        }
    }

    /**
     * Start regular worker process
     *
     * Uses minimum available index, or next sequential if none available.
     *
     * @throws CouldNotStartException If worker cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     */
    private function startRegularWorker(): void
    {
        // Get minimum available index or use next sequential
        if (!empty($this->availableRegularIndices)) {
            $workerIndex = array_shift($this->availableRegularIndices);
        } else {
            $workerIndex = $this->nextRegularWorkerIndex++;
        }

        $process = new Process(
            'php',
            array_merge([$this->workerScript], ArgumentHelper::buildWorkerArgs($workerIndex)),
            $this->workingDirectory,
            [Process::DESCRIPTOR_PIPE, Process::PIPE_READ], // stdin
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stdout
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stderr
        );

        $this->regularWorkers[$workerIndex] = $process;

        // Log worker start
        Logger::info("Worker #{$workerIndex} started [type=regular]");
    }

    /**
     * Start monopolistic worker process
     *
     * Uses minimum available index, or next sequential if none available.
     *
     * @throws CouldNotStartException If worker cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     */
    private function startMonopolisticWorker(): void
    {
        // Get minimum available index or use next sequential
        if (!empty($this->availableMonopolisticIndices)) {
            $workerIndex = array_shift($this->availableMonopolisticIndices);
        } else {
            $workerIndex = $this->nextMonopolisticWorkerIndex++;
        }

        $process = new Process(
            'php',
            array_merge([$this->workerScript], ArgumentHelper::buildWorkerArgs($workerIndex, true)),
            $this->workingDirectory,
            [Process::DESCRIPTOR_PIPE, Process::PIPE_READ], // stdin
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stdout
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stderr
        );

        $this->monopolisticWorkers[$workerIndex] = $process;

        // Log worker start
        Logger::info("Worker #{$workerIndex} started [type=monopolistic]");
    }

    /**
     * Stop all worker processes with graceful shutdown
     *
     * Sends SIGTERM to all workers and tracks them for graceful shutdown.
     * Actual termination will happen asynchronously in tick() method.
     * Does NOT close socket or worker client connections - workers need to disconnect themselves.
     */
    public function stop(): void
    {
        $currentTime = microtime(true);

        // Stop regular workers (send SIGTERM, track for graceful shutdown)
        foreach ($this->regularWorkers as $workerIndex => $process) {
            try {
                $process->stop(); // Send SIGTERM
                $this->regularWorkersWaitingShutdown[$workerIndex] = $currentTime;
                Logger::info("Sent stop signal to regular worker #{$workerIndex}");
            } catch (\Throwable $e) {
                Logger::error("Failed to stop regular worker #{$workerIndex}: " . $e->getMessage());
                // Force remove if stop failed
                unset($this->regularWorkers[$workerIndex]);
            }
        }

        // Stop monopolistic workers (send SIGTERM, track for graceful shutdown)
        foreach ($this->monopolisticWorkers as $workerIndex => $process) {
            try {
                $process->stop(); // Send SIGTERM
                $this->monopolisticWorkersWaitingShutdown[$workerIndex] = $currentTime;
                Logger::info("Sent stop signal to monopolistic worker #{$workerIndex}");
            } catch (\Throwable $e) {
                Logger::error("Failed to stop monopolistic worker #{$workerIndex}: " . $e->getMessage());
                // Force remove if stop failed
                unset($this->monopolisticWorkers[$workerIndex]);
            }
        }

        // Note: Do NOT call parent::stop() here - we don't want to close worker client connections.
        // Workers need to complete their work and disconnect themselves gracefully.
        // Socket will be closed when daemon stops, but worker connections should remain until workers disconnect.
    }

    /**
     * Check workers waiting for graceful shutdown
     *
     * Called in tick() to asynchronously check if workers have exited gracefully.
     * Force kills workers that exceed timeout.
     */
    private function checkGracefulShutdown(): void
    {
        $currentTime = microtime(true);

        // Check regular workers waiting for shutdown
        foreach ($this->regularWorkersWaitingShutdown as $workerIndex => $shutdownStartTime) {
            // Check if timeout exceeded
            if (($currentTime - $shutdownStartTime) >= self::SHUTDOWN_TIMEOUT) {
                // Timeout reached, force kill
                $this->forceKillRegularWorker($workerIndex);
                unset($this->regularWorkersWaitingShutdown[$workerIndex]);
                continue;
            }

            // Check if worker still exists and is running
            $process = $this->regularWorkers[$workerIndex] ?? null;
            if ($process === null) {
                // Worker already terminated
                unset($this->regularWorkersWaitingShutdown[$workerIndex]);
                continue;
            }

            // Check status
            try {
                $status = $process->getStatus();
                if (!$status[Process::STATUS_RUNNING]) {
                    // Worker stopped - read final output before removing
                    $this->saveWorkerOutput($process, 'regular', $workerIndex);

                    // Worker died, remove from list and free index for reuse
                    Logger::info("Worker #{$workerIndex} graceful stopped [type=regular]");

                    // Worker exited gracefully
                    unset($this->regularWorkersWaitingShutdown[$workerIndex]);
                    // Remove from active workers
                    unset($this->regularWorkers[$workerIndex]);
                    // Free index for reuse
                    $this->availableRegularIndices[] = $workerIndex;
                    sort($this->availableRegularIndices);
                }
            } catch (\Throwable $e) {
                // Error checking status, force kill
                $this->forceKillRegularWorker($workerIndex);
                unset($this->regularWorkersWaitingShutdown[$workerIndex]);
            }
        }

        // Check monopolistic workers waiting for shutdown
        foreach ($this->monopolisticWorkersWaitingShutdown as $workerIndex => $shutdownStartTime) {
            // Check if timeout exceeded
            if (($currentTime - $shutdownStartTime) >= self::SHUTDOWN_TIMEOUT) {
                // Timeout reached, force kill
                $this->forceKillMonopolisticWorker($workerIndex);
                unset($this->monopolisticWorkersWaitingShutdown[$workerIndex]);
                continue;
            }

            // Check if worker still exists and is running
            $process = $this->monopolisticWorkers[$workerIndex] ?? null;
            if ($process === null) {
                // Worker already terminated
                unset($this->monopolisticWorkersWaitingShutdown[$workerIndex]);
                continue;
            }

            // Check status
            try {
                $status = $process->getStatus();
                if (!$status[Process::STATUS_RUNNING]) {
                    // Worker stopped - read final output before removing
                    $this->saveWorkerOutput($process, 'monopolistic', $workerIndex);

                    // Worker died, remove from list and free index for reuse
                    Logger::info("Worker #{$workerIndex} graceful stopped [type=monopolistic]");

                    // Worker exited gracefully
                    unset($this->monopolisticWorkersWaitingShutdown[$workerIndex]);
                    // Remove from active workers
                    unset($this->monopolisticWorkers[$workerIndex]);
                    // Free index for reuse
                    $this->availableMonopolisticIndices[] = $workerIndex;
                    sort($this->availableMonopolisticIndices);
                }
            } catch (\Throwable $e) {
                // Error checking status, force kill
                $this->forceKillMonopolisticWorker($workerIndex);
                unset($this->monopolisticWorkersWaitingShutdown[$workerIndex]);
            }
        }
    }

    /**
     * Force kill regular worker process
     *
     * @param int $workerIndex Worker index
     */
    private function forceKillRegularWorker(int $workerIndex): void
    {
        $process = $this->regularWorkers[$workerIndex] ?? null;
        if ($process === null) {
            return;
        }

        try {
            $process->halt(); // Force kill with SIGKILL
            Logger::info("Force killed regular worker #{$workerIndex}");
        } catch (\Throwable $e) {
            Logger::error("Failed to force kill regular worker #{$workerIndex}: " . $e->getMessage());
        }

        // Remove from active workers
        unset($this->regularWorkers[$workerIndex]);

        // Free index for reuse
        $this->availableRegularIndices[] = $workerIndex;
        sort($this->availableRegularIndices);
    }

    /**
     * Force kill monopolistic worker process
     *
     * @param int $workerIndex Worker index
     */
    private function forceKillMonopolisticWorker(int $workerIndex): void
    {
        $process = $this->monopolisticWorkers[$workerIndex] ?? null;
        if ($process === null) {
            return;
        }

        try {
            $process->halt(); // Force kill with SIGKILL
            Logger::info("Force killed monopolistic worker #{$workerIndex}");
        } catch (\Throwable $e) {
            Logger::error("Failed to force kill monopolistic worker #{$workerIndex}: " . $e->getMessage());
        }

        // Remove from active workers
        unset($this->monopolisticWorkers[$workerIndex]);

        // Free index for reuse
        $this->availableMonopolisticIndices[] = $workerIndex;
        sort($this->availableMonopolisticIndices);
    }

    /**
     * Start agent on appropriate worker
     *
     * Agent router: selects appropriate worker and starts agent.
     * For monopolistic agents, uses monopolistic worker.
     * For regular agents, uses regular worker (load balancing).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return bool True if agent was started or already exists, false if no suitable worker available
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     */
    protected function startAgent(string $agentType, ?string $agentIndex = null): bool
    {
        // Build agent ID
        $agentId = $this->buildAgentId($agentType, $agentIndex);

        // Check if agent already exists
        if (isset($this->agentDaemons[$agentId])) {
            $agentDaemon = $this->agentDaemons[$agentId];
            if ($agentDaemon->getWorkerClient() !== null) {
                return true; // Agent already running and linked to worker
            }
        }

        // Create agent daemon if it doesn't exist
        if (!isset($this->agentDaemons[$agentId])) {
            // Store agent daemon temporarily - will be linked when agent_started arrives
            $this->agentDaemons[$agentId] = $this->getAgentDaemonFactoryClass()::createAgentDaemon($agentType, $agentIndex);
        }

        // Select appropriate worker
        $workerClient = $this->selectWorkerForAgent($this->agentDaemons[$agentId]->requiresMonopolisticProcess());

        // If no suitable worker available, add to pending and return false
        if ($workerClient === null) {
            $this->pendingAgents[$agentId] = [
                'agentType' => $agentType,
                'agentIndex' => $agentIndex,
            ];
            return false;
        }

        // Remove from pending if was there
        unset($this->pendingAgents[$agentId]);

        // Link agent daemon to selected worker
        $this->agentDaemons[$agentId]->setWorkerClient($workerClient);

        // Send agent_start signal to worker
        $workerClient->sendAgentStart($agentId, $agentType, $agentIndex);

        return true;
    }

    /**
     * Select worker for agent based on monopolistic requirement
     *
     * For monopolistic agents: selects from monopolistic workers with exactly 0 agents.
     * For regular agents: selects from regular workers with load balancing (random choice among workers with minimum agent count).
     *
     * @param bool $requiresMonopolistic True if agent requires monopolistic worker
     * @return WorkerClient|null Selected worker client or null if no suitable worker available
     */
    private function selectWorkerForAgent(bool $requiresMonopolistic): ?WorkerClient
    {
        $candidates = [];
        $workerAgentCounts = [];

        // Collect suitable worker candidates
        foreach ($this->clients as $client) {
            if (!$client instanceof WorkerClient) {
                continue;
            }

            // Check if worker type matches requirement
            if ($client->isMonopolistic() !== $requiresMonopolistic) {
                continue;
            }

            $workerIndex = $client->getWorkerIndex();
            $agentCount = $this->getAgentCountOnWorker($workerIndex);

            // For monopolistic workers: must have exactly 0 agents
            // For regular workers: collect all (will select by load later)
            if ($requiresMonopolistic) {
                if ($agentCount === 0) {
                    $candidates[] = $client;
                }
            } else {
                $candidates[] = $client;
                $workerAgentCounts[$workerIndex] = $agentCount;
            }
        }

        // If no candidates found, return null
        if (empty($candidates)) {
            return null;
        }

        // For monopolistic: return random candidate (all have 0 agents)
        if ($requiresMonopolistic) {
            return $candidates[array_rand($candidates)];
        }

        // Find minimum agent count
        $minCount = min($workerAgentCounts);

        // Filter candidates to only those with minimum agent count
        $minLoadCandidates = [];
        foreach ($candidates as $client) {
            $workerIndex = $client->getWorkerIndex();
            if ($workerAgentCounts[$workerIndex] === $minCount) {
                $minLoadCandidates[] = $client;
            }
        }

        // Return random worker from minimum load candidates
        return $minLoadCandidates[array_rand($minLoadCandidates)];
    }

    /**
     * Get agent count on worker
     *
     * @param int $workerIndex Worker index
     * @return int Number of agents on worker
     */
    private function getAgentCountOnWorker(int $workerIndex): int
    {
        $count = 0;
        foreach ($this->agentDaemons as $agentDaemon) {
            $workerClient = $agentDaemon->getWorkerClient();
            if ($workerClient !== null && $workerClient->getWorkerIndex() === $workerIndex) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Send signal to agent
     *
     * If agent doesn't exist or is not linked to worker, starts it first, then sends signal.
     * If agent cannot be started (no worker available), signal is queued for later delivery.
     * Always sends signal after ensuring agent exists and is linked, or queues it.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @param array $data Signal data
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, array $data): void
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);

        // If agent doesn't exist or not linked to worker, try to start it
        if (!isset($this->agentDaemons[$agentId]) || $this->agentDaemons[$agentId]->getWorkerClient() === null) {
            $started = $this->startAgent($agentType, $agentIndex);
            if (!$started) {
                // Agent cannot be started now (no worker available), queue the signal
                if (!isset($this->queuedSignals[$agentId])) {
                    $this->queuedSignals[$agentId] = [];
                }
                $this->queuedSignals[$agentId][] = [
                    'agentType' => $agentType,
                    'agentIndex' => $agentIndex,
                    'data' => $data,
                ];
                return;
            }
        }

        // At this point agent should exist, but might not be linked yet if just started
        if (!isset($this->agentDaemons[$agentId])) {
            // Should not happen, but queue signal just in case
            if (!isset($this->queuedSignals[$agentId])) {
                $this->queuedSignals[$agentId] = [];
            }
            $this->queuedSignals[$agentId][] = [
                'agentType' => $agentType,
                'agentIndex' => $agentIndex,
                'data' => $data,
            ];
            return;
        }

        $agentDaemon = $this->agentDaemons[$agentId];
        $workerClient = $agentDaemon->getWorkerClient();
        if ($workerClient === null) {
            // Agent exists but not linked, queue signal
            if (!isset($this->queuedSignals[$agentId])) {
                $this->queuedSignals[$agentId] = [];
            }
            $this->queuedSignals[$agentId][] = [
                'agentType' => $agentType,
                'agentIndex' => $agentIndex,
                'data' => $data,
            ];
            return;
        }

        // Agent exists and is linked, send signal immediately
        // Extract agent type from agentId (format: "type" or "type:index")
        $parts = explode(':', $agentId, 2);
        $agentTypeFromId = $parts[0];
        $agentIndexFromId = $parts[1] ?? null;

        // Create and send signal DTO
        $dto = new AgentMessageDTO(
            type: AgentMessageDTO::TYPE_AGENT_SIGNAL,
            agentId: $agentId,
            agentType: $agentTypeFromId,
            agentIndex: $agentIndexFromId,
            data: $data,
        );

        $workerClient->send($dto->toJson());
    }

    /**
     * Stop agent
     *
     * Finds agent by ID and sends stop signal to worker.
     *
     * @param string $agentId Agent ID
     * @return bool True if agent was stopped, false if agent not found
     */
    public function stopAgent(string $agentId): bool
    {
        // Check if agent exists and is linked to worker
        if (!isset($this->agentDaemons[$agentId])) {
            return false; // Agent not found
        }

        $agentDaemon = $this->agentDaemons[$agentId];
        $workerClient = $agentDaemon->getWorkerClient();
        if ($workerClient === null) {
            return false; // Agent not linked to worker yet
        }

        // Send agent_stop signal to worker
        $workerClient->sendAgentStop($agentId);

        // Note: Agent daemon will be removed when agent_stopped signal arrives from worker
        // This is handled in handleAgentStopped()

        return true;
    }

    /**
     * Get agent daemon by ID
     *
     * @param string $agentId Agent ID
     * @return AgentDaemonInterface|null Agent daemon or null if not found
     */
    public function getAgentDaemon(string $agentId): ?AgentDaemonInterface
    {
        return $this->agentDaemons[$agentId] ?? null;
    }

    /**
     * Prepare server for shutdown
     *
     * Stops accepting new connections and sends stop signal to all worker processes.
     */
    public function prepareShutdown(): void
    {
        parent::prepareShutdown();

        // Send stop signal to all worker processes
        $this->stop();
    }

    /**
     * Check if server is ready to shutdown
     *
     * Worker server is ready when all worker processes have stopped.
     *
     * @return bool True if ready to shutdown
     */
    public function isReadyToShutdown(): bool
    {
        // Ready when all workers have stopped
        return $this->getRegularWorkersCount() === 0 && $this->getMonopolisticWorkersCount() === 0;
    }
}
