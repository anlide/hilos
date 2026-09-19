<?php

declare(strict_types=1);

namespace Demo\Cluster\Constants;

/**
 * AgentType - Agent type constants for the cluster demo.
 *
 * Two placeable agents, neither a cluster-singleton: the leader places both on a capable
 * data-plane node over the peer channel. {@see WORKER} is the fleet the placement and failover
 * scenarios observe; {@see CLAIMER} exists only to be refused, and nothing starts it unless a
 * scenario asks for it.
 *
 * The third is not placed at all. {@see DB_PROBE} is a node replica, one on every node, which
 * is what lets a scenario name the writer and the reader as two particular nodes.
 *
 * The fourth, {@see BALLAST}, is placeable like the first two and exists only to hold capacity:
 * it costs the node ram, so a scenario can show the leader filling the slaves in proportion to
 * what they declare (HIL-448). Nothing starts it unless a scenario asks for it.
 */
final class AgentType
{
    /** @var string Placeable no-op worker agent, run on a data-plane node the leader picks */
    public const string WORKER = 'worker';

    /** @var string Placeable agent that claims the whole collection the fleet owns by rows */
    public const string CLAIMER = 'claimer';

    /** @var string Per-node replica that writes and reads a settings row of the shared database */
    public const string DB_PROBE = 'db_probe';

    /** @var string Placeable agent that does nothing but hold a slice of its node's declared ram */
    public const string BALLAST = 'ballast';
}
