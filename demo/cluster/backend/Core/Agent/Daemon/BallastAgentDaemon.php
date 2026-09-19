<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Agent\Daemon;

use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Constants\ClusterResource;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use LogicException;

/**
 * Daemon proxy for the ballast, the agent that exists to hold capacity (HIL-448).
 *
 * It requires no capability tag on purpose. Without one, the only thing keeping it off the
 * stand's masters is the rule that a node declaring no capacity takes no placed work — which is
 * exactly what the scenario that stages it checks. Its cost is a constant, as a cost must be:
 * the leader reads it on its master loop every time it chooses a node.
 */
final class BallastAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::BALLAST;

    /** @var float Ram one ballast reserves on its node; the scenario mirrors it as BALLAST_RAM_COST */
    public const float RAM_COST = 2.0;

    /**
     * @param string $agentIndex Index this proxy stands for
     * @throws AgentIndexRequiredException When the index is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('BallastAgentDaemon requires a non-empty agentIndex');
        }

        $this->agentIndex = $agentIndex;
    }

    /**
     * It owns nothing exclusive, so it shares the node's regular workers.
     *
     * @return bool False: run in a shared regular worker
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    /**
     * Every ballast costs its node {@see RAM_COST} of ram.
     *
     * @return ResourceProfile Ram cost of one ballast
     * @throws LogicException When a cost is negative, which RAM_COST is not
     */
    public function placementProfile(): ResourceProfile
    {
        return ResourceProfile::costs([ClusterResource::RAM => self::RAM_COST]);
    }
}
