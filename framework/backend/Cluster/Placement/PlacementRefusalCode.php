<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/**
 * Why a placement gate refused an agent, as a machine reads it (HIL-447).
 *
 * The first three name one node and the first gate it failed, in the order the gate checks them
 * ({@see PlacementCandidate::refusal()}): a required capability tag it does not advertise, no
 * declared capacity at all, or too little free room for the agent's cost. The fourth names no
 * node, because there was none to judge: nothing online was a candidate. It is kept apart from a
 * shortage on purpose — an empty cluster is not a full one, and an operator answers the two
 * differently.
 *
 * The values are the wire form, so a peer frame and the `test:cluster:inspect` reply carry
 * exactly these strings.
 */
enum PlacementRefusalCode: string
{
    case MissingCapabilities = 'missing_capabilities';

    case NoDeclaredCapacity = 'no_declared_capacity';

    case InsufficientCapacity = 'insufficient_capacity';

    case NoOnlineNodes = 'no_online_nodes';
}
