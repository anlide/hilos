<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentNotFoundException;
use Hilos\Core\Agent\Exception\AgentNotLinkedToWorkerException;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Core\Agent\Exception\WorkerClientNotFoundException;
use Hilos\Core\Router\DTO\SignalDTO;

/**
 * Local port the peer transport uses to hand a cross-node signal to an agent on this node.
 *
 * The receiving half of cross-node signal routing: when a peer forwards a
 * {@see \Hilos\Cluster\Peer\DTO\PeerSignalDTO}, the transport delivers the already-resolved
 * target straight through this seam — no re-routing — so the signal reaches the placed agent
 * exactly as a locally-dispatched one would. The worker server implements it by reusing its
 * existing send-to-agent path, mirroring how {@see Placement\PlacementExecutor} reuses the
 * start path. A test supplies a fake so the transport runs without a worker pool.
 */
interface AgentSignalSink
{
    /**
     * Delivers a resolved signal to a local agent, starting it first when it is not running.
     *
     * @param string $agentType Target agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param SignalDTO $signal Signal to deliver
     * @throws AgentDaemonCreationFailedException When the agent daemon cannot be created
     * @throws NoSuitableWorkerException When no suitable worker is available
     * @throws AgentNotFoundException When the agent does not exist after a start attempt
     * @throws AgentNotLinkedToWorkerException When the agent is not linked to a worker
     * @throws WorkerClientNotFoundException When the worker client for the agent is missing
     */
    public function deliverSignalToAgent(string $agentType, ?string $agentIndex, SignalDTO $signal): void;
}
