<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Cluster\AgentSignalSink;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\HilosException;
use Hilos\ProtectedMode\ProtectedModeAgentFreezer;
use Hilos\ProtectedMode\ProtectedModeReadyRelay;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Daemon\LiveConnectionRoster;
use Hilos\Core\Daemon\ProtectedModeSnapshotSource;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentNotFoundException;
use Hilos\Core\Agent\Exception\AgentNotLinkedToWorkerException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Core\Agent\Exception\WorkerClientNotFoundException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\Process\CouldNotStartException;
use Hilos\Core\Exception\Process\FailedToClosePipeException;
use Hilos\Core\Exception\Process\FailedToGetStatusException;
use Hilos\Core\Exception\Process\FailedToReadStdOutException;
use Hilos\Core\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Core\Exception\Process\FailedToSetStdErrException;
use Hilos\Core\Exception\Process\FailedToTerminateProcessException;
use Hilos\Core\Exception\Process\FailedToWriteStdInException;
use Hilos\Core\Process;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Utils\Logger;
use Random\RandomException;
use Throwable;

/**
 * WorkerServer - Worker communication server implementation.
 *
 * Manages worker server socket and accepts incoming connections from workers.
 * Also manages worker processes lifecycle - starts, monitors and stops them.
 * Works with epoll in daemon main loop.
 *
 * This is an abstract class - child classes must implement onStart() and onInitialWorkersReady().
 * Agent manager daemon is passed via constructor.
 *
 * @extends AbstractServer<WorkerClientInterface>
 */
abstract class WorkerServer extends AbstractServer implements PlacementExecutor, AgentSignalSink, ProtectedModeReadyRelay, ProtectedModeAgentFreezer
{
    /**
     * @var array<string, array<string, Process|string|int>> Workers indexed by key (format:
     *     "type:index"), values: WorkerConstants::FIELD_WORKER_*
     */
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

    /** @var ?LiveConnectionRoster Master seam naming the node's live sockets, wired at registration */
    private ?LiveConnectionRoster $liveConnectionRoster = null;

    /** @var float Interval between worker processes tick checks in seconds */
    private const float WORKER_PROCESSES_TICK_INTERVAL = 1.0;

    /** @var string PHP binary used to spawn worker processes */
    private const string PHP_BINARY = 'php';

    /** @var string Standard log file extension */
    private const string LOG_EXTENSION = '.log';

    /** @var string Error log file extension */
    private const string ERROR_LOG_EXTENSION = '.error.log';

    /** @var string Worker log file name prefix */
    private const string WORKER_LOG_PREFIX = 'worker-';

    /** @var string Agent log file name prefix */
    private const string AGENT_LOG_PREFIX = 'agent-';

    /** @var string Regex pattern for sanitizing agent ID in log file names */
    private const string AGENT_ID_SANITIZE_PATTERN = '/[^a-zA-Z0-9_-]/';

    /** @var string Replacement for unsafe characters in agent log file names */
    private const string AGENT_ID_SANITIZE_REPLACEMENT = '_';

    /** @var string Worker count limit key: minimum */
    private const string LIMIT_MIN = 'min';

    /** @var string Worker count limit key: maximum */
    private const string LIMIT_MAX = 'max';

    /** @var int Directory permissions for worker/agent log files */
    private const int LOG_DIR_PERMISSIONS = 0755;

    /** @var int Placeholder worker index before agent is linked to a worker */
    private const int UNLINKED_WORKER_INDEX = 0;

    /** @var ?float Last time worker processes were ticked (null = never) */
    private ?float $lastWorkerProcessesTick = null;

    /** @var ?string Cached log directory path */
    private ?string $cachedLogDirectory = null;

    /** @var list<AgentId> Agents stopped for the current protected-mode freeze, replayed on lift; empty outside a freeze */
    private array $protectedModeStoppedAgents = [];

    /**
     * Create worker server with host, port, script paths and agent manager.
     *
     * @param string $host Host to bind
     * @param int $port Port to bind
     * @param string $workerScript Path to worker bootstrap script
     * @param string $workingDirectory Working directory for worker processes
     * @param AgentManagerDaemon $agentManager Agent manager daemon instance
     * @throws EnvException If worker or log env values are missing or invalid
     */
    public function __construct(string $host, int $port, string $workerScript, string $workingDirectory, AgentManagerDaemon $agentManager)
    {
        parent::__construct($host, $port);

        $this->workerScript = $workerScript;
        $this->workingDirectory = $workingDirectory;
        $this->agentManager = $agentManager;

        // Get worker configuration from environment
        $this->minRegular = Hilos::$env->int(EnvConstants::WORKER_MIN_REGULAR);
        $this->minMonopolistic = Hilos::$env->int(EnvConstants::WORKER_MIN_MONOPOLISTIC);
        $this->maxRegular = Hilos::$env->int(EnvConstants::WORKER_MAX_REGULAR);

        // Ensure log directory exists at startup to avoid repeated is_dir() checks
        $this->ensureLogDirectory();
    }

    /**
     * Wires the seam that names the node's live sockets, for the agent starts this server sends.
     *
     * Held by the server rather than reached for at the moment of use, the same way the
     * WebSocket server holds its connection dropper: the start path must not know the
     * concrete manager behind the master.
     *
     * @param LiveConnectionRoster $liveConnectionRoster Master seam naming the node's live sockets
     */
    public function setLiveConnectionRoster(LiveConnectionRoster $liveConnectionRoster): void
    {
        $this->liveConnectionRoster = $liveConnectionRoster;
    }

    /**
     * Called when a new worker client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return WorkerClientInterface Client instance
     * @throws EnvException When socket read buffer env value is missing or invalid
     */
    protected function onCreateClient($socket): WorkerClientInterface
    {
        return new WorkerClient($socket, $this->agentManager);
    }

