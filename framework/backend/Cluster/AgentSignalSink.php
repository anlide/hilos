<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * Local port the peer transport uses to hand a cross-node signal to an agent on this node.
 *
 * The receiving half of cross-node signal routing: when a peer forwards a
 * {@see PeerSignalDTO}, the transport delivers the already-resolved
 * target straight through this seam — no re-routing — so the signal reaches the placed agent
 * exactly as a locally-dispatched one would. A test supplies a fake so the transport runs
 * without a worker pool.
 *
 * The MASTER implements it, through the same local delivery door a routed frame goes through
 * (HIL-1040). The worker server used to, by writing the frame into a worker at once — which
 * put a frame that had crossed the cluster behind a start that could still fail and take it
 * along, the one thing the master's hold exists to prevent.
 */
interface AgentSignalSink
{
    /**
     * Delivers a resolved signal to a local agent, starting it first when it is not running.
     *
     * A start under way holds the frame until it ends. An undelivered tracked page request is
     * answered through the ordinary addressed browser route; other refusals remain log entries.
     *
     * @param string $agentType Target agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @param SignalDTO $signal Signal to deliver
     * @throws InvalidArgumentException When a tracked subscription refusal cannot be named
     * @throws InvalidFormatException When the tracked delivery has an invalid completion address
     */
    public function deliverSignalToAgent(string $agentType, ?string $agentIndex, SignalDTO $signal): void;
}
