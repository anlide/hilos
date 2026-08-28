<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/**
 * AgentDeliveryOutcome - what came of one attempt to reach one agent instance.
 *
 * The answer {@see DaemonManager::deliverToAgentDestination()} gives its callers. It reports
 * and does not decide: the same outcome is worth a different reaction depending on who asked.
 * The ordinary destination walk answers an unreachable subscription and asks for a placement;
 * the connection-close fan-out and the unsubscribe of a replaced subscription owe nobody an
 * answer and ignore the whole enum. Folding those reactions into the delivery would have made
 * one of the two wrong.
 *
 * Every case but {@see self::Delivered} means the signal reached nobody, and each says why
 * separately because the three whys are three different pieces of news: a node on its way out,
 * a peer that cannot be talked to, and an agent nobody can place.
 *
 * Unbacked on purpose: the value never leaves the master process — it is read by the caller
 * one frame later and never written to a log line, a wire frame or a row.
 */
enum AgentDeliveryOutcome
{
    /** The signal was handed to the agent - locally through the worker server, or over the peer channel */
    case Delivered;

    /** The daemon is shutting down and no worker was left to take the signal, so it was dropped */
    case ShutdownSkipped;

    /** The agent lives on another node and that node could not be reached: no peer server, or no live link */
    case RemoteUnreachable;

    /** No node is known to host the agent, so there was no delivery to attempt - not even a local one */
    case AddressUnknown;
}
