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
use Hilos\Exception\Worker\NoSuitableWorkerException;
use Hilos\Utils\DTO\Worker\AgentMessageDTO;
use Hilos\Utils\DTO\Worker\WorkerAgentStartedDTO;
use Hilos\Utils\DTO\Worker\WorkerAgentStoppedDTO;
use Hilos\Socket\Client\ClientInterface;
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

    /** @var float Graceful shutdown timeout in seconds */
    protected float $shutdownTimeout = 5.0;

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

    /** @var bool Whether onInitialWorkersReady() has been called */
    private bool $initialWorkersReadyCalled = false;

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
        return new WorkerClient($socket, $signalRouter);
    }

    /**
     * Handle agent lifecycle signals from workers
     *
     * Called from checkWorkerRegistration when processing worker messages.
     * 
     * @param WorkerClient $workerClient Worker client that sent the signal
     * @param array $data Signal data
     * @throws AgentDaemonCreationFailedException
     */
    protected function handleAgentMessage(WorkerClient $workerClient, array $data): void
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
        try {
            $dto = WorkerAgentStartedDTO::fromArray($data);
        } catch (\Throwable $e) {
            Logger::error("Failed to parse agent_started DTO: " . $e->getMessage());
            return;
        }

        $agentId = $dto->agentId;
        $agentType = $dto->agentType;
        $agentIndex = $dto->agentIndex;

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

        Logger::info("Agent {$agentId} started on worker #{$workerClient->getWorkerIndex()} [daemon side]");
    }

    /**
     * Handle agent_stopped signal from worker
     *
     * @param WorkerClient $workerClient Worker client
     * @param array $data Signal data
     */
    protected function handleAgentStopped(WorkerClient $workerClient, array $data): void
    {
        try {
            $dto = WorkerAgentStoppedDTO::fromArray($data);
        } catch (\Throwable $e) {
            Logger::error("Failed to parse agent_stopped DTO: " . $e->getMessage());
            return;
        }

        $agentId = $dto->agentId;

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
     * Called when initial workers are ready
     *
     * This method is called once when the minimum number of registered workers
     * (both regular and monopolistic) has been reached according to configuration.
     * Must be implemented by child classes to perform initialization that requires workers to be ready.
     */
    abstract protected function onInitialWorkersReady(): void;

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
    public function onTick(): void
    {
        // Process clients (read/write)
        // Registration timeout is handled in WorkerClient::onTick()
        parent::onTick();

        // Handle newly registered workers and process agent messages
        $this->checkWorkerRegistration();

        // Tick all worker processes (check status, read output, handle graceful shutdown)
        $this->tickWorkerProcesses();

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
     * Check worker registration and process agent messages
     *
     * Processes newly registered workers and agent messages.
     * Registration timeout is now handled in WorkerClient::onTick().
     * Also checks if initial workers are ready and calls onInitialWorkersReady() once.
     */
    private function checkWorkerRegistration(): void
    {
        // Process agent messages from all worker clients
        foreach ($this->clients as $client) {
            if (!$client instanceof WorkerClient) {
                continue;
            }

            // Process pending agent messages from worker
            $pendingMessages = $client->getAndClearPendingAgentMessages();
            foreach ($pendingMessages as $messageData) {
                $this->handleAgentMessage($client, $messageData);
            }
        }

        // Check if initial workers are ready (only once)
        if (!$this->initialWorkersReadyCalled) {
            // Count registered workers
            $registeredRegular = 0;
            $registeredMonopolistic = 0;

            foreach ($this->clients as $client) {
                if (!$client instanceof WorkerClient || !$client->isRegistered()) {
                    continue;
                }

                if ($client->isMonopolistic()) {
                    $registeredMonopolistic++;
                } else {
                    $registeredRegular++;
                }
            }

            // Check if both types meet minimum requirements
            $regularReady = $registeredRegular >= $this->minRegular;
            $monopolisticReady = ($this->minMonopolistic === 0) || ($registeredMonopolistic >= $this->minMonopolistic);

            if ($regularReady && $monopolisticReady) {
                $this->initialWorkersReadyCalled = true;
                $this->onInitialWorkersReady();
            }
        }
    }

    /**
     * Remove client from server
     *
     * @param ClientInterface $client Client to remove
     */
    public function removeClient(ClientInterface $client): void
    {
        parent::removeClient($client);
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

                // Check if process is still running
                $status = $process->getStatus();
                
                // Read and save stdout/stderr to files (after status check to ensure we capture final output)
                $this->saveWorkerOutput($process, 'regular', $workerIndex);
                
                if (!$status[Process::STATUS_RUNNING]) {
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

                // Check if process is still running
                $status = $process->getStatus();
                
                // Read and save stdout/stderr to files (after status check to ensure we capture final output)
                $this->saveWorkerOutput($process, 'monopolistic', $workerIndex);
                
                if (!$status[Process::STATUS_RUNNING]) {
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
     * Sends SIGTERM to all workers with shutdown timeout.
     * Actual termination will happen asynchronously in tick() method via Process::tick().
     * Does NOT close socket or worker client connections - workers need to disconnect themselves.
     */
    public function stop(): void
    {
        // Stop regular workers (send SIGTERM with timeout)
        foreach ($this->regularWorkers as $workerIndex => $process) {
            try {
                $process->stop($this->shutdownTimeout); // Send SIGTERM with timeout
                Logger::info("Sent stop signal to regular worker #{$workerIndex}");
            } catch (\Throwable $e) {
                Logger::error("Failed to stop regular worker #{$workerIndex}: " . $e->getMessage());
                // Force remove if stop failed
                unset($this->regularWorkers[$workerIndex]);
            }
        }

        // Stop monopolistic workers (send SIGTERM with timeout)
        foreach ($this->monopolisticWorkers as $workerIndex => $process) {
            try {
                $process->stop($this->shutdownTimeout); // Send SIGTERM with timeout
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
     * Start agent on appropriate worker
     *
     * Agent router: selects appropriate worker and starts agent.
     * For monopolistic agents, uses monopolistic worker.
     * For regular agents, uses regular worker (load balancing).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws NoSuitableWorkerException If no suitable worker is available
     */
    protected function startAgent(string $agentType, ?string $agentIndex = null): void
    {
        // Build agent ID
        $agentId = $this->buildAgentId($agentType, $agentIndex);

        // Check if agent already exists and is linked
        if (isset($this->agentDaemons[$agentId])) {
            $agentDaemon = $this->agentDaemons[$agentId];
            if ($agentDaemon->getWorkerClient() !== null) {
                return; // Agent already running and linked to worker
            }
        }

        // Create agent daemon if it doesn't exist
        if (!isset($this->agentDaemons[$agentId])) {
            $this->agentDaemons[$agentId] = $this->getAgentDaemonFactoryClass()::createAgentDaemon($agentType, $agentIndex);
        }

        // Select appropriate worker
        $workerClient = $this->selectWorkerForAgent($this->agentDaemons[$agentId]->requiresMonopolisticProcess());

        // If no suitable worker available, throw exception
        if ($workerClient === null) {
            $workerType = $this->agentDaemons[$agentId]->requiresMonopolisticProcess() ? 'monopolistic' : 'regular';
            throw new NoSuitableWorkerException($workerType, $this->agentDaemons[$agentId]->requiresMonopolisticProcess());
        }

        // Link agent daemon to selected worker
        $this->agentDaemons[$agentId]->setWorkerClient($workerClient);

        // Send agent_start signal to worker
        $workerClient->sendAgentStart($agentId, $agentType, $agentIndex);
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
     * Throws exception if agent cannot be started (no worker available).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @param array $data Signal data
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws NoSuitableWorkerException If no suitable worker is available
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, array $data): void
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);

        // If agent doesn't exist or not linked to worker, try to start it
        if (!isset($this->agentDaemons[$agentId]) || $this->agentDaemons[$agentId]->getWorkerClient() === null) {
            $this->startAgent($agentType, $agentIndex);
        }

        // At this point agent should exist and be linked
        if (!isset($this->agentDaemons[$agentId])) {
            Logger::error("Agent {$agentId} does not exist after startAgent() call");
            return;
        }

        $agentDaemon = $this->agentDaemons[$agentId];
        $workerClient = $agentDaemon->getWorkerClient();
        if ($workerClient === null) {
            Logger::error("Agent {$agentId} is not linked to worker after startAgent() call");
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
