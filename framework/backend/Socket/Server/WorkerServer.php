<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Generator;
use WeakReference;
use Closure;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\ProtectedMode\FrozenAgentPlacement;
use Hilos\ProtectedMode\ProtectedModeAgentFreezer;
use Hilos\ProtectedMode\ProtectedModeInitiatorRelay;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Cluster\Probe\ClusterProbe;
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
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\Core\Daemon\LiveConnectionRoster;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Core\Daemon\PageAgentRestartDoor;
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
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException as FrameworkLogicException;
use Hilos\Core\Exception\Process\CouldNotStartException;
use Hilos\Core\Exception\Process\FailedToClosePipeException;
use Hilos\Core\Exception\Process\FailedToGetStatusException;
use Hilos\Core\Exception\Process\FailedToReadStdOutException;
use Hilos\Core\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Core\Exception\Process\FailedToSetStdErrException;
use Hilos\Core\Exception\Process\FailedToTerminateProcessException;
use Hilos\Core\Exception\Process\FailedToWriteStdInException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Process;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\ReadyWork;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Constants\TimeConstants;
use Hilos\Files\Upload\UploadMemoryDeliverySink;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsSink;
use Hilos\Files\Upload\UploadMemoryWorkerLifetime;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Log\AgentLogStream;
use Hilos\Notification\Delivery\DeliveryChannelOwners;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\Runtime\State\Item\HilosClusterNode as StateHilosClusterNode;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use Hilos\Status\Exception\StatusComponentDeclarationException;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Utils\Logger;
use Hilos\ProtectedMode\ProtectedModeSourceBinding;
use Random\RandomException;
use Throwable;
use LogicException;
use Hilos\Core\Daemon\CronFireTestDoor;
use Hilos\Core\Daemon\DaemonNodeReportTestDoor;
use Hilos\Core\Daemon\TestOrmForgetDoor;
use Hilos\Socket\Worker\DTO\WorkerTestCronFireDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronScheduleDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Socket\Worker\DTO\WorkerTestOrmForgetDTO;

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
    ProtectedModeInitiatorRelay,
    ProtectedModeAgentFreezer
{
    /** Second wave of a stop: the first wave still has workers running. */
    private const int SECOND_WAVE_WAITING = 0;

    /** Second wave: first-wave exits and links are settled; final dispatch is awaited. */
    private const int SECOND_WAVE_FIRST_GONE = 1;

    /** Second wave: the complete final-frame prefix has passed dispatch. */
    private const int SECOND_WAVE_DISPATCHED = 2;

    /** Second wave: agent_stop went to the held agents; their workers go once each has reported them stopped. */
    private const int SECOND_WAVE_AGENTS_STOPPED = 3;

    /** Maximum worker wait probes visited in one portion. */
    private const int WORKER_WAITS_PER_PORTION = 64;

    /** Maximum released stop-report starts visited in one portion. */
    private const int STOP_REPORT_WAITS_PER_PORTION = 64;

    /** Maximum held agent or process stops visited in one second-wave portion. */
    private const int SECOND_WAVE_STOPS_PER_PORTION = 64;

    /**
     * How long an agent waits in {@see self::$agentsAwaitingWorker} for a worker that is on its way to
     * connect, regular and monopolistic alike (HIL-1671).
     *
     * One phase of the start, not the whole of it: the data sources the worker then waits for keep
     * their own {@see AgentConstants::START_DEADLINE_SECONDS}.
     */
    private const float WORKER_CONNECT_WAIT_SECONDS = 10.0;

    /** Retry a refused initial announcement at its former process-check cadence. */
    private const float INITIAL_REGISTRATION_RETRY_SECONDS = 1.0;

    /**
     * Agent types the full-stop policy leaves running, besides the initiator the row names.
     *
     * The start gate, the stop walk and the published list all read this one list, in this order.
     *
     * @var list<string>
     */
    private const array PROTECTED_MODE_PRESERVED_AGENT_TYPES = [
        HilosAgentType::HILOS_MAIL,
        HilosAgentType::HILOS_STATUS,
    ];

    /** Bounds preparation independently of the single stop or replay effect. */
    private const int PROTECTED_MODE_PREPARATION_PER_PORTION = 64;

    /** Failure address when a channel cannot describe its delivery owners. */
    private const string DELIVERY_CHANNEL_FAILURE_ADDRESS = 'delivery channel ';

    /**
     * @var array<string, array<string, Process|string|int>> Workers indexed by key (format:
     *     "type:index"), values: WorkerConstants::FIELD_WORKER_*
     */
    private array $workers = [];

    private ?UploadMemoryDeliverySink $uploadMemoryPoolSink = null;

    /** Master-owned receiver for delivery window reports. */
    private ?DeliverySettingsSink $deliverySettingsSink = null;

    /** @var array<int, UploadMemoryWorkerLifetime> Registered process/connection pairs retained through both exit facts */
    private array $uploadWorkerLifetimes = [];

    /** @var array<int, int> Original registered worker index by exact connection identity */
    private array $registeredClients = [];

    /** Live worker membership revision for the master's process roster. */
    private int $rosterRevision = 0;

    /** @var array<int, true> Worker indexes lost unexpectedly and awaiting a replacement start */
    private array $lostWorkersAwaitingReplacement = [];

    /** @var list<int> Successful replacement times in this master's lifetime */
    private array $workerRestartTimes = [];

    private const int RESTART_WINDOW_SECONDS = 86400;

    /** @var array<int> Available worker indices (sorted, can be reused) */
    private array $availableIndices = [];

    /**
     * @var array<string, list<string>> Second wave of a stop: worker key to the ids of the agents on it that stop
     *     after the other workers (HIL-1154); empty while no stop holds a worker back
     */
    private array $secondStopWave = [];

    /** @var int Step of the second wave, one of the SECOND_WAVE_* constants */
    private int $secondStopWaveStep = self::SECOND_WAVE_WAITING;

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

    private ?ReadyWork $registrationWork = null;

    private ?EventLoopTimer $registrationRetry = null;

    /** @var ?LiveConnectionRoster Master seam naming the node's live sockets, wired at registration */
    private ?LiveConnectionRoster $liveConnectionRoster = null;

    /** Page-only restart entrance, wired by this server's master at registration. */
    private ?PageAgentRestartDoor $pageAgentRestartDoor = null;

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
     * @var array<string, AwaitingWorkerAgent> Agents waiting for a worker of their type to connect -
     *     a monopolistic one raised for them (HIL-998) or a regular one already launched (HIL-1671) -
     *     keyed by agent id, in the order they asked
     */
    private array $agentsAwaitingWorker = [];

    /** @var array<string, AwaitingStopReportStart> Starts held until the old worker reports the agent stopped */
    private array $agentsAwaitingStopReport = [];

    private ?ReadyWork $workerWaitWork = null;

    private ?EventLoopTimer $workerWaitTimer = null;

    /** @var array<string, AwaitingWorkerAgent> Remaining probes released by a capacity or deadline fact */
    private array $workerWaitPending = [];

    /** @var array<string, int> Waiting agents by WorkerConstants::TYPE_*, updated with queue membership */
    private array $workerWaitCounts = [WorkerConstants::TYPE_REGULAR => 0, WorkerConstants::TYPE_MONOPOLISTIC => 0];

    /** @var array<string, int> Unvisited probes by worker kind, so blocked remainder never self-queues */
    private array $workerWaitPendingCounts = [WorkerConstants::TYPE_REGULAR => 0, WorkerConstants::TYPE_MONOPOLISTIC => 0];

    private ?ReadyWork $stopReportWork = null;

    /** @var array<string, AwaitingStopReportStart> Starts released by their exact old connection */
    private array $stopReportPending = [];

    /** @var array<int, array<string, AwaitingStopReportStart>> Blocked starts indexed by exact connection */
    private array $stopReportWaiters = [];

    private bool $queuesStopping = false;

    private ?ReadyWork $secondStopWork = null;

    private int $stopDispatchGeneration = 0;

    /** @var ?Closure(): void Master's local dispatch cue; opening an empty barrier still owes completion */
    private ?Closure $stopDispatchWake = null;

    /** @var ?Closure(?string): void Actual start/resume progress; null names resume completion */
    private ?Closure $agentHoldWake = null;

    /** @var array<int, Process> First-wave incarnations whose complete exit has not been observed */
    private array $firstStopProcesses = [];

    /** @var array<string, Process> Held incarnations; reusable slots cannot authorize a replacement stop */
    private array $heldStopProcesses = [];

    /** @var array<string, WorkerClient> Exact connections receiving held agent stops */
    private array $heldStopClients = [];

    /** @var array<string, true> Held agent stops still to append after dispatch */
    private array $secondStopAgents = [];

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

    /** Master owning the test-only ORM waits on this server. */
    private ?TestOrmForgetDoor $testOrmForgetDoor = null;

    /** Master owning the test-only cron firing waits on this server. */
    private ?CronFireTestDoor $cronFireTestDoor = null;

    /** Master owning the local node-report waits on this server. */
    private ?DaemonNodeReportTestDoor $daemonNodeReportTestDoor = null;

    /** Process scheduling shares the master's base but outlives initial registration. */
    private ?EventLoop $processLoop = null;

    private ?ReadyWork $processSweepWork = null;

    private ?EventLoopTimer $processSweepTimer = null;

    /** @var array<int, array<string, Process|string|int>> Failed incarnations retained until nonblocking reap */
    private array $retiredWorkerProcesses = [];

    /** @var array<int, array<int, string>> Partial log lines by exact process id and output descriptor */
    private array $workerOutputTails = [];

    /** @var ?string Cached log directory path */
    private ?string $cachedLogDirectory = null;

    /**
     * @var list<FrozenAgentPlacement> Agents the current freeze stopped and the workers they
     *     stood on, replayed on lift; empty outside a freeze
     */
    private array $protectedModeStoppedAgents = [];

    /** @var array<string, AgentDaemonInterface> Original agent incarnations left to stop, front first */
    private array $protectedModeStopQueue = [];

    /** @var ?Generator<string, AwaitingWorkerAgent|AgentDaemonInterface> Original request's finite preparation cohort */
    private ?Generator $protectedModeStopPreparation = null;

    /** @var ?string Initiator agent id the stop in flight leaves running, null when no stop is in flight */
    private ?string $protectedModeStopInitiator = null;

    /**
     * @var ?array<int, FrozenAgentPlacement> Agents left to bring back for the lift in flight, front first;
     *     null when no lift is in flight, which an empty list is not - a lift with nothing to replay
     *     still has to be finished
     */
    private ?array $protectedModeResumeQueue = null;

    /** @var int Master passes the walk in flight has taken, for its one closing log line */
    private int $protectedModeWalkPasses = 0;

    /** @var int Agents the walk in flight has stopped or asked back so far, for the same line */
    private int $protectedModeWalkAgents = 0;

    private int $protectedModeRosterEpoch = 0;

    private ?ReadyWork $protectedModeRosterWork = null;

    private ?ReadyWork $protectedModeDiagnosticsWork = null;

    private bool $protectedModeDiagnosticsDirty = true;

    private int $protectedModeBindingGeneration = 0;

    private ?ProtectedModeSourceBinding $protectedModeSourceBinding = null;

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

    /** @param TestOrmForgetDoor $door Master owning the command waits */
    public function setTestOrmForgetDoor(TestOrmForgetDoor $door): void
    {
        $this->testOrmForgetDoor = $door;
    }

    /** @return ?TestOrmForgetDoor Master entrance, or null before wiring */
    public function testOrmForgetDoor(): ?TestOrmForgetDoor
    {
        return $this->testOrmForgetDoor;
    }

    /**
     * Queues only on an existing registered channel; it never starts a worker.
     *
     * @param int $workerId Signed regular or monopolistic worker id
     * @param WorkerTestOrmForgetDTO $request Request to queue
     * @return ?WorkerClient Exact channel used, or null when no live target exists
     */
    public function sendTestOrmForget(int $workerId, WorkerTestOrmForgetDTO $request): ?WorkerClient
    {
        return $this->sendToLiveWorker($workerId, $request);
    }

    /** @param CronFireTestDoor $door Master owning the cron firing waits */
    public function setCronFireTestDoor(CronFireTestDoor $door): void
    {
        $this->cronFireTestDoor = $door;
    }

    /** @return ?CronFireTestDoor Master entrance, or null before wiring */
    public function cronFireTestDoor(): ?CronFireTestDoor
    {
        return $this->cronFireTestDoor;
    }

    /** @param DaemonNodeReportTestDoor $door Master owning the node-report waits */
    public function setDaemonNodeReportTestDoor(DaemonNodeReportTestDoor $door): void
    {
        $this->daemonNodeReportTestDoor = $door;
    }

    /** @return ?DaemonNodeReportTestDoor Master entrance, or null before wiring */
    public function daemonNodeReportTestDoor(): ?DaemonNodeReportTestDoor
    {
        return $this->daemonNodeReportTestDoor;
    }

    /**
     * Queues only on an existing registered channel; it never starts a worker.
     *
     * @param int $workerId Signed id of the worker the master's roster names for the agent
     * @param WorkerTestCronFireDTO $request Request to queue
     * @return ?WorkerClient Exact channel used, or null when no live target exists
     */
    public function sendTestCronFire(int $workerId, WorkerTestCronFireDTO $request): ?WorkerClient
    {
        return $this->sendToLiveWorker($workerId, $request);
    }

    /**
     * @param int $workerId Signed id of the worker already running the agent
     * @param WorkerTestCronScheduleDTO $request Pause mutation to queue without starting a worker
     * @return ?WorkerClient Exact live registered channel, or null when unavailable
     */
    public function sendTestCronSchedule(int $workerId, WorkerTestCronScheduleDTO $request): ?WorkerClient
    {
        return $this->sendToLiveWorker($workerId, $request);
    }

    /** @param PageAgentRestartDoor $door This server's master entrance for admitted page requests */
    public function setPageAgentRestartDoor(PageAgentRestartDoor $door): void
    {
        $this->pageAgentRestartDoor = $door;
    }

    /**
     * @return PageAgentRestartDoor Registered page restart entrance
     * @throws FrameworkLogicException When server registration never wired the entrance
     */
    public function getPageAgentRestartDoor(): PageAgentRestartDoor
    {
        return $this->pageAgentRestartDoor ?? throw new FrameworkLogicException('Worker server has no page agent restart door');
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
        $client = new WorkerClient($socket, $this->agentManager, $this);
        if ($this->deliverySettingsSink !== null) {
            $client->setDeliverySettingsSink($this->deliverySettingsSink);
        }
        if ($this->uploadMemoryPoolSink !== null) {
            $client->setUploadMemoryPoolSink($this->uploadMemoryPoolSink);
        }
        return $client;
    }

    /**
     * @param DeliverySettingsSink $sink Receiver shared with this master's WebSocket delivery mirror
     */
    public function setDeliverySettingsSink(DeliverySettingsSink $sink): void
    {
        $this->deliverySettingsSink = $sink;
        foreach ($this->clients as $client) {
            if ($client instanceof WorkerClient) {
                $client->setDeliverySettingsSink($sink);
            }
        }
    }

    /**
     * @param UploadMemoryDeliverySink $sink The same owner injected into the WebSocket server
     * @throws FrameworkLogicException When an already registered channel has no tracked process
     */
    public function setUploadMemoryPoolSink(UploadMemoryDeliverySink $sink): void
    {
        $this->uploadMemoryPoolSink = $sink;
        foreach ($this->clients as $client) {
            if ($client instanceof WorkerClient) {
                $client->setUploadMemoryPoolSink($sink);
                if ($client->isRegistered()) {
                    $this->registerUploadWorker($client);
                }
            }
        }
    }

    /**
     * Capture the process at registration, before its index can ever be recycled.
     *
     * @param WorkerClient $worker Newly registered real channel
     * @throws FrameworkLogicException When the registered channel has no tracked process
     */
    public function registerUploadWorker(WorkerClient $worker): void
    {
        if ($this->uploadMemoryPoolSink === null) {
            return;
        }
        $processKey = $this->buildWorkerKey($worker->isMonopolistic(), $worker->getWorkerIndex());
        $process = $this->workers[$processKey][WorkerConstants::FIELD_WORKER_PROCESS] ?? null;
        if ($process === null) {
            throw new FrameworkLogicException('Upload worker registration needs its tracked process incarnation');
        }
        $this->uploadWorkerLifetimes[spl_object_id($worker)] ??= new UploadMemoryWorkerLifetime($worker, $process);
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
     * Starts this node's cluster-singleton agents; fired once per leadership term, and
     * again after a worker dies hosting agents.
     *
     * Invoked by the daemon's leader-gated ensure-once once this node is the cluster
     * leader (or the sole node when cluster mode is off) and its workers are ready.
     * The base queues INITIAL_AGENTS_START so the project bootstrap agent list is
     * launched through the existing signal routing, then directly starts the enabled
     * change-log agent, Status catalog's leader-hosted components and registered delivery
     * channel owners, containing their failures independently. Child classes override to start their own
     * cluster-singletons (e.g. one agent per active bot), calling parent first.
     *
     * Every agent started here still passes the placement gate in {@see startAgent()}, so an
     * agent the registry declares {@see AgentScope::NODE} is unaffected. Re-fires when a
     * follower is later promoted, and on this node after every worker that dies hosting
     * agents (HIL-502), so it must stay idempotent.
     *
     * @throws InvalidArgumentException When the initial-agents signal cannot be named
     * @throws HilosException Whatever the project's own cluster-singleton start raises
     * @throws StatusComponentDeclarationException When the enabled Status catalog is invalid
     */
    public function onBecameSingletonHost(): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName(SignalConstants::INITIAL_AGENTS_START),
            new SystemSignalDTO(systemName: SignalConstants::INITIAL_AGENTS_START),
        );

        $this->startChangeLogAgent();
        $this->startStatusComponentAgents();
        $this->startDeliveryChannelOwners();
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
     * @throws InvalidFormatException When a tracked delivery has an invalid completion address
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
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
        if (!isset($this->workers[$key])) {
            return;
        }
        if (!$this->preparingShutdown) {
            $this->lostWorkersAwaitingReplacement[$index] = true;
        }
        $this->workers[$key][WorkerConstants::FIELD_WORKER_PROCESS]->detachEventLoop();
        unset($this->workers[$key]);
        $this->availableIndices[] = $index;
        sort($this->availableIndices); // Keep sorted
        $this->rosterRevision++;
        $this->noteWorkerCapacityChanged();
    }

    /** @return int Revision of live worker membership */
    public function rosterRevision(): int
    {
        return $this->rosterRevision;
    }

    /**
     * @return list<DaemonWorkerPicture> Live worker identities; RSS and agent lists are filled by the frame builder
     * @throws InvalidFormatException When an internally tracked worker has invalid identity fields
     */
    public function liveWorkerPictures(): array
    {
        $workers = [];
        foreach ($this->workers as $worker) {
            $process = $worker[WorkerConstants::FIELD_WORKER_PROCESS];
            try {
                $status = $process->getStatus();
            } catch (FailedToGetStatusException) {
                continue;
            }
            if ($status[Process::STATUS_RUNNING] !== true) {
                continue;
            }
            $workers[] = new DaemonWorkerPicture(
                $worker[WorkerConstants::FIELD_WORKER_INDEX],
                $worker[WorkerConstants::FIELD_WORKER_TYPE],
                $process->getPid(),
                null,
                [],
            );
        }
        usort($workers, static fn (DaemonWorkerPicture $left, DaemonWorkerPicture $right): int => $left->index <=> $right->index);
        return $workers;
    }

    /**
     * @param ?int $now Time for pruning; current time by default
     * @return int Successful crash replacements still inside the rolling window
     */
    public function workerRestarts24h(?int $now = null): int
    {
        $cutoff = ($now ?? time()) - self::RESTART_WINDOW_SECONDS;
        $this->workerRestartTimes = array_values(array_filter(
            $this->workerRestartTimes,
            static fn (int $startedAt): bool => $startedAt > $cutoff,
        ));
        return count($this->workerRestartTimes);
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
     * Binds registration, process and queue lifetimes independently to the master's existing loop.
     * This server alone owns SIGCHLD; TERM/INT/HUP remain with the manager until its migration.
     *
     * @param EventLoop $loop Owning master's loop
     * @throws EventLoopException When a process deadline cannot be armed
     * @throws EnvException When the local diagnostic identity cannot be read
     * @throws ClusterConfigurationException When the node configuration is invalid
     * @throws ClusterDisabledException When the enabled cluster loses its identity
     */
    public function attachEventLoop(EventLoop $loop): void
    {
        $this->detachEventLoop();
        $this->processLoop = $loop;
        $this->workerWaitWork = $loop->createReadyWork($this->advanceAgentsAwaitingWorker(...));
        $this->workerWaitTimer = $loop->createTimer($this->releaseWorkerWaits(...));
        $this->stopReportWork = $loop->createReadyWork($this->advanceAgentsAwaitingStopReport(...));
        $this->secondStopWork = $loop->createReadyWork($this->advanceSecondStopWave(...));
        $this->agentManager->setWorkerCapacityWake($this->noteWorkerCapacityChanged(...));
        if ($this->queuesStopping) {
            $this->releaseWorkerWaitProbes();
        } else {
            $this->noteWorkerCapacityChanged();
        }
        $this->armWorkerWait();
        $this->stopReportPending = $this->agentsAwaitingStopReport;
        if ($this->stopReportPending !== []) {
            $this->stopReportWork->markReady();
        }
        if ($this->secondStopWave !== []) {
            $this->secondStopWork->markReady();
        }
        if ($this->pendingStopDispatch() !== null) {
            $this->stopDispatchWake?->__invoke();
        }
        $this->processSweepWork = $loop->createReadyWork($this->runProcessSweep(...));
        $this->processSweepTimer = $loop->createTimer(function (): void {
            $this->processSweepWork?->markReady();
            $this->armProcessSweep();
        });
        $loop->registerSignal(SIGCHLD, function (): void {
            $this->processSweepWork?->markReady();
        });
        foreach ($this->workers as $key => $worker) {
            $this->bindWorkerProcess($key, $worker);
        }
        foreach ($this->retiredWorkerProcesses as $id => $worker) {
            $this->bindRetiredWorkerProcess($id, $worker);
        }
        $this->processSweepWork->markReady();
        $this->armProcessSweep();
        $this->attachProtectedModeEventLoop($loop);
        if ($this->initialWorkersReadyCalled || $this->preparingShutdown) {
            return;
        }

        $this->registrationWork = $loop->createReadyWork($this->checkWorkerRegistration(...));
        $this->registrationRetry = $loop->createTimer(function (): void {
            $this->registrationWork?->markReady();
        });
        $this->registrationWork->markReady();
    }

    /** Releases all owned generations and SIGCHLD before final cleanup or rebind. */
    public function detachEventLoop(): void
    {
        $this->detachProtectedModeEventLoop();
        $this->detachRegistrationEventLoop();
        $this->agentManager->setWorkerCapacityWake(null);
        $this->workerWaitTimer?->close();
        $this->workerWaitTimer = null;
        $this->workerWaitWork?->close();
        $this->workerWaitWork = null;
        $this->workerWaitPending = [];
        $this->workerWaitPendingCounts = [WorkerConstants::TYPE_REGULAR => 0, WorkerConstants::TYPE_MONOPOLISTIC => 0];
        $this->stopReportWork?->close();
        $this->stopReportWork = null;
        $this->stopReportPending = [];
        $this->secondStopWork?->close();
        $this->secondStopWork = null;
        $this->stopDispatchGeneration++;
        $this->processSweepTimer?->close();
        $this->processSweepTimer = null;
        $this->processSweepWork?->close();
        $this->processSweepWork = null;
        $this->processLoop?->unregisterSignal(SIGCHLD);
        foreach ([$this->workers, $this->retiredWorkerProcesses] as $workers) {
            foreach ($workers as $worker) {
                $worker[WorkerConstants::FIELD_WORKER_PROCESS]->detachEventLoop();
            }
        }
        $this->processLoop = null;
    }

    /**
     * Each loop duty holds only a weak owner; old queued closures cannot survive a rebind.
     *
     * @param EventLoop $loop Current master loop
     * @throws EnvException When the local diagnostic identity cannot be read
     * @throws ClusterConfigurationException When the node configuration is invalid
     * @throws ClusterDisabledException When the enabled cluster loses its identity
     */
    private function attachProtectedModeEventLoop(EventLoop $loop): void
    {
        $owner = WeakReference::create($this);
        $generation = $this->protectedModeBindingGeneration;
        $this->protectedModeRosterWork = $loop->createReadyWork(static function () use ($owner, $generation): void {
            $server = $owner->get();
            if ($server !== null && $server->protectedModeBindingGeneration === $generation) {
                $server->advanceProtectedModeRoster();
            }
        });
        $this->protectedModeDiagnosticsWork = $loop->createReadyWork(static function () use ($owner, $generation): void {
            $server = $owner->get();
            if ($server !== null && $server->protectedModeBindingGeneration === $generation) {
                $server->reconcileProtectedModeSource();
                if ($server->protectedModeDiagnosticsDirty) {
                    $server->protectedModeDiagnosticsDirty = false;
                    $server->publishProtectedModeAgentDiagnostics();
                }
            }
        });
        if ($this->protectedModeStopInitiator !== null || $this->protectedModeResumeQueue !== null) {
            $this->protectedModeRosterWork->markReady();
        }
        $this->protectedModeSourceBinding ??= new ProtectedModeSourceBinding();
        $this->protectedModeSourceBinding->registerWorkerServer($this);
        $this->reconcileProtectedModeSource();
        $this->noteProtectedModeDiagnosticsChanged();
    }

    /** Detach cancels tickets, preserving the finite walk and any unpublished dirty fact. */
    private function detachProtectedModeEventLoop(): void
    {
        ++$this->protectedModeBindingGeneration;
        $this->protectedModeSourceBinding?->removeWorkerServer($this);
        $this->protectedModeRosterWork?->close();
        $this->protectedModeRosterWork = null;
        $this->protectedModeDiagnosticsWork?->close();
        $this->protectedModeDiagnosticsWork = null;
    }

    /**
     * Reconciles public facade assignment at a guarded boundary, without scanning source rows.
     * The blocking master must call this before waiting: assigning Hilos::$rt cannot wake a sleeper.
     * A missing context or unmounted row remains missing, never a published zero.
     *
     * @throws EnvException When the local diagnostic identity cannot be read
     * @throws ClusterConfigurationException When the node configuration is invalid
     * @throws ClusterDisabledException When the enabled cluster loses its identity
     */
    public function reconcileProtectedModeSource(): void
    {
        $this->protectedModeSourceBinding?->reconcile();
    }

    /**
     * The manager and this server share one source owner; standalone server fixtures own it locally.
     *
     * @param ProtectedModeSourceBinding $binding Concrete shared source fan-out
     * @throws EnvException When the local diagnostic identity cannot be read
     * @throws ClusterConfigurationException When the node configuration is invalid
     * @throws ClusterDisabledException When the enabled cluster loses its identity
     */
    public function registerProtectedModeSourceBinding(ProtectedModeSourceBinding $binding): void
    {
        if ($this->protectedModeSourceBinding === $binding) {
            return;
        }
        $this->protectedModeSourceBinding?->removeWorkerServer($this);
        $this->protectedModeSourceBinding = $binding;
        if ($this->protectedModeDiagnosticsWork !== null) {
            $binding->registerWorkerServer($this);
            $binding->reconcile();
        }
    }

    /** Source callbacks only coalesce readiness; publication stays in the guarded owner portion. */
    public function noteProtectedModeDiagnosticsChanged(): void
    {
        $this->protectedModeDiagnosticsDirty = true;
        $this->protectedModeDiagnosticsWork?->markReady();
    }

    /** Initial readiness and shutdown cancel only the registration duty. */
    private function detachRegistrationEventLoop(): void
    {
        $this->registrationRetry?->close();
        $this->registrationRetry = null;
        $this->registrationWork?->close();
        $this->registrationWork = null;
    }

    /**
     * A completed registration releases worker waits as well as the initial minimums.
     *
     * @param WorkerClient $client Exact connection that completed registration
     */
    public function noteWorkerRegistered(WorkerClient $client): void
    {
        $this->registeredClients[spl_object_id($client)] = $client->getWorkerIndex();
        $this->registrationWork?->markReady();
        $this->noteWorkerCapacityChanged();
        if ($this->secondStopWave !== []) {
            $this->secondStopWork?->markReady();
        }
    }

    /** @return array<int, int> Original registered worker indexes, without retaining another payload roster */
    public function registrationSnapshot(): array
    {
        return $this->registeredClients;
    }

    /** A real change in available seats releases one bounded FIFO probe of the waits. */
    public function noteWorkerCapacityChanged(): void
    {
        $this->agentHoldWake?->__invoke(null);
        $this->releaseWorkerWaits();
    }

    /** An existing wait deadline releases the same FIFO probes without inventing new capacity. */
    private function releaseWorkerWaits(): void
    {
        if ($this->queuesStopping || $this->agentsAwaitingWorker === []) {
            return;
        }
        $this->releaseWorkerWaitProbes();
    }

    /** Snapshot a real fact's FIFO probes and their kinds without walking the blocked queue. */
    private function releaseWorkerWaitProbes(): void
    {
        $this->workerWaitPending = $this->agentsAwaitingWorker;
        $this->workerWaitPendingCounts = $this->workerWaitCounts;
        if ($this->workerWaitPending !== []) {
            $this->workerWaitWork?->markReady();
        }
    }

    /**
     * A consumed report releases only the start waiting on this exact connection.
     *
     * @param WorkerClient $client Reporting connection
     * @param string $agentId Agent actually reported stopped
     */
    public function noteWorkerStopReport(WorkerClient $client, string $agentId): void
    {
        $awaiting = $this->stopReportWaiters[spl_object_id($client)][$agentId] ?? null;
        if ($awaiting !== null) {
            $this->stopReportPending[$agentId] = $awaiting;
            $this->stopReportWork?->markReady();
        }
        if (in_array($client, $this->heldStopClients, true)) {
            $this->secondStopWork?->markReady();
        }
    }

    /**
     * Process clients and reconcile the process-local protected source binding.
     *
     * Process status, output and stop deadlines have their own loop duties. A worker
     * process failure is contained by its owner portion, as the parent contains a
     * client failure here.
     *
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws HilosException Whatever the project's agent-daemon factory raises
     * @throws EnvException When the local diagnostic identity cannot be read
     * @throws ClusterConfigurationException When the node configuration is invalid
     * @throws ClusterDisabledException When the enabled cluster loses its identity
     */
    public function onTick(): void
    {
        // Process clients (read/write)
        // Registration timeout is owned by WorkerClient's independent native timer.
        parent::onTick();

        $this->reconcileProtectedModeSource();
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
     * exactly as it was and the existing one-second retry tries the whole step again: workersReady gates
     * the WebSocket open, the cluster singletons and cron, and a node that latched
     * without announcing would sit half-started with nothing left to retry. Queueing
     * ahead of the hook is not observable - the daemon dispatches the queue after the
     * owner portion, not at the call.
     *
     * @throws EventLoopException When a refused announcement's retry cannot be armed
     */
    private function checkWorkerRegistration(): void
    {
        // Check if initial workers are ready (only once)
        if (!$this->initialWorkersReadyCalled && !$this->preparingShutdown) {
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
                    if ($this->registrationRetry?->getDeadline() === null) {
                        $this->registrationRetry?->armAt(
                            hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND
                            + self::INITIAL_REGISTRATION_RETRY_SECONDS,
                        );
                    }
                    return;
                }

                $this->initialWorkersReadyCalled = true;
                $this->detachRegistrationEventLoop();
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
        unset($this->registeredClients[spl_object_id($client)]);
        parent::removeClient($client);
        foreach ($this->stopReportWaiters[spl_object_id($client)] ?? [] as $agentId => $awaiting) {
            $this->stopReportPending[$agentId] = $awaiting;
        }
        if ($this->stopReportPending !== []) {
            $this->stopReportWork?->markReady();
        }
        $this->noteWorkerCapacityChanged();
        if ($this->secondStopWave !== []) {
            $this->secondStopWork?->markReady();
        }

        if ($client instanceof WorkerClient) {
            $client->discardUploadBuffers();
            $lifetime = $this->uploadWorkerLifetimes[spl_object_id($client)] ?? null;
            if ($lifetime !== null) {
                $lifetime->queuesDiscarded = true;
                $this->settleExitedUploadWorkers();
            }
        }

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
            $this->runWorkerProcess($key, $worker);
        }
        foreach ($this->retiredWorkerProcesses as $id => $worker) {
            $this->reapRetiredWorkerProcess($id, $worker);
        }
    }

    /** One normal sweep or coalesced SIGCHLD; unrelated I/O never resets the sweep deadline. */
    private function runProcessSweep(): void
    {
        $this->tickWorkerProcesses();
        try {
            $this->ensureMinWorkers();
        } catch (CouldNotStartException $e) {
            Logger::error('Failed to start worker process: ' . $e->getMessage());
        } catch (FailedToSetNonBlockingException $e) {
            Logger::error('Failed to set non-blocking mode for worker process: ' . $e->getMessage());
        }
        $this->armProcessSweep();
    }

    /** Keeps the existing one-second pool duty only while the running server owes one. */
    private function armProcessSweep(): void
    {
        if ($this->preparingShutdown || ($this->workers === [] && $this->minRegular === 0 && $this->minMonopolistic === 0)) {
            $this->processSweepTimer?->armAt(null);
            return;
        }
        if ($this->processSweepTimer?->getDeadline() === null) {
            $this->processSweepTimer?->armAt(
                hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND
                + self::WORKER_PROCESSES_TICK_INTERVAL,
            );
        }
    }

    /**
     * @param string $key Current worker slot
     * @param array<string, Process|string|int> $worker Exact process incarnation and its log identity
     */
    private function bindWorkerProcess(string $key, array $worker): void
    {
        if ($this->processLoop !== null) {
            $worker[WorkerConstants::FIELD_WORKER_PROCESS]->attachEventLoop($this->processLoop, function () use ($key, $worker): void {
                $this->runWorkerProcess($key, $worker);
            });
        }
    }

    /**
     * Contains the same per-worker failures for sweep, pipe readiness and stop deadlines.
     *
     * @param string $key Worker slot, which a replacement may reuse
     * @param array<string, Process|string|int> $worker Captured incarnation
     */
    private function runWorkerProcess(string $key, array $worker): void
    {
        $process = $worker[WorkerConstants::FIELD_WORKER_PROCESS];
        if (($this->workers[$key][WorkerConstants::FIELD_WORKER_PROCESS] ?? null) !== $process) {
            return;
        }
        $type = $worker[WorkerConstants::FIELD_WORKER_TYPE];
        $index = $worker[WorkerConstants::FIELD_WORKER_INDEX];
        try {
            $this->tickWorkerProcess($process, $type, $index, $key);
        } catch (
            FailedToClosePipeException | FailedToTerminateProcessException | FailedToGetStatusException
            | FailedToReadStdOutException | FailedToSetStdErrException | FailedToWriteStdInException
        ) {
            $this->removeWorker($key, $type, $index);
            // Keep a failed child alive in bookkeeping until its exit is observed. Losing its
            // last reference here would run a synchronous destructor or abandon a future zombie.
            $id = spl_object_id($process);
            $this->retiredWorkerProcesses[$id] = $worker;
            $this->bindRetiredWorkerProcess($id, $worker);
        }
        if (!isset($this->workers[$key])) {
            $this->processSweepWork?->markReady();
        }
    }

    /**
     * @param int $id Exact retired process identity, unrelated to a reusable worker slot
     * @param array<string, Process|string|int> $worker Failed incarnation
     */
    private function bindRetiredWorkerProcess(int $id, array $worker): void
    {
        if ($this->processLoop !== null) {
            $worker[WorkerConstants::FIELD_WORKER_PROCESS]->attachEventLoop($this->processLoop, function () use ($id, $worker): void {
                $this->reapRetiredWorkerProcess($id, $worker);
            });
        }
    }

    /**
     * @param int $id Retired process identity
     * @param array<string, Process|string|int> $worker Original output identity
     */
    private function reapRetiredWorkerProcess(int $id, array $worker): void
    {
        $process = $worker[WorkerConstants::FIELD_WORKER_PROCESS];
        try {
            $process->halt();
            $this->saveWorkerOutput($process, $worker[WorkerConstants::FIELD_WORKER_TYPE], $worker[WorkerConstants::FIELD_WORKER_INDEX]);
            if (!$process->isInFlight()) {
                foreach ($this->uploadWorkerLifetimes as $lifetime) {
                    if ($lifetime->process === $process) {
                        $lifetime->processExited = true;
                    }
                }
                $this->settleExitedUploadWorkers();
                unset($this->retiredWorkerProcesses[$id], $this->firstStopProcesses[$id]);
                $this->secondStopWork?->markReady();
            }
        } catch (
            FailedToClosePipeException | FailedToTerminateProcessException | FailedToGetStatusException
            | FailedToReadStdOutException | FailedToSetStdErrException
        ) {
            // Retain it for the next real exit notification, without spinning on a failed pipe.
            $process->detachEventLoop();
        }
    }

    /** Release only after BOTH facts for the exact captured process and connection. */
    private function settleExitedUploadWorkers(): void
    {
        foreach ($this->uploadWorkerLifetimes as $id => $lifetime) {
            if ($lifetime->queuesDiscarded && $lifetime->processExited) {
                $this->uploadMemoryPoolSink?->discardUploadWorker($lifetime->worker);
                unset($this->uploadWorkerLifetimes[$id]);
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

        if (!$status[Process::STATUS_RUNNING]) {
            foreach ($this->uploadWorkerLifetimes as $lifetime) {
                if ($lifetime->process === $process) {
                    $lifetime->processExited = true;
                }
            }
            $this->settleExitedUploadWorkers();
        }

        // Read and save stdout/stderr to files (after status check to ensure we capture final output)
        $this->saveWorkerOutput($process, $type, $index);

        if (!$status[Process::STATUS_RUNNING] && !$process->isInFlight()) {
            // Worker died, remove from tracking
            Logger::info("Worker #{$index} stopped [type={$type}]");
            unset($this->firstStopProcesses[spl_object_id($process)]);
            $this->removeWorker($key, $type, $index);
            $this->secondStopWork?->markReady();
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

        try {
            FsPath::ensureDirectory($logDirectory, self::LOG_DIR_PERMISSIONS);
        } catch (DirectoryCreateException) {
            Logger::error("Failed to create log directory: {$logDirectory}");
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
        $stdout = $this->consumeWorkerOutputLines($process, 1, $process->getStdOut());
        if (!empty($stdout)) {
            $stdoutFile = $logDirectory . '/' . LogStreamConstants::WORKER_STREAM_PREFIX
                . $workerType . LogStreamConstants::WORKER_TYPE_SEPARATOR . $workerIndex . LogStreamConstants::STREAM_SUFFIX;
            $this->processWorkerOutput($stdout, $stdoutFile, $logDirectory, false);
        }

        // Read stderr and write to file
        $stderr = $this->consumeWorkerOutputLines($process, 2, $process->getStdErr());
        if (!empty($stderr)) {
            $stderrFile = $logDirectory . '/' . LogStreamConstants::WORKER_STREAM_PREFIX
                . $workerType . LogStreamConstants::WORKER_TYPE_SEPARATOR . $workerIndex . LogStreamConstants::ERROR_STREAM_SUFFIX;
            $this->processWorkerOutput($stderr, $stderrFile, $logDirectory, true);
        }
    }

    /**
     * Preserve log records across bounded reads; an exited child's final tail is a record too.
     *
     * @param Process $process Exact child, independent of a reusable worker index
     * @param int $descriptor Stdout or stderr descriptor
     * @param string $output Newly drained bytes
     * @return string Complete lines, or the final tail once the child is reaped
     */
    private function consumeWorkerOutputLines(Process $process, int $descriptor, string $output): string
    {
        $id = spl_object_id($process);
        if (isset($this->workerOutputTails[$id][$descriptor])) {
            $output = $this->workerOutputTails[$id][$descriptor] . $output;
            unset($this->workerOutputTails[$id][$descriptor]);
            if ($this->workerOutputTails[$id] === []) {
                unset($this->workerOutputTails[$id]);
            }
        }
        if (!$process->isInFlight()) {
            return $output;
        }
        $lastNewline = strrpos($output, "\n");
        if ($lastNewline === false) {
            if ($output !== '') {
                $this->workerOutputTails[$id][$descriptor] = $output;
            }
            return '';
        }
        if ($lastNewline + 1 < strlen($output)) {
            $this->workerOutputTails[$id][$descriptor] = substr($output, $lastNewline + 1);
        }
        return substr($output, 0, $lastNewline + 1);
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
     * @throws EventLoopException When the new child's process deadline cannot be armed
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
        $this->recordWorkerStarted($workerIndex);
        $this->bindWorkerProcess($key, $this->workers[$key]);
        $this->armProcessSweep();

        // Log worker start
        Logger::info("Worker #{$workerIndex} started [type={$type}]");
    }

    /**
     * @param int $workerIndex Index of a child successfully started by proc_open
     */
    protected function recordWorkerStarted(int $workerIndex): void
    {
        $this->rosterRevision++;
        if (isset($this->lostWorkersAwaitingReplacement[$workerIndex])) {
            unset($this->lostWorkersAwaitingReplacement[$workerIndex]);
            $this->workerRestartTimes[] = time();
        }
    }

    /**
     * Stop all worker processes with graceful shutdown - in two waves when an agent asks for it.
     *
     * The first wave is SIGTERM to every worker but the ones hosting an agent whose daemon answers
     * {@see AgentDaemonInterface::stopsAfterOtherWorkers()} - the node's analytics journal, which
     * the others hand their last batch to on the way out (HIL-1154). Those are held for the second
     * wave, {@see self::advanceSecondStopWave()}, and with no such agent the stop is one wave, as
     * it always was. The master's shutdown ceiling covers both waves.
     */
    public function stop(): void
    {
        if ($this->queuesStopping) {
            return;
        }
        $this->queuesStopping = true;
        $this->workerWaitTimer?->close();
        $this->workerWaitTimer = null;
        $this->releaseWorkerWaitProbes();
        $this->stopReportPending = $this->agentsAwaitingStopReport;
        if ($this->stopReportPending !== []) {
            $this->stopReportWork?->markReady();
        }
        $this->detachRegistrationEventLoop();
        $this->processSweepTimer?->close();
        $this->processSweepTimer = null;
        $this->secondStopWave = $this->workersStoppingLast();
        $this->secondStopWaveStep = self::SECOND_WAVE_WAITING;
        $this->stopDispatchGeneration++;
        foreach ($this->secondStopWave as $key => $agentIds) {
            $this->heldStopProcesses[$key] = $this->workers[$key][WorkerConstants::FIELD_WORKER_PROCESS];
            $worker = $this->parseWorkerKey($key);
            $client = $this->findWorkerClientById($this->agentManager->calculateWorkerId(
                $worker[WorkerConstants::FIELD_WORKER_INDEX],
                $worker[WorkerConstants::FIELD_WORKER_TYPE] === WorkerConstants::TYPE_MONOPOLISTIC,
            ));
            if ($client !== null) {
                $this->heldStopClients[$key] = $client;
            }
            foreach ($agentIds as $agentId) {
                $this->secondStopAgents[$agentId] = true;
            }
        }
        foreach ($this->retiredWorkerProcesses as $id => $worker) {
            $this->firstStopProcesses[$id] = $worker[WorkerConstants::FIELD_WORKER_PROCESS];
        }
        if ($this->secondStopWave !== []) {
            $this->secondStopWork?->markReady();
        }

        foreach ($this->workers as $key => $worker) {
            $process = $worker[WorkerConstants::FIELD_WORKER_PROCESS];
            $index = $worker[WorkerConstants::FIELD_WORKER_INDEX];
            $type = $worker[WorkerConstants::FIELD_WORKER_TYPE];

            if (isset($this->secondStopWave[$key])) {
                Logger::debug("Holding {$type} worker #{$index} for the second wave of the stop");
                continue;
            }

            $this->firstStopProcesses[spl_object_id($process)] = $process;
            try {
                $process->stop($this->shutdownTimeout); // Send SIGTERM with timeout
                Logger::debug("Sent stop signal to {$type} worker #{$index}");
            } catch (Throwable $e) {
                Logger::error("Failed to stop {$type} worker #{$index}: " . $e->getMessage());
                // Force remove if stop failed
                $this->removeWorker($key, $type, $index);
                $id = spl_object_id($process);
                $this->retiredWorkerProcesses[$id] = $worker;
                $this->bindRetiredWorkerProcess($id, $worker);
            }
        }

        // Note: Do NOT call parent::stop() here - we don't want to close worker client connections.
        // Workers need to complete their work and disconnect themselves gracefully.
        // Socket will be closed when daemon stops, but worker connections should remain until workers disconnect.
    }

    /**
     * Cues completion without dispatching recursively. Binding observes an already open barrier too.
     * This master binding outlives a server-loop rebind; the master releases it on its own detach.
     *
     * @param ?Closure(): void $wake Local dispatch owner, null to detach
     */
    public function setStopDispatchWake(?Closure $wake): void
    {
        $this->stopDispatchWake = $wake;
        if ($this->pendingStopDispatch() !== null) {
            $this->stopDispatchWake?->__invoke();
        }
    }

    /** @param Closure(): void $wake Exact master binding being detached, leaving a replacement intact */
    public function removeStopDispatchWake(Closure $wake): void
    {
        if ($this->stopDispatchWake === $wake) {
            $this->stopDispatchWake = null;
        }
    }

    /** @param ?Closure(?string): void $wake Master's independent bounded hold-discovery binding */
    public function setAgentHoldWake(?Closure $wake): void
    {
        $this->agentHoldWake = $wake;
    }

    /** @param Closure(?string): void $wake Exact departing master binding */
    public function removeAgentHoldWake(Closure $wake): void
    {
        if ($this->agentHoldWake === $wake) {
            $this->agentHoldWake = null;
        }
    }

    /**
     * @return ?int Current shutdown barrier, captured before a complete signal dispatch
     */
    public function pendingStopDispatch(): ?int
    {
        return $this->secondStopWave !== [] && $this->secondStopWaveStep === self::SECOND_WAVE_FIRST_GONE
            ? $this->stopDispatchGeneration
            : null;
    }

    /**
     * Retained master prefix validation uses this O(1) blocker read.
     *
     * @param int $generation Exact captured dispatch barrier
     * @param string $agentId Agent with a pending final delivery
     * @return bool Whether this same barrier must wait for that agent's final obligation
     */
    public function isStopDispatchAgent(int $generation, string $agentId): bool
    {
        return $generation === $this->pendingStopDispatch() && isset($this->secondStopAgents[$agentId]);
    }

    /**
     * Confirms every signal and destination in the captured prefix has passed dispatch.
     * A partial signal/destination drain must retain the barrier until its whole prefix ends.
     *
     * @param int $generation Barrier captured before dispatch; stale lifetimes cannot release it
     * @param list<string> $pendingAgentIds Dispatch deliveries still held for a start report
     */
    public function completeStopDispatch(int $generation, array $pendingAgentIds = []): void
    {
        if ($generation !== $this->pendingStopDispatch()) {
            return;
        }
        foreach ($pendingAgentIds as $agentId) {
            if (isset($this->secondStopAgents[$agentId])) {
                return;
            }
        }
        $this->secondStopWaveStep = self::SECOND_WAVE_DISPATCHED;
        $this->secondStopWork?->markReady();
    }

    /**
     * Advances only from process exit, link departure, consumed stop report or completed dispatch.
     *
     * First-wave processes and their links must both leave. Closing the master's remembered
     * analytics connections then opens a dispatch barrier. Only its explicit completion permits
     * agent_stop to join each held connection's FIFO, behind the final frames. SIGTERM waits for
     * every held report or the loss of its exact connection, under the existing master ceiling.
     *
     * @throws InvalidFormatException When a tracked delivery has an invalid completion address
     * @throws AgentNotLinkedToWorkerException When a held daemon cannot resolve its linked worker
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    protected function advanceSecondStopWave(): void
    {
        if ($this->secondStopWave === []) {
            return;
        }

        if ($this->secondStopWaveStep === self::SECOND_WAVE_WAITING) {
            if ($this->firstStopProcesses !== [] || $this->firstStopWaveConnected()) {
                return;
            }
            $this->secondStopWaveStep = self::SECOND_WAVE_FIRST_GONE;
            Hilos::$ac?->closeOpenConnections();
            $this->stopDispatchWake?->__invoke();
            return;
        }
        if ($this->secondStopWaveStep === self::SECOND_WAVE_FIRST_GONE) {
            return;
        }

        if ($this->secondStopWaveStep === self::SECOND_WAVE_DISPATCHED) {
            $this->secondStopWork?->markReady();
            $remaining = self::SECOND_WAVE_STOPS_PER_PORTION;
            while ($remaining-- > 0 && $this->secondStopAgents !== []) {
                $agentId = array_key_first($this->secondStopAgents);
                unset($this->secondStopAgents[$agentId]);
                $daemon = $this->agentManager->getAgent($agentId);
                if ($daemon?->hasWorkerClient() === true && !in_array($daemon->getWorkerClient(), $this->heldStopClients, true)) {
                    continue;
                }
                $agent = $this->parseAgentId($agentId);
                $this->stopAgent($agent->type, $agent->index);
            }
            if ($this->secondStopAgents === []) {
                $this->secondStopWaveStep = self::SECOND_WAVE_AGENTS_STOPPED;
            }
            // The queued portion appends remaining stops or checks already satisfied reports.
            return;
        }

        if ($this->heldAgentsAwaitStopReport()) {
            return;
        }

        $remaining = self::SECOND_WAVE_STOPS_PER_PORTION;
        while ($remaining-- > 0 && $this->secondStopWave !== []) {
            $key = array_key_first($this->secondStopWave);
            unset($this->secondStopWave[$key]);
            $worker = $this->workers[$key] ?? null;
            if ($worker === null || $worker[WorkerConstants::FIELD_WORKER_PROCESS] !== $this->heldStopProcesses[$key]) {
                continue;
            }

            try {
                $worker[WorkerConstants::FIELD_WORKER_PROCESS]->stop($this->shutdownTimeout);
                Logger::debug("Sent stop signal to {$worker[WorkerConstants::FIELD_WORKER_TYPE]} worker "
                    . "#{$worker[WorkerConstants::FIELD_WORKER_INDEX]} in the second wave");
            } catch (Throwable $e) {
                Logger::error("Failed to stop {$worker[WorkerConstants::FIELD_WORKER_TYPE]} worker "
                    . "#{$worker[WorkerConstants::FIELD_WORKER_INDEX]}: " . $e->getMessage());
                $this->removeWorker($key, $worker[WorkerConstants::FIELD_WORKER_TYPE], $worker[WorkerConstants::FIELD_WORKER_INDEX]);
                $id = spl_object_id($worker[WorkerConstants::FIELD_WORKER_PROCESS]);
                $this->retiredWorkerProcesses[$id] = $worker;
                $this->bindRetiredWorkerProcess($id, $worker);
            }
        }
        if ($this->secondStopWave !== []) {
            $this->secondStopWork?->markReady();
        } else {
            $this->heldStopProcesses = [];
            $this->heldStopClients = [];
        }
    }

    /**
     * Whether a worker of the first wave of a stop still has its connection open, with what it sent unread.
     *
     * @return bool True while such a connection is open
     */
    private function firstStopWaveConnected(): bool
    {
        foreach ($this->clients as $client) {
            if (!$client instanceof WorkerClient) {
                continue;
            }

            if (!in_array($client, $this->heldStopClients, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a held worker still owes the report that its held agents stopped;
     * a worker whose link is gone owes nothing.
     *
     * @return bool True while a connected held worker owes a stop report
     */
    private function heldAgentsAwaitStopReport(): bool
    {
        foreach ($this->secondStopWave as $key => $agentIds) {
            $client = $this->heldStopClients[$key] ?? null;
            if ($client === null || !in_array($client, $this->clients, true)) {
                continue;
            }

            foreach ($agentIds as $agentId) {
                if ($client->awaitsStopReport($agentId)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The workers hosting an agent whose daemon stops after the other workers, with those agents.
     *
     * @return array<string, list<string>> Worker key to the ids of its agents that stop last
     */
    private function workersStoppingLast(): array
    {
        $held = [];
        foreach ($this->agentManager->getAgents() as $agentId => $agentDaemon) {
            if (!$agentDaemon->stopsAfterOtherWorkers()) {
                continue;
            }

            $workerInfo = $this->agentManager->getAgentWorkerInfo($agentId);
            if ($workerInfo === null) {
                continue;
            }

            $key = $this->buildWorkerKey($workerInfo->isMonopolistic, $workerInfo->workerIndex);
            if (isset($this->workers[$key])) {
                $held[$key][] = $agentId;
            }
        }

        return $held;
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
     * no linked record behind, and that is how the caller tells it from a start under way. An
     * agent that found no free worker while one of its type is on its way is a start under way
     * without a link: it waits for that worker - a monopolistic one raised for it (HIL-998), a
     * regular one already launched (HIL-1671) - and {@see isAgentAwaitingWorker()} says so.
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

        // A worker handles stop before a later start on its link, but an agent placed on a
        // different worker could report its old stop after the new instance was registered.
        // Keep the start out of the roster until that report (or the link's departure). Once
        // recorded, only the advance pass ends this wait: it reports a gate's quiet refusal.
        $awaiting = $this->agentsAwaitingStopReport[$agentId] ?? null;
        $awaitedWorker = $awaiting?->awaitedWorker;
        if ($awaitedWorker === null) {
            foreach ($this->clients as $client) {
                if ($client instanceof WorkerClient && $client->awaitsStopReport($agentId)) {
                    $awaitedWorker = $client;
                    break;
                }
            }
        }

        if ($awaitedWorker !== null) {
            $next = new AwaitingStopReportStart(
                $agentId,
                $agentType,
                $agentIndex,
                $placedByLeader || ($awaiting?->placedByLeader ?? false),
                $awaiting?->preferredWorkerId ?? $preferredWorkerId,
                $awaitedWorker->getWorkerId(),
                $awaitedWorker,
            );
            $this->agentsAwaitingStopReport[$agentId] = $next;
            $this->stopReportWaiters[spl_object_id($awaitedWorker)][$agentId] = $next;
            if (isset($this->stopReportPending[$agentId])) {
                $this->stopReportPending[$agentId] = $next;
            }
            $this->agentHoldWake?->__invoke($agentId);

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

        // Cluster probe gate: a probe is a test instrument of a cluster stand, and it starts
        // only on a clustered node of a non-production environment (HIL-1211). Anywhere else a
        // project listing it simply does not run it - on one node, on its own Playwright stand
        // and in production alike - so it is passed over here quietly, above the temporary
        // record and without a failure card. A per-node replica meets this gate too, since its
        // start comes through here.
        if (
            ClusterProbe::isProbe(AgentRegistry::workerClass(Hilos::appClass()::AGENTS[$agentType] ?? null))
            && !ClusterProbe::mayRunHere()
        ) {
            Logger::debug("Agent {$agentId} not started: a cluster probe starts only on a clustered node of a non-production environment");
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

        // No suitable worker: a monopolistic agent orders one and waits for it (HIL-998), and a
        // regular agent waits for a regular worker already launched and not yet connected
        // (HIL-1671), both keeping the temporary record - it is an agent somebody asked to run.
        // Anything else rolls the record back the way the two gates above do, so the refusal
        // leaves no agent behind that nobody was ever asked to run (HIL-999)
        if ($workerClient === null) {
            $requiresMonopolistic = $agentDaemon->requiresMonopolisticProcess();
            if ($this->mayGrowMonopolisticPoolFor($agentDaemon, $agentIndex)) {
                try {
                    $this->orderMonopolisticWorkerFor($agentId, $agentType, $agentIndex, $placedByLeader);

                    return;
                } catch (CouldNotStartException | FailedToSetNonBlockingException $e) {
                    // A launch that fails now will fail on a retry too: refused like a shortage
                    Logger::error("Monopolistic pool: worker for agent {$agentId} could not be launched: {$e->getMessage()}");
                }
            } elseif ($requiresMonopolistic && $agentIndex !== null) {
                Logger::error("Agent {$agentId} is monopolistic and indexed: the pool does not grow one worker per instance");
            } elseif (!$requiresMonopolistic && $this->regularWorkerConnecting()) {
                $this->awaitWorkerFor($agentId, $agentType, $agentIndex, $placedByLeader, false);
                Logger::info("Regular pool: agent {$agentId} waits for a launched regular worker to connect");

                return;
            }

            if (!$agentExisted) {
                $this->agentManager->removeAgent($agentId);
            }

            throw new NoSuitableWorkerException($this->workerTypeName($requiresMonopolistic), $requiresMonopolistic);
        }

        // Update agent daemon mapping with actual worker info
        $this->agentManager->removeAgent($agentId);
        $this->agentManager->addAgent($agentId, $agentDaemon, $workerClient->getWorkerIndex(), $workerClient->isMonopolistic());

        // Link agent daemon to selected worker
        $agentDaemon->setWorkerClient($workerClient);
        Hilos::$cluster?->protectedModeEntryGate()?->noteRosterChanged();

        // Send agent_start with live keys from this node's sockets and the cluster index:
        // the agent coming up may strike rows left by tabs that closed while it was down,
        // but must keep rows for browsers attached to another master (HIL-664, HIL-1304).
        $workerClient->sendAgentStart($agentType, $agentIndex, $this->liveConnectionRoster?->liveAcceptKeys() ?? []);
        $this->agentHoldWake?->__invoke($agentId);
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
     * back of that queue run out its wait before the warm-up reached it. The agent waits
     * {@see self::WORKER_CONNECT_WAIT_SECONDS} for a worker to connect; the frames the master
     * holds for it keep no clock of their own and end on the verdict this wait gives. The worker
     * is not the agent's: whichever waiting agent is first when a free monopolistic worker exists
     * takes it ({@see advanceAgentsAwaitingWorker()}).
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

        $this->awaitWorkerFor($agentId, $agentType, $agentIndex, $placedByLeader, true);

        Logger::info("Monopolistic pool: worker ordered for agent {$agentId}");
    }

    /**
     * Whether a regular worker this master launched has not connected yet (HIL-1671).
     *
     * Asked of the process roster and the registrations, not of the configured minimum: a node
     * whose regular worker is up and registered never asks, and a node with none launched has
     * nothing on its way to wait for. A node on its way out waits for nothing it is about to kill.
     * Nothing is launched from here - the regular pool keeps its own minimum, maximum and
     * replacement of the dead in {@see ensureMinWorkers()}.
     *
     * @return bool True when a regular worker process is running here without a registered link
     */
    private function regularWorkerConnecting(): bool
    {
        if ($this->preparingShutdown) {
            return false;
        }

        foreach ($this->workers as $worker) {
            if ($worker[WorkerConstants::FIELD_WORKER_TYPE] !== WorkerConstants::TYPE_REGULAR) {
                continue;
            }

            if (!$this->hasRegisteredRegularClient($worker[WorkerConstants::FIELD_WORKER_INDEX])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int $workerIndex Index of a launched regular worker
     * @return bool Whether a regular worker of that index has registered its link
     */
    private function hasRegisteredRegularClient(int $workerIndex): bool
    {
        foreach ($this->clients as $client) {
            if (
                $client instanceof WorkerClient
                && $client->isRegistered()
                && !$client->isMonopolistic()
                && $client->getWorkerIndex() === $workerIndex
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records an agent as waiting for a worker of its type to connect (HIL-998, HIL-1671).
     *
     * One queue for both types: the wait is the same - the agent keeps its temporary record, the
     * master holds its frames, and the wait ends seated or refused by
     * {@see advanceAgentsAwaitingWorker()} - and only the worker that ends it differs.
     *
     * @param string $agentId Agent that found no worker
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param bool $placedByLeader Whether the start carried the placement sanction
     * @param bool $requiresMonopolistic Whether the agent waits for a monopolistic worker rather than a regular one
     */
    private function awaitWorkerFor(
        string $agentId,
        string $agentType,
        ?string $agentIndex,
        bool $placedByLeader,
        bool $requiresMonopolistic,
    ): void {
        $type = $this->workerTypeName($requiresMonopolistic);
        $this->workerWaitCounts[$type]++;
        $this->workerWaitPendingCounts[$type]++;
        $this->agentsAwaitingWorker[$agentId] = new AwaitingWorkerAgent(
            $agentId,
            $agentType,
            $agentIndex,
            $placedByLeader,
            hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND + self::WORKER_CONNECT_WAIT_SECONDS,
            $requiresMonopolistic,
        );
        $this->workerWaitPending[$agentId] = $this->agentsAwaitingWorker[$agentId];
        $this->workerWaitWork?->markReady();
        $this->agentHoldWake?->__invoke($agentId);
        Hilos::$cluster?->protectedModeEntryGate()?->noteRosterChanged();
    }

    /** The insertion order is also deadline order: a repeated ask never replaces its wait. */
    private function armWorkerWait(): void
    {
        $head = reset($this->agentsAwaitingWorker);
        $this->workerWaitTimer?->armAt($this->queuesStopping || $head === false ? null : $head->deadline);
        if ($this->workerWaitPending === []) {
            $this->workerWaitWork?->cancel();
        }
    }

    /**
     * @param string $agentId Wait whose exact queued probe must also be canceled
     */
    private function removeWorkerWait(string $agentId): void
    {
        $type = $this->workerTypeName($this->agentsAwaitingWorker[$agentId]->requiresMonopolistic);
        $this->workerWaitCounts[$type]--;
        if (isset($this->workerWaitPending[$agentId])) {
            $this->workerWaitPendingCounts[$type]--;
        }
        unset($this->agentsAwaitingWorker[$agentId], $this->workerWaitPending[$agentId]);
        $this->workerWaitWork?->markReady();
        Hilos::$cluster?->protectedModeEntryGate()?->noteRosterChanged();
    }

    /**
     * @param string $agentId Start whose blocked index and released probe must also be canceled
     */
    private function removeStopReportWait(string $agentId): void
    {
        $awaiting = $this->agentsAwaitingStopReport[$agentId] ?? null;
        if ($awaiting !== null) {
            $id = spl_object_id($awaiting->awaitedWorker);
            unset($this->stopReportWaiters[$id][$agentId]);
            if ($this->stopReportWaiters[$id] === []) {
                unset($this->stopReportWaiters[$id]);
            }
        }
        unset($this->agentsAwaitingStopReport[$agentId], $this->stopReportPending[$agentId]);
        if ($this->stopReportPending === []) {
            $this->stopReportWork?->cancel();
        }
    }

    /**
     * @param bool $isMonopolistic Whether the worker is monopolistic
     * @return string Worker type, one of the WorkerConstants::TYPE_* values
     */
    private function workerTypeName(bool $isMonopolistic): string
    {
        return $isMonopolistic ? WorkerConstants::TYPE_MONOPOLISTIC : WorkerConstants::TYPE_REGULAR;
    }

    /**
     * Takes at most one portion of FIFO probes released by a capacity or deadline fact.
     * The ordinary start repeats all gates and selection; blocked waits do not self-queue.
     *
     * @throws InvalidFormatException When a tracked delivery has an invalid completion address
     * @throws EventLoopException When the next worker wait deadline cannot be armed
     */
    protected function advanceAgentsAwaitingWorker(): void
    {
        // Isolated legacy fixtures may explicitly request a pass; production is bound to the loop.
        if ($this->workerWaitWork === null) {
            $this->releaseWorkerWaitProbes();
        }
        $now = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        $remaining = self::WORKER_WAITS_PER_PORTION;
        try {
            while ($remaining-- > 0 && $this->workerWaitPending !== []) {
                $agentId = array_key_first($this->workerWaitPending);
                $awaiting = $this->workerWaitPending[$agentId];
                unset($this->workerWaitPending[$agentId]);
                $this->workerWaitPendingCounts[$this->workerTypeName($awaiting->requiresMonopolistic)]--;
                if (($this->agentsAwaitingWorker[$agentId] ?? null) !== $awaiting) {
                    continue;
                }
                if ($this->queuesStopping) {
                    $this->stopAgent($awaiting->agentType, $awaiting->agentIndex);
                } elseif ($this->selectWorkerForAgent($awaiting->requiresMonopolistic) !== null) {
                    $this->removeWorkerWait($agentId);
                    $this->seatAwaitingAgent($awaiting);
                } elseif ($awaiting->deadline <= $now) {
                    $this->removeWorkerWait($agentId);
                    $workerType = $this->workerTypeName($awaiting->requiresMonopolistic);
                    Logger::error("Agent {$agentId} waited " . self::WORKER_CONNECT_WAIT_SECONDS
                        . "s for a {$workerType} worker and did not get one");
                    $this->refuseAwaitingAgent($agentId, new NoSuitableWorkerException($workerType, $awaiting->requiresMonopolistic));
                }
            }
        } finally {
            $head = reset($this->agentsAwaitingWorker);
            if ($this->workerWaitPending !== [] && (
                $this->queuesStopping
                || ($head !== false && $head->deadline <= $now)
                || ($this->workerWaitPendingCounts[WorkerConstants::TYPE_REGULAR] > 0 && $this->selectWorkerForAgent(false) !== null)
                || ($this->workerWaitPendingCounts[WorkerConstants::TYPE_MONOPOLISTIC] > 0 && $this->selectWorkerForAgent(true) !== null)
            )) {
                $this->workerWaitWork?->markReady();
            } else {
                $this->workerWaitPending = [];
                $this->workerWaitPendingCounts = [WorkerConstants::TYPE_REGULAR => 0, WorkerConstants::TYPE_MONOPOLISTIC => 0];
            }
            $this->armWorkerWait();
        }
    }

    /**
     * Starts a waiting agent now that a free worker of its type exists (HIL-998, HIL-1671).
     *
     * Through the ordinary start, so every gate it passed when it asked is asked again: a node
     * that lost leadership or an RT claim in the meantime keeps the agent off it as it would have
     * then. A start that ends without a worker link was refused quietly by one of those gates -
     * quietly to the gate, not to the master: the wait ends out loud, because the frames held for
     * this agent are waiting on exactly that word (HIL-1040).
     *
     * @param AwaitingWorkerAgent $awaiting Agent to seat
     */
    private function seatAwaitingAgent(AwaitingWorkerAgent $awaiting): void
    {
        $workerType = $this->workerTypeName($awaiting->requiresMonopolistic);
        try {
            $this->startAgentInternal($awaiting->agentType, $awaiting->agentIndex, $awaiting->placedByLeader);
        } catch (Throwable $e) {
            Logger::error("Worker wait: agent {$awaiting->agentId} could not be seated on a {$workerType} worker: {$e->getMessage()}");
            $this->refuseAwaitingAgent($awaiting->agentId, $e);

            return;
        }

        // Still under way in a wait of its own, which answers for it when it ends
        if ($this->isAgentAwaitingStopReport($awaiting->agentId) || $this->isAgentAwaitingWorker($awaiting->agentId)) {
            return;
        }

        $agentDaemon = $this->agentManager->getAgent($awaiting->agentId);
        if ($agentDaemon === null || !$agentDaemon->hasWorkerClient()) {
            $this->reportWaitEndedWithoutStart(
                $awaiting->agentId,
                "a gate refused the start when its {$workerType} worker came free",
            );

            return;
        }

        Logger::info("Worker wait: agent {$awaiting->agentId} seated on {$workerType} worker"
            . " #{$agentDaemon->getWorkerClient()->getWorkerIndex()}");
    }

    /**
     * Continues starts once their old worker reports the stop or its link closes.
     *
     * The ordinary start asks every current gate again. A quiet refusal must be reported to
     * the master because it may be holding frames for this start.
     */
    private function advanceAgentsAwaitingStopReport(): void
    {
        if ($this->stopReportWork === null) {
            $this->stopReportPending = $this->agentsAwaitingStopReport;
        }
        $remaining = self::STOP_REPORT_WAITS_PER_PORTION;
        try {
            while ($remaining-- > 0 && $this->stopReportPending !== []) {
                $agentId = array_key_first($this->stopReportPending);
                $awaiting = $this->stopReportPending[$agentId];
                unset($this->stopReportPending[$agentId]);
                if (($this->agentsAwaitingStopReport[$agentId] ?? null) !== $awaiting) {
                    continue;
                }
                if ($this->queuesStopping) {
                    $this->stopAgent($awaiting->agentType, $awaiting->agentIndex);
                    continue;
                }
                if (in_array($awaiting->awaitedWorker, $this->clients, true) && $awaiting->awaitedWorker->awaitsStopReport($agentId)) {
                    continue;
                }

                $this->removeStopReportWait($agentId);
                try {
                    $this->startAgentInternal(
                        $awaiting->agentType,
                        $awaiting->agentIndex,
                        $awaiting->placedByLeader,
                        $awaiting->preferredWorkerId,
                    );
                } catch (Throwable $failure) {
                    Logger::error("Agent {$agentId} could not start after its earlier stop: {$failure->getMessage()}");
                    $this->refuseAwaitingAgent($agentId, $failure);

                    continue;
                }

                if ($this->isAgentAwaitingStopReport($agentId) || $this->isAgentAwaitingWorker($agentId)) {
                    continue;
                }
                if ($this->agentManager->getAgent($agentId)?->hasWorkerClient() !== true) {
                    $this->reportWaitEndedWithoutStart(
                        $agentId,
                        'a gate refused the start once its earlier stop was reported',
                    );
                }
            }
        } finally {
            if ($this->stopReportPending !== []) {
                $this->stopReportWork?->markReady();
            }
        }
    }

    /**
     * Ends a wait that produced no agent: the record goes, and the asker and the project are told
     * (HIL-998).
     *
     * The same answer a start refused for want of a worker gets (HIL-999), and at once, plus one
     * {@see MasterFailureUnit::AGENT_START} card for the project - which is what separates this
     * from the other ends of the wait: nobody asked for those, this one is a failure.
     *
     * @param string $agentId Agent whose wait ended without a worker
     * @param Throwable $failure What the start was refused with
     */
    private function refuseAwaitingAgent(string $agentId, Throwable $failure): void
    {
        $this->reportWaitEndedWithoutStart($agentId, $failure->getMessage());
        $this->reportContainedFailure(new ContainedFailure(MasterFailureUnit::AGENT_START, $agentId, $failure));
    }

    /**
     * Tells the master that a wait for a worker ended and the agent will not start.
     *
     * EVERY way out of that wait comes through here, because the frames the master holds for a
     * starting agent end on a fact and on nothing else since HIL-1040: a wait that simply stopped
     * being would leave a page loading and an operator's command unanswered for as long as the
     * process lives. Before that they ran out on a six-second ceiling, which is why three of these
     * ways out used to be able to say nothing at all.
     *
     * Reported as a start that failed, which drops the master's record and answers those frames in
     * the words of a start refused on this node. The record is the temporary one
     * {@see startAgentInternal()} writes for a waiting agent and is linked to no worker, so there
     * is no live agent for the drop to take away.
     *
     * A refusal that cannot be named is written and swallowed: the record is gone by then, nothing
     * is left to unwind, and raising here would take the loop down over a frame nobody can be told
     * about. That frame is stranded, and this line is the only account of it there will be.
     *
     * @param string $agentId Agent whose wait ended without a start
     * @param string $reason Why it will not start, as the master reports it on
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    private function reportWaitEndedWithoutStart(string $agentId, string $reason): void
    {
        try {
            $this->agentManager->reportAgentStartFailed($agentId, $reason);
        } catch (InvalidArgumentException $failure) {
            Logger::error("Worker wait: the end of agent {$agentId}'s wait"
                . " could not be answered: {$failure->getMessage()}");
        }
    }

    /**
     * Whether an agent is waiting for a worker of its type to connect (HIL-998, HIL-1671).
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
     * @param string $agentId Agent id
     * @return bool Whether its next start awaits the earlier worker stop report
     */
    public function isAgentAwaitingStopReport(string $agentId): bool
    {
        return isset($this->agentsAwaitingStopReport[$agentId]);
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
     * Every non-inactive phase holds except the verification window - a follower stands on
     * activating until its leader says every node has stopped, and a window during deactivating
     * would reopen the very defect the gate closes. {@see StateProtectedModeRuntime::PHASE_VERIFYING} is the
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

        if (in_array($agentType, self::PROTECTED_MODE_PRESERVED_AGENT_TYPES, true)) {
            // Raw mail carries freeze alerts; Status keeps public files and heartbeat alive.
            // Their database halves obey the freeze independently. This grants no DB writer
            // an exemption from silence (AbstractDeliveryChannelAgent and StatusAgent).
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
            if (!$client instanceof WorkerClient || !$client->isRegistered() || $client->shouldClose()) {
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
     * Queues a test-only control frame on a registered worker that is not closing.
     *
     * @param int $workerId Signed regular or monopolistic worker id
     * @param WorkerDTO $frame Frame to queue
     * @return ?WorkerClient Exact channel used, or null when no live target exists
     */
    private function sendToLiveWorker(int $workerId, WorkerDTO $frame): ?WorkerClient
    {
        $worker = $this->findWorkerClientById($workerId);
        if ($worker === null || !$worker->isRegistered() || $worker->shouldClose()) {
            return null;
        }
        $worker->send($frame->toJson());
        return $worker;
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
            Hilos::$cluster?->protectedModeEntryGate()?->noteRosterChanged();
        }

        if ($this->uploadMemoryPoolSink !== null && $messageDto->signal->data instanceof WebSocketFrameBinarySignalDTO) {
            $lifetime = $this->uploadWorkerLifetimes[spl_object_id($workerClient)] ?? null;
            if ($lifetime === null || $lifetime->processExited) {
                throw new FrameworkLogicException('Upload worker delivery needs its registered live process incarnation');
            }
            $receiptId = $this->uploadMemoryPoolSink->assignUploadFrame($messageDto->signal->data, $workerClient);
            if ($receiptId !== null) {
                $messageDto = new DaemonAgentMessageDTO($agentId, $messageDto->signal, $receiptId);
            }
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
     * Stop agent and remove from manager
     *
     * Sends agent_stop signal to worker and removes agent from agent manager.
     * No-op if agent is not running. An agent still waiting for a worker to connect has none to
     * be stopped on: the stop takes it out of the wait, and its record with it (HIL-998, HIL-1671).
     * An agent already linked to a worker is taken off the roster the same way, and the master
     * is told so frames held for its start are let go rather than left waiting (HIL-1041).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @throws InvalidFormatException When a tracked delivery has an invalid completion address
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    protected function stopAgent(string $agentType, ?string $agentIndex = null): void
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);

        if (isset($this->agentsAwaitingStopReport[$agentId])) {
            $this->removeStopReportWait($agentId);
            $this->reportWaitEndedWithoutStart($agentId, 'it was stopped while waiting for its earlier stop report');

            return;
        }

        if (isset($this->agentsAwaitingWorker[$agentId])) {
            $this->removeWorkerWait($agentId);
            $this->reportWaitEndedWithoutStart($agentId, 'it was stopped while waiting for its worker to connect');

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
        $this->agentManager->reportAgentStopped($agentId);
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
     * bounded preparation and then one stop per native portion run through {@see advanceProtectedModeRoster()},
     * and the switch hears {@see ProtectedModeSwitch::onRosterStopped()} when the last one is.
     * Walking the whole roster inside this call used to hold the master's loop for as long as the
     * roster was long, and every client of the node waited on it (HIL-1012). The initiator agent is
     * left running so it can carry out the destructive operation the freeze protects, and so is
     * the mail pool and the public Status publisher. Bringing stopped agents back is the mirror seam,
     * landed in HIL-267 slice 7b.
     *
     * @param string $initiatorAgentType Initiator agent type left running
     * @param ?string $initiatorAgentIndex Initiator agent index, or null for a singleton initiator
     */
    public function stopAgentsForProtectedMode(string $initiatorAgentType, ?string $initiatorAgentIndex): void
    {
        ++$this->protectedModeRosterEpoch;
        $this->protectedModeRosterWork?->cancel();
        $this->protectedModeStoppedAgents = [...$this->protectedModeStoppedAgents, ...($this->protectedModeResumeQueue ?? [])];
        $this->protectedModeResumeQueue = null;
        $this->protectedModeStopQueue = [];
        $this->protectedModeStopInitiator = $this->buildAgentId($initiatorAgentType, $initiatorAgentIndex);
        // Capture once, before any external wait-removal hook can change the roster.
        $this->protectedModeStopPreparation = self::protectedModeStopCandidates(
            $this->agentsAwaitingWorker,
            $this->agentManager->getAgents(),
        );
        $this->protectedModeWalkPasses = 0;
        $this->protectedModeWalkAgents = 0;
        $this->protectedModeRosterWork?->markReady();
        $this->noteProtectedModeDiagnosticsChanged();
    }

    /**
     * @param array<string, AwaitingWorkerAgent> $waits Original waits, retaining exact incarnations
     * @param array<string, AgentDaemonInterface> $agents Original roster, excluding later arrivals
     * @return Generator<string, AwaitingWorkerAgent|AgentDaemonInterface> Finite request preparation
     */
    private static function protectedModeStopCandidates(array $waits, array $agents): Generator
    {
        yield from $waits;
        yield from $agents;
    }

    /**
     * Examines a bounded prefix before the first stop. Captured arrays retain their ordinary
     * PHP copy-on-write cost; this bounds filtering and external wait outcomes, not array copying.
     *
     * @param string $initiatorAgentId Agent spared by this request
     * @param int $epoch Exact request generation, checked after each external wait outcome
     * @throws InvalidFormatException When a tracked delivery has an invalid completion address
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    private function prepareProtectedModeStop(string $initiatorAgentId, int $epoch): void
    {
        $candidates = $this->protectedModeStopPreparation;
        for ($examined = 0; $examined < self::PROTECTED_MODE_PREPARATION_PER_PORTION && $candidates?->valid(); $examined++) {
            $agentId = $candidates->key();
            $candidate = $candidates->current();
            $candidates->next();
            if ($agentId === $initiatorAgentId
                || in_array($this->parseAgentId($agentId)->type, self::PROTECTED_MODE_PRESERVED_AGENT_TYPES, true)) {
                continue;
            }
            if ($candidate instanceof AwaitingWorkerAgent) {
                if (($this->agentsAwaitingWorker[$agentId] ?? null) === $candidate) {
                    $this->removeWorkerWait($agentId);
                    $this->reportWaitEndedWithoutStart($agentId, 'protected mode holds the node');
                    Logger::info("Worker wait: agent {$agentId} left its wait for a worker, protected mode holds the node");
                }
            } elseif ($this->agentManager->hasAgent($agentId) && $this->agentManager->getAgent($agentId) === $candidate) {
                $this->protectedModeStopQueue[$agentId] = $candidate;
            }
            if ($this->protectedModeRosterEpoch !== $epoch) {
                return;
            }
        }
        if ($candidates !== null && !$candidates->valid()) {
            $this->protectedModeStopPreparation = null;
        }
    }

    /**
     * Names the agents this node has asked for and not yet heard back about
     * ({@see ProtectedModeAgentFreezer::agentsStillStarting()}).
     *
     * Read off the roster itself: an agent is here the moment it is registered, and started only
     * once its worker has reported it. Everything between those two moments is a start in flight,
     * and a freeze entered on top of one costs the node that agent.
     *
     * So is an agent WAITING FOR A WORKER to connect ({@see isAgentAwaitingWorker()}, HIL-998,
     * HIL-1671): its record is linked to no worker yet, but somebody asked for it and the wait ends
     * by a deadline, seated or refused.
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
     * Current hold this node keeps for the freeze, read from this server's memory.
     *
     * The count is the distinct agents still held: remembered stops and the resume queue the walk
     * has not asked to start yet. A start already asked for has left that union, and the stop
     * queue is not in it — those agents are still running. The preserved types are the one policy
     * both gates already pass, in that policy's order. The gate flag is the same phase verdict
     * the inspect snapshot reports once the row is mounted, and false when the row is absent.
     * Unpublished null stays the row's own contract: publishing refuses to write until the row
     * is there.
     *
     * @return array{stoppedAgentCount: int, preservedAgentTypes: list<string>, agentStartGateClosed: bool} Hold this node would publish
     */
    public function getProtectedModeAgentDiagnostics(): array
    {
        $heldAgentIds = [];
        foreach ([...$this->protectedModeStoppedAgents, ...($this->protectedModeResumeQueue ?? [])] as $frozen) {
            $heldAgentIds[$this->buildAgentId($frozen->agent->type, $frozen->agent->index)] = true;
        }

        return [
            StateProtectedModeRuntime::stoppedAgentCount => count($heldAgentIds),
            StateProtectedModeRuntime::preservedAgentTypes => self::PROTECTED_MODE_PRESERVED_AGENT_TYPES,
            StateProtectedModeRuntime::agentStartGateClosed => Hilos::$rt?->hilosProtectedModeRuntime !== null
                && Hilos::$rt->hilosProtectedModeRuntime->phase !== StateProtectedModeRuntime::PHASE_INACTIVE
                && Hilos::$rt->hilosProtectedModeRuntime->phase !== StateProtectedModeRuntime::PHASE_VERIFYING,
        ];
    }

    /**
     * Whether the freeze in flight stopped this agent on this node and the lift has not asked for it back yet.
     *
     * Read off the remembered set and the replay queue, the two places such an agent stands between
     * its stop and its turn in the walk. A stop over an unfinished lift moves the queue back into the
     * set (stopAgentsForProtectedMode()), so the answer holds through it; the initiator and preserved
     * types are never stopped, so never here; once the walk has asked for the agent it is an ordinary
     * start under way. The master asks this to hold a connection's close for the agent rather than
     * drop it (HIL-1208).
     *
     * @param string $agentType Agent type to ask about
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return bool Whether that agent still awaits the protected-mode lift
     */
    public function awaitsProtectedModeResume(string $agentType, ?string $agentIndex): bool
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);
        foreach ([...$this->protectedModeStoppedAgents, ...($this->protectedModeResumeQueue ?? [])] as $frozen) {
            if ($this->buildAgentId($frozen->agent->type, $frozen->agent->index) === $agentId) {
                return true;
            }
        }

        return false;
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
        ++$this->protectedModeRosterEpoch;
        $this->protectedModeRosterWork?->cancel();
        // Entries not reached by a superseded stop are still running.
        $this->protectedModeStopQueue = [];
        $this->protectedModeStopPreparation = null;
        $this->protectedModeStopInitiator = null;

        // A lift over an unfinished lift - the window opened and the mode lifted before the window's
        // replay was done - keeps what the first had not asked for yet, in front of anything new.
        $this->protectedModeResumeQueue = [...$this->protectedModeResumeQueue ?? [], ...$this->protectedModeStoppedAgents];
        $this->protectedModeStoppedAgents = [];
        $this->protectedModeWalkPasses = 0;
        $this->protectedModeWalkAgents = 0;
        $this->protectedModeRosterWork?->markReady();
        $this->noteProtectedModeDiagnosticsChanged();
    }

    /**
     * Writes this node's hold onto the freeze row when the tuple has changed.
     *
     * Called by the dirty native duty after a source or roster fact. The row has to be mounted;
     * without it the four diagnostic fields stay null. The comparison is the row itself, so an
     * unchanged tuple never calls the action. The node id is this master's local id, and the
     * standalone id when the cluster facade is absent — the same rule as the cluster-node roster.
     * The write does not move the phase or the gate, and it adds no peer frame of its own: the
     * row's existing sync is what this node's workers already receive.
     *
     * @throws InvalidFormatException When the preserved agent types are not a list of strings
     * @throws RtActionsCollectionNameNullException When the freeze row cannot name its collection
     * @throws RtTruthSourceWriteNotAllowedException When this master is not the truth source of the row
     * @throws EnvException When the cluster-enabled flag or a cluster env value cannot be read
     * @throws ClusterConfigurationException When cluster mode is on and the node config is missing or invalid
     * @throws ClusterDisabledException When the cluster reports itself on and then refuses its identity
     */
    protected function publishProtectedModeAgentDiagnostics(): void
    {
        if (Hilos::$rt?->hilosProtectedModeRuntime === null) {
            return;
        }

        $diagnostics = $this->getProtectedModeAgentDiagnostics();
        $nodeId = Hilos::$cluster?->localNodeId() ?? StateHilosClusterNode::STANDALONE_NODE_ID;
        if (
            Hilos::$rt->hilosProtectedModeRuntime->diagnosticsNodeId === $nodeId
            && Hilos::$rt->hilosProtectedModeRuntime->stoppedAgentCount
                === $diagnostics[StateProtectedModeRuntime::stoppedAgentCount]
            && Hilos::$rt->hilosProtectedModeRuntime->preservedAgentTypes
                === $diagnostics[StateProtectedModeRuntime::preservedAgentTypes]
            && Hilos::$rt->hilosProtectedModeRuntime->agentStartGateClosed
                === $diagnostics[StateProtectedModeRuntime::agentStartGateClosed]
        ) {
            return;
        }

        Hilos::$rt->hilosProtectedModeRuntime->actions->updateAgentDiagnostics(
            $nodeId,
            $diagnostics[StateProtectedModeRuntime::stoppedAgentCount],
            $diagnostics[StateProtectedModeRuntime::preservedAgentTypes],
            $diagnostics[StateProtectedModeRuntime::agentStartGateClosed],
        );
    }

    /**
     * Takes one step of the protected-mode roster walk in flight, if there is one.
     *
     * Preparation examines a finite bounded prefix, then each portion stops or replays one agent.
     * A runnable tail joins the native FIFO again without waiting for another input or timer.
     * A stop and a lift are never both in flight. External hooks may replace the epoch; the old
     * portion must not finish or cancel its replacement.
     *
     * @throws RtActionsCollectionNameNullException When the switch a finished walk tells cannot name its row
     * @throws RtTruthSourceWriteNotAllowedException When that switch writes a row this master is not the truth source of
     * @throws InvalidFormatException When a tracked delivery has an invalid completion address
     * @throws StatusComponentDeclarationException When the lift reads an invalid enabled Status catalog
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    protected function advanceProtectedModeRoster(): void
    {
        $this->protectedModeRosterWork?->cancel();
        if ($this->protectedModeStopInitiator === null && $this->protectedModeResumeQueue === null) {
            return;
        }
        $epoch = $this->protectedModeRosterEpoch;
        try {
            if ($this->protectedModeStopInitiator !== null) {
                $this->prepareProtectedModeStop($this->protectedModeStopInitiator, $epoch);
                if ($this->protectedModeRosterEpoch === $epoch && $this->protectedModeStopPreparation === null) {
                    $this->advanceProtectedModeStop($this->protectedModeStopInitiator);
                }
            } elseif ($this->protectedModeResumeQueue !== null) {
                $this->advanceProtectedModeResume();
            }
        } finally {
            if ($this->protectedModeRosterEpoch === $epoch) {
                $this->noteProtectedModeDiagnosticsChanged();
                if ($this->protectedModeStopInitiator !== null || $this->protectedModeResumeQueue !== null) {
                    $this->protectedModeRosterWork?->markReady();
                }
            }
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
     * @throws InvalidFormatException When a tracked delivery has an invalid completion address
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    private function advanceProtectedModeStop(string $initiatorAgentId): void
    {
        $epoch = $this->protectedModeRosterEpoch;
        $this->protectedModeWalkPasses++;

        $agentId = array_key_first($this->protectedModeStopQueue);
        $candidate = $agentId === null ? null : $this->protectedModeStopQueue[$agentId];
        unset($this->protectedModeStopQueue[$agentId]);
        if ($agentId !== null && $this->agentManager->hasAgent($agentId) && $this->agentManager->getAgent($agentId) === $candidate) {
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
            $this->protectedModeWalkAgents++;
            $this->stopAgent($parsed->type, $parsed->index);
        }

        if ($this->protectedModeRosterEpoch !== $epoch || $this->protectedModeStopQueue !== []) {
            return;
        }

        $this->protectedModeStopInitiator = null;
        $this->protectedModeRosterWork?->cancel();
        $this->noteProtectedModeDiagnosticsChanged();

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
     *
     * @throws StatusComponentDeclarationException When the lift reads an invalid enabled Status catalog
     */
    private function advanceProtectedModeResume(): void
    {
        $epoch = $this->protectedModeRosterEpoch;
        $this->protectedModeWalkPasses++;

        $key = array_key_first($this->protectedModeResumeQueue);
        $frozen = $key === null ? null : $this->protectedModeResumeQueue[$key];
        unset($this->protectedModeResumeQueue[$key]);
        if ($frozen !== null) {
            $this->protectedModeWalkAgents++;
            try {
                $this->startAgentInternal($frozen->agent->type, $frozen->agent->index, true, $frozen->workerId);
            } catch (Throwable $e) {
                $agentId = $this->buildAgentId($frozen->agent->type, $frozen->agent->index);
                Logger::error("Protected mode: failed to resume agent {$agentId}: {$e->getMessage()}");
            } finally {
                Hilos::$cluster?->protectedModeEntryGate()?->noteRosterChanged();
                $this->agentHoldWake?->__invoke($this->buildAgentId($frozen->agent->type, $frozen->agent->index));
            }
        }

        if ($this->protectedModeRosterEpoch !== $epoch || $this->protectedModeResumeQueue !== []) {
            return;
        }

        $this->protectedModeResumeQueue = null;
        $this->protectedModeRosterWork?->cancel();
        $this->noteProtectedModeDiagnosticsChanged();
        Hilos::$cluster?->protectedModeEntryGate()?->noteRosterChanged();
        $passes = $this->protectedModeWalkPasses;
        $agents = $this->protectedModeWalkAgents;
        $this->agentHoldWake?->__invoke(null);
        if ($this->protectedModeRosterEpoch !== $epoch) {
            return;
        }

        Logger::info(
            'Protected mode: brought back ' . $agents . ' agent(s) over ' . $passes . ' pass(es)',
        );

        $this->onProtectedModeLifted();
        if ($this->protectedModeRosterEpoch === $epoch) {
            Hilos::$cluster?->protectedMode()?->onRosterResumed();
        }
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
     * the per-node registry list, enabled leader-hosted change log, Status components and delivery
     * channel owners this default starts.
     * Anything else this node was running is the application's call - a project that starts local agents by overriding
     * {@see onInitialWorkersReady()} has to override this hook too, or those agents stay down
     * until something else starts them. The default is not redundant with the replayed roster:
     * the roster is captured once on entry, so an agent whose start the gate refused during the
     * freeze (a worker re-registering after a crash, a cluster placement) is in no list at all.
     * {@see startAgent()} is idempotent, so replaying a still-running agent is a no-op.
     *
     * @throws StatusComponentDeclarationException When the enabled Status catalog is invalid
     */
    protected function onProtectedModeLifted(): void
    {
        $this->startPerNodeAgents();
        $this->startChangeLogAgent();
        $this->startStatusComponentAgents();
        $this->startDeliveryChannelOwners();
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
     * @return ResourceProfile Project cost; empty when the agent adds nothing to its slot
     * @throws LogicException When the agent declares a negative cost or the reserved slot
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
     * A start waiting for a worker to connect or for an earlier stop report is accepted and
     * answered with null; {@see placedWorkerId()} names its worker once it is seated.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return ?int Worker id the agent was placed on (negative = monopolistic, positive = regular), or
     *     null while the agent waits for a worker or an earlier stop report
     * @throws AgentDaemonCreationFailedException If the agent daemon cannot be built
     * @throws NoSuitableWorkerException If no suitable worker is available to host it
     * @throws AgentNotLinkedToWorkerException If the agent is neither linked nor waiting for a start
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function executePlacement(string $agentType, ?string $agentIndex): ?int
    {
        $this->startAgentInternal($agentType, $agentIndex, true);

        $agentId = $this->buildAgentId($agentType, $agentIndex);
        $workerId = $this->placedWorkerId($agentType, $agentIndex);
        if ($workerId === null && !$this->isAgentAwaitingWorker($agentId) && !$this->isAgentAwaitingStopReport($agentId)) {
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
     * @return ?int Worker id once the agent is seated, null while its start is still waiting
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
     * Stops a local agent and asks the ordinary start to bring it back on its former worker.
     *
     * @param string $agentType Agent type to restart
     * @param ?string $agentIndex Agent index, or null for a singleton
     * @param bool $placedByLeader Whether the leader sanctioned this placement
     * @throws LogicException When the old instance could not be taken off the roster
     * @throws AgentDaemonCreationFailedException If the new agent daemon cannot be built
     * @throws NoSuitableWorkerException If no worker can host the new instance
     * @throws HilosException Whatever the project's agent-daemon factory raises
     */
    public function restartAgentHere(string $agentType, ?string $agentIndex, bool $placedByLeader): void
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);
        $oldWorkerId = $this->placedWorkerId($agentType, $agentIndex);
        $this->stopAgent($agentType, $agentIndex);
        if ($this->agentManager->hasAgent($agentId)) {
            throw new LogicException("Agent {$agentId} could not be stopped");
        }

        $this->startAgentInternal($agentType, $agentIndex, $placedByLeader, $oldWorkerId);
    }

    /**
     * @param string $agentType Agent type asking to start
     * @param ?string $agentIndex Agent index, or null for a singleton
     * @return bool Whether protected mode currently refuses its start
     */
    public function refusesStartUnderProtectedMode(string $agentType, ?string $agentIndex): bool
    {
        return $this->protectedModeRefusesStart($agentType, $agentIndex);
    }

    /**
     * Stops a placed agent on this node ({@see PlacementExecutor}); a no-op when it is not
     * running.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @throws InvalidFormatException When a tracked delivery has an invalid completion address
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
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
     * Ready when every worker process has exited and every linked worker connection is closed.
     * The master reads a connection one buffer at a time, so an exited worker's last frames
     * may still be in its socket (HIL-1207); the second stop wave also waits on this fact.
     *
     * @return bool True if ready to shutdown
     */
    public function isReadyToShutdown(): bool
    {
        return $this->workers === [] && $this->retiredWorkerProcesses === [] && !$this->linkedWorkerConnectionOpen();
    }

    /**
     * Requests the enabled leader-hosted journal through the ordinary start gates, containing failure.
     * NODE replicas and POLICY singletons keep their existing bootstrap and placement paths.
     * A refused first start under freeze is retried on lift even when no roster entry was remembered.
     */
    private function startChangeLogAgent(): void
    {
        if (!Hilos::hasFeature(HilosFeature::CHANGE_LOG) || !$this->amClusterLeader()) {
            return;
        }

        $registryEntry = Hilos::appClass()::AGENTS[HilosAgentType::HILOS_CHANGE_LOG] ?? null;
        if (
            AgentRegistry::scope($registryEntry) !== AgentScope::CLUSTER
            || AgentRegistry::placement($registryEntry) !== AgentPlacement::LEADER
        ) {
            return;
        }

        try {
            $this->startAgent(HilosAgentType::HILOS_CHANGE_LOG, null);
        } catch (Throwable $throwable) {
            Logger::error('Failed to start change-log agent ' . HilosAgentType::HILOS_CHANGE_LOG . ': ' . $throwable->getMessage());
            $this->reportContainedFailure(new ContainedFailure(
                MasterFailureUnit::AGENT_START,
                $this->buildAgentId(HilosAgentType::HILOS_CHANGE_LOG, null),
                $throwable,
            ));
        }
    }

    /**
     * Starts each enabled Status leader component type once per pass, containing individual failures.
     * NODE replicas and POLICY singletons keep their existing bootstrap and placement paths.
     *
     * @throws StatusComponentDeclarationException When the enabled Status catalog is invalid
     */
    private function startStatusComponentAgents(): void
    {
        if (!Hilos::hasFeature(HilosFeature::STATUS) || !$this->amClusterLeader()) {
            return;
        }

        /** @var array<string, true> Types already requested in this synchronous catalog pass */
        $requestedTypes = [];
        foreach (Hilos::statusComponents() as $component) {
            if ($component->scope !== AgentScope::CLUSTER || $component->placement !== AgentPlacement::LEADER) {
                continue;
            }

            $agentType = $component->agentType;
            if (isset($requestedTypes[$agentType])) {
                continue;
            }
            $requestedTypes[$agentType] = true;

            try {
                $this->startAgent($agentType, null);
            } catch (Throwable $throwable) {
                Logger::error("Failed to start Status component agent {$agentType}: " . $throwable->getMessage());
                $this->reportContainedFailure(new ContainedFailure(
                    MasterFailureUnit::AGENT_START,
                    $this->buildAgentId($agentType, null),
                    $throwable,
                ));
            }
        }
    }

    /**
     * Requests every declared delivery owner without reading recipients or transport configuration.
     * Cluster POLICY demand goes through placement to preserve remote owners and wait for rebuild.
     * Channel description and individual start failures are contained separately so later owners still start.
     */
    private function startDeliveryChannelOwners(): void
    {
        if (!Hilos::hasFeature(HilosFeature::NOTIFICATION_DELIVERY) || !$this->amClusterLeader()) {
            return;
        }

        foreach (array_keys(Hilos::notificationChannelRegistryClass()::all()) as $channel) {
            try {
                $owners = DeliveryChannelOwners::of($channel);
            } catch (Throwable $throwable) {
                Logger::error("Failed to describe delivery channel {$channel} owners: " . $throwable->getMessage());
                $this->reportContainedFailure(new ContainedFailure(
                    MasterFailureUnit::AGENT_START,
                    self::DELIVERY_CHANNEL_FAILURE_ADDRESS . $channel,
                    $throwable,
                ));
                continue;
            }

            foreach ($owners->agentIndexes as $index) {
                try {
                    $placement = Hilos::$cluster?->placement();
                    $registryEntry = Hilos::appClass()::AGENTS[$owners->agentType] ?? null;
                    if ($placement !== null
                        && AgentRegistry::scope($registryEntry) === AgentScope::CLUSTER
                        && AgentRegistry::placement($registryEntry) === AgentPlacement::POLICY) {
                        $placement->requirePlacement($owners->agentType, $index);
                    } else {
                        $this->startAgent($owners->agentType, $index);
                    }
                } catch (Throwable $throwable) {
                    $agentId = $this->buildAgentId($owners->agentType, $index);
                    Logger::error("Failed to start delivery channel owner {$agentId}: " . $throwable->getMessage());
                    $this->reportContainedFailure(new ContainedFailure(
                        MasterFailureUnit::AGENT_START,
                        $agentId,
                        $throwable,
                    ));
                }
            }
        }
    }

    /**
     * @return bool Whether a linked worker still has an open connection
     */
    private function linkedWorkerConnectionOpen(): bool
    {
        foreach ($this->clients as $client) {
            if ($client instanceof WorkerClient && $client->getWorkerIndex() !== self::UNLINKED_WORKER_INDEX) {
                return true;
            }
        }

        return false;
    }
}
