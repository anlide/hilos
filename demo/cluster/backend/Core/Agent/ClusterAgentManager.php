<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Agent;

use Demo\Cluster\Hilos;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\TopologyAgentFactory;

/**
 * ClusterAgentManager - Agent manager for the cluster demo (worker side).
 *
 * Extends base AgentManager to build the demo's agents from project topology.
 */
final class ClusterAgentManager extends AgentManager
{
    /**
     * Create agent instance (factory method).
     *
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index (optional)
     * @return AgentInterface Agent instance
     * @throws AgentIndexRequiredException If agent index is required but not provided
     * @throws AgentCreationFailedException If agent type is unknown and cannot be created
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return TopologyAgentFactory::createWorker(Hilos::class, $agentType, $agentIndex);
    }
}
