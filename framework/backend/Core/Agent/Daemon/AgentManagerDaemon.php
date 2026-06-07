<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentDaemonNotRegisteredException;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncUpdatedMessageDTO;
use Hilos\Utils\Logger;

/**
 * AgentManagerDaemon - Base class for managing agent daemons in daemon process.
 *
 * Manages lifecycle of agent daemons running in daemon process.
 * Provides unified interface for agent daemon creation, storage and retrieval.
 * Uses workerId mapping instead of WorkerClient objects (workerId: negative = monopolistic, positive = regular).
 * Child classes must implement createAgentDaemon() factory method.
 */
abstract class AgentManagerDaemon
{
    /** @var array<string, AgentDaemonInterface> Active agent daemons indexed by agent ID */
    protected array $agentDaemons = [];

    /** @var array<string, int> Mapping agentId => workerId (negative = monopolistic, positive = regular) */
    protected array $agentToWorker = [];

    /**
     * Create agent daemon instance (factory method)
     *
     * Must be implemented in child classes to create specific agent daemon types.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentDaemonInterface Agent daemon instance
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     */
    abstract protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface;

    /**
     * @param ?string $agentType Agent type (null for non-agent sources like DB sync)
     * @param ?string $agentIndex Agent index (optional)
     * @return ?string Agent ID (format: "type" or "type:index") or null if agentType is null
     */
    public function buildAgentId(?string $agentType, ?string $agentIndex): ?string
    {
        if ($agentType === null) {
            return null;
        }
        return $agentIndex !== null ? $agentType . AgentConstants::ID_SEPARATOR . $agentIndex : $agentType;
    }

    /**
     * @param int $workerIndex Worker index
     * @param bool $isMonopolistic True if worker is monopolistic
     * @return int Worker ID (negative = monopolistic, positive = regular)
     */
    public function calculateWorkerId(int $workerIndex, bool $isMonopolistic): int
    {
        return $isMonopolistic ? -$workerIndex : $workerIndex;
    }

    /**
     * Derives worker placement from a workerId.
     *
     * @param int $workerId Worker ID (negative = monopolistic, positive = regular)
     * @return WorkerInfo Worker index and monopolistic flag
     */
    public function extractWorkerInfo(int $workerId): WorkerInfo
    {
        return new WorkerInfo(abs($workerId), $workerId < 0);
    }

    /**
     * @param string $agentId Agent ID
     * @param AgentDaemonInterface $agentDaemon Agent daemon instance
     * @param int $workerIndex Worker index
     * @param bool $isMonopolistic True if worker is monopolistic
     */
    public function addAgent(string $agentId, AgentDaemonInterface $agentDaemon, int $workerIndex, bool $isMonopolistic): void
    {
        $this->agentDaemons[$agentId] = $agentDaemon;
        $this->agentToWorker[$agentId] = $this->calculateWorkerId($workerIndex, $isMonopolistic);
    }

    /**
     * @param string $agentId Agent ID
     */
    public function removeAgent(string $agentId): void
    {
        unset($this->agentDaemons[$agentId]);
        unset($this->agentToWorker[$agentId]);
    }

    /**
     * @param string $agentId Agent ID
     * @return ?AgentDaemonInterface Agent daemon instance or null if not found
     */
    public function getAgent(string $agentId): ?AgentDaemonInterface
    {
        return $this->agentDaemons[$agentId] ?? null;
    }

    /**
     * @param string $agentId Agent ID
     * @return ?int Worker ID (negative = monopolistic, positive = regular) or null if not found
     */
    public function getAgentWorkerId(string $agentId): ?int
    {
        return $this->agentToWorker[$agentId] ?? null;
    }

    /**
     * Returns worker placement for an agent, or null when the agent is not mapped to a worker.
     *
     * @param string $agentId Agent ID
     * @return ?WorkerInfo Worker index and monopolistic flag, or null if not found
     */
    public function getAgentWorkerInfo(string $agentId): ?WorkerInfo
    {
        $workerId = $this->getAgentWorkerId($agentId);
        if ($workerId === null) {
            return null;
        }

        return $this->extractWorkerInfo($workerId);
    }

    /**
     * @param string $agentId Agent ID
     * @return bool True if agent exists
     */
    public function hasAgent(string $agentId): bool
    {
        return isset($this->agentDaemons[$agentId]);
    }

    /**
     * @return array<string, AgentDaemonInterface> All agent daemons indexed by agent ID
     */
    public function getAgents(): array
    {
        return $this->agentDaemons;
    }

    /**
     * @return int Number of active agent daemons
     */
    public function getAgentCount(): int
    {
        return count($this->agentDaemons);
    }

    /**
     * @param int $workerIndex Worker index
     * @param bool $isMonopolistic True if worker is monopolistic
     * @return int Number of agents on worker
     */
    public function getAgentCountOnWorker(int $workerIndex, bool $isMonopolistic): int
    {
        $targetWorkerId = $this->calculateWorkerId($workerIndex, $isMonopolistic);
        $count = 0;

        foreach ($this->agentToWorker as $agentId => $workerId) {
            if ($workerId === $targetWorkerId) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Returns the agent daemon already registered for this id, or creates and registers a new one.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @param int $workerIndex Worker index
     * @param bool $isMonopolistic True if worker is monopolistic
     * @return AgentDaemonInterface Created or existing agent daemon instance
     * @throws AgentDaemonCreationFailedException If agent daemon cannot be created
     */
    public function createAndAddAgent(string $agentType, ?string $agentIndex, int $workerIndex, bool $isMonopolistic): AgentDaemonInterface
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);

        // Check if agent already exists
        if ($this->hasAgent($agentId)) {
            return $this->getAgent($agentId);
        }

        // Create agent daemon
        $agentDaemon = $this->createAgentDaemon($agentType, $agentIndex);
        $this->addAgent($agentId, $agentDaemon, $workerIndex, $isMonopolistic);

        return $agentDaemon;
    }

