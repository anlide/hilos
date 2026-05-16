<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Database\DbSyncApplicator;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Page\Exception\PageSignalRouterNotFoundException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Hilos;
use Hilos\Socket\SocketException;
use Hilos\Socket\WebSocket\DTO\WebSocketAcceptKeySignalDTO;
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
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\CronSignalDTO;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Environment\Exception\EnvException;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Utils\Logger;
use Throwable;

/**
 * Base process manager for worker processes.
 *
 * Owns the daemon connection, worker-local agents, page signal routers,
 * subscription mirrors, and browser flushing.
 */
abstract class WorkerManager extends BaseManager
{
    /** Worker index assigned by the daemon supervisor. */
    protected int $workerIndex;

    /** Whether this worker handles monopolistic agents only. */
    protected bool $isMonopolistic;

    /** Daemon client connection, or null before connect/after cleanup. */
    private ?WorkerDaemonClient $daemonClient = null;

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
     */
    public function run(): void
    {
        // Setup error handling and signal handlers
        $this->setupErrorHandling();
        $this->setupSignalHandlers();
        Hilos::$ac?->openWorkerSession($this->workerIndex, $this->isMonopolistic);

        Logger::info("Worker #{$this->workerIndex} started");

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
                }

                // Process messages from daemon queue
                while (($message = $this->daemonClient->getNextMessage()) !== null) {
                    try {
                        $this->handleDaemonMessage($message);
                    } catch (AgentCreationFailedException $e) {
                        $this->logError("Failed to handle daemon message: " . $e->getMessage());
                    } catch (PageSignalRouterNotFoundException $e) {
                        $this->logError("Failed to route signal: " . $e->getMessage());
                    } finally {
                        ExecutionContext::clear();
                    }
                }

                // Call tick method (only when connected)
                $this->setCurrentAgentId(null);
                $this->onTick();

                // Tick all agents
                foreach ($this->agentManager->getAgents() as $agentId => $agent) {
                    $this->setCurrentAgentId($agent->getId());
                    $agent->onTick();

                    // Check if agent requested stop
                    if ($agent->shouldStop()) {
                        $this->runAgentStopHook($agent);
                        Logger::logAgentStop($agent->getId(), $agent->getType());
                        Hilos::$ac?->closeAgentSession($agent->getType(), $agent->getIndex());
                        $this->agentManager->removeAgent($agentId);
                        Logger::info("Agent {$agentId} stopped (self-requested)");
                        Logger::logAgentInfo($agentId, "Agent stopped (self-requested) on worker [workerIndex={$this->workerIndex}]");
                        $this->notifyAgentStopped($agentId);
                    }
                }

