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
     * @param float $deadline Unix seconds after which the signal is answered as undelivered if the agent is still not up
     */
    public function __construct(
        public SignalDTO $signal,
        public string $agentId,
        public float $deadline,
    ) {
    }
}
