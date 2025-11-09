<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Process;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalType;
use Hilos\DTO\Worker\DaemonAgentMessageDTO;
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
use Hilos\Exception\Worker\AgentNotFoundException;
use Hilos\Exception\Worker\AgentNotLinkedToWorkerException;
use Hilos\Exception\Worker\NoSuitableWorkerException;
use Hilos\Exception\Worker\WorkerClientNotFoundException;
use Hilos\Logging\Logger\Logger;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Utils\Env;
use Hilos\Utils\Helpers\ArgumentHelper;

/**
 * WorkerServer - Worker communication server implementation
 *
 * Manages worker server socket and accepts incoming connections from workers.
 * Also manages worker processes lifecycle - starts, monitors and stops them.
 * Works with epoll in daemon main loop.
 *
 * This is an abstract class - child classes must implement onStart() and onInitialWorkersReady().
 * Agent manager daemon is passed via constructor.
 */
abstract class WorkerServer extends AbstractServer
{
    /** @var array<string, array{process: Process, type: string, index: int}> Workers indexed by key (format: "type:index") */
    private array $workers = [];

    /** @var array<int> Available worker indices (sorted, can be reused) */
    private array $availableIndices = [];

    /** @var int Next worker index to assign if no available */
    private int $nextWorkerIndex = 1;

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

    /** @var AgentManagerDaemon Agent manager daemon instance */
    private AgentManagerDaemon $agentManager;

    /** @var bool Whether onInitialWorkersReady() has been called */
    private bool $initialWorkersReadyCalled = false;

    /**
     * WorkerServer constructor
     *
     * @param string $host Host to bind
     * @param int $port Port to bind
     * @param string $workerScript Path to worker bootstrap script
     * @param string $workingDirectory Working directory for worker processes
     * @param SignalRouter $signalRouter Signal router instance
     * @param AgentManagerDaemon $agentManager Agent manager daemon instance
     * @throws MissingEnvironmentVariableException
     */
    public function __construct(string $host, int $port, string $workerScript, string $workingDirectory, SignalRouter $signalRouter, AgentManagerDaemon $agentManager)
    {
        parent::__construct($host, $port, $signalRouter);

        $this->workerScript = $workerScript;
        $this->workingDirectory = $workingDirectory;
        $this->agentManager = $agentManager;

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
        return new WorkerClient($socket, $signalRouter, $this->agentManager);
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
        return count(array_filter($this->workers, fn($worker) => $worker['type'] === 'regular'));
    }

    /**
     * Get count of active monopolistic worker processes
     *
     * @return int Number of active monopolistic workers
     */
    public function getMonopolisticWorkersCount(): int
    {
        return count(array_filter($this->workers, fn($worker) => $worker['type'] === 'monopolistic'));
    }

    /**
     * Build worker key from type and index
     *
     * @param bool $isMonopolistic True if monopolistic
     * @param int $index Worker index
     * @return string Worker key (format: "type:index")
     */
    private function buildWorkerKey(bool $isMonopolistic, int $index): string
    {
        $type = $isMonopolistic ? 'monopolistic' : 'regular';
        return "{$type}:{$index}";
    }

    /**
     * Parse worker key to extract type and index
     *
     * @param string $key Worker key (format: "type:index")
     * @return array{type: string, index: int}
     */
    private function parseWorkerKey(string $key): array
    {
        [$type, $index] = explode(':', $key, 2);
        return [
            'type' => $type,
            'index' => (int)$index
        ];
    }

    /**
     * Get workers count by type
     *
     * @param string $type Worker type ('regular' or 'monopolistic')
     * @return int Count
     */
    private function getWorkersCountByType(string $type): int
    {
        return count(array_filter($this->workers, fn($worker) => $worker['type'] === $type));
    }

    /**
     * Get next available worker index
     *
     * @return int Worker index
     */
    private function getNextWorkerIndex(): int
    {
        if (!empty($this->availableIndices)) {
            return array_shift($this->availableIndices);
        }

        return $this->nextWorkerIndex++;
    }

