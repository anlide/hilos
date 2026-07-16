<?php

declare(strict_types=1);

namespace Demo\Cluster\Constants;

/**
 * AgentType - Agent type constants for the cluster demo.
 *
 * The demo owns a single placeable, monopolistic no-op agent. It is not a
 * cluster-singleton: the leader places it on a capable data-plane node over the
 * peer channel, and it is the observable subject of the placement and failover
 * scenarios in the multi-node harness.
 */
final class AgentType
{
    /** @var string Placeable no-op worker agent, run on a data-plane node the leader picks */
    public const string WORKER = 'worker';
}
