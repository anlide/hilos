<?php

declare(strict_types=1);

namespace Demo\Cluster\Constants;

/**
 * ClusterCapability - Capability tags the cluster demo places against.
 *
 * A capability tag is the hard placement gate: the leader refuses to place an
 * agent on a node whose advertised capabilities (CLUSTER_NODE_CAPABILITIES) do
 * not include every tag the agent requires. Data-plane nodes advertise
 * {@see WORKER}; master (coordination) nodes do not, so the no-op agent is only
 * ever placed on a data-plane node.
 */
final class ClusterCapability
{
    /** @var string Advertised by data-plane nodes able to host the placeable worker agent */
    public const string WORKER = 'worker';
}