    /**
     * Remove worker from tracking
     *
     * @param string $key Worker key
     * @param string $type Worker type
     * @param int $index Worker index
     */
    private function removeWorker(string $key, string $type, int $index): void
    {
        unset($this->workers[$key]);
        $this->availableIndices[] = $index;
        sort($this->availableIndices); // Keep sorted
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
     * Check worker registration
     *
     * Checks if initial workers are ready and calls onInitialWorkersReady() once.
     * Agent messages are now processed directly in WorkerClient.
     */
    private function checkWorkerRegistration(): void
    {
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

                // Send workers ready signal to daemon
                $this->signalRouter->queueSignal(
                    new SignalSource(SignalSource::DAEMON),
                    new SignalType(SignalTypeConstants::SYSTEM),
                    new SignalName(SignalConstants::WORKERS_READY),
                    new SignalData(),
                );
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
     * Tick all worker processes - check status and read output
     */
    private function tickWorkerProcesses(): void
    {
        foreach ($this->workers as $key => $worker) {
            $process = $worker['process'];
            $type = $worker['type'];
            $index = $worker['index'];

            try {
                $this->tickWorkerProcess($process, $type, $index, $key);
            } catch (FailedToClosePipeException | FailedToTerminateProcessExceptionException $e) {
                // Worker error, remove from tracking
                $this->removeWorker($key, $type, $index);
            } catch (FailedToGetStatusException | FailedToReadStdOutException | FailedToSetStdErrException $e) {
                try {
                    $process->stop();
                } catch (FailedToGetStatusException | FailedToTerminateProcessExceptionException $e) {
                    // Ignore errors during halt
                }
                // Process error, remove from tracking
                $this->removeWorker($key, $type, $index);
            }
        }
    }

    /**
     * Tick single worker process - check status and read output
     *
     * @param Process $process Worker process
     * @param string $type Worker type
     * @param int $index Worker index
     * @param string $key Worker key
     */
    private function tickWorkerProcess(Process $process, string $type, int $index, string $key): void
    {
        $process->tick();

        // Check if process is still running
        $status = $process->getStatus();

        // Read and save stdout/stderr to files (after status check to ensure we capture final output)
        $this->saveWorkerOutput($process, $type, $index);

        if (!$status[Process::STATUS_RUNNING]) {
            // Worker died, remove from tracking
            Logger::info("Worker #{$index} stopped [type={$type}]");
            $this->removeWorker($key, $type, $index);
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

        $types = [
            'regular' => ['min' => $this->minRegular, 'max' => $this->maxRegular],
            'monopolistic' => ['min' => $this->minMonopolistic, 'max' => PHP_INT_MAX]
        ];

        foreach ($types as $type => $limits) {
            $count = $this->getWorkersCountByType($type);
            $isMonopolistic = ($type === 'monopolistic');

            // Check if we need to start a worker of this type
            if ($count < $limits['min']) {
                // For regular workers, also check max limit
                if ($type === 'regular' && $count >= $limits['max']) {
                    continue;
                }
                // For monopolistic workers, skip if min is 0
                if ($type === 'monopolistic' && $limits['min'] === 0) {
                    continue;
                }

                $this->startWorker($isMonopolistic);
                return; // Exit after starting one - next tick will check again
            }
        }
    }

    /**
     * Start worker process
     *
     * Uses next available index (reused from stopped workers if available, otherwise next sequential).
     * All workers share the same index space regardless of type.
     *
     * @param bool $isMonopolistic True if monopolistic worker
     * @throws CouldNotStartException If worker cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     */
    private function startWorker(bool $isMonopolistic): void
    {
        $type = $isMonopolistic ? 'monopolistic' : 'regular';

        // Get next available index
        $workerIndex = $this->getNextWorkerIndex();

        // Create process
        $process = new Process(
            'php',
            array_merge([$this->workerScript], ArgumentHelper::buildWorkerArgs($workerIndex, $isMonopolistic)),
            $this->workingDirectory,
            [Process::DESCRIPTOR_PIPE, Process::PIPE_READ], // stdin
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stdout
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stderr
        );

        // Store worker
        $key = $this->buildWorkerKey($isMonopolistic, $workerIndex);
        $this->workers[$key] = [
            'process' => $process,
            'type' => $type,
            'index' => $workerIndex,
        ];

        // Log worker start
        Logger::info("Worker #{$workerIndex} started [type={$type}]");
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
        foreach ($this->workers as $key => $worker) {
            $process = $worker['process'];
            $index = $worker['index'];
            $type = $worker['type'];

            try {
                $process->stop($this->shutdownTimeout); // Send SIGTERM with timeout
                Logger::debug("Sent stop signal to {$type} worker #{$index}");
            } catch (\Throwable $e) {
                Logger::error("Failed to stop {$type} worker #{$index}: " . $e->getMessage());
                // Force remove if stop failed
                unset($this->workers[$key]);
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
        if ($this->agentManager->hasAgent($agentId)) {
            $agentDaemon = $this->agentManager->getAgent($agentId);
            if ($agentDaemon !== null && $agentDaemon->getWorkerClient() !== null) {
                return; // Agent already running and linked to worker
            }
        }

        // Create agent daemon if it doesn't exist (temporary, will be linked to worker below)
        if (!$this->agentManager->hasAgent($agentId)) {
            // Create agent daemon with dummy worker info (will be updated when linked)
            $this->agentManager->createAndAddAgent($agentType, $agentIndex, 0, false);
        }

        $agentDaemon = $this->agentManager->getAgent($agentId);
        if ($agentDaemon === null) {
            throw new AgentDaemonCreationFailedException("Failed to create agent daemon for {$agentId}");
        }

        // Select appropriate worker
        $workerClient = $this->selectWorkerForAgent($agentDaemon->requiresMonopolisticProcess());

        // If no suitable worker available, throw exception
        if ($workerClient === null) {
            $workerType = $agentDaemon->requiresMonopolisticProcess() ? 'monopolistic' : 'regular';
            throw new NoSuitableWorkerException($workerType, $agentDaemon->requiresMonopolisticProcess());
        }

        // Update agent daemon mapping with actual worker info
        $this->agentManager->removeAgent($agentId);
        $this->agentManager->addAgent($agentId, $agentDaemon, $workerClient->getWorkerIndex(), $workerClient->isMonopolistic());

        // Link agent daemon to selected worker
        $agentDaemon->setWorkerClient($workerClient);

        // Send agent_start signal to worker
        $workerClient->sendAgentStart($agentType, $agentIndex);
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
            $isMonopolistic = $client->isMonopolistic();
            $agentCount = $this->agentManager->getAgentCountOnWorker($workerIndex, $isMonopolistic);

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
     * Find worker client by worker ID
     *
     * @param int $workerId Worker ID (negative = monopolistic, positive = regular)
     * @return ?WorkerClient Worker client or null if not found
     */
    private function findWorkerClientById(int $workerId): ?WorkerClient
    {
        $isMonopolistic = $workerId < 0;
        $workerIndex = abs($workerId);

        foreach ($this->clients as $client) {
            if (!$client instanceof WorkerClient) {
                continue;
            }
            if ($client->getWorkerIndex() === $workerIndex && $client->isMonopolistic() === $isMonopolistic) {
                return $client;
            }
        }

        return null;
    }

    /**
     * Send signal to agent
     *
     * If agent doesn't exist or is not linked to worker, starts it first, then sends signal.
     * Throws exception if agent cannot be started or found.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @param DaemonAgentMessageDTO $messageDto Message DTO containing signal data
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws NoSuitableWorkerException If no suitable worker is available
     * @throws AgentNotFoundException If agent does not exist after startAgent() call
     * @throws AgentNotLinkedToWorkerException If agent is not linked to worker
     * @throws WorkerClientNotFoundException If worker client is not found for agent
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);

        // If agent doesn't exist or not linked to worker, try to start it
        if (!$this->agentManager->hasAgent($agentId)) {
            $this->startAgent($agentType, $agentIndex);
        } else {
            $agentDaemon = $this->agentManager->getAgent($agentId);
            if ($agentDaemon === null || $agentDaemon->getWorkerClient() === null) {
                $this->startAgent($agentType, $agentIndex);
            }
        }

        // At this point agent should exist
        if (!$this->agentManager->hasAgent($agentId)) {
            throw new AgentNotFoundException($agentId);
        }

        $agentDaemon = $this->agentManager->getAgent($agentId);
        if ($agentDaemon === null) {
            throw new AgentNotFoundException($agentId);
        }

        // Get worker client from mapping
        $workerInfo = $this->agentManager->getAgentWorkerInfo($agentId);
        if ($workerInfo === null) {
            throw new AgentNotLinkedToWorkerException($agentId);
        }

        // Ensure agent daemon has worker client set
        $workerClient = $agentDaemon->getWorkerClient();
        if ($workerClient === null) {
            $workerClient = $this->findWorkerClientById($this->agentManager->getAgentWorkerId($agentId));
            if ($workerClient === null) {
                throw new WorkerClientNotFoundException($agentId, $workerInfo['workerIndex'], $workerInfo['isMonopolistic']);
            }

            $agentDaemon->setWorkerClient($workerClient);
        }

        // Agent exists and is linked, send message DTO immediately
        $workerClient->send($messageDto->toJson());
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
        return count($this->workers) === 0;
    }
}
