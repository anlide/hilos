<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\WorkerPlacement;

/**
 * AgentLocationKind - which of the three answers a placement lookup gave about an agent.
 *
 * The whole point of the enum is that {@see Here} and {@see Unknown} are different answers.
 * Before it, {@see WorkerPlacement} answered a node id or null, and null meant both "the agent
 * runs on this node" and "nobody has told me where that agent runs" — so a signal to an agent
 * placed elsewhere, asked on a node that had no picture yet, was delivered into this node's own
 * workers, which run no such agent. Nothing reports that: the send succeeds, the agent never
 * hears it.
 *
 * The three cases are exhaustive by construction, because the question has no fourth answer:
 * the agent is here, it is on a named node, or its address is not known.
 */
enum AgentLocationKind
{
    /** The agent runs on this node: deliver it locally, exactly as a single node always did. */
    case Here;

    /** The agent runs on the named node: the signal goes out over the peer channel. */
    case Node;

    /** Nobody knows where the agent runs: the signal is undeliverable and is dropped with a log. */
    case Unknown;
}
