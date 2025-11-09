<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

use Hilos\DTO\Worker\WorkerAgentMessageDTO;
use Hilos\DTO\Worker\WorkerAgentStartedDTO;
use Hilos\DTO\Worker\WorkerAgentStoppedDTO;
use Hilos\Exception\Worker\AgentDaemonCreationFailedException;
use Hilos\Logging\Logger\Logger;
use Hilos\Socket\Client\WorkerClient;

/**
 * AgentManagerDaemon - Base class for managing agent daemons in daemon process
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
     * Build agent ID from type and index
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return string Agent ID (format: "type" or "type:index")
     */
    public function buildAgentId(string $agentType, ?string $agentIndex): string
    {
        return $agentIndex !== null ? $agentType . ':' . $agentIndex : $agentType;
    }

    /**
     * Calculate workerId from worker index and monopolistic flag
     *
     * @param int $workerIndex Worker index
     * @param bool $isMonopolistic True if worker is monopolistic
     * @return int Worker ID (negative = monopolistic, positive = regular)
     */
    public function calculateWorkerId(int $workerIndex, bool $isMonopolistic): int
    {
        return $isMonopolistic ? -$workerIndex : $workerIndex;
    }

    /**
     * Extract worker info from workerId
     *
     * @param int $workerId Worker ID
     * @return array{workerIndex: int, isMonopolistic: bool} Worker info
     */
    public function extractWorkerInfo(int $workerId): array
    {
        return [
            'workerIndex' => abs($workerId),
            'isMonopolistic' => $workerId < 0,
        ];
    }

    /**
     * Add agent daemon to manager
     *
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
     * Remove agent daemon from manager
     *
     * @param string $agentId Agent ID
     */
    public function removeAgent(string $agentId): void
    {
        unset($this->agentDaemons[$agentId]);
        unset($this->agentToWorker[$agentId]);
    }

    /**
     * Get agent daemon by ID
     *
     * @param string $agentId Agent ID
     * @return ?AgentDaemonInterface Agent daemon instance or null if not found
     */
    public function getAgent(string $agentId): ?AgentDaemonInterface
    {
        return $this->agentDaemons[$agentId] ?? null;
    }

    /**
     * Get worker ID for agent
     *
     * @param string $agentId Agent ID
     * @return ?int Worker ID (negative = monopolistic, positive = regular) or null if not found
     */
    public function getAgentWorkerId(string $agentId): ?int
    {
        return $this->agentToWorker[$agentId] ?? null;
    }

    /**
     * Get worker info for agent
     *
     * @param string $agentId Agent ID
     * @return ?array{workerIndex: int, isMonopolistic: bool} Worker info or null if not found
     */
    public function getAgentWorkerInfo(string $agentId): ?array
    {
        $workerId = $this->getAgentWorkerId($agentId);
        if ($workerId === null) {
            return null;
        }

        return $this->extractWorkerInfo($workerId);
    }

    /**
     * Check if agent exists
     *
     * @param string $agentId Agent ID
     * @return bool True if agent exists
     */
    public function hasAgent(string $agentId): bool
    {
        return isset($this->agentDaemons[$agentId]);
    }

    /**
     * Get all agent daemons
     *
     * @return array<string, AgentDaemonInterface> All agent daemons indexed by agent ID
     */
    public function getAgents(): array
    {
        return $this->agentDaemons;
    }

    /**
     * Get agent count
     *
     * @return int Number of active agent daemons
     */
    public function getAgentCount(): int
    {
        return count($this->agentDaemons);
    }

    /**
     * Get agent count on specific worker
     *
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
     * Create and add agent daemon
     *
     * Factory method that creates agent daemon and adds it to manager.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @param int $workerIndex Worker index
     * @param bool $isMonopolistic True if worker is monopolistic
     * @return AgentDaemonInterface Created agent daemon instance
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
     * Handle agent_started signal from worker
     *
     * Creates or updates agent daemon and links it to worker client.
     *
     * @param WorkerClient $workerClient Worker client that sent the signal
     * @param WorkerAgentStartedDTO $dto DTO with agent started data
     * @throws AgentDaemonCreationFailedException
     */
    public function handleAgentStarted(WorkerClient $workerClient, WorkerAgentStartedDTO $dto): void
    {
        $agentId = $dto->agentId;
        $agentType = $dto->agentType;
        $agentIndex = $dto->agentIndex;

        if ($agentId === '' || $agentType === '') {
            return;
        }

        // Create and link agent daemon if it doesn't exist
        if (!$this->hasAgent($agentId)) {
            $this->createAndAddAgent($agentType, $agentIndex, $workerClient->getWorkerIndex(), $workerClient->isMonopolistic());
        }

        $agentDaemon = $this->getAgent($agentId);
        if ($agentDaemon === null) {
            return;
        }

        $agentDaemon->setWorkerClient($workerClient);
        $agentDaemon->onStart();

        Logger::info("Agent '{$agentId}' started on worker #{$workerClient->getWorkerIndex()}");
    }

    /**
     * Handle agent_stopped signal from worker
     *
     * Removes agent daemon and calls onStop().
     *
     * @param WorkerClient $workerClient Worker client that sent the signal
     * @param WorkerAgentStoppedDTO $dto DTO with agent stopped data
     */
    public function handleAgentStopped(WorkerClient $workerClient, WorkerAgentStoppedDTO $dto): void
    {
        $agentId = $dto->agentId;

        if ($agentId === '') {
            return;
        }

        // Remove agent daemon
        if ($this->hasAgent($agentId)) {
            $agentDaemon = $this->getAgent($agentId);
            if ($agentDaemon !== null) {
                $agentDaemon->onStop();
            }
            $this->removeAgent($agentId);

            Logger::info("Agent {$agentId} stopped on worker #{$workerClient->getWorkerIndex()}");
        }
    }

    /**
     * Handle agent_message signal from worker
     *
     * Forwards message from worker agent to agent daemon.
     *
     * @param WorkerClient $workerClient Worker client that sent the signal
     * @param WorkerAgentMessageDTO $dto DTO with agent message data
     */
    public function handleAgentMessage(WorkerClient $workerClient, WorkerAgentMessageDTO $dto): void
    {
        $agentId = $dto->agentId;

        if ($agentId === '' || !$this->hasAgent($agentId)) {
            return;
        }

        $agentDaemon = $this->getAgent($agentId);
        if ($agentDaemon === null) {
            return;
        }

        // TODO: Implement message forwarding to agent daemon
        // This will depend on the specific message format and agent daemon interface
        // For now, just log the message
        Logger::info("Agent message received from worker [agentId={$agentId}] [workerIndex={$workerClient->getWorkerIndex()}]");
    }
}
