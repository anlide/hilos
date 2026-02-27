<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Database\DbSyncApplicator;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Page\Exception\PageSignalRouterNotFoundException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\SocketException;
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
use Hilos\Socket\Worker\DTO\WorkerRtSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Exception\MissingEnvironmentVariableException;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Utils\Logger;

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

    /** @var array<string, PageSignalRouter> Page routers by agent ID */
    private array $pageSignalRouters = [];

    /**
     * WorkerManager constructor
     *
     * Initializes signal router via Hilos::initSignalRouter() and creates agent manager.
     *
     * @param int $workerIndex Worker index
     * @param array<string> $argv Command line arguments
     */
    public function __construct(int $workerIndex, array $argv = [])
    {
        $this->workerIndex = $workerIndex;
        $this->isMonopolistic = ArgumentHelper::isMonopolistic($argv);
        Hilos::initSignalRouter($this->createSignalRouter());
        $this->agentManager = $this->createAgentManager();
    }

    /**
     * Create signal router instance.
     *
     * Must be implemented in child classes to create specific signal router.
     * The created instance is registered globally via Hilos::$sr.
     *
     * @return SignalRouter
     */
    abstract protected function createSignalRouter(): SignalRouter;

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
                    } catch (PageSignalRouterNotFoundException $e) {
                        $this->logError("Failed to route signal: " . $e->getMessage());
                    }
                }

                // Call tick method (only when connected)
                $this->onTick();

                // Tick all agents
                foreach ($this->agentManager->getAgents() as $agentId => $agent) {
                    $agent->onTick();

                    // Check if agent requested stop
                    if ($agent->shouldStop()) {
                        $agent->onStop();
                        $this->agentManager->removeAgent($agentId);
                        Logger::info("Agent {$agentId} stopped (self-requested)");
                        Logger::logAgentInfo($agentId, "Agent stopped (self-requested) on worker [workerIndex={$this->workerIndex}]");
                        $this->notifyAgentStopped($agentId);
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
     * @throws PageSignalRouterNotFoundException If page router is not found for agent
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

            case WorkerConstants::MESSAGE_DAEMON_AGENT_MESSAGE:
                if ($data instanceof DaemonAgentMessageDTO) {
                    $this->handleAgentMessage($data);
                } else {
                    Logger::error("handleAgentMessage - unexpected type: " . get_class($data));
                }
                break;

            case WorkerConstants::MESSAGE_DB_SYNC_CREATED:
                $this->handleDbSyncCreatedMessage($data);
                break;

            case WorkerConstants::MESSAGE_DB_SYNC_UPDATED:
                $this->handleDbSyncUpdatedMessage($data);
                break;

            case WorkerConstants::MESSAGE_DB_SYNC_DELETED:
                $this->handleDbSyncDeletedMessage($data);
                break;

            case WorkerConstants::MESSAGE_RT_SYNC_CREATED:
                $this->handleRtSyncCreatedMessage($data);
                break;

            case WorkerConstants::MESSAGE_RT_SYNC_UPDATED:
                $this->handleRtSyncUpdatedMessage($data);
                break;

            case WorkerConstants::MESSAGE_RT_SYNC_DELETED:
                $this->handleRtSyncDeletedMessage($data);
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

    private function handleDaemonAgentMessage(WorkerDTO $data): void
    {

    }

    /**
      * Handle DB sync created message from daemon.
      *
      * @param WorkerDTO $data Message data
      */
    private function handleDbSyncCreatedMessage(WorkerDTO $data): void
    {
        if ($data instanceof WorkerDbSyncCreatedMessageDTO) {
            DbSyncApplicator::applyCreated($data->signalData);
            $this->dispatchDbSyncToAgents(SignalConstants::DB_SYNC_CREATED, $data->signalData);
        } else {
            Logger::error("handleDbSyncCreatedMessage - unexpected type: " . get_class($data));
        }
    }

    /**
     * Handle DB sync updated message from daemon.
     *
     * @param WorkerDTO $data Message data
     */
    private function handleDbSyncUpdatedMessage(WorkerDTO $data): void
    {
        if ($data instanceof WorkerDbSyncUpdatedMessageDTO) {
            DbSyncApplicator::applyUpdated($data->signalData);
            $this->dispatchDbSyncToAgents(SignalConstants::DB_SYNC_UPDATED, $data->signalData);
        } else {
            Logger::error("handleDbSyncUpdatedMessage - unexpected type: " . get_class($data));
        }
    }

    /**
     * Handle DB sync deleted message from daemon.
     *
     * @param WorkerDTO $data Message data
     */
    private function handleDbSyncDeletedMessage(WorkerDTO $data): void
    {
        if ($data instanceof WorkerDbSyncDeletedMessageDTO) {
            DbSyncApplicator::applyDeleted($data->signalData);
            $this->dispatchDbSyncToAgents(SignalConstants::DB_SYNC_DELETED, $data->signalData);
        } else {
            Logger::error("handleDbSyncDeletedMessage - unexpected type: " . get_class($data));
        }
    }

    /**
     * Dispatch DB sync signal to all agents on this worker.
     * Each agent can filter by collectionKey/idString in its onSignal* handler.
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
            match ($signalName) {
                SignalConstants::DB_SYNC_CREATED => $agent->onSignalDbSyncCreated($data, $source, $signalName),
                SignalConstants::DB_SYNC_UPDATED => $agent->onSignalDbSyncUpdated($data, $source, $signalName),
                SignalConstants::DB_SYNC_DELETED => $agent->onSignalDbSyncDeleted($data, $source, $signalName),
                default => null,
            };
        }
    }

    /**
     * Handle RT sync created message from daemon.
     *
     * @param WorkerDTO $data Message data
     */
    private function handleRtSyncCreatedMessage(WorkerDTO $data): void
    {
        if ($data instanceof WorkerRtSyncCreatedMessageDTO) {
            // TODO: Implement RT sync applicator and dispatch to agents
        } else {
            Logger::error("handleRtSyncCreatedMessage - unexpected type: " . get_class($data));
        }
    }

    /**
     * Handle RT sync updated message from daemon.
     *
     * @param WorkerDTO $data Message data
     */
    private function handleRtSyncUpdatedMessage(WorkerDTO $data): void
    {
        if ($data instanceof WorkerRtSyncUpdatedMessageDTO) {
            // TODO: Implement RT sync applicator and dispatch to agents
        } else {
            Logger::error("handleRtSyncUpdatedMessage - unexpected type: " . get_class($data));
        }
    }

    /**
     * Handle RT sync deleted message from daemon.
     *
     * @param WorkerDTO $data Message data
     */
    private function handleRtSyncDeletedMessage(WorkerDTO $data): void
    {
        if ($data instanceof WorkerRtSyncDeletedMessageDTO) {
            // TODO: Implement RT sync applicator and dispatch to agents
        } else {
            Logger::error("handleRtSyncDeletedMessage - unexpected type: " . get_class($data));
        }
    }

    /**
     * Handle agent message (from worker or daemon)
     *
     * @param DaemonAgentMessageDTO $data Message data (daemon -> worker)
     * @throws PageSignalRouterNotFoundException If page router is not found for agent
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
        // Route to appropriate handler in agent based on signal type
        switch ($signalType) {
            case SignalTypeConstants::SYSTEM:
                if ($signalData instanceof SystemSignalDTO) {
                    $agent->onSignalSystem($signalData, $source, $name);
                } else {
                    Logger::error("onSignalSystem - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::CRON:
                if ($signalData instanceof CronSignalDTO) {
                    $agent->onSignalCron($signalData, $source, $name);
                } else {
                    Logger::error("onSignalCron - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::HANDSHAKE:
                if ($signalData instanceof WebSocketHandshakeSignalDTO) {
                    $agent->onSignalHandshake($signalData, $source, $name);
                } else {
                    Logger::error("onSignalHandshake - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::CONNECTION_CLOSE:
                if ($signalData instanceof WebSocketCloseSignalDTO) {
                    $agent->onSignalConnectionClose($signalData, $source, $name);
                } else {
                    Logger::error("onSignalConnectionClose - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::ACTION:
                if ($signalData instanceof WebSocketActionSignalDTO) {
                    $this->onActionHandled($name, $signalData);
                    $agent->onSignalAction($signalData, $source, $name);
                    $this->getPageSignalRouter($agentId, $agent)->dispatchAction($signalData, $source);
                } else {
                    Logger::error("handleActionSignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::FRAME_BINARY:
                if ($signalData instanceof WebSocketFrameBinarySignalDTO) {
                    $agent->onSignalFrameBinary($signalData, $source, $name);
                } else {
                    Logger::error("handleFrameBinarySignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::PAGE_SUBSCRIBE:
                if ($signalData instanceof WebSocketPageSubscribeSignalDTO) {
                    $this->onPageSubscribed($signalData, $source, $name);
                    $agent->onSignalPageSubscribe($signalData, $source, $name);
                    $this->getPageSignalRouter($agentId, $agent)->dispatchPageSubscribe($signalData, $source, $name);
                } else {
                    Logger::error("handlePageSubscribeSignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION:
                if ($signalData instanceof WebSocketPageUpdateSubscriptionSignalDTO) {
                    $this->onPageSubscriptionUpdated($signalData, $source, $name);
                    $agent->onSignalPageUpdateSubscription($signalData, $source, $name);
                    $this->getPageSignalRouter($agentId, $agent)->dispatchPageUpdateSubscription($signalData, $source, $name);
                } else {
                    Logger::error("handlePageUpdateSubscriptionSignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::PAGE_UNSUBSCRIBE:
                if ($signalData instanceof WebSocketPageUnsubscribeSignalDTO) {
                    $this->onPageUnsubscribed($signalData, $source, $name);
                    $agent->onSignalPageUnsubscribe($signalData, $source, $name);
                    $this->getPageSignalRouter($agentId, $agent)->dispatchPageUnsubscribe($signalData, $source, $name);
                } else {
                    Logger::error("handlePageUnsubscribeSignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::GROUP_SUBSCRIBE:
                if ($signalData instanceof WebSocketGroupSubscribeSignalDTO) {
                    $this->onGroupSubscribed($signalData, $source, $name);
                    $agent->onSignalGroupSubscribe($signalData, $source, $name);
                } else {
                    Logger::error("handleGroupSubscribeSignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::GROUP_UNSUBSCRIBE:
                if ($signalData instanceof WebSocketGroupUnsubscribeSignalDTO) {
                    $this->onGroupUnsubscribed($signalData, $source, $name);
                    $agent->onSignalGroupUnsubscribe($signalData, $source, $name);
                } else {
                    Logger::error("handleGroupUnsubscribeSignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION:
                if ($signalData instanceof WebSocketGroupUpdateSubscriptionSignalDTO) {
                    $this->onGroupSubscriptionUpdated($signalData, $source, $name);
                    $agent->onSignalGroupUpdateSubscription($signalData, $source, $name);
                } else {
                    Logger::error("handleGroupUpdateSubscriptionSignal - invalid signal data type: " . get_class($signalData));
                }
                break;

            case SignalTypeConstants::AGENT_SIGNAL:
                if ($signalData instanceof AgentSignalData) {
                    $agent->onSignalAgent($signalData, $source, $name);
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
     * Hook: page subscribe handled on worker.
     *
     * @param WebSocketPageSubscribeSignalDTO $signalData
     * @param string $source
     * @param string $name
     */
    protected function onPageSubscribed(WebSocketPageSubscribeSignalDTO $signalData, string $source, string $name): void
    {
        // Default: no-op
    }

    /**
     * Hook: page unsubscribe handled on worker.
     *
     * @param WebSocketPageUnsubscribeSignalDTO $signalData
     * @param string $source
     * @param string $name
     */
    protected function onPageUnsubscribed(WebSocketPageUnsubscribeSignalDTO $signalData, string $source, string $name): void
    {
        // Default: no-op
    }

    /**
     * Hook: page update subscription handled on worker.
     *
     * @param WebSocketPageUpdateSubscriptionSignalDTO $signalData
     * @param string $source
     * @param string $name
     */
    protected function onPageSubscriptionUpdated(WebSocketPageUpdateSubscriptionSignalDTO $signalData, string $source, string $name): void
    {
        // Default: no-op
    }

    /**
     * Hook: group subscribe handled on worker.
     *
     * @param WebSocketGroupSubscribeSignalDTO $signalData
     * @param string $source
     * @param string $name
     */
    protected function onGroupSubscribed(WebSocketGroupSubscribeSignalDTO $signalData, string $source, string $name): void
    {
        // Default: no-op
    }

    /**
     * Hook: group unsubscribe handled on worker.
     *
     * @param WebSocketGroupUnsubscribeSignalDTO $signalData
     * @param string $source
     * @param string $name
     */
    protected function onGroupUnsubscribed(WebSocketGroupUnsubscribeSignalDTO $signalData, string $source, string $name): void
    {
        // Default: no-op
    }

    /**
     * Hook: group update subscription handled on worker.
     *
     * @param WebSocketGroupUpdateSubscriptionSignalDTO $signalData
     * @param string $source
     * @param string $name
     */
    protected function onGroupSubscriptionUpdated(WebSocketGroupUpdateSubscriptionSignalDTO $signalData, string $source, string $name): void
    {
        // Default: no-op
    }

    /**
     * Hook: action handled on worker.
     *
     * @param string $action
     * @param WebSocketActionSignalDTO $signalData
     */
    protected function onActionHandled(string $action, WebSocketActionSignalDTO $signalData): void
    {
        // Default: no-op
    }

    /**
     * Create page router for the given agent (if supported).
     *
     * @param AgentInterface $agent
     * @return PageSignalRouter
     * @throws PageSignalRouterNotFoundException
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        throw new PageSignalRouterNotFoundException($agent::class);
    }

    /**
     * Get cached page router for agent (create if missing)
     *
     * @param string $agentId Agent ID
     * @param AgentInterface $agent Agent instance
     * @return PageSignalRouter Page router instance
     * @throws PageSignalRouterNotFoundException If router is not supported for agent
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
     * Logic:
     * - DB/RT sync: sent as WorkerDbSync*MessageDTO / WorkerRtSync*MessageDTO (worker-level broadcast)
     * - Agent signals: sent as WorkerAgentMessageDTO (agent-level routing)
     */
    private function dispatchSignals(): void
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
                $this->daemonClient->send($syncDto);
                continue;
            }

            // Agent signals: agent-level routing
            $agentType = $signal->signalSource->getType();
            $agentIndex = $signal->signalSource->getIndex();
            $agentId = $this->agentManager->buildAgentId($agentType, $agentIndex);
            if ($agentId === null) {
                Logger::error("Cannot dispatch signal without agent ID: {$signalType}/{$signalName}");
                continue;
            }

            $this->daemonClient->send(new WorkerAgentMessageDTO(
                agentId: $agentId,
                signal: $signal,
            ));
        }
    }

    /**
     * Create agent manager instance.
     *
     * Must be implemented in child classes to create specific agent manager.
     *
     * @return AgentManager
     */
    abstract protected function createAgentManager(): AgentManager;

    /**
     * Get manager name for logging
     *
     * @return string Manager name
     */
    protected function getManagerName(): string
    {
        return "Worker #{$this->workerIndex}";
    }

    /**
     * Log error message
     *
     * @param string $message Error message to log
     */
    protected function logError(string $message): void
    {
        Logger::errorLog($message);
    }

    /**
     * Log exception message
     *
     * @param string $message Exception message to log
     */
    protected function logException(string $message): void
    {
        Logger::errorLog($message);
    }

    /**
     * Log shutdown message
     *
     * @param string $message Shutdown message to log
     */
    protected function logShutdown(string $message): void
    {
        Logger::errorLog($message);
    }

    /**
     * Handle error event
     *
     * Sets exit flag to stop worker loop.
     */
    protected function onError(): void
    {
        $this->shouldExit = true;
    }

    /**
     * Handle exception event
     *
     * Sets exit flag to stop worker loop.
     */
    protected function onException(): void
    {
        $this->shouldExit = true;
    }

    /**
     * Handle shutdown event
     *
     * Sets exit flag to stop worker loop.
     */
    protected function onShutdown(): void
    {
        $this->shouldExit = true;
    }

    /**
     * Handle shutdown signal event
     *
     * Worker-specific shutdown logic can be implemented in child classes.
     */
    protected function onShutdownSignal(): void
    {
        // Worker-specific shutdown logic (none needed)
    }

    /**
     * Handle restart signal event
     *
     * Worker-specific restart logic can be implemented in child classes.
     */
    protected function onRestartSignal(): void
    {
        // Worker-specific restart logic (none needed)
    }
}
