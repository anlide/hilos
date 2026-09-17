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
 * Every case but {@see self::Delivered} and {@see self::Held} means the signal reached nobody,
 * and each says why separately because the three whys are three different pieces of news: a node
 * on its way out, a peer that cannot be talked to, and an agent nobody can place. A fourth why is
 * the only one about THIS node: the agent belongs here and did not come up (HIL-999).
 *
 * Held is the one case that is not an answer yet: the agent is coming up and the frame waits for
 * it in the master (HIL-629). Whoever asked is answered when the wait ends - by the agent, or by
 * the refusal the frame is owed if the agent never arrives.
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

    /** The agent belongs on this node and could not be started or reached here: no worker, or the start failed */
    case StartRefused;

    /** The agent is not up yet and the frame is being held until it is; nobody is answered now */
    case Held;
}