                // Dispatch accumulated signals (send to daemon)
                $this->setCurrentAgentId(null);
                $this->dispatchSignals();
                Hilos::$ac?->tick();
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
     * Starts the non-blocking daemon connection and queues worker registration.
     *
     * Connection progress is polled by the main worker loop.
     *
     * @throws SocketException When connection setup fails
     * @throws EnvException When daemon connection environment values are missing or invalid
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
     * Routes one daemon message to the worker handler that owns its type.
     *
     * @param WorkerDTO $data Daemon message DTO
     * @throws AgentCreationFailedException When agent creation fails
     * @throws PageSignalRouterNotFoundException When page routing is requested for an unsupported agent
     */
    public function handleDaemonMessage(WorkerDTO $data): void
    {
        $this->setCurrentAgentId(null);
        $type = $data->getType();
        Logger::debug("Received message from daemon: type={$type}, data=" . json_encode($data->toArray()));

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

            case WorkerConstants::MESSAGE_DB_SYNC_CREATED:
                if (!$data instanceof WorkerDbSyncCreatedMessageDTO) {
                    Logger::error("handleDbSyncCreatedMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleDbSyncCreatedMessage($data);
                break;

            case WorkerConstants::MESSAGE_DB_SYNC_UPDATED:
                if (!$data instanceof WorkerDbSyncUpdatedMessageDTO) {
                    Logger::error("handleDbSyncUpdatedMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleDbSyncUpdatedMessage($data);
                break;

            case WorkerConstants::MESSAGE_DB_SYNC_DELETED:
                if (!$data instanceof WorkerDbSyncDeletedMessageDTO) {
                    Logger::error("handleDbSyncDeletedMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleDbSyncDeletedMessage($data);
                break;

            case WorkerConstants::MESSAGE_RT_SYNC_CREATED:
                if (!$data instanceof WorkerRtSyncCreatedMessageDTO) {
                    Logger::error("handleRtSyncCreatedMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleRtSyncCreatedMessage($data);
                break;

            case WorkerConstants::MESSAGE_RT_SYNC_UPDATED:
                if (!$data instanceof WorkerRtSyncUpdatedMessageDTO) {
                    Logger::error("handleRtSyncUpdatedMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleRtSyncUpdatedMessage($data);
                break;

            case WorkerConstants::MESSAGE_RT_SYNC_DELETED:
                if (!$data instanceof WorkerRtSyncDeletedMessageDTO) {
                    Logger::error("handleRtSyncDeletedMessage - unexpected type: " . get_class($data));
                    break;
                }
                $this->handleRtSyncDeletedMessage($data);
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
        $agentType = $parsed['agentType'];
        $agentIndex = $parsed['agentIndex'];

        // Create agent using factory method
        $agent = $this->agentManager->createAndAddAgent($agentType, $agentIndex);

        Logger::logAgentStart($agent->getId(), $agent->getType());
        $agent->onStart();
        Hilos::$ac?->openAgentSession($agentType, $agentIndex);
        Logger::info("Agent '{$agentId}' started");
        // Additional agent log from worker side
        Logger::logAgentInfo($agentId, "Agent started on worker [workerIndex={$this->workerIndex}]");

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

        $this->runAgentStopHook($agent);
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
     * Reserved handler for daemon-scoped agent messages.
     *
     * @param WorkerDTO $data Daemon message DTO
     */
    private function handleDaemonAgentMessage(WorkerDTO $data): void
    {
    }

    /**
     * Applies and forwards a DB create sync received from the daemon.
     *
     * @param WorkerDbSyncCreatedMessageDTO $data Worker-level DB create sync message
     */
    private function handleDbSyncCreatedMessage(WorkerDbSyncCreatedMessageDTO $data): void
    {
        if ($this->consumeIncomingDbSyncSelfBroadcast($data->signalData)) {
            return;
        }
        DbSyncApplicator::applyCreated($data->signalData, skipSelfBroadcastCheck: false);
        $this->recordBrowserSourceChange(SignalTypeConstants::DB_SYNC_CREATED, $data->signalData);
        $this->dispatchDbSyncToAgents(SignalConstants::DB_SYNC_CREATED, $data->signalData);
    }

    /**
     * Applies and forwards a DB update sync received from the daemon.
     *
     * @param WorkerDbSyncUpdatedMessageDTO $data Worker-level DB update sync message
     */
    private function handleDbSyncUpdatedMessage(WorkerDbSyncUpdatedMessageDTO $data): void
    {
        if ($this->consumeIncomingDbSyncSelfBroadcast($data->signalData)) {
            return;
        }
        DbSyncApplicator::applyUpdated($data->signalData, skipSelfBroadcastCheck: false);
        $this->recordBrowserSourceChange(SignalTypeConstants::DB_SYNC_UPDATED, $data->signalData);
        $this->dispatchDbSyncToAgents(SignalConstants::DB_SYNC_UPDATED, $data->signalData);
    }

    /**
     * Applies and forwards a DB delete sync received from the daemon.
     *
     * @param WorkerDbSyncDeletedMessageDTO $data Worker-level DB delete sync message
     */
    private function handleDbSyncDeletedMessage(WorkerDbSyncDeletedMessageDTO $data): void
    {
        if ($this->consumeIncomingDbSyncSelfBroadcast($data->signalData)) {
            return;
        }
        DbSyncApplicator::applyDeleted($data->signalData, skipSelfBroadcastCheck: false);
        $this->recordBrowserSourceChange(SignalTypeConstants::DB_SYNC_DELETED, $data->signalData);
        $this->dispatchDbSyncToAgents(SignalConstants::DB_SYNC_DELETED, $data->signalData);
    }

    /**
     * Consume a DB sync self-broadcast marker before applying a daemon echo.
     *
     * The originating worker already recorded the local DB change for browser
     * state when it sent the sync to the daemon. The echoed worker message
     * must therefore neither re-apply nor re-emit the same fact.
     *
     * @param array<string, mixed> $signalData DB sync payload
     * @return bool True when this worker should ignore the daemon echo
     */
    private function consumeIncomingDbSyncSelfBroadcast(array $signalData): bool
    {
        $collectionKey = (string)($signalData['collectionKey'] ?? '');
        $idString = (string)($signalData['idString'] ?? '');
        if ($collectionKey === '' || $idString === '') {
            return false;
        }

        return Hilos::$sr?->shouldSkipDbSyncApply($collectionKey, $idString) ?? false;
    }

    /**
     * Dispatches a DB sync signal to every agent on this worker.
     *
     * Each agent can filter by collectionKey/idString in its onSignal* handler.
     *
     * @param string $signalName DB sync signal name
     * @param array<string, mixed> $signalData DB sync payload
     */
    private function dispatchDbSyncToAgents(string $signalName, array $signalData): void
    {
        $source = 'db';
        $data = match ($signalName) {
            SignalConstants::DB_SYNC_CREATED => DbSyncCreatedSignalData::fromArray($signalData),
            SignalConstants::DB_SYNC_UPDATED => DbSyncUpdatedSignalData::fromArray($signalData),
            SignalConstants::DB_SYNC_DELETED => DbSyncDeletedSignalData::fromArray($signalData),
            default => null,
        };
        if ($data === null) {
            return;
        }

        foreach ($this->agentManager->getAgents() as $agentId => $agent) {
            if (!$agent instanceof AgentInterface) {
                continue;
            }
            $this->setCurrentAgentId($agent->getId());
            match ($signalName) {
                SignalConstants::DB_SYNC_CREATED => $agent->onSignalDbSyncCreated($data, $source, $signalName),
                SignalConstants::DB_SYNC_UPDATED => $agent->onSignalDbSyncUpdated($data, $source, $signalName),
                SignalConstants::DB_SYNC_DELETED => $agent->onSignalDbSyncDeleted($data, $source, $signalName),
                default => null,
            };
        }

        $this->setCurrentAgentId(null);
    }

    /**
     * Dispatches an RT sync signal to every agent on this worker.
     *
     * Each agent can filter by collectionKey/stateId in its onSignal* handler.
     *
     * @param string $signalName RT sync signal name
     * @param array<string, mixed> $signalData RT sync payload
     */
    private function dispatchRtSyncToAgents(string $signalName, array $signalData): void
    {
        $source = 'rt';
        $data = match ($signalName) {
            SignalConstants::RT_SYNC_CREATED => RtSyncCreatedSignalData::fromArray($signalData),
            SignalConstants::RT_SYNC_UPDATED => RtSyncUpdatedSignalData::fromArray($signalData),
            SignalConstants::RT_SYNC_DELETED => RtSyncDeletedSignalData::fromArray($signalData),
            default => null,
        };
        if ($data === null) {
            return;
        }

        foreach ($this->agentManager->getAgents() as $agentId => $agent) {
            if (!$agent instanceof AgentInterface) {
                continue;
            }
            $this->setCurrentAgentId($agent->getId());
            match ($signalName) {
                SignalConstants::RT_SYNC_CREATED => $agent->onSignalRtSyncCreated($data, $source, $signalName),
                SignalConstants::RT_SYNC_UPDATED => $agent->onSignalRtSyncUpdated($data, $source, $signalName),
                SignalConstants::RT_SYNC_DELETED => $agent->onSignalRtSyncDeleted($data, $source, $signalName),
                default => null,
            };
        }

        $this->setCurrentAgentId(null);
    }

    /**
     * Applies and forwards an RT create sync received from the daemon.
     *
     * @param WorkerRtSyncCreatedMessageDTO $data Worker-level RT create sync message
     */
    private function handleRtSyncCreatedMessage(WorkerRtSyncCreatedMessageDTO $data): void
    {
        if ($this->consumeIncomingRtSyncSelfBroadcast($data->signalData)) {
            return;
        }
        RtSyncApplicator::applyCreated($data->signalData, skipSelfBroadcastCheck: false);
        $this->recordBrowserSourceChange(SignalTypeConstants::RT_SYNC_CREATED, $data->signalData);
        $this->dispatchRtSyncToAgents(SignalConstants::RT_SYNC_CREATED, $data->signalData);
    }

    /**
     * Applies and forwards an RT update sync received from the daemon.
     *
     * @param WorkerRtSyncUpdatedMessageDTO $data Worker-level RT update sync message
     */
    private function handleRtSyncUpdatedMessage(WorkerRtSyncUpdatedMessageDTO $data): void
    {
        if ($this->consumeIncomingRtSyncSelfBroadcast($data->signalData)) {
            return;
        }
        RtSyncApplicator::applyUpdated($data->signalData, skipSelfBroadcastCheck: false);
        $this->recordBrowserSourceChange(SignalTypeConstants::RT_SYNC_UPDATED, $data->signalData);
        $this->dispatchRtSyncToAgents(SignalConstants::RT_SYNC_UPDATED, $data->signalData);
    }

    /**
     * Applies and forwards an RT delete sync received from the daemon.
     *
     * @param WorkerRtSyncDeletedMessageDTO $data Worker-level RT delete sync message
     */
    private function handleRtSyncDeletedMessage(WorkerRtSyncDeletedMessageDTO $data): void
    {
        if ($this->consumeIncomingRtSyncSelfBroadcast($data->signalData)) {
            return;
        }
        RtSyncApplicator::applyDeleted($data->signalData, skipSelfBroadcastCheck: false);
        $this->recordBrowserSourceChange(SignalTypeConstants::RT_SYNC_DELETED, $data->signalData);
        $this->dispatchRtSyncToAgents(SignalConstants::RT_SYNC_DELETED, $data->signalData);
    }

    /**
     * Consume an RT sync self-broadcast marker before applying a daemon echo.
     *
     * @param array<string, mixed> $signalData RT sync payload
     * @return bool True when this worker should ignore the daemon echo
     */
    private function consumeIncomingRtSyncSelfBroadcast(array $signalData): bool
    {
        $collectionKey = (string)($signalData['collectionKey'] ?? '');
        $stateId = (string)($signalData['stateId'] ?? '');
        if ($collectionKey === '' || $stateId === '') {
            return false;
        }

        return Hilos::$sr?->shouldSkipRtSyncApply($collectionKey, $stateId) ?? false;
    }

    /**
     * Records a DB/RT sync fact in worker-local browser source buffers.
     *
     * Local writes are recorded when the worker first drains its queued sync
     * signal. Remote writes are recorded after the daemon sync message is
     * accepted. Incoming self-broadcast echoes are consumed before this method,
     * so one backend fact becomes one browser invalidation per worker.
     *
     * @param string $signalType DB/RT sync signal type
     * @param array<string, mixed> $signalData Sync payload
     */
    private function recordBrowserSourceChange(string $signalType, array $signalData): void
    {
        if (Hilos::$browser === null) {
            return;
        }

        $change = match ($signalType) {
            SignalTypeConstants::DB_SYNC_CREATED => (static function () use ($signalData): SourceChange {
                $data = DbSyncCreatedSignalData::fromArray($signalData);
                return SourceChange::dbCreated($data->collectionKey, $data->idString, $data->row);
            })(),
            SignalTypeConstants::DB_SYNC_UPDATED => (static function () use ($signalData): SourceChange {
                $data = DbSyncUpdatedSignalData::fromArray($signalData);
                return SourceChange::dbUpdated($data->collectionKey, $data->idString, $data->row);
            })(),
            SignalTypeConstants::DB_SYNC_DELETED => (static function () use ($signalData): SourceChange {
                $data = DbSyncDeletedSignalData::fromArray($signalData);
                return SourceChange::dbDeleted($data->collectionKey, $data->idString, $data->row);
            })(),
            SignalTypeConstants::RT_SYNC_CREATED => (static function () use ($signalData): SourceChange {
                $data = RtSyncCreatedSignalData::fromArray($signalData);
                return SourceChange::rtCreated($data->collectionKey, $data->stateId, $data->row);
            })(),
            SignalTypeConstants::RT_SYNC_UPDATED => (static function () use ($signalData): SourceChange {
                $data = RtSyncUpdatedSignalData::fromArray($signalData);
                return SourceChange::rtUpdated($data->collectionKey, $data->stateId, $data->row);
            })(),
            SignalTypeConstants::RT_SYNC_DELETED => (static function () use ($signalData): SourceChange {
                $data = RtSyncDeletedSignalData::fromArray($signalData);
                return SourceChange::rtDeleted($data->collectionKey, $data->stateId, $data->row);
            })(),
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
     * @param string $signalType DB/RT sync signal type
     * @param array<string, mixed> $signalData Sync payload
     */
    private function dispatchSyncToLocalAgents(string $signalType, array $signalData): void
    {
        match ($signalType) {
            SignalTypeConstants::DB_SYNC_CREATED,
            SignalTypeConstants::DB_SYNC_UPDATED,
            SignalTypeConstants::DB_SYNC_DELETED => $this->dispatchDbSyncToAgents($signalType, $signalData),
            SignalTypeConstants::RT_SYNC_CREATED,
            SignalTypeConstants::RT_SYNC_UPDATED,
            SignalTypeConstants::RT_SYNC_DELETED => $this->dispatchRtSyncToAgents($signalType, $signalData),
            default => null,
        };
    }

    /**
     * Routes one daemon-delivered agent signal to agent and page handlers.
     *
     * @param DaemonAgentMessageDTO $data Daemon-to-worker agent signal
     * @throws PageSignalRouterNotFoundException When page routing is requested for an unsupported agent
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

        Logger::debug('Signal routing: agentId=' . $agentId . ', signalType=' . $signalType . ', signalName=' . $name);
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
                    $this->dispatchPageUnsubscribeIfTrackedOnConnectionClose($agentId, $agent, $signalData, $source, $name);
                    if ($signalData->acceptKey !== '') {
                        Hilos::$sr?->unsubscribeFromAll($signalData->acceptKey);
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
                    $this->getPageSignalRouter($agentId, $agent)->dispatchAction($signalData, $source);
                } else {
                    Logger::error("handleActionSignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::FRAME_BINARY:
                if ($signalData instanceof WebSocketFrameBinarySignalDTO) {
                    $this->onFrameBinaryHandled($signalData, $source, $name);
                    $agent->onSignalFrameBinary($signalData, $source, $name);
                    $this->getPageSignalRouter($agentId, $agent)->dispatchFrameBinary($signalData, $source, $name);
                } else {
                    Logger::error("handleFrameBinarySignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::PAGE_SUBSCRIBE:
                if ($signalData instanceof WebSocketPageSubscribeSignalDTO) {
                    $this->dispatchPreviousPageUnsubscribeIfReplaced($agentId, $agent, $signalData, $name, $source);
                    $agent->onSignalPageSubscribe($signalData, $source, $name);
                    $this->getPageSignalRouter($agentId, $agent)->dispatchPageSubscribe($signalData, $source, $name);
                    $this->rememberPageSubscriptionAfterSubscribe($signalData, $name);
                } else {
                    Logger::error("handlePageSubscribeSignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION:
                if ($signalData instanceof WebSocketPageUpdateSubscriptionSignalDTO) {
                    $agent->onSignalPageUpdateSubscription($signalData, $source, $name);
                    $this->getPageSignalRouter($agentId, $agent)->dispatchPageUpdateSubscription($signalData, $source, $name);
                    $this->mergePageSubscriptionParamsOnUpdate($signalData);
                } else {
                    Logger::error("handlePageUpdateSubscriptionSignal - invalid signal data type: " . get_class($signalData));
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
                    Logger::error("handlePageUnsubscribeSignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::GROUP_SUBSCRIBE:
                if ($signalData instanceof WebSocketGroupSubscribeSignalDTO) {
                    $agent->onSignalGroupSubscribe($signalData, $source, $name);
                    $group = $signalData->group !== '' ? $signalData->group : $name;
                    Hilos::$sr?->subscribeToGroup($group, $signalData);
                } else {
                    Logger::error("handleGroupSubscribeSignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::GROUP_UNSUBSCRIBE:
                if ($signalData instanceof WebSocketGroupUnsubscribeSignalDTO) {
                    $agent->onSignalGroupUnsubscribe($signalData, $source, $name);
                    $group = $signalData->group !== '' ? $signalData->group : $name;
                    Hilos::$sr?->unsubscribeFromGroup($group, $signalData);
                } else {
                    Logger::error("handleGroupUnsubscribeSignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION:
                if ($signalData instanceof WebSocketGroupUpdateSubscriptionSignalDTO) {
                    $agent->onSignalGroupUpdateSubscription($signalData, $source, $name);
                    $group = $signalData->group !== '' ? $signalData->group : $name;
                    try {
                        Hilos::$sr?->updateGroupSubscription($group, $signalData);
                    } catch (Throwable $e) {
                        Logger::error("WorkerManager: cannot mirror group subscription update: acceptKey={$signalData->acceptKey} group={$group}, {$e->getMessage()}");
                    }
                } else {
                    Logger::error("handleGroupUpdateSubscriptionSignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::AGENT_SIGNAL:
                if ($signalData instanceof AgentSignalData) {
                    if ($apiRequestId !== null) {
                        Hilos::$ac?->logApiAgentAction($apiRequestId, $agent->getType(), $agent->getIndex(), $name, $signalData->toArray());
                    }
                    $this->onAgentSignalHandled($name, $signalData);
                    try {
                        $agent->onSignalAgent($signalData, $source, $name);
                    } catch (AgentException $e) {
                        Logger::logAgentError($agent->getId(), "Agent signal handler failed: {$e->getMessage()}");
                    }
                    try {
                        $this->getPageSignalRouter($agentId, $agent)->dispatchAgentSignal($signalData, $source, $name);
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

        $newPage = $dto->page !== '' ? $dto->page : $name;
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

        $page = $dto->page !== '' ? $dto->page : $name;
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
        $page = $dto->page !== '' ? $dto->page : $this->pageSubscriptionByAcceptKey[$acceptKey]['page'];
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
     * @param string $name Signal name (passed to router; typically empty for connection close)
     */
    private function dispatchPageUnsubscribeIfTrackedOnConnectionClose(
        string $agentId,
        AgentInterface $agent,
        WebSocketCloseSignalDTO $dto,
        string $source,
        string $name,
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
            'type' => WorkerConstants::MESSAGE_AGENT_STOPPED,
            'agentId' => $agentId,
        ];

        $this->daemonClient->send($message);
    }

    /**
     * Stops local agents, closes daemon transport, and shuts down analytics.
     */
    private function cleanup(): void
    {
        // Stop all agents
        foreach ($this->agentManager->getAgents() as $agentId => $agent) {
            $this->runAgentStopHook($agent);
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
        Hilos::$browser?->flushToSignalRouter();
        $this->dispatchQueuedSignalsToDaemon();
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
            $signalName = $signal->signalName->getName();
            $signalData = $signal->data->toArray();

            $json = json_encode($signal->toArray());
            Logger::debug("Signal going to transmit: {$json}");

            // DB/RT sync: worker-level broadcast (daemon + all workers)
            $syncDto = match ($signalType) {
                SignalTypeConstants::DB_SYNC_CREATED => new WorkerDbSyncCreatedMessageDTO($signalData),
                SignalTypeConstants::DB_SYNC_UPDATED => new WorkerDbSyncUpdatedMessageDTO($signalData),
                SignalTypeConstants::DB_SYNC_DELETED => new WorkerDbSyncDeletedMessageDTO($signalData),
                SignalTypeConstants::RT_SYNC_CREATED => new WorkerRtSyncCreatedMessageDTO($signalData),
                SignalTypeConstants::RT_SYNC_UPDATED => new WorkerRtSyncUpdatedMessageDTO($signalData),
                SignalTypeConstants::RT_SYNC_DELETED => new WorkerRtSyncDeletedMessageDTO($signalData),
                default => null,
            };

            if ($syncDto !== null) {
                $this->recordBrowserSourceChange($signalType, $signalData);
                $this->dispatchSyncToLocalAgents($signalType, $signalData);
                $this->daemonClient->send($syncDto);
                continue;
            }

            // Worker browser flush signals may be already-addressed WebSocket deliveries.
            // The daemon ignores agentId for WorkerAgentMessageDTO and routes the inner
            // SignalDTO by its signal type and WebSocket target metadata.
            $agentType = $signal->signalSource->getType();
            $agentIndex = $signal->signalSource->getIndex();
            $agentId = $this->agentManager->buildAgentId($agentType, $agentIndex) ?? '';

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
}
