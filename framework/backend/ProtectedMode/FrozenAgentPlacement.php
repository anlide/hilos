<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Core\Agent\AgentId;

/**
 * One agent a protected-mode freeze stopped, remembered together with the worker it was taken off.
 *
 * The freeze already had to remember WHICH agents it stopped, so the lift could bring back the
 * same set. This adds the only other thing the lift needs to put the node back as it was: WHERE
 * each of them stood.
 *
 * Without it the lift picks a worker for every agent afresh, at random among the monopolistic
 * workers holding none - so a node that freezes 25 times in a run deals its whole roster out
 * again 25 times, and an agent ends the run having lived on a dozen different workers. Nothing
 * about that is wrong for one agent, and all of it is work nobody asked for.
 *
 * The worker is nullable because an agent can be on none: a start the master asked for and no
 * worker has reported yet is in the roster without a link, and the lift then picks for it the way
 * it always did.
 */
final readonly class FrozenAgentPlacement
{
    /**
     * @param AgentId $agent Agent the freeze stopped
     * @param ?int $workerId Worker it was stopped on, negative for a monopolistic one; null when it was linked to none
     */
    public function __construct(
        public AgentId $agent,
        public ?int $workerId,
    ) {
    }
}
