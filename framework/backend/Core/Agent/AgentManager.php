<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Constants\AgentConstants;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\HilosException;

/**
 * AgentManager - Base class for managing agents in worker processes.
 *
 * Manages lifecycle of agents running in worker processes.
 * Provides unified interface for agent creation, storage and retrieval.
 * Child classes must implement createAgent() factory method.
 */
abstract class AgentManager
{
    /** @var array<string, AgentInterface> Active agents indexed by agent ID */
    protected array $agents = [];

    /**
     * Create agent instance (factory method).
     *
     * Must be implemented in child classes to create specific agent types.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentInterface Agent instance
     * @throws AgentCreationFailedException If agent cannot be created
     * @throws HilosException Whatever the project's factory raises, a missing agent index among it
     */
    abstract protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface;

    /**
     * Build agent ID from type and index.
     *
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
     * Parses an agent ID into its type and index.
     *
     * @param string $agentId Agent ID (format: "type" or "type:index")
     * @return AgentId Parsed agent identity
     */
    public function parseAgentId(string $agentId): AgentId
    {
        return AgentId::fromId($agentId);
    }

    /**
     * @param string $agentId Agent ID
     * @param AgentInterface $agent Agent instance
     */
    public function addAgent(string $agentId, AgentInterface $agent): void
    {
        $this->agents[$agentId] = $agent;
    }

    /**
     * @param string $agentId Agent ID
     */
    public function removeAgent(string $agentId): void
    {
        unset($this->agents[$agentId]);
    }

    /**
     * @param string $agentId Agent ID
     * @return ?AgentInterface Agent instance or null if not found
     */
    public function getAgent(string $agentId): ?AgentInterface
    {
        return $this->agents[$agentId] ?? null;
    }

    /**
     * @param string $agentId Agent ID
     * @return bool True if agent exists
     */
    public function hasAgent(string $agentId): bool
    {
        return isset($this->agents[$agentId]);
    }

    /**
     * @return array<string, AgentInterface> All agents indexed by agent ID
     */
    public function getAgents(): array
    {
        return $this->agents;
    }

    /**
     * @return int Number of active agents
     */
    public function getAgentCount(): int
    {
        return count($this->agents);
    }

    /**
     * Returns the agent already registered for this id, or creates and registers a new one.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentInterface Created or existing agent instance
     * @throws AgentCreationFailedException If agent cannot be created
     * @throws HilosException Whatever the project's factory raises, a missing agent index among it
     */
    public function createAndAddAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);

        // Check if agent already exists
        if ($this->hasAgent($agentId)) {
            return $this->getAgent($agentId);
        }

        // Create agent
        $agent = $this->createAgent($agentType, $agentIndex);
        $this->addAgent($agentId, $agent);

        return $agent;
    }
}
