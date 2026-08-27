<?php

declare(strict_types=1);

namespace Demo\Cluster\Core\Agent\Daemon;

use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Constants\ClusterCapability;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;

/**
 * Daemon proxy for the deliberate second claimer of the fleet's collection (HIL-696).
 *
 * Declared exactly like a fleet member — shared worker, WORKER capability — because the split
 * it stages has to happen where the fleet already writes. A claimer the policy could only put
 * on a coordination node would clash with nobody.
 */
final class ClaimerAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::CLAIMER;

    /**
     * @param string $agentIndex Index this proxy stands for
     * @throws AgentIndexRequiredException When the index is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('ClaimerAgentDaemon requires a non-empty agentIndex');
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
     * Only a node advertising the WORKER capability may host it — the data plane the fleet is on.
     *
     * @return list<string> Required capability tags
     */
    public function requiredCapabilities(): array
    {
        return [ClusterCapability::WORKER];
    }
}
