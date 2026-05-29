<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Constants\AgentConstants;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;

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
        return $agentIndex !== null ? $agentType . ':' . $agentIndex : $agentType;
    }

    /**
     * Parse agent ID to extract type and index.
     *
     * @param string $agentId Agent ID (format: "type" or "type:index")
     * @return array<string, string|null> Associative array with agent type and index field keys
     */
    public function parseAgentId(string $agentId): array
    {
        $parts = explode(':', $agentId, 2);
        return [
            AgentConstants::FIELD_AGENT_TYPE => $parts[0] ?? '',
            AgentConstants::FIELD_AGENT_INDEX => $parts[1] ?? null,
        ];
    }

    /**
     * Add agent to manager.
     *
     * @param string $agentId Agent ID
     * @param AgentInterface $agent Agent instance
     */
    public function addAgent(string $agentId, AgentInterface $agent): void
    {
        $this->agents[$agentId] = $agent;
    }

    /**
     * Remove agent from manager.
     *
     * @param string $agentId Agent ID
     */
    public function removeAgent(string $agentId): void
    {
        unset($this->agents[$agentId]);
    }

    /**
     * Get agent by ID.
     *
     * @param string $agentId Agent ID
     * @return ?AgentInterface Agent instance or null if not found
     */
    public function getAgent(string $agentId): ?AgentInterface
    {
        return $this->agents[$agentId] ?? null;
    }

    /**
     * Check if agent exists.
     *
     * @param string $agentId Agent ID
     * @return bool True if agent exists
     */
    public function hasAgent(string $agentId): bool
    {
        return isset($this->agents[$agentId]);
    }

    /**
     * Get all agents.
     *
     * @return array<string, AgentInterface> All agents indexed by agent ID
     */
    public function getAgents(): array
    {
        return $this->agents;
    }

    /**
     * Get agent count.
     *
     * @return int Number of active agents
     */
    public function getAgentCount(): int
    {
        return count($this->agents);
    }

    /**
     * Create and add agent.
     *
     * Factory method that creates agent and adds it to manager.
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentInterface Created agent instance
     * @throws AgentCreationFailedException If agent cannot be created
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