    /**
     * Get server name for logging.
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return "Worker Server";
    }

    /**
     * Build agent ID from type and index.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return string Agent ID (format: "type" or "type:index")
     */
    final protected function buildAgentId(string $agentType, ?string $agentIndex): string
    {
        return $agentIndex !== null ? $agentType . AgentConstants::ID_SEPARATOR . $agentIndex : $agentType;
    }

    /**
     * Parses an agent ID into its type and index.
     *
     * @param string $agentId Agent ID (format: "type" or "type:index")
     * @return AgentId Parsed agent identity
     */
    final protected function parseAgentId(string $agentId): AgentId
    {
        return AgentId::fromId($agentId);
    }

    /**
     * Called once when this node's local workers are ready.
     *
     * This is a per-node hook: it fires on every node when the minimum number of
     * registered workers (both regular and monopolistic) has been reached, whether
     * or not the node is the cluster leader. It does not start cluster-singleton
     * agents — that is the leader-gated {@see onBecameSingletonHost()}, driven by the
     * daemon's ensure-once. The base starts every agent the project registry declares
     * {@see AgentScope::NODE} (an empty per-node set is a normal no-op); a per-agent start
     * failure is contained and logged so it never strands the others. Child classes may
     * override for local, non-singleton setup, calling parent::onInitialWorkersReady() first.
     */
    protected function onInitialWorkersReady(): void
    {
        $this->startPerNodeAgents();
    }

    /**
     * Starts every agent the project registry declares {@see AgentScope::NODE}.
     *
     * Shared by the workers-ready bootstrap and by the protected-mode lift default
     * ({@see onProtectedModeLifted()}), which needs exactly this loop, containment included.
     * A per-agent start failure is contained and logged so it never strands the others; an
     * empty per-node set is a normal no-op.
     */
    final protected function startPerNodeAgents(): void
    {
        foreach (Hilos::appClass()::AGENTS as $agentType => $registryEntry) {
            if (!AgentRegistry::startsOnEveryNode($registryEntry)) {
                continue;
            }

            try {
                $this->startAgent($agentType, null);
            } catch (Throwable $throwable) {
                // Contain a per-node start failure so the remaining per-node agents still start.
                Logger::error("Failed to start per-node agent {$agentType}: " . $throwable->getMessage());
            }
        }
    }

