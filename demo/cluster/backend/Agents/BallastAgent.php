<?php

declare(strict_types=1);

namespace Demo\Cluster\Agents;

use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Constants\ClusterResource;
use Demo\Cluster\Core\Agent\Daemon\BallastAgentDaemon;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Utils\Logger;

/**
 * BallastAgent - an agent whose only job is to cost its node something (HIL-448).
 *
 * It does no work and owns nothing. What it brings to the cluster is its declared cost,
 * {@see BallastAgentDaemon::RAM_COST} of ram, which the leader reserves against the declared
 * capacity of whichever node it places it on — so a scenario can ask for a row of them and read
 * off where they landed whether the slaves filled in proportion to what they declare, whether
 * the masters (which declare nothing) were passed over, and whether a full node stopped taking
 * work.
 *
 * Nothing places it on its own — indexed agents are outside the framework's policy-placement
 * sweep, and the demo's own supervisor places only the worker fleet — so it exists only for as
 * long as a scenario asks for it.
 */
final class BallastAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::BALLAST;

    /**
     * @param string $agentIndex Index this instance carries, so a row of them may be staged
     * @throws AgentIndexRequiredException When the index is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('BallastAgent requires a non-empty agentIndex');
        }

        $this->agentIndex = $agentIndex;
    }

    /** Says out loud that the node now carries this ballast's reservation. */
    public function onStart(): void
    {
        Logger::info("Ballast {$this->getId()} started on this node: it holds "
            . ClusterResource::RAM . '=' . BallastAgentDaemon::RAM_COST);
    }

    /**
     * Logs that the ballast left this node, and its reservation with it.
     */
    public function onStop(): void
    {
        Logger::info("Ballast {$this->getId()} stopped on this node: its " . ClusterResource::RAM . ' is free again');
    }
}
