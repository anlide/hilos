<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Agent\Exception\InvalidCommandPayloadException;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\DTO\DbReHydrateOutcome;
use Hilos\Runtime\ConnectionRosterReconciler;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\Worker\WorkerTickUnit;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\MissingRequiredParameterException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\Exception\FramePopOrderException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Page\DTO\PageAccessReassessConnectionsSignalData;
use Hilos\Core\Page\DTO\PageAccessReassessUserSignalData;
use Hilos\Core\Page\Exception\PageSignalRouterNotFoundException;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\SocketException;
use Hilos\Socket\WebSocket\DTO\WebSocketAcceptKeySignalDTO;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use Hilos\Socket\Worker\DTO\ProtectedModeReadyDTO;
use Hilos\Core\Sync\DTO\DbReHydrateSignalData;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\SyncSignalDataInterface;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\DaemonWorkerSignalDTO;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydratedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydrateMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReReadMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncClearedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncMessageInterface;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessConnectionsMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeDisableDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeEnableDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModePassDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeProgressDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeRefreezeDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeVerifyDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceReleasedDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncMessageInterface;
use Hilos\Socket\Worker\DTO\WorkerRtSyncUpdatedMessageDTO;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeRefreezeSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeVerifySignalData;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Environment\Exception\EnvException;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Utils\Helpers\HttpHeaderHelper;
use Hilos\Utils\Logger;
use Hilos\Utils\WorkerTickFailureLog;
use Throwable;

/**
 * Base process manager for worker processes.
 *
 * Owns the daemon connection, worker-local agents, page signal routers,
 * subscription mirrors, and browser flushing.
 */
abstract class WorkerManager extends BaseManager
{
    /** Seconds between parent-process checks; the loop itself spins every 10 ms. */
    private const float PARENT_CHECK_INTERVAL_SECONDS = 1.0;

    /** Worker index assigned by the daemon supervisor. */
    protected int $workerIndex;

    /** Whether this worker handles monopolistic agents only. */
    protected bool $isMonopolistic;

    /** Daemon client connection, or null before connect/after cleanup. */
    protected ?WorkerDaemonClient $daemonClient = null;

    /** Pid of the daemon that forked this worker, or null before the loop starts. */
    protected ?int $daemonPid = null;

    /** Loop timestamp of the last parent-process check. */
    private float $lastParentCheckAt = 0.0;

    /** Agent manager for worker-local agent instances. */
    protected AgentManager $agentManager;

    /** @var array<string, PageSignalRouter> Page routers by agent id */
    private array $pageSignalRouters = [];

    /**
     * Worker-local mirror of the current page subscription per WebSocket accept key.
     *
     * The daemon-side page subscription mirror overwrites state when the client
     * sends a page subscribe signal. The worker keeps the previous page so it
     * can run page teardown before a replacement subscribe and again on
     * connection close when the client did not send an explicit unsubscribe.
     *
     * @var array<string, array{page: string, params: array<string, string>}> Accept key → last page id and route params
     */
    private array $pageSubscriptionByAcceptKey = [];

    /**
     * Creates the worker manager and initializes worker-local framework services.
     *
     * The concrete worker supplies the signal router and agent manager factory.
     *
     * @param int $workerIndex Worker index
     * @param list<string> $argv Command line arguments
     */
    public function __construct(int $workerIndex, array $argv = [])
    {
        $this->workerIndex = $workerIndex;
        $this->isMonopolistic = ArgumentHelper::isMonopolistic($argv);
        Hilos::initSignalRouter($this->createSignalRouter());
        $this->agentManager = $this->createAgentManager();
    }

    /**
     * Sets DB and RT truth-source context for the current agent callback.
     *
     * @param ?string $agentId Current agent id, or null outside an agent callback
     */
    private function setCurrentAgentId(?string $agentId): void
    {
        ExecutionContext::setCurrentAgentId($agentId);
    }

    /**
     * Creates the signal router used by this worker.
     *
     * The constructor registers the returned router globally through Hilos::$sr.
     *
     * @return SignalRouter Signal router instance
     */
    abstract protected function createSignalRouter(): SignalRouter;

    /**
     * Runs the worker event loop.
     *
     * Sets up process handlers, opens the daemon connection, handles daemon
     * messages, ticks worker-local agents, and drains queued signals until a
     * shutdown condition requests exit.
     *
     * A tick is made of units of work - one daemon message, one agent, the project's
     * hook, the signal dispatch, the analytics tick - and a failure that belongs to one
     * of them is contained by {@see self::containFailure()}: written, handed to
     * {@see self::onTickFailure()}, and the unit skipped, so the units around it keep
     * ticking. The worker still leaves for the two things that mean it has nothing left
     * to serve - a lost daemon connection and being orphaned - and for nothing else. The
     * master answers the same question by leaving, and a worker deliberately does not:
     * {@see WorkerServer::ensureMinWorkers()} raises a replacement on the next tick, so a
     * cause that stays put is a crash loop rather than a failover, and every restart
     * stops this worker's agents along the way.
     *
     * @throws MissingRequiredParameterException When required signal functions are unavailable
     */
    public function run(): void
    {
        // Check the availability of required functions
        $this->checkRequiredFunctions(['posix_getppid']);

        // Setup error handling and signal handlers
        $this->setupErrorHandling();
        $this->setupSignalHandlers();
        Hilos::$ac?->openWorkerSession($this->workerIndex, $this->isMonopolistic);

        Logger::info("Worker #{$this->workerIndex} started");

        // Remember the supervisor before the connection exists: an orphaned worker
        // whose EOF never arrives is recognised by this pid changing.
        $this->daemonPid = $this->currentParentPid();

        // Start connection to daemon (non-blocking)
        try {
            $this->connectToDaemon();
        } catch (Throwable $e) {
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
                } catch (Throwable $failure) {
                    // A frame that never became a message. The socket itself is fine, so
                    // this belongs to the message and not to the transport: contained like
                    // any other unit, with the frames behind it still queued.
                    $this->containFailure(WorkerTickUnit::DAEMON_MESSAGE, 'unparsed frame', $failure);
                }

                // Process messages from daemon queue
                while (($message = $this->daemonClient->getNextMessage()) !== null) {
                    try {
                        $this->handleDaemonMessage($message);
                    } catch (Throwable $failure) {
                        $this->containFailure(WorkerTickUnit::DAEMON_MESSAGE, $message->getType(), $failure);
                    } finally {
                        ExecutionContext::clear();
                    }
                }

                // Call tick method (only when connected)
                $this->setCurrentAgentId(null);
                try {
                    $this->onTick();
                } catch (Throwable $failure) {
                    $this->containFailure(WorkerTickUnit::WORKER_TICK, 'onTick', $failure);
                }

                // Tick all agents
                foreach ($this->agentManager->getAgents() as $agentId => $agent) {
                    $this->setCurrentAgentId($agent->getId());

                    try {
                        $agent->onTick();
                        $this->releaseDeferredWork($agentId);

                        // Check if agent requested stop
                        if ($agent->shouldStop()) {
                            try {
                                $this->runAgentStopHook($agent);
                            } catch (Throwable $failure) {
                                // Contained one level in, not by the guard around the agent: the
                                // stop was asked for by the agent itself, and the steps below are
                                // what grant it. Letting the guard take them would leave an agent
                                // that wants to be gone in the manager, asking again every tick.
                                $this->containFailure(WorkerTickUnit::AGENT, $agent->getId(), $failure);
                            }
                            Logger::logAgentStop($agent->getId(), $agent->getType());
                            Hilos::$ac?->closeAgentSession($agent->getType(), $agent->getIndex());
                            $this->agentManager->removeAgent($agentId);
                            Logger::info("Agent {$agentId} stopped (self-requested)");
                            Logger::logAgentInfo($agentId, "Agent stopped (self-requested) on worker [workerIndex={$this->workerIndex}]");
                            $this->notifyAgentStopped($agentId);
                        }
                    } catch (Throwable $failure) {
                        // The agent stays in the manager: it is not a connection, dropping it
                        // would lose the truth sources it owns and the work it had started, and
                        // the decision to stop belongs to the agent and its owner, not here.
                        $this->containFailure(WorkerTickUnit::AGENT, $agent->getId(), $failure);
                    }
                }

                // Dispatch accumulated signals (send to daemon)
                $this->setCurrentAgentId(null);
                try {
                    $this->dispatchSignals();
                } catch (Throwable $failure) {
                    $this->containFailure(WorkerTickUnit::SIGNAL_DISPATCH, 'dispatchSignals', $failure);
                }

                try {
                    Hilos::$ac?->tick();
                } catch (Throwable $failure) {
                    $this->containFailure(WorkerTickUnit::ANALYTICS, 'analytics tick', $failure);
                }
            }

            // Close the windows the contained-failure limiter has been counting in, so a
            // stream of failures that stopped still reports how much it held back.
            WorkerTickFailureLog::flushClosedWindows($this->workerIndex, $loopStartTime);

