<?php

declare(strict_types=1);

namespace Demo\Cluster\Agents;

use Demo\Cluster\Constants\AgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Utils\Logger;

/**
 * WorkerAgent - the placeable no-op agent the cluster harness observes.
 *
 * It carries no domain work: it exists to be placed by the leader onto a
 * data-plane node and to move on failover, so the multi-node harness can assert
 * placement and re-placement through the cluster:test:inspect command. The real
 * synthetic workload that will live here belongs to the bot-agents epic
 * (HIL-120); this stays a no-op on purpose.
 */
final class WorkerAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::WORKER;

    /**
     * Logs that the placed agent came up on this data-plane node.
     */
    public function onStart(): void
    {
        Logger::info("WorkerAgent placed and started on this node [type={$this->getType()}]");
    }

    /**
     * No runtime state to clear; the agent owns nothing but its own presence.
     */
    public function onStop(): void
    {
        Logger::info("WorkerAgent stopped on this node [type={$this->getType()}]");
    }
}