    /**
     * Starts this node's cluster-singleton agents; fired once per leadership term.
     *
     * Invoked by the daemon's leader-gated ensure-once once this node is the cluster
     * leader (or the sole node when cluster mode is off) and its workers are ready.
     * The base queues INITIAL_AGENTS_START so the project bootstrap agent list is
     * launched through the existing signal routing. Child classes override to start
     * their own cluster-singletons (e.g. one agent per active bot), calling
     * parent::onBecameSingletonHost() first.
     *
     * Every agent started here still passes the placement gate in {@see startAgent()}, so an
     * agent the registry declares {@see AgentScope::NODE} is unaffected. Re-fires when a
     * follower is later promoted, so it must stay idempotent.
     *
     * @throws InvalidArgumentException When the initial-agents signal cannot be named
     * @throws HilosException Whatever the project's own cluster-singleton start raises
     */
    public function onBecameSingletonHost(): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName(SignalConstants::INITIAL_AGENTS_START),
            new SystemSignalDTO(systemName: SignalConstants::INITIAL_AGENTS_START),
        );
    }

    /**
     * Stops this node's cluster-singleton agents; the mirror of {@see onBecameSingletonHost()}.
     *
     * Invoked by the daemon when this node loses leadership, so a truth source never
     * outlives the term it was elected for: every running agent the registry declares
     * {@see AgentScope::CLUSTER} with {@see AgentPlacement::LEADER} is stopped. The two other
     * cells are left alone on purpose — an every-node replica was never tied to the term, and a
     * policy-placed singleton lives on the node the policy picked, its failover owned by
     * placement rather than by the leader's list. Idempotent — safe to call with nothing to
     * stop — so a second leadership-loss signal is harmless. A child that starts extra
     * cluster-singletons in {@see onBecameSingletonHost()} may override to release matching
     * resources, calling parent::onLostSingletonHost() first.
     */
    public function onLostSingletonHost(): void
    {
        foreach (array_keys($this->agentManager->getAgents()) as $agentId) {
            $parsed = $this->parseAgentId($agentId);
            $registryEntry = Hilos::appClass()::AGENTS[$parsed->type] ?? null;
            if (
                AgentRegistry::scope($registryEntry) !== AgentScope::CLUSTER
                || AgentRegistry::placement($registryEntry) !== AgentPlacement::LEADER
            ) {
                continue;
            }

            $this->stopAgent($parsed->type, $parsed->index);
        }
    }

    /**
     * Get count of active regular worker processes.
     *
     * @return int Number of active regular workers
     */
    public function getRegularWorkersCount(): int
    {
        return count(array_filter($this->workers, fn($worker) => $worker[WorkerConstants::FIELD_WORKER_TYPE] === WorkerConstants::TYPE_REGULAR));
    }

    /**
     * Get count of active monopolistic worker processes.
     *
     * @return int Number of active monopolistic workers
     */
    public function getMonopolisticWorkersCount(): int
    {
        return count(array_filter($this->workers, fn($worker) => $worker[WorkerConstants::FIELD_WORKER_TYPE] === WorkerConstants::TYPE_MONOPOLISTIC));
    }

    /**
     * Build worker key from type and index.
     *
     * @param bool $isMonopolistic True if monopolistic
     * @param int $index Worker index
     * @return string Worker key (format: "type:index")
     */
    private function buildWorkerKey(bool $isMonopolistic, int $index): string
    {
        $type = $isMonopolistic ? WorkerConstants::TYPE_MONOPOLISTIC : WorkerConstants::TYPE_REGULAR;
        return "{$type}" . WorkerConstants::KEY_SEPARATOR . "{$index}";
    }

    /**
     * Parse worker key to extract type and index.
     *
     * @param string $key Worker key (format: "type:index")
     * @return array<string, string|int> Parsed type and index (keys: WorkerConstants::FIELD_WORKER_TYPE, WorkerConstants::FIELD_WORKER_INDEX)
     */
    private function parseWorkerKey(string $key): array
    {
        [$type, $index] = explode(WorkerConstants::KEY_SEPARATOR, $key, WorkerConstants::KEY_MAX_PARTS);
        return [
            WorkerConstants::FIELD_WORKER_TYPE => $type,
            WorkerConstants::FIELD_WORKER_INDEX => (int)$index
        ];
    }

    /**
     * Get workers count by type
     *
     * @param string $type Worker type (WorkerConstants::TYPE_REGULAR or WorkerConstants::TYPE_MONOPOLISTIC)
     * @return int Count
     */
    private function getWorkersCountByType(string $type): int
    {
        return count(array_filter($this->workers, fn($worker) => $worker[WorkerConstants::FIELD_WORKER_TYPE] === $type));
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
     * Checks running processes and starts missing ones. A failure that belongs to one
     * worker is contained by the loops below, the same way the parent contains a
     * failure that belongs to one client.
     *
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function onTick(): void
    {
        // Process clients (read/write)
        // Registration timeout is handled in WorkerClient::onTick()
        parent::onTick();

        // Tick worker processes and related checks (check status, read output, handle graceful shutdown)
        // In normal operation, check once per second to reduce system call overhead.
        // During shutdown, check every tick for faster cleanup.
        $now = microtime(true);
        $shouldTick = $this->preparingShutdown
            || $this->lastWorkerProcessesTick === null
            || ($now - $this->lastWorkerProcessesTick) >= self::WORKER_PROCESSES_TICK_INTERVAL;

        if ($shouldTick) {
            // Handle newly registered workers and process agent messages
            $this->checkWorkerRegistration();

            // Tick all worker processes
            $this->tickWorkerProcesses();

            // Ensure minimum number of workers are running
            try {
                $this->ensureMinWorkers();
            } catch (CouldNotStartException $e) {
                Logger::error("Failed to start worker process: " . $e->getMessage());
            } catch (FailedToSetNonBlockingException $e) {
                Logger::error("Failed to set non-blocking mode for worker process: " . $e->getMessage());
            }

            $this->lastWorkerProcessesTick = $now;
        }
    }

    /**
     * Check worker registration
     *
     * Checks if initial workers are ready and calls onInitialWorkersReady() once.
     * Agent messages are now processed directly in WorkerClient.
     *
     * A readiness announcement that cannot be named is logged rather than thrown -
     * leaving the tick loop would cost this node every connection it is serving. It is
     * announced BEFORE the once-only latch and the hook, so a refusal leaves the node
     * exactly as it was and the next tick tries the whole step again: workersReady gates
     * the WebSocket open, the cluster singletons and cron, and a node that latched
     * without announcing would sit half-started with nothing left to retry. Queueing
     * ahead of the hook is not observable - the daemon dispatches the queue after the
     * tick, not at the call.
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
                // Send workers ready signal to daemon
                try {
                    Hilos::$sr->queueSignal(
                        new SignalSource(SignalSource::DAEMON),
                        new SignalType(SignalTypeConstants::SYSTEM),
                        new SignalName(SignalConstants::WORKERS_READY),
                        new SystemSignalDTO(systemName: SignalConstants::WORKERS_READY),
                    );
                } catch (InvalidArgumentException $exception) {
                    Logger::error(
                        'Workers ready signal could not be named, node stays unannounced until the next tick: '
                        . $exception->getMessage()
                    );
                    return;
                }

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
     * Tick all worker processes - check status and read output
     */
    private function tickWorkerProcesses(): void
    {
        foreach ($this->workers as $key => $worker) {
            $process = $worker[WorkerConstants::FIELD_WORKER_PROCESS];
            $type = $worker[WorkerConstants::FIELD_WORKER_TYPE];
            $index = $worker[WorkerConstants::FIELD_WORKER_INDEX];

            try {
                $this->tickWorkerProcess($process, $type, $index, $key);
            } catch (FailedToClosePipeException | FailedToTerminateProcessException $e) {
                // Worker error, remove from tracking
                $this->removeWorker($key, $type, $index);
            } catch (
                FailedToGetStatusException
                | FailedToReadStdOutException
                | FailedToSetStdErrException
                | FailedToWriteStdInException $e
            ) {
                try {
                    $process->stop();
                } catch (FailedToGetStatusException | FailedToTerminateProcessException $e) {
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
     * Ensure log directory exists
     *
     * Checks and creates log directory if it doesn't exist.
     * Called once in constructor to avoid repeated is_dir() checks during runtime.
     */
    private function ensureLogDirectory(): void
    {
        $logDirectory = $this->getLogDirectory();

        // Ensure log directory exists
        if (!is_dir($logDirectory)) {
            if (!mkdir($logDirectory, self::LOG_DIR_PERMISSIONS, true)) {
                Logger::error("Failed to create log directory: {$logDirectory}");
            }
        }
    }

    /**
     * Get log directory path
     *
     * Caches the result to avoid repeated env lookups.
     *
     * @return string Log directory path
     */
    private function getLogDirectory(): string
    {
        if ($this->cachedLogDirectory === null) {
            // Determine log directory from daemon log file path (same directory)
            // DAEMON_LOG_FILE must be set in environment configuration
            $daemonLogFile = Hilos::$env[EnvConstants::DAEMON_LOG_FILE];
            $this->cachedLogDirectory = dirname($daemonLogFile);
        }

        return $this->cachedLogDirectory;
    }

    /**
     * Save worker stdout and stderr output to files
     *
     * @param Process $process Worker process
     * @param string $workerType Worker type (WorkerConstants::TYPE_REGULAR or WorkerConstants::TYPE_MONOPOLISTIC)
     * @param int $workerIndex Worker index
     */
    private function saveWorkerOutput(Process $process, string $workerType, int $workerIndex): void
    {
        // Get log directory (already ensured to exist in constructor)
        $logDirectory = $this->getLogDirectory();

        // Read stdout and write to file
        $stdout = $process->getStdOut();
        if (!empty($stdout)) {
            $stdoutFile = $logDirectory . '/' . self::WORKER_LOG_PREFIX . "{$workerType}-{$workerIndex}" . self::LOG_EXTENSION;
            $this->processWorkerOutput($stdout, $stdoutFile, $logDirectory, false);
        }

        // Read stderr and write to file
        $stderr = $process->getStdErr();
        if (!empty($stderr)) {
            $stderrFile = $logDirectory . '/' . self::WORKER_LOG_PREFIX . "{$workerType}-{$workerIndex}" . self::ERROR_LOG_EXTENSION;
            $this->processWorkerOutput($stderr, $stderrFile, $logDirectory, true);
        }
    }

    /**
     * Process worker output and extract agent logs
     *
     * Parses stdout/stderr to find agent log lines and writes them to separate agent log files.
     * Format: Logger::AGENT_LOG_MARKER + agentId|level|message
     *
     * @param string $output Worker output (stdout or stderr)
     * @param string $workerLogFile Worker log file path
     * @param string $logDirectory Log directory for agent logs
     * @param bool $isStderr Whether this is stderr (for error logs)
     */
    private function processWorkerOutput(string $output, string $workerLogFile, string $logDirectory, bool $isStderr): void
    {
        $agentLogMarker = Logger::AGENT_LOG_MARKER;
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
     * Format: Logger::AGENT_LOG_MARKER + agentId|level|message
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
        $parts = explode(Logger::AGENT_LOG_FIELD_SEPARATOR, $content, Logger::AGENT_LOG_FIELDS_COUNT);
        if (count($parts) < Logger::AGENT_LOG_FIELDS_COUNT) {
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
            $safeAgentId = preg_replace(self::AGENT_ID_SANITIZE_PATTERN, self::AGENT_ID_SANITIZE_REPLACEMENT, $agentId);

            foreach ($levels as $level => $messages) {
                // Determine log file extension
                // ERROR level or stderr -> .error.log, otherwise -> .log
                $extension = ($level === Logger::LEVEL_ERROR || $isStderr) ? self::ERROR_LOG_EXTENSION : self::LOG_EXTENSION;
                $agentLogFile = $logDirectory . '/' . self::AGENT_LOG_PREFIX . "{$safeAgentId}{$extension}";

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
            WorkerConstants::TYPE_REGULAR => [self::LIMIT_MIN => $this->minRegular, self::LIMIT_MAX => $this->maxRegular],
            WorkerConstants::TYPE_MONOPOLISTIC => [self::LIMIT_MIN => $this->minMonopolistic, self::LIMIT_MAX => PHP_INT_MAX]
        ];

        foreach ($types as $type => $limits) {
            $count = $this->getWorkersCountByType($type);
            $isMonopolistic = ($type === WorkerConstants::TYPE_MONOPOLISTIC);

            // Check if we need to start a worker of this type
            if ($count < $limits[self::LIMIT_MIN]) {
                // For regular workers, also check max limit
                if ($type === WorkerConstants::TYPE_REGULAR && $count >= $limits[self::LIMIT_MAX]) {
                    continue;
                }
                // For monopolistic workers, skip if min is 0
                if ($type === WorkerConstants::TYPE_MONOPOLISTIC && $limits[self::LIMIT_MIN] === 0) {
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
        $type = $isMonopolistic ? WorkerConstants::TYPE_MONOPOLISTIC : WorkerConstants::TYPE_REGULAR;

        // Get next available index
        $workerIndex = $this->getNextWorkerIndex();

        // Create process
        $process = new Process(
            self::PHP_BINARY,
            array_merge([$this->workerScript], ArgumentHelper::buildWorkerArgs($workerIndex, $isMonopolistic)),
            $this->workingDirectory,
            [Process::DESCRIPTOR_PIPE, Process::PIPE_READ], // stdin
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stdout
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stderr
        );

        // Store worker
        $key = $this->buildWorkerKey($isMonopolistic, $workerIndex);
        $this->workers[$key] = [
            WorkerConstants::FIELD_WORKER_PROCESS => $process,
            WorkerConstants::FIELD_WORKER_TYPE => $type,
            WorkerConstants::FIELD_WORKER_INDEX => $workerIndex,
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
            $process = $worker[WorkerConstants::FIELD_WORKER_PROCESS];
            $index = $worker[WorkerConstants::FIELD_WORKER_INDEX];
            $type = $worker[WorkerConstants::FIELD_WORKER_TYPE];

            try {
                $process->stop($this->shutdownTimeout); // Send SIGTERM with timeout
                Logger::debug("Sent stop signal to {$type} worker #{$index}");
            } catch (Throwable $e) {
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
     * An ordinary start carries no placement sanction, so a cluster-wide agent whose node the
     * policy picks ({@see AgentPlacement::POLICY}) comes up here only on the leader; the
     * placement path reaches the same start through {@see executePlacement()}.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws NoSuitableWorkerException If no suitable worker is available
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    protected function startAgent(string $agentType, ?string $agentIndex = null): void
    {
        $this->startAgentInternal($agentType, $agentIndex, false);
    }

    /**
     * The start every entry point shares, with the one bit no caller outside this class may set.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @param bool $placedByLeader True when this node hosts the agent because placement said so
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws NoSuitableWorkerException If no suitable worker is available
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    private function startAgentInternal(string $agentType, ?string $agentIndex, bool $placedByLeader): void
    {
        // Build agent ID
        $agentId = $this->buildAgentId($agentType, $agentIndex);

        // Check if agent already exists and is linked
        $agentExisted = $this->agentManager->hasAgent($agentId);
        if ($agentExisted) {
            $agentDaemon = $this->agentManager->getAgent($agentId);
            if ($agentDaemon !== null && $agentDaemon->hasWorkerClient()) {
                return; // Agent already running and linked to worker
            }
        }

        // Protected-mode freeze gate: while the node is frozen only the initiator agent may
        // start, so an inbound signal cannot revive an agent the freeze just stopped. It sits
        // here, above the temporary record, because a freeze refuses everyone alike - unlike the
        // placement gate below, which refuses only this node and therefore has to leave the
        // record the way a later promotion or placement expects to find it.
        if ($this->protectedModeRefusesStart($agentType, $agentIndex)) {
            Logger::debug("Agent {$agentId} not started: protected mode holds the node");
            return;
        }

        // Create agent daemon if it doesn't exist (temporary, will be linked to worker below)
        if (!$this->agentManager->hasAgent($agentId)) {
            // Create agent daemon with dummy worker info (will be updated when linked)
            $this->agentManager->createAndAddAgent($agentType, $agentIndex, self::UNLINKED_WORKER_INDEX, false);
        }

        $agentDaemon = $this->agentManager->getAgent($agentId)
            ?? throw new AgentDaemonCreationFailedException($agentType, $agentIndex);

        // Placement gate: the registry's two axes decide whether this node may host the agent.
        // An every-node replica starts anywhere; a leader-hosted cluster singleton starts only
        // where leadership sits; a policy-placed cluster singleton starts only where placement
        // put it, which keeps "exactly one cluster-wide" a mechanism rather than a convention
        // the callers are trusted to observe. Standalone nodes are always the leader, so this
        // is a no-op off-cluster.
        $registryEntry = Hilos::appClass()::AGENTS[$agentType] ?? null;
        if (AgentRegistry::scope($registryEntry) === AgentScope::CLUSTER && !$this->amClusterLeader()) {
            $placedByPolicy = AgentRegistry::placement($registryEntry) === AgentPlacement::POLICY;
            if (!$placedByPolicy || !$placedByLeader) {
                // Roll back the temporary record so a later promotion starts it cleanly.
                if (!$agentExisted) {
                    $this->agentManager->removeAgent($agentId);
                }
                Logger::debug($placedByPolicy
                    ? "Agent {$agentId} not started: a cluster-wide agent placed by policy starts only where the leader placed it"
                    : "Agent {$agentId} not started: node is not the cluster leader");
                return;
            }
        }

        // Select appropriate worker
        $workerClient = $this->selectWorkerForAgent($agentDaemon->requiresMonopolisticProcess());

        // If no suitable worker available, throw exception
        if ($workerClient === null) {
            $workerType = $agentDaemon->requiresMonopolisticProcess() ? WorkerConstants::TYPE_MONOPOLISTIC : WorkerConstants::TYPE_REGULAR;
            throw new NoSuitableWorkerException($workerType, $agentDaemon->requiresMonopolisticProcess());
        }

        // Update agent daemon mapping with actual worker info
        $this->agentManager->removeAgent($agentId);
        $this->agentManager->addAgent($agentId, $agentDaemon, $workerClient->getWorkerIndex(), $workerClient->isMonopolistic());

        // Link agent daemon to selected worker
        $agentDaemon->setWorkerClient($workerClient);

        // Send agent_start signal to worker, with the sockets this node holds open right now:
        // the agent coming up is the only one entitled to strike out the connection rows left
        // behind by tabs that closed while it was down (HIL-664).
        $workerClient->sendAgentStart($agentType, $agentIndex, $this->liveConnectionRoster?->liveAcceptKeys() ?? []);
    }

    /**
     * Whether the local node currently holds cluster leadership.
     *
     * Off-cluster the facade reports the node as its own leader, so this is true for
     * a standalone daemon. A failure reading the leadership seam must not block agent
     * start on a single-node daemon, so it is treated as leader (the same defensive
     * stance the daemon takes when resolving its lifecycle phase).
     *
     * @return bool True when the node is the leader, cluster mode is off, or leadership is unreadable
     */
    private function amClusterLeader(): bool
    {
        try {
            return Hilos::$cluster?->amLeader() ?? true;
        } catch (Throwable $e) {
            Logger::error("Cluster leadership unavailable, assuming standalone leader: {$e->getMessage()}");
            return true;
        }
    }

    /**
     * Whether the protected-mode freeze refuses to let this agent start right now.
     *
     * A pure read of the freeze row, with no state of its own: the gate opens again the
     * moment the phase returns to inactive, even if the resume that follows the lift fails.
     * Every non-inactive phase holds except the verification window - a follower stops at
     * activating and never reaches active, and a window during deactivating would reopen the
     * very defect the gate closes. {@see StateProtectedModeRuntime::PHASE_VERIFYING} is the
     * exception because that phase exists to bring the agents back: a verifier has nothing to
     * look at while the page agents are stopped, and a gate still closed there would refuse the
     * very resume the phase orders, start by start, handing the verifier an empty system.
     * An active freeze with no initiator recorded refuses everyone: during a live freeze an
     * unknown initiator must read as "nobody may start", never as "everybody may". Fail-open
     * without a mounted row is safe by construction - the mode cannot be entered at all
     * there ({@see DaemonProtectedModeExecutor::enterActivating()}), so there is no freeze
     * to protect.
     *
     * @param string $agentType Agent type asking to start
     * @param ?string $agentIndex Agent index asking to start, or null for a singleton
     * @return bool True when the freeze refuses this start
     */
    private function protectedModeRefusesStart(string $agentType, ?string $agentIndex): bool
    {
        $freeze = Hilos::$rt?->hilosProtectedModeRuntime;
        if (
            $freeze === null
            || $freeze->phase === StateProtectedModeRuntime::PHASE_INACTIVE
            || $freeze->phase === StateProtectedModeRuntime::PHASE_VERIFYING
        ) {
            return false;
        }

        if ($agentType === HilosAgentType::HILOS_MAIL) {
            // The one pool the freeze lets through, and it is load-bearing: the alert about a node
            // frozen with nothing happening behind it goes out over this pool, so a freeze that
            // stopped it would kill the only channel out of the node exactly when it is needed
            // (HIL-482). Narrow and justified - raw send touches no database, the payload travels
            // whole inside the signal.
            return false;
        }

        if ($freeze->initiatorAgentType === null) {
            return true;
        }

        $initiatorAgentId = $this->buildAgentId(
            $freeze->initiatorAgentType,
            $freeze->initiatorAgentIndex === null ? null : (string)$freeze->initiatorAgentIndex,
        );

        return $this->buildAgentId($agentType, $agentIndex) !== $initiatorAgentId;
    }

    /**
     * Select worker for agent based on monopolistic requirement
     *
     * For monopolistic agents: selects from monopolistic workers with exactly 0 agents.
     * For regular agents: selects from regular workers with load balancing (random choice among workers with minimum agent count).
     *
     * @param bool $requiresMonopolistic True if agent requires monopolistic worker
     * @return ?WorkerClient Selected worker client or null if no suitable worker available
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
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        // Use agentId from messageDto (it already contains the correct agentId)
        $agentId = $messageDto->agentId;

        // Parse agentId to get type and index for startAgent if needed
        $parsed = $this->parseAgentId($agentId);
        $parsedAgentType = $parsed->type;
        $parsedAgentIndex = $parsed->index;

        // If agent doesn't exist or not linked to worker, try to start it. A start the freeze
        // refuses ends the delivery here, quietly: the gate in startAgent() would otherwise
        // surface as AgentNotFoundException below, and dispatchSignals() catches only
        // NoSuitableWorkerException - the freeze would kill the daemon mid-restore. Delivery
        // to the still-running initiator goes through the branch below and is untouched.
        if (!$this->agentManager->hasAgent($agentId)) {
            if ($this->protectedModeRefusesStart($parsedAgentType, $parsedAgentIndex)) {
                Logger::debug("Signal to agent {$agentId} dropped: protected mode holds the node");
                return;
            }
            $this->startAgent($parsedAgentType, $parsedAgentIndex);
        } else {
            $agentDaemon = $this->agentManager->getAgent($agentId);
            if ($agentDaemon === null || !$agentDaemon->hasWorkerClient()) {
                if ($this->protectedModeRefusesStart($parsedAgentType, $parsedAgentIndex)) {
                    Logger::debug("Signal to agent {$agentId} dropped: protected mode holds the node");
                    return;
                }
                $this->startAgent($parsedAgentType, $parsedAgentIndex);
            }
        }

        // At this point agent should exist
        if (!$this->agentManager->hasAgent($agentId)) {
            throw new AgentNotFoundException($agentId);
        }

        $agentDaemon = $this->agentManager->getAgent($agentId)
            ?? throw new AgentNotFoundException($agentId);

        // Get worker client from mapping
        $workerInfo = $this->agentManager->getAgentWorkerInfo($agentId)
            ?? throw new AgentNotLinkedToWorkerException($agentId);

        // Ensure agent daemon has worker client set
        if ($agentDaemon->hasWorkerClient()) {
            $workerClient = $agentDaemon->getWorkerClient();
        } else {
            $workerClient = $this->findWorkerClientById($this->agentManager->getAgentWorkerId($agentId))
                ?? throw new WorkerClientNotFoundException($agentId, $workerInfo->workerIndex, $workerInfo->isMonopolistic);

            $agentDaemon->setWorkerClient($workerClient);
        }

        // Agent exists and is linked, send message DTO immediately
        $workerClient->send($messageDto->toJson());
    }

    /**
     * Delivers a signal forwarded from another node to a local agent.
     *
     * Implements {@see AgentSignalSink} for cross-node signal routing: the target agent was
     * already resolved on the sending node, so this only wraps the signal for the local
     * worker and reuses {@see sendSignalToAgent()} — the same path a locally-dispatched
     * signal takes, including starting the agent if it is not yet running.
     *
     * @param string $agentType Target agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param SignalDTO $signal Signal to deliver
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws NoSuitableWorkerException If no suitable worker is available
     * @throws AgentNotFoundException If agent does not exist after startAgent() call
     * @throws AgentNotLinkedToWorkerException If agent is not linked to worker
     * @throws WorkerClientNotFoundException If worker client is not found for agent
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function deliverSignalToAgent(string $agentType, ?string $agentIndex, SignalDTO $signal): void
    {
        $this->sendSignalToAgent(
            $agentType,
            $agentIndex,
            new DaemonAgentMessageDTO($this->buildAgentId($agentType, $agentIndex), $signal),
        );
    }

    /**
     * Stop agent and remove from manager
     *
     * Sends agent_stop signal to worker and removes agent from agent manager.
     * No-op if agent is not running.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     */
    protected function stopAgent(string $agentType, ?string $agentIndex = null): void
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);

        $workerInfo = $this->agentManager->getAgentWorkerInfo($agentId);
        if ($workerInfo === null) {
            return;
        }

        $workerId = $this->agentManager->calculateWorkerId(
            $workerInfo->workerIndex,
            $workerInfo->isMonopolistic,
        );
        $workerClient = $this->findWorkerClientById($workerId);
        if ($workerClient === null) {
            return;
        }

        $workerClient->sendAgentStop($agentType, $agentIndex);
        $this->agentManager->removeAgent($agentId);
    }

    /**
     * Relays the leader's protected-mode ready to the worker hosting the initiator agent
     * ({@see ProtectedModeReadyRelay}).
     *
     * Resolves the agent's worker exactly like {@see stopAgent()} but leaves the agent running —
     * a no-op when the agent is not hosted on this node.
     *
     * @param string $agentType Initiator agent type
     * @param ?string $agentIndex Initiator agent index, or null for a singleton agent
     */
    public function deliverProtectedModeReady(string $agentType, ?string $agentIndex): void
    {
        $workerInfo = $this->agentManager->getAgentWorkerInfo($this->buildAgentId($agentType, $agentIndex));
        if ($workerInfo === null) {
            return;
        }

        $workerClient = $this->findWorkerClientById($this->agentManager->calculateWorkerId(
            $workerInfo->workerIndex,
            $workerInfo->isMonopolistic,
        ));
        if ($workerClient === null) {
            return;
        }

        $workerClient->sendProtectedModeReady($agentType, $agentIndex);
    }

    /**
     * Relays the aggregated re-hydrate verdict to the worker hosting the announcing agent (HIL-436).
     *
     * Resolves that agent's worker exactly like {@see deliverProtectedModeReady()}, from an id
     * that is already composed - it travelled on the announcement - so nothing is rebuilt here.
     * A no-op when the agent is not (or no longer) hosted on this node: an agent that died with
     * its restore unfinished has no verdict to receive, and the run it left behind is the
     * supervisor's problem, not this relay's.
     *
     * @param DbReHydrateCompleteDTO $dto Verdict addressed to the agent that announced the swap
     */
    public function deliverDbReHydrateComplete(DbReHydrateCompleteDTO $dto): void
    {
        if ($dto->agentId === null) {
            return;
        }

        $workerInfo = $this->agentManager->getAgentWorkerInfo($dto->agentId);
        if ($workerInfo === null) {
            return;
        }

        $workerClient = $this->findWorkerClientById($this->agentManager->calculateWorkerId(
            $workerInfo->workerIndex,
            $workerInfo->isMonopolistic,
        ));
        if ($workerClient === null) {
            return;
        }

        $workerClient->sendDbReHydrateComplete($dto);
    }

    /**
     * Stops every agent this node hosts except the initiator, for the protected-mode freeze
     * ({@see ProtectedModeAgentFreezer}).
     *
     * Walks this node's agent roster exactly like {@see onLostSingletonHost()} and stops each
     * one through {@see stopAgent()}, leaving the initiator agent running so it can carry out
     * the destructive operation the freeze protects. Snapshots the id list first because
     * {@see stopAgent()} mutates the roster. Bringing the stopped agents back when the freeze
     * lifts is the mirror seam, landed in HIL-267 slice 7b.
     *
     * @param string $initiatorAgentType Initiator agent type left running
     * @param ?string $initiatorAgentIndex Initiator agent index, or null for a singleton initiator
     */
    public function stopAgentsForProtectedMode(string $initiatorAgentType, ?string $initiatorAgentIndex): void
    {
        $initiatorAgentId = $this->buildAgentId($initiatorAgentType, $initiatorAgentIndex);

        $this->protectedModeStoppedAgents = [];
        foreach (array_keys($this->agentManager->getAgents()) as $agentId) {
            if ($agentId === $initiatorAgentId) {
                continue;
            }

            $parsed = $this->parseAgentId($agentId);
            if ($parsed->type === HilosAgentType::HILOS_MAIL) {
                // Left running for the same reason the start gate lets it back up: it carries the
                // alert about this very freeze, and a stopped mail pool would make a stuck node
                // silent as well as unreachable (HIL-482).
                continue;
            }

            $this->protectedModeStoppedAgents[] = $parsed;
            $this->stopAgent($parsed->type, $parsed->index);
        }

        // Say the freeze took hold: a restore log otherwise shows the decision to freeze but
        // nothing about the roster it actually stopped on this node.
        Logger::info(
            'Protected mode: froze this node for ' . $initiatorAgentId . ', stopped '
            . count($this->protectedModeStoppedAgents) . ' agent(s)',
        );
    }

    /**
     * Names the agents {@see stopAgentsForProtectedMode()} stopped for the freeze in flight.
     *
     * Read-only: this is what the test-only inspector reports as this node's own view of the
     * freeze ({@see ProtectedModeSnapshotSource}), next to the runtime row. The row alone
     * would not answer the question a test actually asks - it says the mode is on, while this
     * says the roster it took down here, which is the difference between a decision to freeze
     * and a freeze that took hold.
     *
     * Returns the ids rather than the parsed pairs so the reply speaks the same vocabulary as
     * the freeze log and the agent-start gate, and so the id spelling stays owned by the one
     * class that builds it.
     *
     * @return list<string> Agent ids stopped for the current freeze, empty outside one
     */
    public function getProtectedModeStoppedAgents(): array
    {
        return array_map(
            fn(AgentId $agent): string => $this->buildAgentId($agent->type, $agent->index),
            $this->protectedModeStoppedAgents,
        );
    }

    /**
     * Restarts the agents {@see stopAgentsForProtectedMode()} stopped for this freeze, when it lifts
     * ({@see ProtectedModeAgentFreezer}).
     *
     * Replays exactly the remembered set through the same local start bootstrap and placement
     * use, so each agent comes back on this node as it was, and the placement and worker gates
     * silently drop any that no longer belong here (e.g. a cluster-singleton whose node lost
     * leadership during the freeze). The replay carries the placement sanction, because the
     * remembered set is itself the record of one: every agent in it was running here, so it had
     * already passed the gate. Without the sanction a {@see AgentPlacement::POLICY} agent would
     * be refused on the very node placement chose for it, and nothing would ask for it again
     * while its placement record stands. Clears the remembered set up front so a second call is a
     * harmless no-op, and contains a per-agent start failure so one bad restart never strands the
     * rest. Nothing has to be un-set first: the executor writes the phase before it calls this,
     * and the freeze gate lets starts through on both phases that resume - the verification
     * window and inactive - so each replayed start passes it on its own. Ends by firing
     * {@see onProtectedModeLifted()} for whatever else the application wants back.
     */
    public function resumeAgentsForProtectedMode(): void
    {
        $stopped = $this->protectedModeStoppedAgents;
        $this->protectedModeStoppedAgents = [];

        foreach ($stopped as $agent) {
            try {
                $this->startAgentInternal($agent->type, $agent->index, true);
            } catch (Throwable $e) {
                $agentId = $this->buildAgentId($agent->type, $agent->index);
                Logger::error("Protected mode: failed to resume agent {$agentId}: {$e->getMessage()}");
            }
        }

        $this->onProtectedModeLifted();
    }

    /**
     * Called on this node whenever the protected-mode freeze gives the system back, after the
     * remembered roster has been replayed.
     *
     * That is twice per freeze that goes the whole way, not once: the verification window resumes
     * the agents while the freeze still stands, and the final lift resumes them again. An override
     * therefore has to be safe to run twice, and has to expect what it started to be stopped again -
     * an operator closing the window back re-freezes the node through
     * {@see stopAgentsForProtectedMode()}, which walks the whole roster, and there is no hook on
     * that side. It is the same bargain {@see onInitialWorkersReady()} already makes with the
     * freeze; what the window adds is that it now happens mid-operation rather than only at the end.
     *
     * The framework brings back only what it knows: the agents the freeze itself stopped, plus
     * the per-node registry list this default starts. Anything else this node was running is
     * the application's call - a project that starts local agents by overriding
     * {@see onInitialWorkersReady()} has to override this hook too, or those agents stay down
     * until something else starts them. The default is not redundant with the replayed roster:
     * the roster is captured once on entry, so an agent whose start the gate refused during the
     * freeze (a worker re-registering after a crash, a cluster placement) is in no list at all.
     * {@see startAgent()} is idempotent, so replaying a still-running agent is a no-op.
     */
    protected function onProtectedModeLifted(): void
    {
        $this->startPerNodeAgents();
    }

    /**
     * Resolves the capability tags an agent type requires, for the leader's placement
     * hard-check ({@see PlacementExecutor}).
     *
     * Builds a throwaway agent daemon to read its type-level requirement without
     * registering it or touching a worker.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return list<string> Required capability tags; empty when the agent runs anywhere
     * @throws AgentDaemonCreationFailedException If the agent daemon cannot be built
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function requiredCapabilities(string $agentType, ?string $agentIndex): array
    {
        return $this->agentManager->instantiateAgentDaemon($agentType, $agentIndex)->requiredCapabilities();
    }

    /**
     * Resolves the numeric resource demand an agent type declares, for the best-fit policy and
     * the leader's capacity hard-check ({@see PlacementExecutor}).
     *
     * Builds a throwaway agent daemon to read its type-level profile without registering it or
     * touching a worker, mirroring {@see requiredCapabilities()}.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return ResourceProfile Resource demand; empty when the agent has no numeric preference
     * @throws AgentDaemonCreationFailedException If the agent daemon cannot be built
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function placementProfile(string $agentType, ?string $agentIndex): ResourceProfile
    {
        return $this->agentManager->instantiateAgentDaemon($agentType, $agentIndex)->placementProfile();
    }

    /**
     * Launches a placed agent on this node and returns the worker it landed on
     * ({@see PlacementExecutor}).
     *
     * Reuses the ordinary local start — no new spawn logic — so a placed agent is hosted exactly
     * like a locally-started one, then reads back the worker id the agent manager recorded. This
     * is the one entry that carries the placement sanction, so it is also the only way a
     * {@see AgentPlacement::POLICY} agent comes up on a node that is not the leader.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return int Worker id the agent was placed on (negative = monopolistic, positive = regular)
     * @throws AgentDaemonCreationFailedException If the agent daemon cannot be built
     * @throws NoSuitableWorkerException If no suitable worker is available to host it
     * @throws AgentNotLinkedToWorkerException If the agent did not link to a worker
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function executePlacement(string $agentType, ?string $agentIndex): int
    {
        $this->startAgentInternal($agentType, $agentIndex, true);

        $agentId = $this->buildAgentId($agentType, $agentIndex);

        return $this->agentManager->getAgentWorkerId($agentId)
            ?? throw new AgentNotLinkedToWorkerException($agentId);
    }

    /**
     * Stops a placed agent on this node ({@see PlacementExecutor}); a no-op when it is not
     * running.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     */
    public function revokePlacement(string $agentType, ?string $agentIndex): void
    {
        $this->stopAgent($agentType, $agentIndex);
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
