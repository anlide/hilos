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
 */
final class AgentType
{
    /** @var string Placeable no-op worker agent, run on a data-plane node the leader picks */
    public const string WORKER = 'worker';

    /** @var string Placeable agent that claims the whole collection the fleet owns by rows */
    public const string CLAIMER = 'claimer';
}