    /**
     * Runs the daemon-side onStart hook for an already-registered agent and logs it.
     *
     * @param WorkerAgentStartedDTO $dto DTO with agent started data
     * @throws AgentDaemonNotRegisteredException When the worker reports an agent the manager never registered
     */
    public function handleAgentStarted(WorkerAgentStartedDTO $dto): void
    {
        $agentId = $dto->agentId;

        // The worker only reports agents the daemon already registered; a missing one is a registration bug
        if (!$this->hasAgent($agentId)) {
            throw new AgentDaemonNotRegisteredException($agentId);
        }

        $this->getAgent($agentId)?->onStart();
        $workerIndex = $this->getAgentWorkerInfo($agentId)?->workerIndex ?? 'unknown';

        Logger::info("Agent '{$agentId}' started on worker #{$workerIndex}");
    }

    /**
     * Handle agent_stopped signal from worker
     *
     * Removes agent daemon and calls onStop().
     *
     * @param WorkerAgentStoppedDTO $dto DTO with agent stopped data
     */
    public function handleAgentStopped(WorkerAgentStoppedDTO $dto): void
    {
        if (!$this->hasAgent($dto->agentId)) {
            return;
        }

        $workerIndex = $this->getAgentWorkerInfo($dto->agentId)?->workerIndex ?? 'unknown';
        $this->getAgent($dto->agentId)?->onStop();
        $this->removeAgent($dto->agentId);

        Logger::info("Agent '{$dto->agentId}' stopped on worker #{$workerIndex}");
    }

    /**
     * Handle worker signal message (agent_message wire type)
     *
     * Receives signal from worker and queues it in daemon's SignalRouter.
     * DaemonManager will process the signal and decide what to do with it
     * (routing to other agents, WebSocket delivery to clients, etc.).
     *
     * @param WorkerAgentMessageDTO $dto DTO containing signal from worker
     */
    public function handleAgentMessage(WorkerAgentMessageDTO $dto): void
    {
        // Simply queue signal in daemon's SignalRouter
        // DaemonManager will process it in dispatchSignals() and handle routing/delivery
        Hilos::$sr->queueSignal(
            signalSource: $dto->signal->signalSource,
            signalType: $dto->signal->signalType,
            signalName: $dto->signal->signalName,
            signalData: $dto->signal->data,
        );
    }

    /**
     * Handle DB sync created message from worker (worker-level broadcast to daemon + all workers).
     *
     * @param WorkerDbSyncCreatedMessageDTO $dto DTO with sync created data
     */
    public function handleWorkerDbSyncCreated(WorkerDbSyncCreatedMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::DB),
            signalType: new SignalType(SignalTypeConstants::DB_SYNC_CREATED),
            signalName: new SignalName(SignalConstants::DB_SYNC_CREATED),
            signalData: $dto->signalData,
        );
    }

    /**
     * Handle DB sync updated message from worker (worker-level broadcast).
     *
     * @param WorkerDbSyncUpdatedMessageDTO $dto DTO with sync updated data
     */
    public function handleWorkerDbSyncUpdated(WorkerDbSyncUpdatedMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::DB),
            signalType: new SignalType(SignalTypeConstants::DB_SYNC_UPDATED),
            signalName: new SignalName(SignalConstants::DB_SYNC_UPDATED),
            signalData: $dto->signalData,
        );
    }

    /**
     * Handle DB sync deleted message from worker (worker-level broadcast).
     *
     * @param WorkerDbSyncDeletedMessageDTO $dto DTO with sync deleted data
     */
    public function handleWorkerDbSyncDeleted(WorkerDbSyncDeletedMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::DB),
            signalType: new SignalType(SignalTypeConstants::DB_SYNC_DELETED),
            signalName: new SignalName(SignalConstants::DB_SYNC_DELETED),
            signalData: $dto->signalData,
        );
    }

    /**
     * Handle RT sync created message from worker (worker-level broadcast).
     *
     * @param WorkerRtSyncCreatedMessageDTO $dto DTO with sync created data
     */
    public function handleWorkerRtSyncCreated(WorkerRtSyncCreatedMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::RT),
            signalType: new SignalType(SignalTypeConstants::RT_SYNC_CREATED),
            signalName: new SignalName(SignalConstants::RT_SYNC_CREATED),
            signalData: $dto->signalData,
        );
    }

    /**
     * Handle RT sync updated message from worker (worker-level broadcast).
     *
     * @param WorkerRtSyncUpdatedMessageDTO $dto DTO with sync updated data
     */
    public function handleWorkerRtSyncUpdated(WorkerRtSyncUpdatedMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::RT),
            signalType: new SignalType(SignalTypeConstants::RT_SYNC_UPDATED),
            signalName: new SignalName(SignalConstants::RT_SYNC_UPDATED),
            signalData: $dto->signalData,
        );
    }

    /**
     * Handle RT sync deleted message from worker (worker-level broadcast).
     *
     * @param WorkerRtSyncDeletedMessageDTO $dto DTO with sync deleted data
     */
    public function handleWorkerRtSyncDeleted(WorkerRtSyncDeletedMessageDTO $dto): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::RT),
            signalType: new SignalType(SignalTypeConstants::RT_SYNC_DELETED),
            signalName: new SignalName(SignalConstants::RT_SYNC_DELETED),
            signalData: $dto->signalData,
        );
    }
}
