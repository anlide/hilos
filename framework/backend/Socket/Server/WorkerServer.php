<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Cluster\AgentSignalSink;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\ProtectedMode\FrozenAgentPlacement;
use Hilos\ProtectedMode\ProtectedModeAgentFreezer;
use Hilos\ProtectedMode\ProtectedModeInitiatorRelay;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\LogStreamConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\LiveConnectionRoster;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
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
use Hilos\Log\AgentLogStream;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Utils\Logger;
use Random\RandomException;
use Throwable;
use LogicException;

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
abstract class WorkerServer extends AbstractServer implements
    PlacementExecutor,
    AgentSignalSink,
    ProtectedModeInitiatorRelay,
    ProtectedModeAgentFreezer
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

    /**
     * @var array<string, true> Agents this node may not start, keyed by agent id (HIL-696).
     *
     * Node-local and never persisted, like every other refusal in the cluster. It exists because
     * the leader's own terminal mark cannot reach the one case that needs it most: an agent the
     * registry declares {@see AgentScope::NODE} is started by this node's own bootstrap, which
     * the leader neither sees nor is asked about, so a stop frame would be answered by the next
     * {@see startPerNodeAgents()} bringing the very same replica back up.
     */
    private array $rtClaimRefused = [];

    /**
     * @var array<string, AwaitingWorkerAgent> Agents whose monopolistic worker is being raised, keyed by
     *     agent id, in the order they asked (HIL-998)
     */
    private array $agentsAwaitingWorker = [];

    /** @var float Interval between worker processes tick checks in seconds */
    private const float WORKER_PROCESSES_TICK_INTERVAL = 1.0;

    /** @var string PHP binary used to spawn worker processes */
    private const string PHP_BINARY = 'php';

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

    /**
     * @var list<FrozenAgentPlacement> Agents the current freeze stopped and the workers they
     *     stood on, replayed on lift; empty outside a freeze
     */
    private array $protectedModeStoppedAgents = [];

    /** @var list<string> Agent ids left to stop for the freeze being entered, front first */
    private array $protectedModeStopQueue = [];

    /** @var ?string Initiator agent id the stop in flight leaves running, null when no stop is in flight */
    private ?string $protectedModeStopInitiator = null;

    /**
     * @var ?list<FrozenAgentPlacement> Agents left to bring back for the lift in flight, front first;
     *     null when no lift is in flight, which an empty list is not - a lift with nothing to replay
     *     still has to be finished
     */
    private ?array $protectedModeResumeQueue = null;

    /** @var int Master passes the walk in flight has taken, for its one closing log line */
    private int $protectedModeWalkPasses = 0;

    /** @var int Agents the walk in flight has stopped or asked back so far, for the same line */
    private int $protectedModeWalkAgents = 0;

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
        $this->minRegular = Hilos::$env[EnvConstants::WORKER_MIN_REGULAR]->int();
        $this->minMonopolistic = Hilos::$env[EnvConstants::WORKER_MIN_MONOPOLISTIC]->int();
        $this->maxRegular = Hilos::$env[EnvConstants::WORKER_MAX_REGULAR]->int();

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
                $this->reportContainedFailure(new ContainedFailure(
                    MasterFailureUnit::AGENT_START,
                    $this->buildAgentId($agentType, null),
                    $throwable,
                ));
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
     * @throws RtActionsCollectionNameNullException When the switch a finished roster walk tells cannot name its row
     * @throws RtTruthSourceWriteNotAllowedException When that switch writes a row this master is not the truth source of
     */
    public function onTick(): void
    {
        // Process clients (read/write)
        // Registration timeout is handled in WorkerClient::onTick()
        parent::onTick();

        // One step of the protected-mode roster walk, every pass. Above the throttle below, which
        // is for worker processes: a walk that took it would move one agent a second (HIL-1012).
        $this->advanceProtectedModeRoster();

        // Seat or give up the agents waiting for a monopolistic worker, every pass and for the same
        // reason: under the throttle a worker that registered would wait up to a second for its agent.
        $this->advanceAgentsAwaitingWorker();

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
     * Remove client from server, and put back what a dead worker took that nothing will ask for.
     *
     * An agent forgotten with its worker comes back by being addressed - a frame, an action, a
     * cron rule, which outlives the agent that registered it. A node-scoped one has none of
     * that by declaration: the registry says it runs on every node, so the start pass is the
     * only thing that ever asks for it, and the agent lifecycle says as much - a node replica
     * "comes up from the bootstrap, and nothing would address it back into existence". Without
     * this it would stay down until the daemon restarts, silently, exactly the shape of defect
     * the roster cleanup above it was written to end.
     *
     * Here and not beside that cleanup, because here the order is certain: a client is closed
     * before it is removed, so {@see WorkerClient::onClose()} has already emptied the roster of
     * this worker, and a start pass that ran first would find the dead agents still listed and
     * do nothing. {@see startPerNodeAgents()} is idempotent, so the ones on the surviving
     * workers are untouched, and it contains a per-agent failure of its own.
     *
     * Nothing is started while the node is leaving - a shutdown that starts what it is about to
     * stop never ends - and nothing is started for a client that never registered as a worker,
     * which carried no agents to lose.
     *
     * @param ClientInterface $client Client to remove
     */
    public function removeClient(ClientInterface $client): void
    {
        parent::removeClient($client);

        if ($this->preparingShutdown) {
            return;
        }

        if (!$client instanceof WorkerClient || $client->getWorkerIndex() <= 0) {
            return;
        }

        $this->startPerNodeAgents();
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
            $daemonLogFile = Hilos::$env[EnvConstants::DAEMON_LOG_FILE]->string();
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
            $stdoutFile = $logDirectory . '/' . LogStreamConstants::WORKER_STREAM_PREFIX
                . $workerType . LogStreamConstants::WORKER_TYPE_SEPARATOR . $workerIndex . LogStreamConstants::STREAM_SUFFIX;
            $this->processWorkerOutput($stdout, $stdoutFile, $logDirectory, false);
        }

        // Read stderr and write to file
        $stderr = $process->getStdErr();
        if (!empty($stderr)) {
            $stderrFile = $logDirectory . '/' . LogStreamConstants::WORKER_STREAM_PREFIX
                . $workerType . LogStreamConstants::WORKER_TYPE_SEPARATOR . $workerIndex . LogStreamConstants::ERROR_STREAM_SUFFIX;
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
            foreach ($levels as $level => $messages) {
                foreach ($messages as $message) {
                    AgentLogStream::append($logDirectory, (string) $agentId, $level, $message, $isStderr);
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
     * For monopolistic workers the minimum is a warm-up, not a ceiling: the pool grows past it
     * one worker per agent that finds none free ({@see orderMonopolisticWorkerFor()}, HIL-998), so
     * the minimum only decides how many agents come up without that wait.
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
            WorkerConstants::TYPE_MONOPOLISTIC => [self::LIMIT_MIN => $this->minMonopolistic]
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
     * Protected rather than private so a test can stand in for the process launch and still drive
     * the pool growth that orders it ({@see orderMonopolisticWorkerFor()}).
     *
     * @param bool $isMonopolistic True if monopolistic worker
     * @throws CouldNotStartException If worker cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     */
    protected function startWorker(bool $isMonopolistic): void
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
     * Starts the agent a frame is addressed to, without delivering the frame.
     *
     * Being addressed is what starts an agent, so the master's delivery door keeps starting it at
     * the moment it is addressed; what it no longer does there is write the frame. A frame for an
     * agent that has not reported its start is held by the master until the report arrives
     * (HIL-629), and {@see sendSignalToAgent()} is reached only once it has.
     *
     * A start refused quietly - the freeze, or a gate that keeps the agent off this node - leaves
     * no linked record behind, and that is how the caller tells it from a start under way. A
     * monopolistic agent that found no free worker is a start under way without a link: it waits
     * for a worker raised for it, and {@see isAgentAwaitingWorker()} says so (HIL-998).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws NoSuitableWorkerException If no suitable worker is available
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function ensureAgentUp(string $agentType, ?string $agentIndex): void
    {
        $this->startAgent($agentType, $agentIndex);
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
     * @param ?int $preferredWorkerId Worker to hand it back to when that worker is still able to take it
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     * @throws NoSuitableWorkerException If no suitable worker is available
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    private function startAgentInternal(
        string $agentType,
        ?string $agentIndex,
        bool $placedByLeader,
        ?int $preferredWorkerId = null,
    ): void {
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

        // A worker is already on order for this agent: its start is under way, and a second frame
        // addressed to it neither orders another nor counts as a refusal (HIL-998)
        if (isset($this->agentsAwaitingWorker[$agentId])) {
            return;
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

        // RT-claim gate: an agent refused the truth source it claimed does not come up here again
        // (HIL-696). Below the placement gate and above the worker pick, because it answers the
        // same shape of question — may THIS node host it — and rolls the temporary record back
        // the same way, so the refusal narrows to this node and leaves the agent placeable
        // wherever the right actually belongs.
        if ($this->rtClaimRefusesStart($agentType, $agentIndex)) {
            if (!$agentExisted) {
                $this->agentManager->removeAgent($agentId);
            }
            Logger::debug("Agent {$agentId} not started: an RT collection it claims is owned on another node");
            return;
        }

        // Select appropriate worker
        $workerClient = $this->selectWorkerForAgent(
            $agentDaemon->requiresMonopolisticProcess(),
            $preferredWorkerId,
        );

        // No suitable worker: a monopolistic agent orders one and waits for it, keeping the
        // temporary record - it is an agent somebody asked to run (HIL-998). Anything else rolls
        // the record back the way the two gates above do, so the refusal leaves no agent behind
        // that nobody was ever asked to run (HIL-999)
        if ($workerClient === null) {
            if ($this->mayGrowMonopolisticPoolFor($agentDaemon, $agentIndex)) {
                try {
                    $this->orderMonopolisticWorkerFor($agentId, $agentType, $agentIndex, $placedByLeader);

                    return;
                } catch (CouldNotStartException | FailedToSetNonBlockingException $e) {
                    // A launch that fails now will fail on a retry too: refused like a shortage
                    Logger::error("Monopolistic pool: worker for agent {$agentId} could not be launched: {$e->getMessage()}");
                }
            } elseif ($agentDaemon->requiresMonopolisticProcess() && $agentIndex !== null) {
                Logger::error("Agent {$agentId} is monopolistic and indexed: the pool does not grow one worker per instance");
            }

            if (!$agentExisted) {
                $this->agentManager->removeAgent($agentId);
            }

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
     * Whether an agent that found no free worker may have one raised for it (HIL-998).
     *
     * Every reason is a named one. The regular pool keeps its own maximum and its own load
     * logic, so only a monopolistic agent grows the pool. A node on its way out gains nothing
     * from a process it is about to kill. And a per-instance agent does not grow it: the pool
     * would then follow the number of entities rather than the agent types the node hosts, and
     * that is the one bound growth has. The rule sits here and not in the topology validator,
     * because monopolistic-ness is declared by the daemon instance, which the validator never
     * builds.
     *
     * An agent already waiting never gets this far - {@see startAgentInternal()} returns for it
     * before the worker pick.
     *
     * @param AgentDaemonInterface $agentDaemon Daemon of the agent that found no worker
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return bool True when a worker may be ordered for the agent
     */
    private function mayGrowMonopolisticPoolFor(AgentDaemonInterface $agentDaemon, ?string $agentIndex): bool
    {
        return $agentDaemon->requiresMonopolisticProcess()
            && !$this->preparingShutdown
            && $agentIndex === null;
    }

    /**
     * Raises one monopolistic worker for an agent and records the agent as waiting for it (HIL-998).
     *
     * At once and off the tick: the one-worker-a-second pace of {@see ensureMinWorkers()} is the
     * warm-up's, and a bootstrap that addresses a dozen monopolistic agents at once would see the
     * back of that queue miss the master's hold on their frames. The agent gives up one second
     * before that hold does ({@see AgentConstants::START_DEADLINE_SECONDS}), so a held frame meets
     * a verdict rather than an empty wait. The worker is not the agent's: whichever waiting agent
     * is first when a free monopolistic worker exists takes it ({@see advanceAgentsAwaitingWorker()}).
     *
     * @param string $agentId Agent that found no worker
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param bool $placedByLeader Whether the start carried the placement sanction
     * @throws CouldNotStartException If the worker process cannot be launched
     * @throws FailedToSetNonBlockingException If the worker's pipes cannot be made non-blocking
     */
    private function orderMonopolisticWorkerFor(
        string $agentId,
        string $agentType,
        ?string $agentIndex,
        bool $placedByLeader,
    ): void {
        $this->startWorker(true);

        $this->agentsAwaitingWorker[$agentId] = new AwaitingWorkerAgent(
            $agentId,
            $agentType,
            $agentIndex,
            $placedByLeader,
            microtime(true) + AgentConstants::START_DEADLINE_SECONDS,
        );

        Logger::info("Monopolistic pool: worker ordered for agent {$agentId}");
    }

    /**
     * Seats the agents waiting for a monopolistic worker, and gives up the ones whose wait is over
     * (HIL-998).
     *
     * Asks the worker pick rather than listening for a registration: one question covers a worker
     * that just registered and one an agent just left, and the pick admits a monopolistic worker
     * only with no agent on it, so one worker takes one agent and the next one in line finds none.
     * Waiting agents are taken in the order they asked.
     *
     * Every failure is one agent's and is contained here, the way the roster walk beside it
     * contains its own: this runs on the master's every pass.
     *
     * Protected rather than private so a pass can be taken without the rest of {@see onTick()},
     * whose worker-process half needs a server built from a worker environment.
     */
    protected function advanceAgentsAwaitingWorker(): void
    {
        if ($this->agentsAwaitingWorker === []) {
            return;
        }

        $now = microtime(true);
        foreach ($this->agentsAwaitingWorker as $agentId => $awaiting) {
            if ($this->selectWorkerForAgent(true) !== null) {
                unset($this->agentsAwaitingWorker[$agentId]);
                $this->seatAwaitingAgent($awaiting);
                continue;
            }

            if ($awaiting->deadline <= $now) {
                unset($this->agentsAwaitingWorker[$agentId]);
                Logger::error("Agent {$agentId} waited " . AgentConstants::START_DEADLINE_SECONDS
                    . 's for a monopolistic worker and did not get one');
                $this->refuseAwaitingAgent($agentId, new NoSuitableWorkerException(WorkerConstants::TYPE_MONOPOLISTIC, true));
            }
        }
    }

    /**
     * Starts a waiting agent now that a free monopolistic worker exists (HIL-998).
     *
     * Through the ordinary start, so every gate it passed when it asked is asked again: a node
     * that lost leadership or an RT claim in the meantime keeps the agent off it as it would have
     * then. A start that ends without a worker link was refused quietly by one of those gates, and
     * the record the wait kept is taken away with it.
     *
     * @param AwaitingWorkerAgent $awaiting Agent to seat
     */
    private function seatAwaitingAgent(AwaitingWorkerAgent $awaiting): void
    {
        try {
            $this->startAgentInternal($awaiting->agentType, $awaiting->agentIndex, $awaiting->placedByLeader);
        } catch (Throwable $e) {
            Logger::error("Monopolistic pool: agent {$awaiting->agentId} could not be seated: {$e->getMessage()}");
            $this->refuseAwaitingAgent($awaiting->agentId, $e);

            return;
        }

        $agentDaemon = $this->agentManager->getAgent($awaiting->agentId);
        if ($agentDaemon === null || !$agentDaemon->hasWorkerClient()) {
            $this->forgetUnlinkedRecord($awaiting->agentId);

            return;
        }

        Logger::info("Monopolistic pool: agent {$awaiting->agentId} seated on worker #{$agentDaemon->getWorkerClient()->getWorkerIndex()}");
    }

    /**
     * Ends a wait that produced no agent: the record goes, and the asker and the project are told
     * (HIL-998).
     *
     * The same answer a start refused for want of a worker gets (HIL-999), and at once: reported
     * as a start that failed, which drops the record and answers the frames the master holds for
     * the agent in the words of a start refused on this node, plus one
     * {@see MasterFailureUnit::AGENT_START} card for the project.
     *
     * @param string $agentId Agent whose wait ended without a worker
     * @param Throwable $failure What the start was refused with
     */
    private function refuseAwaitingAgent(string $agentId, Throwable $failure): void
    {
        try {
            $this->agentManager->reportAgentStartFailed($agentId, $failure->getMessage());
        } catch (InvalidArgumentException $e) {
            // The record is gone before the sink is told; an answer that cannot be named leaves the
            // held frame to its own deadline, which answers it then
            Logger::error("Monopolistic pool: refusal of agent {$agentId} could not be answered: {$e->getMessage()}");
        }
        $this->reportContainedFailure(new ContainedFailure(MasterFailureUnit::AGENT_START, $agentId, $failure));
    }

    /**
     * Takes away the record of an agent that is linked to no worker, and leaves a linked one alone.
     *
     * The record a wait keeps is the temporary one {@see startAgentInternal()} writes, the only kind
     * of record that is linked to no worker, so this is the rollback HIL-999 does for a refused
     * start, taken later.
     *
     * @param string $agentId Agent whose record may go
     */
    private function forgetUnlinkedRecord(string $agentId): void
    {
        if ($this->agentManager->getAgent($agentId)?->hasWorkerClient() === false) {
            $this->agentManager->removeAgent($agentId);
        }
    }

    /**
     * Whether an agent is waiting for a monopolistic worker raised for it (HIL-998).
     *
     * A start under way like one whose worker has not reported yet: the master holds frames
     * addressed to it instead of delivering them, and a freeze counts it as still starting.
     *
     * @param string $agentId Agent id
     * @return bool True while the agent waits for its worker
     */
    public function isAgentAwaitingWorker(string $agentId): bool
    {
        return isset($this->agentsAwaitingWorker[$agentId]);
    }

    /**
     * Records that the leader refused this agent the RT right it claimed here (HIL-696).
     *
     * Only the mark; the agent itself comes down through the ordinary revoke the leader orders
     * with a stop frame. Marked separately from that stop, and before it, so the agent cannot be
     * started again in the window between the two.
     *
     * @param string $agentType Agent type whose claim was refused
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    public function refuseRtClaim(string $agentType, ?string $agentIndex): void
    {
        $this->rtClaimRefused[$this->buildAgentId($agentType, $agentIndex)] = true;
    }

    /**
     * Whether a refused RT claim keeps this agent from starting on this node (HIL-696).
     *
     * A pure read of the set, with no state of its own, and it never opens again on its own: the
     * declaration that put two owners on one collection is a configuration a person changes, and
     * an automatic reopening would be a flap between the two nodes rather than a fix. The mark
     * dies with the process, and if the declaration is still wrong the leader says so again.
     *
     * @param string $agentType Agent type asking to start
     * @param ?string $agentIndex Agent index asking to start, or null for a singleton
     * @return bool True when this node was refused the right this agent needs
     */
    private function rtClaimRefusesStart(string $agentType, ?string $agentIndex): bool
    {
        return isset($this->rtClaimRefused[$this->buildAgentId($agentType, $agentIndex)]);
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
     * A caller that knows where the agent stood a moment ago may say so, and then the pick is
     * not a pick at all: if that worker is still linked, still of the right kind and still able
     * to take the agent, it gets it back. The lift of a protected-mode freeze is the caller this
     * is for - it restarts a roster it stopped itself, and choosing afresh for every one of them
     * deals the whole node out again on each freeze, with an agent ending a run of 25 freezes
     * having lived on a dozen workers. A preference that no longer holds falls through to the
     * ordinary choice below, so this never keeps an agent off a node it can still run on.
     *
     * @param bool $requiresMonopolistic True if agent requires monopolistic worker
     * @param ?int $preferredWorkerId Worker the agent should go back to when that worker can still take it
     * @return ?WorkerClient Selected worker client or null if no suitable worker available
     */
    private function selectWorkerForAgent(bool $requiresMonopolistic, ?int $preferredWorkerId = null): ?WorkerClient
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

        // Hand it back where it stood, when that worker is among the ones that could take it now.
        if ($preferredWorkerId !== null) {
            foreach ($candidates as $candidate) {
                $candidateId = $this->agentManager->calculateWorkerId(
                    $candidate->getWorkerIndex(),
                    $candidate->isMonopolistic(),
                );
                if ($candidateId === $preferredWorkerId) {
                    return $candidate;
                }
            }
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
        // surface as AgentNotFoundException below, and the daemon would record it as a refused
        // start - with a card to the project and an error page to the asker. A frozen-out signal
        // is not a refused start; it is protected mode's own answer and is written down as one.
        // Delivery to the still-running initiator goes through the branch below and is untouched.
        if (!$this->agentManager->hasAgent($agentId)) {
            if ($this->protectedModeRefusesStart($parsedAgentType, $parsedAgentIndex)) {
                $this->reportSignalFrozenOut($agentId, $messageDto);
                return;
            }
            $this->startAgent($parsedAgentType, $parsedAgentIndex);
        } else {
            $agentDaemon = $this->agentManager->getAgent($agentId);
            if ($agentDaemon === null || !$agentDaemon->hasWorkerClient()) {
                if ($this->protectedModeRefusesStart($parsedAgentType, $parsedAgentIndex)) {
                    $this->reportSignalFrozenOut($agentId, $messageDto);
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
     * Writes down a signal the freeze refused to start an agent for.
     *
     * Loud for a command request and quiet for everything else, because the two cost different
     * things (HIL-1000). An ordinary signal dropped here is a fact of the freeze: the agent it
     * addressed is meant to be stopped, and nobody is waiting on an answer. A command request is
     * the opposite - somebody IS waiting, on a socket, and this drop is the whole of why they
     * will get nothing but a timeout. Named with the correlation id so the trace of that caller's
     * request ends on the reason it ended.
     *
     * @param string $agentId Agent the signal was addressed to
     * @param DaemonAgentMessageDTO $messageDto Message that was not delivered
     */
    private function reportSignalFrozenOut(string $agentId, DaemonAgentMessageDTO $messageDto): void
    {
        $signalData = $messageDto->signal->data;
        if ($signalData instanceof CommandRequestDTO) {
            Logger::warning("Command channel: protected mode dropped {$signalData->command}"
                . " #{$signalData->correlationId} on its way to agent {$agentId}");

            return;
        }

        Logger::debug("Signal to agent {$agentId} dropped: protected mode holds the node");
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
     * No-op if agent is not running. An agent still waiting for a worker raised for it has none
     * to be stopped on: the stop takes it out of the wait, and its record with it (HIL-998).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     */
    protected function stopAgent(string $agentType, ?string $agentIndex = null): void
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);

        if (isset($this->agentsAwaitingWorker[$agentId])) {
            unset($this->agentsAwaitingWorker[$agentId]);
            $this->forgetUnlinkedRecord($agentId);

            return;
        }

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
     * ({@see ProtectedModeInitiatorRelay}).
     *
     * Resolves the agent's worker exactly like {@see stopAgent()} but leaves the agent running —
     * a no-op when the agent is not hosted on this node.
     *
     * Both ways of reaching nobody are written down (HIL-1000). The relay is the only thing that
     * ever answers an initiator's enter, so an undelivered one is not a frame lost among many: it
     * is a caller that will now wait out its whole window and be told nothing.
     *
     * @param string $agentType Initiator agent type
     * @param ?string $agentIndex Initiator agent index, or null for a singleton agent
     */
    public function deliverProtectedModeReady(string $agentType, ?string $agentIndex): void
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);
        $workerInfo = $this->agentManager->getAgentWorkerInfo($agentId);
        if ($workerInfo === null) {
            Logger::warning("Protected mode: the ready relay reached nobody - initiator {$agentId} is on no worker");

            return;
        }

        $workerClient = $this->findWorkerClientById($this->agentManager->calculateWorkerId(
            $workerInfo->workerIndex,
            $workerInfo->isMonopolistic,
        ));
        if ($workerClient === null) {
            Logger::warning("Protected mode: the ready relay reached nobody"
                . " - the worker hosting initiator {$agentId} has no live link");

            return;
        }

        $workerClient->sendProtectedModeReady($agentType, $agentIndex);
    }

    /**
     * Relays the leader's protected-mode refusal to the worker hosting the initiator agent
     * ({@see ProtectedModeInitiatorRelay}).
     *
     * Resolves the agent's worker exactly like {@see deliverProtectedModeReady()} but delivers
     * the refusal reason — a no-op when the agent is not hosted on this node.
     *
     * Both ways of reaching nobody are written down (HIL-1000). The refusal relay answers an
     * initiator's enter when it cannot be granted, so an undelivered one is not a frame lost among many: it
     * is a caller that will now wait out its whole window and be told nothing.
     *
     * @param string $agentType Initiator agent type
     * @param ?string $agentIndex Initiator agent index, or null for a singleton agent
     * @param string $reason Human-readable operator-facing refusal message
     */
    public function deliverProtectedModeRefused(string $agentType, ?string $agentIndex, string $reason): void
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);
        $workerInfo = $this->agentManager->getAgentWorkerInfo($agentId);
        if ($workerInfo === null) {
            Logger::warning("Protected mode: the refusal relay reached nobody - initiator {$agentId} is on no worker");

            return;
        }

        $workerClient = $this->findWorkerClientById($this->agentManager->calculateWorkerId(
            $workerInfo->workerIndex,
            $workerInfo->isMonopolistic,
        ));
        if ($workerClient === null) {
            Logger::warning("Protected mode: the refusal relay reached nobody"
                . " - the worker hosting initiator {$agentId} has no live link");

            return;
        }

        $workerClient->sendProtectedModeRefused($agentType, $agentIndex, $reason);
    }

    /**
     * Relays the aggregated re-hydrate verdict to the worker hosting the announcing agent (HIL-436).
     *
     * Resolves that agent's worker exactly like {@see deliverProtectedModeReady()}, from an id
     * that is already composed - it travelled on the announcement - so nothing is rebuilt here.
     * Still a no-op when the agent is not (or no longer) hosted on this node: an agent that died
     * with its restore unfinished has no verdict to receive, and the run it left behind is the
     * supervisor's problem, not this relay's.
     *
     * Each of the three ways that happens now says so (HIL-694). They used to be silent returns,
     * which was affordable while the announcing agent kept a deadline of its own: an undelivered
     * verdict simply ran that timer out. It has none any more, so an undelivered verdict is a
     * restore that never finishes, and the only trace of why is written here. The three are told
     * apart on purpose - a verdict addressed to nobody, an agent nothing placed anywhere, and an
     * agent on a worker whose link is gone are three different faults with three different places
     * to look.
     *
     * @param DbReHydrateCompleteDTO $dto Verdict addressed to the agent that announced the swap
     */
    public function deliverDbReHydrateComplete(DbReHydrateCompleteDTO $dto): void
    {
        if ($dto->agentId === null) {
            Logger::error('DB re-hydrate verdict names no agent to deliver it to');

            return;
        }

        $workerInfo = $this->agentManager->getAgentWorkerInfo($dto->agentId);
        if ($workerInfo === null) {
            Logger::error("DB re-hydrate verdict undelivered: agent '{$dto->agentId}' is placed on no worker");

            return;
        }

        $workerClient = $this->findWorkerClientById($this->agentManager->calculateWorkerId(
            $workerInfo->workerIndex,
            $workerInfo->isMonopolistic,
        ));
        if ($workerClient === null) {
            Logger::error("DB re-hydrate verdict undelivered: the worker hosting '{$dto->agentId}' has no live link");

            return;
        }

        $workerClient->sendDbReHydrateComplete($dto);
    }

    /**
     * Asks for every agent this node hosts except the initiator to be stopped, for the
     * protected-mode freeze ({@see ProtectedModeAgentFreezer}).
     *
     * Snapshots this node's agent roster exactly like {@see onLostSingletonHost()} and queues it;
     * the stops themselves are taken one per master pass by {@see advanceProtectedModeRoster()},
     * and the switch hears {@see ProtectedModeSwitch::onRosterStopped()} when the last one is.
     * Walking the whole roster inside this call used to hold the master's loop for as long as the
     * roster was long, and every client of the node waited on it (HIL-1012). The initiator agent is
     * left running so it can carry out the destructive operation the freeze protects, and so is
     * the mail pool. Bringing the stopped agents back when the freeze lifts is the mirror seam,
     * landed in HIL-267 slice 7b.
     *
     * @param string $initiatorAgentType Initiator agent type left running
     * @param ?string $initiatorAgentIndex Initiator agent index, or null for a singleton initiator
     */
    public function stopAgentsForProtectedMode(string $initiatorAgentType, ?string $initiatorAgentIndex): void
    {
        $initiatorAgentId = $this->buildAgentId($initiatorAgentType, $initiatorAgentIndex);

        // A stop over an unfinished lift inherits what that lift had not asked for yet. Those agents
        // are on no roster - their start was never sent - so the snapshot below cannot see them, and
        // without this line nothing would ever ask for them again.
        $this->protectedModeStoppedAgents = $this->protectedModeResumeQueue ?? [];
        $this->protectedModeResumeQueue = null;

        // An agent waiting for a worker raised for it has no worker to be stopped on: it leaves the
        // wait, and its record goes with it. It is not remembered for the lift - it never ran here -
        // and comes up the ordinary way the next time it is addressed (HIL-998).
        foreach (array_keys($this->agentsAwaitingWorker) as $agentId) {
            if ($agentId === $initiatorAgentId) {
                continue;
            }

            unset($this->agentsAwaitingWorker[$agentId]);
            $this->forgetUnlinkedRecord($agentId);
            Logger::info("Monopolistic pool: agent {$agentId} left its wait for a worker, protected mode holds the node");
        }

        $this->protectedModeStopQueue = [];
        foreach (array_keys($this->agentManager->getAgents()) as $agentId) {
            if ($agentId === $initiatorAgentId) {
                continue;
            }

            if ($this->parseAgentId($agentId)->type === HilosAgentType::HILOS_MAIL) {
                // Left running for the same reason the start gate lets it back up: it carries the
                // alert about this very freeze, and a stopped mail pool would make a stuck node
                // silent as well as unreachable (HIL-482).
                continue;
            }

            $this->protectedModeStopQueue[] = $agentId;
        }

        $this->protectedModeStopInitiator = $initiatorAgentId;
        $this->protectedModeWalkPasses = 0;
        $this->protectedModeWalkAgents = 0;
    }

    /**
     * Names the agents this node has asked for and not yet heard back about
     * ({@see ProtectedModeAgentFreezer::agentsStillStarting()}).
     *
     * Read off the roster itself: an agent is here the moment it is registered, and started only
     * once its worker has reported it. Everything between those two moments is a start in flight,
     * and a freeze entered on top of one costs the node that agent.
     *
     * So is an agent WAITING FOR A WORKER raised for it ({@see isAgentAwaitingWorker()}, HIL-998):
     * its record is linked to no worker yet, but somebody asked for it and the wait ends by a
     * deadline, seated or refused.
     *
     * Any other agent linked to no worker is not a start in flight, and this is why the link is
     * asked about here. A start that found no free worker used to throw and leave its record
     * behind ({@see startAgentInternal()}); it rolls the record back now (HIL-999), and a waiting
     * agent's record is taken away when its wait ends, so the link check stays as the guard for
     * any path that can still leave an unlinked record: the node then holds an agent nobody was
     * ever asked to run, and no report about it will ever arrive. Counted as a start in flight,
     * such a record made every freeze from then on wait out the entry gate's whole deadline and
     * go in on top of it: measured in run 0232, nine holds of five seconds each, always on the
     * same three agents, and exactly the three whose start reports the run was short of.
     *
     * @return list<string> Ids of agents whose start was asked for and not reported yet
     */
    public function agentsStillStarting(): array
    {
        $starting = [];
        foreach ($this->agentManager->getAgents() as $agentId => $agentDaemon) {
            if ($this->isAgentAwaitingWorker($agentId)) {
                $starting[] = $agentId;
                continue;
            }

            if (!$agentDaemon->hasWorkerClient() || $this->agentManager->isAgentStarted($agentId)) {
                continue;
            }

            $starting[] = $agentId;
        }

        return $starting;
    }

    /**
     * Names the agents {@see stopAgentsForProtectedMode()} stopped for the freeze in flight.
     *
     * Read-only: this is what `protected-mode:inspect` reports as this node's own view of the
     * freeze ({@see ProtectedModeSnapshotSource}), next to the runtime row. The row alone
     * would not answer whether the freeze took hold here - it says the mode is on, while this
     * says the roster it took down on this node. Neither claim is a cluster-wide quiescence
     * verdict.
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
            fn(FrozenAgentPlacement $stopped): string => $this->buildAgentId(
                $stopped->agent->type,
                $stopped->agent->index,
            ),
            $this->protectedModeStoppedAgents,
        );
    }

    /**
     * Asks for the agents {@see stopAgentsForProtectedMode()} stopped for this freeze to be brought
     * back, when it lifts ({@see ProtectedModeAgentFreezer}).
     *
     * Queues exactly the remembered set; {@see advanceProtectedModeRoster()} replays it one agent
     * per master pass through the same local start bootstrap and placement use, so each agent
     * comes back on this node as it was, and the placement and worker gates silently drop any that
     * no longer belong here (e.g. a cluster-singleton whose node lost leadership during the
     * freeze). The replay carries the placement sanction, because the remembered set is itself the
     * record of one: every agent in it was running here, so it had already passed the gate. Without
     * the sanction a {@see AgentPlacement::POLICY} agent would be refused on the very node placement
     * chose for it, and nothing would ask for it again while its placement record stands. Clears
     * the remembered set up front so a second call adds nothing, and contains a per-agent start
     * failure so one bad restart never strands the rest. Nothing has to be un-set first: the
     * executor writes the phase before it calls this, and the freeze gate lets starts through on
     * both phases that resume - the verification window and inactive - so each replayed start
     * passes it on its own. The walk ends by firing {@see onProtectedModeLifted()} for whatever
     * else the application wants back, and then telling the switch
     * {@see ProtectedModeSwitch::onRosterResumed()}.
     */
    public function resumeAgentsForProtectedMode(): void
    {
        // A lift over an unfinished stop drops what that stop had not reached: those agents are
        // still running and were never remembered, so there is nothing of theirs to bring back.
        $this->protectedModeStopQueue = [];
        $this->protectedModeStopInitiator = null;

        // A lift over an unfinished lift - the window opened and the mode lifted before the window's
        // replay was done - keeps what the first had not asked for yet, in front of anything new.
        $this->protectedModeResumeQueue = [...$this->protectedModeResumeQueue ?? [], ...$this->protectedModeStoppedAgents];
        $this->protectedModeStoppedAgents = [];
        $this->protectedModeWalkPasses = 0;
        $this->protectedModeWalkAgents = 0;
    }

    /**
     * Takes one step of the protected-mode roster walk in flight, if there is one.
     *
     * One agent per master pass, and never the whole roster in one: at the master's loop period a
     * roster of twenty costs a fifth of a second spread over passes, where one walk cost that and
     * more inside a single pass that every client of the node stood behind (HIL-1012). A stop and a
     * lift are never both in flight - each request drops the other's queue - so the order of the
     * two branches decides nothing.
     *
     * Protected rather than private so a pass can be taken without the rest of {@see onTick()},
     * whose worker-process half needs a server built from a worker environment.
     *
     * @throws RtActionsCollectionNameNullException When the switch a finished walk tells cannot name its row
     * @throws RtTruthSourceWriteNotAllowedException When that switch writes a row this master is not the truth source of
     */
    protected function advanceProtectedModeRoster(): void
    {
        if ($this->protectedModeStopInitiator !== null) {
            $this->advanceProtectedModeStop($this->protectedModeStopInitiator);
        } elseif ($this->protectedModeResumeQueue !== null) {
            $this->advanceProtectedModeResume();
        }
    }

    /**
     * Stops the next queued agent for the freeze being entered, and closes the walk after the last.
     *
     * An agent that left the roster before its turn is not remembered: it was not running when the
     * freeze reached it, which is what the snapshot would have said had it been taken a pass later.
     *
     * @param string $initiatorAgentId Initiator agent id the stop leaves running, for the log line
     * @throws RtActionsCollectionNameNullException When the switch told about the stopped roster cannot name its row
     * @throws RtTruthSourceWriteNotAllowedException When that switch writes a row this master is not the truth source of
     */
    private function advanceProtectedModeStop(string $initiatorAgentId): void
    {
        $this->protectedModeWalkPasses++;

        $agentId = array_shift($this->protectedModeStopQueue);
        if ($agentId !== null && $this->agentManager->hasAgent($agentId)) {
            $parsed = $this->parseAgentId($agentId);

            // Read before the stop, not after: stopAgent() takes the agent off the roster, and with
            // it the only record of the worker the lift should hand it back to.
            $workerInfo = $this->agentManager->getAgentWorkerInfo($agentId);
            $this->protectedModeStoppedAgents[] = new FrozenAgentPlacement(
                $parsed,
                $workerInfo === null
                    ? null
                    : $this->agentManager->calculateWorkerId($workerInfo->workerIndex, $workerInfo->isMonopolistic),
            );
            $this->stopAgent($parsed->type, $parsed->index);
            $this->protectedModeWalkAgents++;
        }

        if ($this->protectedModeStopQueue !== []) {
            return;
        }

        $this->protectedModeStopInitiator = null;

        // Say the freeze took hold: a restore log otherwise shows the decision to freeze but
        // nothing about the roster it actually stopped on this node, nor how long that took.
        Logger::info(
            'Protected mode: froze this node for ' . $initiatorAgentId . ', stopped '
            . $this->protectedModeWalkAgents . ' agent(s) over ' . $this->protectedModeWalkPasses . ' pass(es)',
        );

        Hilos::$cluster?->protectedMode()?->onRosterStopped();
    }

    /**
     * Brings the next queued agent back for the lift in flight, and closes the walk after the last.
     */
    private function advanceProtectedModeResume(): void
    {
        $this->protectedModeWalkPasses++;

        $frozen = array_shift($this->protectedModeResumeQueue);
        if ($frozen !== null) {
            try {
                $this->startAgentInternal($frozen->agent->type, $frozen->agent->index, true, $frozen->workerId);
            } catch (Throwable $e) {
                $agentId = $this->buildAgentId($frozen->agent->type, $frozen->agent->index);
                Logger::error("Protected mode: failed to resume agent {$agentId}: {$e->getMessage()}");
            }
            $this->protectedModeWalkAgents++;
        }

        if ($this->protectedModeResumeQueue !== []) {
            return;
        }

        $this->protectedModeResumeQueue = null;

        Logger::info(
            'Protected mode: brought back ' . $this->protectedModeWalkAgents . ' agent(s) over '
            . $this->protectedModeWalkPasses . ' pass(es)',
        );

        $this->onProtectedModeLifted();
        Hilos::$cluster?->protectedMode()?->onRosterResumed();
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
     * Resolves the resource cost an agent declares, for the leader's capacity accounting
     * ({@see PlacementExecutor}).
     *
     * Builds a throwaway agent daemon to read its cost without registering it or touching a
     * worker, mirroring {@see requiredCapabilities()}. This runs on the leader's master loop, which
     * is why the cost must come from the agent type, index and constants, without I/O.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return ResourceProfile Resource cost; empty when the agent consumes nothing
     * @throws LogicException When the agent declares a negative cost
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
     * A monopolistic agent that found no free worker is accepted and answered with null: it waits
     * for a worker raised for it, and {@see placedWorkerId()} names the worker once it is seated
     * (HIL-998).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return ?int Worker id the agent was placed on (negative = monopolistic, positive = regular), or
     *     null while the agent waits for a worker raised for it
     * @throws AgentDaemonCreationFailedException If the agent daemon cannot be built
     * @throws NoSuitableWorkerException If no suitable worker is available to host it
     * @throws AgentNotLinkedToWorkerException If the agent neither linked to a worker nor waits for one
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function executePlacement(string $agentType, ?string $agentIndex): ?int
    {
        $this->startAgentInternal($agentType, $agentIndex, true);

        $agentId = $this->buildAgentId($agentType, $agentIndex);
        $workerId = $this->placedWorkerId($agentType, $agentIndex);
        if ($workerId === null && !$this->isAgentAwaitingWorker($agentId)) {
            throw new AgentNotLinkedToWorkerException($agentId);
        }

        return $workerId;
    }

    /**
     * Returns the worker a placed agent was seated on ({@see PlacementExecutor}).
     *
     * Read off the link, not the roster's worker id: a waiting agent's record carries the
     * placeholder index until it is seated, and that is not a worker.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return ?int Worker id once the agent is seated, null while it is still waiting for a worker
     */
    public function placedWorkerId(string $agentType, ?string $agentIndex): ?int
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);
        if ($this->agentManager->getAgent($agentId)?->hasWorkerClient() !== true) {
            return null;
        }

        return $this->agentManager->getAgentWorkerId($agentId);
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