            // Outside the connected branch on purpose: a worker whose connect() is
            // still queued in an inherited listen backlog never gets here otherwise.
            $this->checkDaemonLiveness($loopStartTime);
            if ($this->shouldExit) {
                break;
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
     * Requests exit once this worker is orphaned.
     *
     * Two independent detectors feed one exit: the daemon connection reaching its
     * terminal lost state, and the supervisor pid changing. The second one covers
     * the case where no EOF arrives at all, so it is checked on its own interval
     * rather than per loop iteration.
     *
     * @param float $loopStartTime Timestamp of the current loop iteration
     */
    protected function checkDaemonLiveness(float $loopStartTime): void
    {
        if ($this->daemonClient !== null && $this->daemonClient->isConnectionLost()) {
            Logger::info("Worker #{$this->workerIndex}: daemon connection closed, worker exits");
            $this->shouldExit = true;
            return;
        }

        if ($loopStartTime - $this->lastParentCheckAt < self::PARENT_CHECK_INTERVAL_SECONDS) {
            return;
        }
        $this->lastParentCheckAt = $loopStartTime;

        if ($this->daemonPid !== null && $this->currentParentPid() !== $this->daemonPid) {
            Logger::info("Worker #{$this->workerIndex}: daemon parent gone, worker exits");
            $this->shouldExit = true;
        }
    }

    /**
     * Reads the pid of the process supervising this worker.
     *
     * @return int Parent process id
     */
    protected function currentParentPid(): int
    {
        return posix_getppid();
    }

    /**
     * Starts the non-blocking daemon connection and queues worker registration.
     *
     * Connection progress is polled by the main worker loop.
     *
     * Protected because this is the seam a test overrides: it is the one step of
     * {@see self::run()} that needs a real socket, and the containment the loop is built
     * around cannot be exercised at all without putting a scripted client in its place.
     *
     * @throws SocketException When connection setup fails
     * @throws EnvException When daemon connection environment values are missing or invalid
     */
    protected function connectToDaemon(): void
    {
        $this->daemonClient = new WorkerDaemonClient();
        $this->daemonClient->connect();

        // Send worker registration message (will be sent when connection is established)
        $this->daemonClient->send(new WorkerRegisterDTO(
            workerIndex: $this->workerIndex,
            monopolistic: $this->isMonopolistic,
        ));
    }

    /**
     * Routes one daemon message to the worker handler that owns its type.
     *
     * @param WorkerDTO $data Daemon message DTO
     * @throws AgentCreationFailedException When agent creation fails
     * @throws PageSignalRouterNotFoundException When page routing is requested for an unsupported agent
     * @throws TableRowKeyMissingException When a windowed table row is a placeholder and carries no key
     * @throws InvalidArgumentException When a command handler cannot name its reply, or a re-decision its page
     * @throws HilosException When a collection refuses to be re-read from the replaced database
     */
    public function handleDaemonMessage(WorkerDTO $data): void
    {
        $this->setCurrentAgentId(null);
        $type = $data->getType();

        switch ($type) {
            case WorkerConstants::MESSAGE_WORKER_REGISTERED:
                if (!$data instanceof WorkerRegisteredDTO) {
                    Logger::error("handleWorkerRegistered - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleWorkerRegistered($data);
                break;

            case WorkerConstants::MESSAGE_AGENT_START:
                if (!$data instanceof AgentStartDTO) {
                    Logger::error("handleAgentStart - unexpected type: " . get_class($data));
                    break;
                }
                $this->setCurrentAgentId($data->agentId);
                $this->handleAgentStart($data);
                break;

            case WorkerConstants::MESSAGE_AGENT_STOP:
                if (!$data instanceof AgentStopDTO) {
                    Logger::error("handleAgentStop - unexpected type: " . get_class($data));
                    break;
                }
                $this->setCurrentAgentId($data->agentId);
                $this->handleAgentStop($data);
                break;

            case WorkerConstants::MESSAGE_PROTECTED_MODE_READY:
                if (!$data instanceof ProtectedModeReadyDTO) {
                    Logger::error("handleProtectedModeReady - unexpected type: " . get_class($data));
                    break;
                }
                $this->setCurrentAgentId($data->agentId);
                $this->handleProtectedModeReady($data);
                break;

            case WorkerConstants::MESSAGE_DAEMON_AGENT_MESSAGE:
                if (!$data instanceof DaemonAgentMessageDTO) {
                    Logger::error("handleAgentMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->setCurrentAgentId($data->agentId);
                if ($data->signal->data instanceof WebSocketAcceptKeySignalDTO) {
                    ExecutionContext::setCurrentAcceptKey($data->signal->data->getAcceptKey());
                }
                $this->handleAgentMessage($data);
                break;

            case WorkerConstants::MESSAGE_DAEMON_WORKER_SIGNAL:
                if (!$data instanceof DaemonWorkerSignalDTO) {
                    Logger::error("onDaemonSignal - unexpected type: " . get_class($data));
                    break;
                }
                $this->onDaemonSignal($data->signalName, $data->data);
                break;

            case WorkerConstants::MESSAGE_PAGE_ACCESS_REASSESS_USER:
                if (!$data instanceof WorkerPageAccessReassessMessageDTO) {
                    Logger::error("sweepThisWorker - unexpected type: " . get_class($data));
                    break;
                }
                PageAccessReassessment::sweepThisWorker($data->userId);
                break;

            case WorkerConstants::MESSAGE_PAGE_ACCESS_REASSESS_CONNECTIONS:
                if (!$data instanceof WorkerPageAccessReassessConnectionsMessageDTO) {
                    Logger::error("sweepThisWorkerConnections - unexpected type: " . get_class($data));
                    break;
                }
                PageAccessReassessment::sweepThisWorkerConnections($data->acceptKeys);
                break;

            case WorkerConstants::MESSAGE_DB_SYNC_CREATED:
                if (!$data instanceof WorkerDbSyncCreatedMessageDTO) {
                    Logger::error("handleDbSyncMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleDbSyncMessage($data);
                break;

            case WorkerConstants::MESSAGE_DB_SYNC_UPDATED:
                if (!$data instanceof WorkerDbSyncUpdatedMessageDTO) {
                    Logger::error("handleDbSyncMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleDbSyncMessage($data);
                break;

            case WorkerConstants::MESSAGE_DB_SYNC_DELETED:
                if (!$data instanceof WorkerDbSyncDeletedMessageDTO) {
                    Logger::error("handleDbSyncMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleDbSyncMessage($data);
                break;

            case WorkerConstants::MESSAGE_DB_SYNC_CLEARED:
                if (!$data instanceof WorkerDbSyncClearedMessageDTO) {
                    Logger::error("handleDbSyncClearedMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleDbSyncClearedMessage($data);
                break;

            case WorkerConstants::MESSAGE_DB_REHYDRATE:
                if (!$data instanceof WorkerDbReHydrateMessageDTO) {
                    Logger::error("handleDbReHydrateMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleDbReHydrateMessage();
                break;

            case WorkerConstants::MESSAGE_DB_RE_READ:
                if (!$data instanceof WorkerDbReReadMessageDTO) {
                    Logger::error("handleDbReReadMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleDbReReadMessage();
                break;

            case WorkerConstants::MESSAGE_DB_REHYDRATE_COMPLETE:
                if (!$data instanceof DbReHydrateCompleteDTO) {
                    Logger::error("handleDbReHydrateComplete - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleDbReHydrateComplete($data);
                break;

            case WorkerConstants::MESSAGE_RT_SYNC_CREATED:
                if (!$data instanceof WorkerRtSyncCreatedMessageDTO) {
                    Logger::error("handleRtSyncMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleRtSyncMessage($data);
                break;

            case WorkerConstants::MESSAGE_RT_SYNC_UPDATED:
                if (!$data instanceof WorkerRtSyncUpdatedMessageDTO) {
                    Logger::error("handleRtSyncMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleRtSyncMessage($data);
                break;

            case WorkerConstants::MESSAGE_RT_SYNC_DELETED:
                if (!$data instanceof WorkerRtSyncDeletedMessageDTO) {
                    Logger::error("handleRtSyncMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleRtSyncMessage($data);
                break;

            default:
                // Unknown message type
                Logger::info("Unknown message type received from daemon: {$type}");
                break;
        }
    }

    /**
     * Marks the daemon connection as registered and logs the worker session.
     *
     * @param WorkerRegisteredDTO $data Worker registration acknowledgement
     */
    private function handleWorkerRegistered(WorkerRegisteredDTO $data): void
    {
        // Connection confirmed by daemon
        Logger::info("Connected to daemon");
        Hilos::$ac?->logWorkerSystemSignal('worker_registered', [
            'workerIndex' => $this->workerIndex,
            'isMonopolistic' => $this->isMonopolistic,
        ]);
    }

    /**
     * Starts one worker-local agent requested by the daemon.
     *
     * @param AgentStartDTO $data Agent start request
     * @throws AgentCreationFailedException When agent creation fails
     * @throws HilosException Whatever the started agent's start hook raises, or the roster reconcile after it
     */
    private function handleAgentStart(AgentStartDTO $data): void
    {
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
        $agentType = $parsed->type;
        $agentIndex = $parsed->index;

        // Create agent using factory method
        $agent = $this->agentManager->createAndAddAgent($agentType, $agentIndex);

        Logger::logAgentStart($agent->getId(), $agent->getType());
        $agent->onStart();
        Hilos::$ac?->openAgentSession($agentType, $agentIndex);
        Logger::info("Agent '{$agentId}' started");
        // Additional agent log from worker side
        Logger::logAgentInfo($agentId, "Agent started on worker [workerIndex={$this->workerIndex}]");

        // Now that the agent has claimed its collections, and before it can be addressed: the rows
        // of sockets that died while it was down are struck against the roster the frame carried
        // (HIL-664). Earlier than onStart() is impossible - the write-guard has no owner to accept
        // yet - and later would mean serving presence that names people who are gone.
        $struck = ConnectionRosterReconciler::reconcile($data->liveAcceptKeys);
        if ($struck > 0) {
            Logger::info("Connection roster: dropped {$struck} connection(s) with no live socket on {$agentId} start");
        }

        // Tell the daemon what this agent took ownership of before reporting that it started:
        // the master replicates RT by that map, and the agent is addressable from the moment
        // the started notification lands.
        $this->notifyRtSourcesRegistered($agentId);

        // Notify daemon that agent started
        $this->notifyAgentStarted($agentId, $agentType, $agentIndex);
    }

    /**
     * Stops one worker-local agent requested by the daemon.
     *
     * @param AgentStopDTO $data Agent stop request
     */
    private function handleAgentStop(AgentStopDTO $data): void
    {
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

        try {
            $this->runAgentStopHook($agent);
        } catch (Throwable $e) {
            // A failing stop hook must not crash the worker on a daemon stop request;
            // truth sources are already unregistered in the hook's finally.
            Logger::logAgentError($agent->getId(), "Stop hook failed on agent stop request: {$e->getMessage()}");
        }
        Logger::logAgentStop($agent->getId(), $agent->getType());
        Hilos::$ac?->closeAgentSession($agent->getType(), $agent->getIndex());
        $this->agentManager->removeAgent($agentId);
        Logger::info("Agent {$agentId} stopped");
        // Additional agent log from worker side
        Logger::logAgentInfo($agentId, "Agent stopped on worker [workerIndex={$this->workerIndex}]");

        // Notify daemon that agent stopped
        $this->notifyAgentStopped($agentId);
    }

    /**
     * Relays the leader's protected-mode ready to the addressed initiator agent on this worker.
     *
     * A no-op when the agent is not (or no longer) hosted here, mirroring {@see handleAgentStop()}.
     *
     * @param ProtectedModeReadyDTO $data Ready relay naming the initiator agent
     */
    private function handleProtectedModeReady(ProtectedModeReadyDTO $data): void
    {
        if ($data->agentId === '') {
            return;
        }

        $agent = $this->agentManager->getAgent($data->agentId);
        if ($agent === null) {
            return;
        }

        $agent->onProtectedModeReady();
    }

    /**
     * Applies and forwards a row-scoped DB sync received from the daemon.
     *
     * The step order is part of the contract: the self-echo is dropped before the
     * applicator runs, the browser invalidation is recorded after it, and agents
     * are notified last.
     *
     * The frame's origin is passed to the applicator rather than dropped here: this worker's
     * copy of a collection is its own, so whether it takes a row created elsewhere in the
     * cluster is its own question to answer (HIL-670). Everything after the apply is unchanged
     * by it - a browser looking at a row and an agent listening for it care that the fact
     * happened, not which node it happened on.
     *
     * @param WorkerDbSyncMessageInterface $data Worker-level DB sync message (create, update or delete)
     */
    private function handleDbSyncMessage(WorkerDbSyncMessageInterface $data): void
    {
        if ($this->consumeIncomingDbSyncSelfBroadcast($data->signalData)) {
            return;
        }
        match (true) {
            $data->signalData instanceof DbSyncCreatedSignalData => DbSyncApplicator::applyCreated(
                $data->signalData,
                skipSelfBroadcastCheck: false,
                originNodeId: $data->originNodeId,
            ),
            $data->signalData instanceof DbSyncUpdatedSignalData => DbSyncApplicator::applyUpdated(
                $data->signalData,
                skipSelfBroadcastCheck: false,
                originNodeId: $data->originNodeId,
            ),
            $data->signalData instanceof DbSyncDeletedSignalData => DbSyncApplicator::applyDeleted(
                $data->signalData,
                skipSelfBroadcastCheck: false,
                originNodeId: $data->originNodeId,
            ),
            default => Logger::error(
                'handleDbSyncMessage - unexpected payload: ' . get_class($data->signalData),
            ),
        };
        $this->recordBrowserSourceChange($data->signalData);
        $this->dispatchDbSyncToAgents($data->signalData);
    }

    /**
     * Applies and fans out a DB clear (collection truncate) received from the daemon.
     *
     * A clear is browser-only fan-out: it drops the local in-memory rows and
     * records a browser source change. It is not dispatched to agents — agents
     * that care about a truncate observe the per-collection delete/create events.
     *
     * @param WorkerDbSyncClearedMessageDTO $data Worker-level DB clear sync message
     */
    private function handleDbSyncClearedMessage(WorkerDbSyncClearedMessageDTO $data): void
    {
        if ($this->isOwnDbSyncClearEcho($data->signalData)) {
            return;
        }
        DbSyncApplicator::applyCleared(
            $data->signalData,
            skipSelfBroadcastCheck: false,
            originNodeId: $data->originNodeId,
        );
        $this->recordBrowserSourceChange($data->signalData);
    }

    /**
     * Re-reads every DB-backed collection after the database was replaced under the node (HIL-479).
     *
     * The whole-context sibling of {@see handleDbSyncClearedMessage()}: no browser source change
     * and no agent fan-out, because the event names no collection and no row - the worker simply
     * stops trusting everything it cached. A failed re-read is logged rather than thrown: the
     * alternative is a worker that dies while the node is still finishing a restore, and the
     * generation fallback in {@see DbContext::reHydrateIfDbChanged()} still catches the stale
     * rows at their first collision.
     *
     * Either way the worker answers (HIL-436). The failure used to end at the log line above,
     * where nobody restoring a database would ever see it; now it also travels back as a negative
     * answer, and it is what keeps the node closed to the verifiers who would otherwise read this
     * worker's stale caches.
     */
    private function handleDbReHydrateMessage(): void
    {
        try {
            DbSyncApplicator::applyReHydrate();
            $this->daemonClient->send(new WorkerDbReHydratedDTO(ok: true));
        } catch (DatabaseException | LogicException $e) {
            Logger::error('handleDbReHydrateMessage - could not re-read the database: ' . $e->getMessage());
            $this->daemonClient->send(new WorkerDbReHydratedDTO(ok: false, error: $e->getMessage()));
        }
    }

    /**
     * Stops trusting the database rows this worker holds, after its node linked to a peer.
     *
     * The same re-read as {@see handleDbReHydrateMessage()} and deliberately without its two
     * companions (HIL-670). Nothing is answered, because nothing is waiting: the database was
     * not replaced, no freeze is being held open, and there is no barrier to close. And the
     * failure is only logged for the same reason it is there — a worker that dies because one
     * query failed costs its node the agents and connections it was holding, while the rows it
     * kept are caught at their first collision by the generation fallback.
     */
    private function handleDbReReadMessage(): void
    {
        try {
            DbSyncApplicator::applyReHydrate();
        } catch (DatabaseException | LogicException $e) {
            Logger::error('handleDbReReadMessage - could not re-read the database: ' . $e->getMessage());
        }
    }

    /**
     * Relays the aggregated re-hydrate verdict to the agent that announced the swap (HIL-436).
     *
     * A no-op when the agent is not (or no longer) hosted here, mirroring
     * {@see handleProtectedModeReady()}.
     *
     * @param DbReHydrateCompleteDTO $data Verdict naming the announcing agent
     */
    private function handleDbReHydrateComplete(DbReHydrateCompleteDTO $data): void
    {
        if ($data->agentId === null) {
            return;
        }

        $agent = $this->agentManager->getAgent($data->agentId);
        if ($agent === null) {
            return;
        }

        $agent->onDbReHydrateComplete(new DbReHydrateOutcome($data->complete, $data->problems));
    }

    /**
     * Dispatches a DB sync signal to every agent on this worker.
     *
     * Each agent can filter by collectionKey/idString in its onSignal* handler.
     *
     * @param DbSyncSignalDataInterface $data DB sync payload
     */
    private function dispatchDbSyncToAgents(DbSyncSignalDataInterface $data): void
    {
        $source = SignalSource::DB;

        foreach ($this->agentManager->getAgents() as $agentId => $agent) {
            if (!$agent instanceof AgentInterface) {
                continue;
            }
            $this->setCurrentAgentId($agent->getId());
            match (true) {
                $data instanceof DbSyncCreatedSignalData => $agent->onSignalDbSyncCreated(
                    $data,
                    $source,
                    SignalConstants::DB_SYNC_CREATED,
                ),
                $data instanceof DbSyncUpdatedSignalData => $agent->onSignalDbSyncUpdated(
                    $data,
                    $source,
                    SignalConstants::DB_SYNC_UPDATED,
                ),
                $data instanceof DbSyncDeletedSignalData => $agent->onSignalDbSyncDeleted(
                    $data,
                    $source,
                    SignalConstants::DB_SYNC_DELETED,
                ),
                default => Logger::error('dispatchDbSyncToAgents - unexpected payload: ' . get_class($data)),
            };
        }

        $this->setCurrentAgentId(null);
    }

    /**
     * Dispatches an RT sync signal to every agent on this worker.
     *
     * Each agent can filter by collectionKey/stateId in its onSignal* handler.
     *
     * @param RtSyncSignalDataInterface $data RT sync payload
     */
    private function dispatchRtSyncToAgents(RtSyncSignalDataInterface $data): void
    {
        $source = SignalSource::RT;

        foreach ($this->agentManager->getAgents() as $agentId => $agent) {
            if (!$agent instanceof AgentInterface) {
                continue;
            }
            $this->setCurrentAgentId($agent->getId());
            match (true) {
                $data instanceof RtSyncCreatedSignalData => $agent->onSignalRtSyncCreated(
                    $data,
                    $source,
                    SignalConstants::RT_SYNC_CREATED,
                ),
                $data instanceof RtSyncUpdatedSignalData => $agent->onSignalRtSyncUpdated(
                    $data,
                    $source,
                    SignalConstants::RT_SYNC_UPDATED,
                ),
                $data instanceof RtSyncDeletedSignalData => $agent->onSignalRtSyncDeleted(
                    $data,
                    $source,
                    SignalConstants::RT_SYNC_DELETED,
                ),
                default => Logger::error('dispatchRtSyncToAgents - unexpected payload: ' . get_class($data)),
            };
        }

        $this->setCurrentAgentId(null);
    }

    /**
     * Applies and forwards a state-scoped RT sync received from the daemon.
     *
     * The step order is part of the contract: the self-echo is dropped before the
     * applicator runs, the browser invalidation is recorded after it, and agents
     * are notified last.
     *
     * @param WorkerRtSyncMessageInterface $data Worker-level RT sync message (create, update or delete)
     * @throws HilosException Whatever a subscriber to the collection's announcement raises
     */
    private function handleRtSyncMessage(WorkerRtSyncMessageInterface $data): void
    {
        if ($this->consumeIncomingRtSyncSelfBroadcast($data->signalData)) {
            return;
        }
        match (true) {
            $data->signalData instanceof RtSyncCreatedSignalData => RtSyncApplicator::applyCreated(
                $data->signalData,
                skipSelfBroadcastCheck: false,
            ),
            $data->signalData instanceof RtSyncUpdatedSignalData => RtSyncApplicator::applyUpdated(
                $data->signalData,
                skipSelfBroadcastCheck: false,
            ),
            $data->signalData instanceof RtSyncDeletedSignalData => RtSyncApplicator::applyDeleted(
                $data->signalData,
                skipSelfBroadcastCheck: false,
            ),
            default => Logger::error(
                'handleRtSyncMessage - unexpected payload: ' . get_class($data->signalData),
            ),
        };
        $this->recordBrowserSourceChange($data->signalData);
        $this->dispatchRtSyncToAgents($data->signalData);
    }

    /**
     * Consume a DB sync self-broadcast marker before applying a daemon echo.
     *
     * The originating worker already recorded the local DB change for browser
     * state when it sent the sync to the daemon. The echoed worker message
     * must therefore neither re-apply nor re-emit the same fact.
     *
     * The emptiness check stays until HIL-532 rules on whether an incomplete
     * signal payload must be rejected by fromArray(): this side reads a payload
     * off the pipe, so an empty key here is broken input, not a sentinel this
     * code minted.
     *
     * @param DbSyncSignalDataInterface $signalData DB sync payload
     * @return bool True when this worker should ignore the daemon echo
     */
    private function consumeIncomingDbSyncSelfBroadcast(DbSyncSignalDataInterface $signalData): bool
    {
        if ($signalData->collectionKey === '' || $signalData->idString === '') {
            return false;
        }

        return Hilos::$sr?->shouldSkipDbSyncApply($signalData->collectionKey, $signalData->idString) ?? false;
    }

    /**
     * Recognize this worker's own DB clear before applying a daemon echo.
     *
     * The originating worker already cleared its in-memory rows and recorded the
     * browser fact when it queued the clear. Re-recording it on the echo would
     * wipe rows created in the same truncate (for example a follow-up marker
     * event), so the originating worker must ignore its own clear echo. Unlike the
     * row-sync sibling this consumes nothing: the payload carries the emitter
     * identity and the check is a comparison with this process's own.
     *
     * The emptiness check stays until HIL-532 rules on whether an incomplete
     * signal payload must be rejected by fromArray(): this side reads a payload
     * off the pipe, so an empty key here is broken input, not a sentinel this
     * code minted.
     *
     * @param DbSyncClearedSignalData $signalData DB clear sync payload
     * @return bool True when this worker should ignore the daemon echo
     */
    private function isOwnDbSyncClearEcho(DbSyncClearedSignalData $signalData): bool
    {
        if ($signalData->collectionKey === '') {
            return false;
        }

        return Hilos::$sr?->shouldSkipDbSyncClearApply($signalData->emitter) ?? false;
    }

    /**
     * Consume an RT sync self-broadcast marker before applying a daemon echo.
     *
     * The emptiness check stays until HIL-532 rules on whether an incomplete
     * signal payload must be rejected by fromArray(): this side reads a payload
     * off the pipe, so an empty key here is broken input, not a sentinel this
     * code minted.
     *
     * @param RtSyncSignalDataInterface $signalData RT sync payload
     * @return bool True when this worker should ignore the daemon echo
     */
    private function consumeIncomingRtSyncSelfBroadcast(RtSyncSignalDataInterface $signalData): bool
    {
        if ($signalData->collectionKey === '' || $signalData->stateId === '') {
            return false;
        }

        return Hilos::$sr?->shouldSkipRtSyncApply($signalData->collectionKey, $signalData->stateId) ?? false;
    }

    /**
     * Records a DB/RT sync fact in worker-local browser source buffers.
     *
     * Local writes are recorded when the worker first drains its queued sync
     * signal. Remote writes are recorded after the daemon sync message is
     * accepted. Incoming self-broadcast echoes are consumed before this method,
     * so one backend fact becomes one browser invalidation per worker.
     *
     * @param SyncSignalDataInterface $signalData Sync payload
     */
    private function recordBrowserSourceChange(SyncSignalDataInterface $signalData): void
    {
        if (Hilos::$browser === null) {
            return;
        }

        $change = match (true) {
            $signalData instanceof DbSyncCreatedSignalData => SourceChange::dbCreated(
                $signalData->collectionKey,
                $signalData->idString,
                $signalData->row,
                $signalData->origin,
            ),
            $signalData instanceof DbSyncUpdatedSignalData => SourceChange::dbUpdated(
                $signalData->collectionKey,
                $signalData->idString,
                $signalData->row,
                $signalData->origin,
            ),
            $signalData instanceof DbSyncDeletedSignalData => SourceChange::dbDeleted(
                $signalData->collectionKey,
                $signalData->idString,
                $signalData->row,
                $signalData->origin,
            ),
            $signalData instanceof DbSyncClearedSignalData => SourceChange::dbCleared(
                $signalData->collectionKey,
                $signalData->origin,
            ),
            $signalData instanceof RtSyncCreatedSignalData => SourceChange::rtCreated(
                $signalData->collectionKey,
                $signalData->stateId,
                $signalData->row,
                $signalData->origin,
            ),
            $signalData instanceof RtSyncUpdatedSignalData => SourceChange::rtUpdated(
                $signalData->collectionKey,
                $signalData->stateId,
                $signalData->row,
                $signalData->origin,
            ),
            $signalData instanceof RtSyncDeletedSignalData => SourceChange::rtDeleted(
                $signalData->collectionKey,
                $signalData->stateId,
                $signalData->row,
                $signalData->origin,
            ),
            default => null,
        };

        if ($change !== null) {
            Hilos::$browser?->record($change);
        }
    }

    /**
     * Dispatches a locally-originated DB/RT sync fact to agents in this worker.
     *
     * The daemon echo is intentionally consumed as a self-broadcast, so local
     * agents must see the sync when the worker first drains the queued signal.
     *
     * @param SyncSignalDataInterface $signalData Sync payload
     */
    private function dispatchSyncToLocalAgents(SyncSignalDataInterface $signalData): void
    {
        match (true) {
            $signalData instanceof DbSyncSignalDataInterface => $this->dispatchDbSyncToAgents($signalData),
            $signalData instanceof RtSyncSignalDataInterface => $this->dispatchRtSyncToAgents($signalData),
            // A collection truncate is browser-only fan-out: agents that care observe
            // the per-row delete/create events, so this no-op is the contract.
            $signalData instanceof DbSyncClearedSignalData => null,
            default => Logger::error('dispatchSyncToLocalAgents - unexpected payload: ' . get_class($signalData)),
        };
    }

    /**
     * Routes one daemon-delivered agent signal to agent and page handlers.
     *
     * @param DaemonAgentMessageDTO $data Daemon-to-worker agent signal
     * @throws PageSignalRouterNotFoundException When page routing is requested for an unsupported agent
     * @throws TableRowKeyMissingException When a windowed table row is a placeholder and carries no key
     * @throws InvalidArgumentException When a command handler cannot name its reply
     */
    private function handleAgentMessage(DaemonAgentMessageDTO $data): void
    {
        $agentId = $data->agentId;

        if (!$this->agentManager->hasAgent($agentId)) {
            Logger::error("handleAgentMessage - agent not found: {$agentId}");
            return;
        }

        $agent = $this->agentManager->getAgent($agentId);
        if ($agent === null) {
            Logger::error("handleAgentMessage - agent is null: {$agentId}");
            return;
        }

        $signalType = $data->signal->signalType->getType();
        $source = $data->signal->signalSource->getSource();
        $name = $data->signal->signalName->getName();
        $signalData = $data->signal->data;

        $apiRequestId = Hilos::$ac?->getSignalMetaInt($data->signal, AnalyticsCollector::META_API_REQUEST_ID);
        $userActionId = Hilos::$ac?->getSignalMetaInt($data->signal, AnalyticsCollector::META_USER_ACTION_ID);

        // Route to appropriate handler in agent based on signal type
        switch ($signalType) {
            case SignalTypeConstants::SYSTEM:
                if ($signalData instanceof SystemSignalDTO) {
                    Hilos::$ac?->logAgentSystemSignal($agent->getType(), $agent->getIndex(), $name, $signalData->toArray());
                    if ($apiRequestId !== null) {
                        Hilos::$ac?->logApiAgentAction($apiRequestId, $agent->getType(), $agent->getIndex(), $name, $signalData->toArray());
                    }
                    $agent->onSignalSystem($signalData, $source, $name);
                } else {
                    Logger::error("onSignalSystem - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::CRON:
                if ($signalData instanceof CronSignalDTO) {
                    Hilos::$ac?->logAgentCronSignal($agent->getType(), $agent->getIndex(), $name, $signalData->toArray());
                    if ($apiRequestId !== null) {
                        Hilos::$ac?->logApiAgentAction($apiRequestId, $agent->getType(), $agent->getIndex(), $name, $signalData->toArray());
                    }
                    $this->onCronHandled($name, $signalData);
                    $agent->onSignalCron($signalData, $source, $name);
                    $this->getPageSignalRouter($agentId, $agent)->dispatchCron($signalData, $source, $name);
                } else {
                    Logger::error("onSignalCron - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::HANDSHAKE:
                if ($signalData instanceof WebSocketHandshakeSignalDTO) {
                    // The master opened the connection row without an owner because reading a
                    // browser session on the accept loop is forbidden; the join happens here,
                    // and before the hook, so a user identified in it lands on a live session.
                    Hilos::$ac?->attachWsConnectionToBrowserSession(
                        $signalData->acceptKey,
                        $signalData->sessionToken,
                        HttpHeaderHelper::get($signalData->headers, HttpConstants::HEADER_USER_AGENT),
                        HttpHeaderHelper::get($signalData->headers, HttpConstants::HEADER_ACCEPT_LANGUAGE),
                    );
                    try {
                        $agent->onSignalHandshake($signalData, $source, $name);
                    } catch (ValidationException $e) {
                        Logger::info("Handshake rejected: acceptKey={$signalData->acceptKey}, reason={$e->getMessage()}");
                    }
                } else {
                    Logger::error("onSignalHandshake - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::CONNECTION_CLOSE:
                if ($signalData instanceof WebSocketCloseSignalDTO) {
                    $this->dispatchPageUnsubscribeIfTrackedOnConnectionClose($agentId, $agent, $signalData, $source);
                    if ($signalData->acceptKey !== '') {
                        Hilos::$sr?->unsubscribeFromAll($signalData->acceptKey);
                        // Anything this connection was waiting to have judged dies with it:
                        // held frames would otherwise sit out their deadline and then be
                        // dispatched at a socket nobody is listening on.
                        ($this->pageSignalRouters[$agentId] ?? null)?->dropPendingFrames($signalData->acceptKey);
                    }
                    $agent->onSignalConnectionClose($signalData, $source, $name);
                } else {
                    Logger::error("onSignalConnectionClose - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::ACTION:
                if ($signalData instanceof WebSocketActionSignalDTO) {
                    Hilos::$ac?->logAgentUserAction($agent->getType(), $agent->getIndex(), $userActionId, $name, $signalData->toArray());
                    if ($apiRequestId !== null) {
                        Hilos::$ac?->logApiAgentAction($apiRequestId, $agent->getType(), $agent->getIndex(), $name, $signalData->toArray());
                    }
                    $this->onActionHandled($name, $signalData);
                    $agent->onSignalAction($signalData, $source, $name);
                    // Both owners of an action go through the one dispatcher, page-owned and
                    // agent-owned alike: it is where the identity wait, the throttle park, the
                    // auth guard and the tracked reply live, and an agent that was called
                    // straight from here reached none of them (HIL-622).
                    $this->getPageSignalRouter($agentId, $agent)->dispatchAction($signalData, $source);
                } else {
                    Logger::error("onSignalAction - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::FRAME_BINARY:
                if ($signalData instanceof WebSocketFrameBinarySignalDTO) {
                    $this->onFrameBinaryHandled($signalData, $source, $name);
                    $agent->onSignalFrameBinary($signalData, $source, $name);
                    $this->getPageSignalRouter($agentId, $agent)->dispatchFrameBinary($signalData, $source, $name);
                } else {
                    Logger::error("onSignalFrameBinary - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::PAGE_SUBSCRIBE:
                if ($signalData instanceof WebSocketPageSubscribeSignalDTO) {
                    $this->dispatchPreviousPageUnsubscribeIfReplaced($agentId, $agent, $signalData, $name, $source);
                    $agent->onSignalPageSubscribe($signalData, $source, $name);
                    $this->getPageSignalRouter($agentId, $agent)->dispatchPageSubscribe($signalData, $source, $name);
                    $this->rememberPageSubscriptionAfterSubscribe($signalData, $name);
                } else {
                    Logger::error("onSignalPageSubscribe - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::PAGE_ACCESS_REASSESS:
                if ($signalData instanceof WebSocketPageSubscribeSignalDTO) {
                    // Only the frame, and none of the subscribe's bookkeeping: the previous
                    // page is not being replaced, the agent is not being told of a new
                    // subscriber, and the mirror already holds this exact subscription. A
                    // re-decision changes the answer, not the subscription (HIL-621).
                    $this->getPageSignalRouter($agentId, $agent)->dispatchPageAccessReassess($signalData, $source, $name);
                } else {
                    Logger::error("onSignalPageAccessReassess - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION:
                if ($signalData instanceof WebSocketPageUpdateSubscriptionSignalDTO) {
                    $agent->onSignalPageUpdateSubscription($signalData, $source, $name);
                    // Only an accepted update settles into the mirrors: a set the guards
                    // refused would otherwise judge the next fan-out for this connection.
                    if ($this->getPageSignalRouter($agentId, $agent)->dispatchPageUpdateSubscription($signalData, $source, $name)) {
                        $this->mergePageSubscriptionParamsOnUpdate($signalData);
                    }
                } else {
                    Logger::error("onSignalPageUpdateSubscription - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::PAGE_UNSUBSCRIBE:
                if ($signalData instanceof WebSocketPageUnsubscribeSignalDTO) {
                    $agent->onSignalPageUnsubscribe($signalData, $source, $name);
                    $this->getPageSignalRouter($agentId, $agent)->dispatchPageUnsubscribe($signalData, $source, $name);
                    if ($signalData->acceptKey !== '') {
                        $page = $this->pageSubscriptionByAcceptKey[$signalData->acceptKey]['page'] ?? $name;
                        Hilos::$sr?->unsubscribeFromPage($page, $signalData);
                        unset($this->pageSubscriptionByAcceptKey[$signalData->acceptKey]);
                    }
                } else {
                    Logger::error("onSignalPageUnsubscribe - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::TABLE_VIEWPORT:
                if ($signalData instanceof WebSocketTableViewportSignalDTO) {
                    $this->getPageSignalRouter($agentId, $agent)->dispatchTableViewport($signalData, $source, $name);
                } else {
                    Logger::error("dispatchTableViewport - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::COMMAND_REQUEST:
                if ($signalData instanceof CommandRequestDTO) {
                    try {
                        $parsedCommandData = Hilos::$sr?->createCommandPayloadDTO($name, $signalData) ?? $signalData;
                        $agent->onSignalCommand($parsedCommandData, $source, $name);
                    } catch (InvalidCommandPayloadException $e) {
                        Logger::logAgentError($agent->getId(), "Command payload validation failed: {$e->getMessage()}");
                        $agent->replyToCommand(CommandReplyDTO::error($signalData->correlationId, $e->getMessage()));
                    } catch (AgentException $e) {
                        Logger::logAgentError($agent->getId(), "Command handler failed: {$e->getMessage()}");
                    }
                } else {
                    Logger::error("onSignalCommand - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::GROUP_SUBSCRIBE:
                if ($signalData instanceof WebSocketGroupSubscribeSignalDTO) {
                    $agent->onSignalGroupSubscribe($signalData, $source, $name);
                    $group = $signalData->group ?? $name;
                    Hilos::$sr?->subscribeToGroup($group, $signalData);
                } else {
                    Logger::error("onSignalGroupSubscribe - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::GROUP_UNSUBSCRIBE:
                if ($signalData instanceof WebSocketGroupUnsubscribeSignalDTO) {
                    $agent->onSignalGroupUnsubscribe($signalData, $source, $name);
                    $group = $signalData->group ?? $name;
                    Hilos::$sr?->unsubscribeFromGroup($group, $signalData);
                } else {
                    Logger::error("onSignalGroupUnsubscribe - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION:
                if ($signalData instanceof WebSocketGroupUpdateSubscriptionSignalDTO) {
                    $agent->onSignalGroupUpdateSubscription($signalData, $source, $name);
                    $group = $signalData->group ?? $name;
                    try {
                        Hilos::$sr?->updateGroupSubscription($group, $signalData);
                    } catch (Throwable $e) {
                        Logger::error('WorkerManager: cannot mirror group subscription update:'
                            . " acceptKey={$signalData->acceptKey} group={$group}, {$e->getMessage()}");
                    }
                } else {
                    Logger::error("onSignalGroupUpdateSubscription - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::AGENT_SIGNAL:
                if ($signalData instanceof AgentSignalData) {
                    if ($apiRequestId !== null) {
                        Hilos::$ac?->logApiAgentAction($apiRequestId, $agent->getType(), $agent->getIndex(), $name, $signalData->toArray());
                    }
                    $this->onAgentSignalHandled($name, $signalData);
                    $parsedAgentSignalData = $signalData;
                    try {
                        $parsedAgentSignalData = Hilos::$sr?->createAgentSignalPayloadDTO($name, $signalData) ?? $signalData;
                        $agent->onSignalAgent($parsedAgentSignalData, $source, $name);
                    } catch (InvalidAgentSignalPayloadException $e) {
                        Logger::logAgentError($agent->getId(), "Agent signal payload validation failed: {$e->getMessage()}");
                    } catch (AgentException $e) {
                        Logger::logAgentError($agent->getId(), "Agent signal handler failed: {$e->getMessage()}");
                    }
                    try {
                        $this->getPageSignalRouter($agentId, $agent)->dispatchAgentSignal($parsedAgentSignalData, $source, $name);
                    } catch (ValidationException $e) {
                        Logger::logAgentError($agent->getId(), "Page signal validation failed: {$e->getMessage()}");
                    } catch (AgentException $e) {
                        Logger::logAgentError($agent->getId(), "Page signal handler failed: {$e->getMessage()}");
                    }
                } else {
                    Logger::error("onSignalAgent - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::DB_SYNC_CREATED:
                if ($signalData instanceof DbSyncCreatedSignalData) {
                    $agent->onSignalDbSyncCreated($signalData, $source, $name);
                } else {
                    Logger::error("onSignalDbSyncCreated - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::DB_SYNC_UPDATED:
                if ($signalData instanceof DbSyncUpdatedSignalData) {
                    $agent->onSignalDbSyncUpdated($signalData, $source, $name);
                } else {
                    Logger::error("onSignalDbSyncUpdated - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::DB_SYNC_DELETED:
                if ($signalData instanceof DbSyncDeletedSignalData) {
                    $agent->onSignalDbSyncDeleted($signalData, $source, $name);
                } else {
                    Logger::error("onSignalDbSyncDeleted - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::RT_SYNC_CREATED:
                if ($signalData instanceof RtSyncCreatedSignalData) {
                    $agent->onSignalRtSyncCreated($signalData, $source, $name);
                } else {
                    Logger::error("onSignalRtSyncCreated - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::RT_SYNC_UPDATED:
                if ($signalData instanceof RtSyncUpdatedSignalData) {
                    $agent->onSignalRtSyncUpdated($signalData, $source, $name);
                } else {
                    Logger::error("onSignalRtSyncUpdated - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::RT_SYNC_DELETED:
                if ($signalData instanceof RtSyncDeletedSignalData) {
                    $agent->onSignalRtSyncDeleted($signalData, $source, $name);
                } else {
                    Logger::error("onSignalRtSyncDeleted - invalid signal data type: " . get_class($signalData));
                }
                break;

            default:
                // Unknown signal type - ignore
                break;
        }
    }

    /**
     * Hook called before a WebSocket action reaches agent and page handlers.
     *
     * @param string $action Action name
     * @param WebSocketActionSignalDTO $signalData Action signal (acceptKey, payload)
     */
    protected function onActionHandled(string $action, WebSocketActionSignalDTO $signalData): void
    {
    }

    /**
     * Hook called before a cron signal reaches agent and page handlers.
     *
     * @param string $cron Cron job name
     * @param CronSignalDTO $signalData Cron signal payload
     */
    protected function onCronHandled(string $cron, CronSignalDTO $signalData): void
    {
    }

    /**
     * Hook called before a binary frame signal reaches agent and page handlers.
     *
     * @param WebSocketFrameBinarySignalDTO $signalData Binary frame payload
     * @param string $source Signal source identifier
     * @param string $name Signal name
     */
    protected function onFrameBinaryHandled(WebSocketFrameBinarySignalDTO $signalData, string $source, string $name): void
    {
    }

    /**
     * Hook called before an agent-to-agent signal reaches agent and page handlers.
     *
     * @param string $name Signal name
     * @param AgentSignalData $signalData Wrapped agent signal payload
     */
    protected function onAgentSignalHandled(string $name, AgentSignalData $signalData): void
    {
    }

    /**
     * Creates the page router for an agent that supports page routing.
     *
     * @param AgentInterface $agent Agent to create router for
     * @return PageSignalRouter Page router instance
     * @throws PageSignalRouterNotFoundException When the agent does not support page routing
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        throw new PageSignalRouterNotFoundException($agent::class);
    }

    /**
     * Builds a deterministic signature for comparing route parameter sets.
     *
     * Two arrays with the same keys and values compare equal after sorting.
     *
     * @param array<string, string> $params Route parameters from a page subscribe DTO
     * @return string Serialized representation after sorting keys
     */
    private function pageParamsSignature(array $params): string
    {
        ksort($params);

        return serialize($params);
    }

    /**
     * Dispatches a synthetic unsubscribe before a replacement page subscribe.
     *
     * This matches SPA navigation where the client sends the next page subscribe
     * without an explicit unsubscribe for the previous page. No-op when there is
     * no prior subscription or when page id and params are unchanged.
     *
     * @param string $agentId Agent instance id in this worker process
     * @param AgentInterface $agent Agent receiving the signal
     * @param WebSocketPageSubscribeSignalDTO $dto Incoming subscribe payload
     * @param string $name Signal name used as page id when the DTO page is empty
     * @param string $source Signal source identifier
     */
    private function dispatchPreviousPageUnsubscribeIfReplaced(
        string $agentId,
        AgentInterface $agent,
        WebSocketPageSubscribeSignalDTO $dto,
        string $name,
        string $source,
    ): void {
        $acceptKey = $dto->acceptKey;
        if ($acceptKey === '') {
            return;
        }

        $newPage = $dto->page ?? $name;
        $newParams = $dto->params;
        if (!isset($this->pageSubscriptionByAcceptKey[$acceptKey])) {
            return;
        }

        $prev = $this->pageSubscriptionByAcceptKey[$acceptKey];
        $samePage = $prev['page'] === $newPage;
        $sameParams = $this->pageParamsSignature($prev['params']) === $this->pageParamsSignature($newParams);
        if ($samePage && $sameParams) {
            return;
        }

        try {
            $router = $this->getPageSignalRouter($agentId, $agent);
            $unsubDto = new WebSocketPageUnsubscribeSignalDTO(acceptKey: $acceptKey);
            $router->dispatchPageUnsubscribe($unsubDto, $source, $prev['page']);
            Hilos::$sr?->unsubscribeFromPage($prev['page'], $unsubDto);
        } catch (PageSignalRouterNotFoundException $e) {
            Logger::error(
                'WorkerManager: cannot dispatch synthetic page_unsubscribe before new subscribe (no page router): '
                . "agentId={$agentId} acceptKey={$acceptKey} previousPage={$prev['page']}, {$e->getMessage()}",
            );
        }
    }

    /**
     * Stores the current page id and params after page subscribe dispatch.
     *
     * Used by {@see self::dispatchPreviousPageUnsubscribeIfReplaced()} on subsequent subscribe signals.
     *
     * @param WebSocketPageSubscribeSignalDTO $dto Subscribe payload
     * @param string $name Signal name used as page id when the DTO page is empty
     */
    private function rememberPageSubscriptionAfterSubscribe(WebSocketPageSubscribeSignalDTO $dto, string $name): void
    {
        $acceptKey = $dto->acceptKey;
        if ($acceptKey === '') {
            return;
        }

        $page = $dto->page ?? $name;
        $this->pageSubscriptionByAcceptKey[$acceptKey] = [
            'page' => $page,
            'params' => $dto->params,
        ];
        Hilos::$sr?->subscribeToPage($page, $dto);
    }

    /**
     * Merges incoming params into the tracked subscription after update dispatch.
     *
     * Aligns worker-side tracking with daemon {@see SignalRouter::updatePageSubscription()} merge semantics.
     * No-op when this accept key has no tracked subscription.
     *
     * @param WebSocketPageUpdateSubscriptionSignalDTO $dto Update payload (acceptKey, page, params to merge)
     */
    private function mergePageSubscriptionParamsOnUpdate(WebSocketPageUpdateSubscriptionSignalDTO $dto): void
    {
        $acceptKey = $dto->acceptKey;
        if ($acceptKey === '' || !isset($this->pageSubscriptionByAcceptKey[$acceptKey])) {
            return;
        }

        $this->pageSubscriptionByAcceptKey[$acceptKey]['params'] = array_merge(
            $this->pageSubscriptionByAcceptKey[$acceptKey]['params'],
            $dto->params,
        );
        $page = $dto->page ?? $this->pageSubscriptionByAcceptKey[$acceptKey]['page'];
        try {
            Hilos::$sr?->updatePageSubscription($page, $dto);
        } catch (Throwable $e) {
            Logger::error("WorkerManager: cannot mirror page subscription update: acceptKey={$acceptKey} page={$page}, {$e->getMessage()}");
        }
    }

    /**
     * Runs page unsubscribe for the last tracked page on WebSocket disconnect.
     *
     * Invoked before the agent connection-close hook so page cleanup runs even
     * when the client closes the tab without sending a page unsubscribe signal.
     * Clears the worker-local subscription mirror for this accept key after
     * dispatch.
     *
     * @param string $agentId Agent instance id in this worker process
     * @param AgentInterface $agent Agent receiving the signal
     * @param WebSocketCloseSignalDTO $dto Close payload (acceptKey)
     * @param string $source Signal source identifier
     */
    private function dispatchPageUnsubscribeIfTrackedOnConnectionClose(
        string $agentId,
        AgentInterface $agent,
        WebSocketCloseSignalDTO $dto,
        string $source,
    ): void {
        $acceptKey = $dto->acceptKey;
        if ($acceptKey === '' || !isset($this->pageSubscriptionByAcceptKey[$acceptKey])) {
            return;
        }

        $prevPage = $this->pageSubscriptionByAcceptKey[$acceptKey]['page'];
        try {
            $router = $this->getPageSignalRouter($agentId, $agent);
            $unsubDto = new WebSocketPageUnsubscribeSignalDTO(acceptKey: $acceptKey);
            $router->dispatchPageUnsubscribe($unsubDto, $source, $prevPage);
        } catch (PageSignalRouterNotFoundException $e) {
            Logger::error(
                'WorkerManager: cannot dispatch page_unsubscribe on connection close (no page router): '
                . "agentId={$agentId} acceptKey={$acceptKey} page={$prevPage}, {$e->getMessage()}",
            );
        }

        unset($this->pageSubscriptionByAcceptKey[$acceptKey]);
        Hilos::$sr?->unsubscribeFromAll($acceptKey);
    }

    /**
     * Returns the cached page router for an agent, creating it when needed.
     *
     * @param string $agentId Agent id
     * @param AgentInterface $agent Agent instance
     * @return PageSignalRouter Page router instance
     * @throws PageSignalRouterNotFoundException When the agent does not support page routing
     */
    private function getPageSignalRouter(string $agentId, AgentInterface $agent): PageSignalRouter
    {
        if (array_key_exists($agentId, $this->pageSignalRouters)) {
            return $this->pageSignalRouters[$agentId];
        }

        $this->pageSignalRouters[$agentId] = $this->createPageSignalRouter($agent);
        return $this->pageSignalRouters[$agentId];
    }

    /**
     * Sweeps everything one agent's page router is holding until something else happens.
     *
     * Two pools, one visit: actions that never got their throttle verdict, and frames
     * waiting to learn who is behind their connection. Both are emptied on the tick because
     * both wait on an answer from another process, and this worker must keep serving every
     * other connection while they wait.
     *
     * Only a router that already exists is asked. A router is built the first time its agent
     * routes something, so building one here - on every tick, for every agent - would stand
     * up a page factory for agents that route nothing at all, to sweep pools that cannot
     * have anything in them.
     *
     * @param string $agentId Agent whose page router to sweep
     * @throws FramePopOrderException When a resumed frame leaves the execution stack imbalanced
     */
    private function releaseDeferredWork(string $agentId): void
    {
        $router = $this->pageSignalRouters[$agentId] ?? null;
        $router?->releaseExpiredDeferredActions();
        $router?->releasePendingFrames();
    }

    /**
     * Notifies the daemon that a worker-local agent has started.
     *
     * @param string $agentId Agent id
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for singleton agents
     */
    private function notifyAgentStarted(string $agentId, string $agentType, ?string $agentIndex): void
    {
        if ($this->daemonClient === null || !$this->daemonClient->isConnected()) {
            return;
        }

        $message = [
            WorkerDTO::TYPE => WorkerConstants::MESSAGE_AGENT_STARTED,
            AgentConstants::FIELD_AGENT_ID => $agentId,
            AgentConstants::FIELD_AGENT_TYPE => $agentType,
        ];

        if ($agentIndex !== null) {
            $message[AgentConstants::FIELD_AGENT_INDEX] = $agentIndex;
        }

        $this->daemonClient->send($message);
    }

    /**
     * Reports to the daemon which RT collections an agent of this worker owns.
     *
     * The truth-source registry is worker-local, and the master is where cross-node replication
     * is decided, so what the agent registered in its own process has to be told rather than
     * read. An agent that registered nothing is not reported at all: the map answers "does this
     * node own the collection", and an empty answer is the same as no entry.
     *
     * @param string $agentId Agent whose registrations are being reported
     */
    private function notifyRtSourcesRegistered(string $agentId): void
    {
        if ($this->daemonClient === null || !$this->daemonClient->isConnected()) {
            return;
        }

        $collectionKeys = RtTruthSourceRegistry::collectionsOf($agentId);
        if ($collectionKeys === []) {
            return;
        }

        $this->daemonClient->send(new WorkerRtSourceRegisteredDTO(
            $agentId,
            $collectionKeys,
            RtTruthSourceRegistry::partialCollectionsOf($agentId),
        ));
    }

    /**
     * Reports to the daemon that a stopped agent of this worker owns nothing any more.
     *
     * Sent for every stopped agent, including one that never registered anything: the worker
     * would otherwise have to remember what it once reported, and the master drops an agent it
     * does not know about anyway.
     *
     * @param string $agentId Agent that stopped
     */
    private function notifyRtSourcesReleased(string $agentId): void
    {
        if ($this->daemonClient === null || !$this->daemonClient->isConnected()) {
            return;
        }

        $this->daemonClient->send(new WorkerRtSourceReleasedDTO($agentId));
    }

    /**
     * Notifies the daemon that a worker-local agent has stopped.
     *
     * @param string $agentId Agent id
     */
    private function notifyAgentStopped(string $agentId): void
    {
        if ($this->daemonClient === null || !$this->daemonClient->isConnected()) {
            return;
        }

        $message = [
            WorkerDTO::TYPE => WorkerConstants::MESSAGE_AGENT_STOPPED,
            AgentConstants::FIELD_AGENT_ID => $agentId,
        ];

        $this->daemonClient->send($message);
    }

    /**
     * Stops local agents, closes daemon transport, and shuts down analytics.
     *
     * A failing agent stop hook is contained per agent, so the remaining agents,
     * the daemon transport, and analytics are always released.
     */
    protected function cleanup(): void
    {
        // Stop all agents
        foreach ($this->agentManager->getAgents() as $agentId => $agent) {
            try {
                $this->runAgentStopHook($agent);
            } catch (Throwable $e) {
                // A failing stop hook must not abort the cleanup of the remaining agents,
                // the daemon transport, and analytics.
                Logger::logAgentError($agent->getId(), "Stop hook failed during worker cleanup: {$e->getMessage()}");
            }
            Hilos::$ac?->closeAgentSession($agent->getType(), $agent->getIndex());
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

        Hilos::$ac?->closeWorkerSession();
        Hilos::$ac?->shutdown();
    }

    /**
     * Run the agent stop hook while preserving truth-source write rights during cleanup.
     *
     * The agent may still write to owned DB/RT collections inside {@see AgentInterface::onStop()}.
     * Truth-source registrations are removed immediately after the hook, even when it fails.
     *
     * @param AgentInterface $agent Agent being stopped
     * @throws Throwable When the agent stop hook fails
     */
    private function runAgentStopHook(AgentInterface $agent): void
    {
        $this->setCurrentAgentId($agent->getId());
        try {
            $agent->onStop();
        } finally {
            TruthSourceRegistry::unregisterAgent($agent->getId());
            RtTruthSourceRegistry::unregisterAgent($agent->getId());
            $this->notifyRtSourcesReleased($agent->getId());
        }
    }

    /**
     * Worker-level periodic hook called from the main loop.
     *
     * Child classes can override this for lightweight periodic tasks. The hook
     * runs only while the worker is connected to the daemon.
     */
    protected function onTick(): void
    {
    }

    /**
     * Worker-level hook called after the tick contained a failure of one of its units.
     *
     * The project's place to answer a failure the worker swallowed - raise a counter,
     * tell an operator, stop offering a feature that keeps breaking. Empty by default,
     * because the framework's own answer is the journal line, and that line is written
     * before this hook runs and whatever this hook decides.
     *
     * Must not throw. A failure raised here is caught and written as a failure of the
     * hook itself, so it cannot take down the tick - but it costs the project the very
     * reaction it came here for.
     *
     * Named apart from {@see BaseManager::onException()} on purpose: that one answers a
     * failure PHP could not place at all, and in a worker it asks the process to leave.
     * This one is the opposite report - the failure was caught and life goes on.
     *
     * @param ContainedFailure $failure Failure the tick contained, the unit it belongs
     *     to and where in that unit it happened
     */
    protected function onTickFailure(ContainedFailure $failure): void
    {
    }

    /**
     * Worker-level hook called when the master addressed a signal to every worker of this node.
     *
     * The receiving half of {@see MasterSignalSender::sendToWorkers()}: project code on the
     * master loop is not allowed to do the work it discovers, so it says what happened here,
     * where the database and the project's own state are at hand. Empty by default - the
     * framework sends nothing through this door itself, and a project that overrides nothing
     * loses nothing.
     *
     * Off the master's loop is not off every loop: this runs on the worker's tick, so it is
     * bound by the same bar as {@see onTick()} and every other signal handler - a blocking
     * call here stalls this worker's agents and its WebSocket deliveries
     * (docs/agents/antipatterns/blocking-in-ontick.md). Work that cannot finish promptly goes
     * on a queue this worker drains an item per tick, or into a monopolistic agent.
     *
     * Every worker of the node gets the call, including the monopolistic ones, and the agents
     * living inside them do not: an agent is addressed by name through
     * {@see MasterSignalSender::sendToAgent()} and arrives at its own onSignalAgent().
     *
     * May throw. The call arrives inside the tick's guard, so a failure raised here is written
     * as a contained failure of the DAEMON_MESSAGE unit and reaches {@see onTickFailure()} -
     * the same treatment {@see onTick()} gets, and the reason there is no try/catch of its own
     * around this call.
     *
     * @param string $signalName Signal name the master addressed the workers under
     * @param SignalDataInterface $data Signal payload, rebuilt as the class the master sent
     *     when this process knows it and as SignalData when it does not
     */
    protected function onDaemonSignal(string $signalName, SignalDataInterface $data): void
    {
    }

    /**
     * Drains queued worker signals and flushes browser deliveries.
     *
     * Processes all queued signals from SignalRouter and forwards them to daemon.
     * Signals are processed one by one in while-do loop.
     * Called at the end of each loop iteration when connected to daemon.
     *
     * DB/RT sync signals are broadcast at worker level. Other signals are sent
     * as agent messages. Browser flushes run between two queue drains so
     * addressed WS_USER deliveries are sent in the same tick.
     */
    private function dispatchSignals(): void
    {
        if ($this->daemonClient === null || !$this->daemonClient->isConnected()) {
            return;
        }

        // Phase 1 sends backend state changes and records them for this worker's
        // browser state. Phase 2 sends the WS_USER signals produced by flushes
        // in the same tick, instead of waiting for the next loop.
        $this->dispatchQueuedSignalsToDaemon();
        $containedByFanout = Hilos::$browser?->flushToSignalRouter() ?? [];
        $this->dispatchQueuedSignalsToDaemon();

        // Written after both phases, not between them: a subscription that failed must
        // not delay what the subscriptions around it collected for this same tick.
        foreach ($containedByFanout as $contained) {
            $this->containFailure($contained->unit, $contained->address, $contained->failure);
        }
    }

    /**
     * Contains a failure that belongs to one unit of the tick.
     *
     * Writes it, hands it to the project, and returns: the unit is skipped and the units
     * around it keep ticking. The order is not an implementation detail. The line is
     * written first and always, so a project that overrides the hook adds a reaction to
     * the record rather than replacing it - an overridable record is how a guard becomes
     * the silent place it was built to prevent.
     *
     * The hook runs under a guard of its own, because it is the project's code and can
     * fail like any other; unguarded, its failure would take down the very tick this
     * guard exists to keep alive. It is written as a failure of the hook and not of the
     * unit, so the journal cannot be read as if the original failure happened twice.
     *
     * The unit is typed as the shared {@see FailureUnit} rather than as
     * {@see WorkerTickUnit}, because one caller does not name a unit at all: the browser
     * fan-out already contained its own failures and hands over the cards it built
     * ({@see BrowserContext::flushToSignalRouter()}). Every unit that reaches here is
     * still one of the worker's - the callers below name them literally - and widening
     * the parameter is what keeps that pass-through from restating a unit it was told.
     *
     * @param FailureUnit $unit Unit of work whose failure is being contained
     * @param string $address Which one of that unit failed, in the unit's own terms
     * @param Throwable $failure Failure the unit ended with
     */
    private function containFailure(FailureUnit $unit, string $address, Throwable $failure): void
    {
        $contained = new ContainedFailure($unit, $address, $failure);
        WorkerTickFailureLog::write($this->workerIndex, $contained, microtime(true));

        try {
            $this->onTickFailure($contained);
        } catch (Throwable $hookFailure) {
            WorkerTickFailureLog::write(
                $this->workerIndex,
                new ContainedFailure(WorkerTickUnit::FAILURE_HOOK, 'onTickFailure', $hookFailure),
                microtime(true)
            );
        }

        // Whatever agent this unit was running under is not running any more. Left set,
        // its id would sign the journal lines of every unit that follows in this tick.
        $this->setCurrentAgentId(null);
    }

    /**
     * Drains the current worker signal queue and forwards it to the daemon.
     *
     * Called before and after browser flush because flushes
     * queue ordinary worker signals into the same router queue.
     */
    private function dispatchQueuedSignalsToDaemon(): void
    {
        if ($this->daemonClient === null || !$this->daemonClient->isConnected()) {
            return;
        }

        // Process signals one by one in while-do loop
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signalType = $signal->signalType->getType();

            $syncSignalData = match ($signalType) {
                SignalTypeConstants::DB_SYNC_CREATED => self::syncSignalData($signal->data, DbSyncCreatedSignalData::class),
                SignalTypeConstants::DB_SYNC_UPDATED => self::syncSignalData($signal->data, DbSyncUpdatedSignalData::class),
                SignalTypeConstants::DB_SYNC_DELETED => self::syncSignalData($signal->data, DbSyncDeletedSignalData::class),
                SignalTypeConstants::DB_SYNC_CLEARED => self::syncSignalData($signal->data, DbSyncClearedSignalData::class),
                SignalTypeConstants::RT_SYNC_CREATED => self::syncSignalData($signal->data, RtSyncCreatedSignalData::class),
                SignalTypeConstants::RT_SYNC_UPDATED => self::syncSignalData($signal->data, RtSyncUpdatedSignalData::class),
                SignalTypeConstants::RT_SYNC_DELETED => self::syncSignalData($signal->data, RtSyncDeletedSignalData::class),
                default => null,
            };

            if ($syncSignalData !== null) {
                $syncDto = match ($signalType) {
                    SignalTypeConstants::DB_SYNC_CREATED => new WorkerDbSyncCreatedMessageDTO($syncSignalData),
                    SignalTypeConstants::DB_SYNC_UPDATED => new WorkerDbSyncUpdatedMessageDTO($syncSignalData),
                    SignalTypeConstants::DB_SYNC_DELETED => new WorkerDbSyncDeletedMessageDTO($syncSignalData),
                    SignalTypeConstants::DB_SYNC_CLEARED => new WorkerDbSyncClearedMessageDTO($syncSignalData),
                    SignalTypeConstants::RT_SYNC_CREATED => new WorkerRtSyncCreatedMessageDTO($syncSignalData),
                    SignalTypeConstants::RT_SYNC_UPDATED => new WorkerRtSyncUpdatedMessageDTO($syncSignalData),
                    SignalTypeConstants::RT_SYNC_DELETED => new WorkerRtSyncDeletedMessageDTO($syncSignalData),
                    default => null,
                };
                if ($syncDto !== null) {
                    $this->recordBrowserSourceChange($syncSignalData);
                    $this->dispatchSyncToLocalAgents($syncSignalData);
                    $this->daemonClient->send($syncDto);
                }
                continue;
            }

            // The re-hydrate announcement carries no collection to unwrap, so it takes its own
            // branch rather than a null-carrying entry in the map above. The emitting worker has
            // already re-read its own collections (HIL-479), so nothing is applied or fanned out
            // locally here - the frame is purely for the other processes, and its one field says
            // which agent is waiting to hear back from them (HIL-436).
            if ($signalType === SignalTypeConstants::DB_REHYDRATE) {
                if ($signal->data instanceof DbReHydrateSignalData) {
                    $this->daemonClient->send(new WorkerDbReHydrateMessageDTO($signal->data->agentId));
                } else {
                    Logger::error('dispatchQueuedSignalsToDaemon - re-hydrate carries invalid data: ' . get_class($signal->data));
                }
                continue;
            }

            // The access re-decision announcement is addressed to "every worker of this node",
            // which only the master can address - so it is a control frame and deliberately not
            // the WorkerAgentMessageDTO fallback below, which needs an agent to address (HIL-644).
            if ($signalType === SignalTypeConstants::PAGE_ACCESS_REASSESS_USER) {
                if ($signal->data instanceof PageAccessReassessUserSignalData) {
                    $this->daemonClient->send(new WorkerPageAccessReassessMessageDTO($signal->data->userId));
                } else {
                    Logger::error('dispatchQueuedSignalsToDaemon - access re-decision carries invalid data: ' . get_class($signal->data));
                }
                continue;
            }

            // The by-connection announcement takes the same road for the same reason (HIL-652);
            // it is a frame of its own rather than a criterion field, so that a worker holding
            // it never has to establish which of the two questions it was asked.
            if ($signalType === SignalTypeConstants::PAGE_ACCESS_REASSESS_CONNECTIONS) {
                if ($signal->data instanceof PageAccessReassessConnectionsSignalData) {
                    $this->daemonClient->send(
                        new WorkerPageAccessReassessConnectionsMessageDTO($signal->data->acceptKeys),
                    );
                } else {
                    Logger::error('dispatchQueuedSignalsToDaemon - by-connection re-decision carries invalid data: ' . get_class($signal->data));
                }
                continue;
            }

            // Protected-mode requests are worker->own-daemon control frames, not agent messages:
            // the initiator agent cannot emit the peer frame itself, so the daemon does it (slice 5d).
            if ($signalType === SignalTypeConstants::PROTECTED_MODE_ENABLE) {
                if ($signal->data instanceof ProtectedModeEnableSignalData) {
                    $this->daemonClient->send(new WorkerProtectedModeEnableDTO($signal->data));
                } else {
                    Logger::error('dispatchQueuedSignalsToDaemon - protected-mode enable carries invalid data: ' . get_class($signal->data));
                }
                continue;
            }
            if ($signalType === SignalTypeConstants::PROTECTED_MODE_DISABLE) {
                if ($signal->data instanceof ProtectedModeDisableSignalData) {
                    $this->daemonClient->send(new WorkerProtectedModeDisableDTO($signal->data));
                } else {
                    Logger::error('dispatchQueuedSignalsToDaemon - protected-mode disable carries invalid data: ' . get_class($signal->data));
                }
                continue;
            }
            if ($signalType === SignalTypeConstants::PROTECTED_MODE_VERIFY) {
                if ($signal->data instanceof ProtectedModeVerifySignalData) {
                    $this->daemonClient->send(new WorkerProtectedModeVerifyDTO($signal->data));
                } else {
                    Logger::error('dispatchQueuedSignalsToDaemon - protected-mode verify carries invalid data: ' . get_class($signal->data));
                }
                continue;
            }
            if ($signalType === SignalTypeConstants::PROTECTED_MODE_PROGRESS) {
                if ($signal->data instanceof ProtectedModeProgressSignalData) {
                    $this->daemonClient->send(new WorkerProtectedModeProgressDTO($signal->data));
                } else {
                    Logger::error('dispatchQueuedSignalsToDaemon - protected-mode progress carries invalid data: ' . get_class($signal->data));
                }
                continue;
            }
            if ($signalType === SignalTypeConstants::PROTECTED_MODE_PASS) {
                if ($signal->data instanceof ProtectedModePassSignalData) {
                    $this->daemonClient->send(new WorkerProtectedModePassDTO($signal->data));
                } else {
                    Logger::error('dispatchQueuedSignalsToDaemon - protected-mode pass carries invalid data: ' . get_class($signal->data));
                }
                continue;
            }
            if ($signalType === SignalTypeConstants::PROTECTED_MODE_REFREEZE) {
                if ($signal->data instanceof ProtectedModeRefreezeSignalData) {
                    $this->daemonClient->send(new WorkerProtectedModeRefreezeDTO($signal->data));
                } else {
                    Logger::error('dispatchQueuedSignalsToDaemon - protected-mode refreeze carries invalid data: ' . get_class($signal->data));
                }
                continue;
            }

            // Worker browser flush signals may be already-addressed WebSocket deliveries.
            // The daemon ignores agentId for WorkerAgentMessageDTO and routes the inner
            // SignalDTO by its signal type and WebSocket target metadata.
            $agentType = $signal->signalSource->getType();
            $agentIndex = $signal->signalSource->getIndex();
            // An unaddressed message is the normal case here, not a missing value:
            // BrowserContext flushes carry SignalSource::WORKER with no agent type,
            // so the message carries no agent id at all rather than an empty one.
            $agentId = $this->agentManager->buildAgentId($agentType, $agentIndex);

            $this->daemonClient->send(new WorkerAgentMessageDTO(
                agentId: $agentId,
                signal: $signal,
            ));
        }
    }

    /**
     * Creates the agent manager used by this worker.
     *
     * @return AgentManager Agent manager instance
     */
    abstract protected function createAgentManager(): AgentManager;

    /**
     * Returns this worker's manager name for shared logging.
     *
     * @return string Worker manager name
     */
    protected function getManagerName(): string
    {
        return "Worker #{$this->workerIndex}";
    }

    /**
     * Logs a worker error message.
     *
     * @param string $message Error message
     */
    protected function logError(string $message): void
    {
        Logger::errorLog($message);
    }

    /**
     * Logs a worker exception message.
     *
     * @param string $message Exception message
     */
    protected function logException(string $message): void
    {
        Logger::errorLog($message);
    }

    /**
     * Logs a worker shutdown message.
     *
     * @param string $message Shutdown message
     */
    protected function logShutdown(string $message): void
    {
        Logger::errorLog($message);
    }

    /**
     * Requests worker-loop exit after a PHP error.
     *
     * The main loop performs normal cleanup after the flag is set.
     */
    protected function onError(): void
    {
        $this->shouldExit = true;
    }

    /**
     * Requests worker-loop exit after an uncaught exception.
     *
     * The main loop performs normal cleanup after the flag is set.
     */
    protected function onException(): void
    {
        $this->shouldExit = true;
    }

    /**
     * Requests worker-loop exit after a fatal shutdown.
     *
     * The main loop performs normal cleanup after the flag is set.
     */
    protected function onShutdown(): void
    {
        $this->shouldExit = true;
    }

    /**
     * Handles a shutdown signal after the base manager sets the exit flag.
     *
     * The base worker does not need extra signal-specific work.
     */
    protected function onShutdownSignal(): void
    {
        // Worker-specific shutdown logic (none needed)
    }

    /**
     * Handles a restart signal after the base manager sets the exit flag.
     *
     * The base worker does not need extra signal-specific work.
     */
    protected function onRestartSignal(): void
    {
        // Worker-specific restart logic (none needed)
    }

    /**
     * @template T of SignalDataInterface
     *
     * @param class-string<T> $expectedClass
     * @return T
     */
    private static function syncSignalData(SignalDataInterface $data, string $expectedClass): SignalDataInterface
    {
        if ($data instanceof $expectedClass) {
            return $data;
        }

        return $expectedClass::fromArray($data->toArray());
    }
}
