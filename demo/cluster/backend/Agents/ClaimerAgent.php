<?php

declare(strict_types=1);

namespace Demo\Cluster\Agents;

use Demo\Cluster\Constants\AgentType;
use Demo\Cluster\Runtime\View\Context\ClusterRtContext;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Utils\Logger;

/**
 * ClaimerAgent - a deliberate second owner of the collection the fleet writes (HIL-696).
 *
 * The one thing the harness cannot stage with a {@see WorkerAgent}: a claim over the WHOLE of
 * `workerStatuses`, where every fleet member claims one row of it by its own index. Two whole
 * rights over overlapping rows is the split the cluster-wide guard exists to name, and naming
 * it needs an agent on a node that is not the one already holding those rows.
 *
 * It writes NOTHING, and that is the point rather than an omission: the claim is made by
 * declaring the right, before a single row is written, which is precisely the window the guard
 * was built to close. A second writer would also corrupt the fleet's rows on its way to being
 * caught, and the harness asserts those rows survive.
 *
 * Nothing places it on its own — indexed agents are outside the framework's policy-placement
 * sweep, and the demo's own supervisor places only the worker fleet — so it exists on the mesh
 * for exactly as long as a scenario asks for it, and only ever on the node the leader picks.
 */
final class ClaimerAgent extends AbstractAgent
{
    public const string AGENT_TYPE = AgentType::CLAIMER;

    /**
     * @param string $agentIndex Index this instance carries, so several may be staged at once
     * @throws AgentIndexRequiredException When the index is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('ClaimerAgent requires a non-empty agentIndex');
        }

        $this->agentIndex = $agentIndex;
    }

    /**
     * Declares this agent the truth source of the whole collection, and stops there.
     *
     * No keys, so the claim is over every row: that is what makes it overlap whichever rows the
     * fleet holds elsewhere, whatever indices the fleet happens to run under.
     */
    public function onStart(): void
    {
        $this->registerRtTruthSource(ClusterRtContext::workerStatuses);

        Logger::info("Claimer {$this->getId()} started on this node: it claims all of "
            . ClusterRtContext::workerStatuses);
    }

    /**
     * Logs that the claim left this node; the registry drops it with the agent.
     */
    public function onStop(): void
    {
        Logger::info("Claimer {$this->getId()} stopped on this node: its claim is gone with it");
    }
}
