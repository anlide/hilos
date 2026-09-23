<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Router\DTO\SignalDTO;

/**
 * One signal the master holds for one agent that is not up yet (HIL-629).
 *
 * The sibling of {@see ParkedSignal}, held at a different moment and for a different reason:
 * that one waits before routing for the identity behind a connection, this one waits after
 * routing for the agent the walk addressed - because that agent has no address anywhere yet, or
 * its start on this node has not been reported. Handing the frame to a worker at that moment is
 * how it used to die: a start that fails inside the worker takes every frame written behind it.
 *
 * Those two reasons end differently (HIL-1041). A start under way here ends in a fact the node
 * is going to hear - the start reported, the start refused, the worker gone - so the wait lasts
 * as long as the start does. An agent no node is known to host waits on a placement verdict:
 * the leader names a node, or it answers that it could not place it. Nothing here is a clock.
 *
 * A frame that arrived over the peer mesh is marked, because the sender already decided which
 * node hosts the agent and a hold must not reopen that question: released back into the placing
 * walk it could be forwarded a second time, and two nodes disagreeing about the host would pass
 * it back and forth. Such a frame is delivered here or dropped with a line, never sent on.
 *
 * The whole signal is kept, not a closure over it, for the reason its sibling gives. It is held
 * for ONE agent, though, not for the signal's whole destination list: the walk has already
 * reached the other destinations, so a released frame goes to this agent alone, through the
 * same delivery door a frame that never waited goes through.
 */
final readonly class ParkedAgentSignal
{
    /**
     * @param SignalDTO $signal Signal as the walk was delivering it
     * @param string $agentId Agent the signal waits for
     * @param float $parkedAt Unix seconds the hold began at, which the release line reports the age from
     * @param bool $awaitingPlacement Whether the wait is for a placement verdict rather than a start here
     * @param bool $localOnly Whether this frame already crossed the mesh, so it is never placed again
     */
    public function __construct(
        public SignalDTO $signal,
        public string $agentId,
        public float $parkedAt,
        public bool $awaitingPlacement,
        public bool $localOnly = false,
    ) {
    }

    /**
     * @return self The same hold, waiting on a start here rather than a placement verdict
     */
    public function withoutPlacementWait(): self
    {
        return new self($this->signal, $this->agentId, $this->parkedAt, false, $this->localOnly);
    }
}
