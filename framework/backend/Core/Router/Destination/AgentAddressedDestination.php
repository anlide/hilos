<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;

/**
 * AgentAddressedDestination - Marks a destination that names one agent instance.
 *
 * The three answers the placement post-pass can give about an agent
 * ({@see SignalRouter::placeAgentDestination()}) are three classes, and every reader that
 * cares about the agent rather than about where it runs had to name all three: the daemon's
 * delivery walk, the list of agents a signal already reached, the fan-out that compares
 * against that list. This interface is what those readers name instead, so a fourth answer
 * would not have to be added to each of them by hand.
 *
 * Only an addressed agent gets it. A browser destination is placed by its own post-pass and a
 * command reply is bound to a connection, so neither names an agent at all.
 *
 * Both members are read-only by intent: a destination is a value the router computed, and
 * {@see DaemonManager} delivers it rather than edits it.
 */
interface AgentAddressedDestination extends Destination
{
    /** @var string Target agent type */
    public string $agentType { get; }

    /** @var ?string Agent instance index, or null for unindexed agents */
    public ?string $agentIndex { get; }
}
