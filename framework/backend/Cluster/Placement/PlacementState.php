<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;

/**
 * Lifecycle state of a placed agent as the leader tracks it.
 *
 * {@see Placing} and {@see Unplaced} are leader-local: {@see Placing} means the placement
 * frame was sent to the target node but no status has come back yet, and {@see Unplaced}
 * means failover found no capable+online node to host the agent, so it is degraded and
 * awaiting a capable node to join. Neither travels the wire. The other three are the states
 * a node reports back in a {@see PeerAgentStatusDTO}: {@see Started} when the local agent
 * launched, {@see Failed} when it could not, and {@see Stopped} when it was revoked. The
 * value is string-backed for the wire and for logging; placement tracking is soft-state and
 * never persisted.
 */
enum PlacementState: string
{
    case Placing = 'placing';

    case Started = 'started';

    case Failed = 'failed';

    case Stopped = 'stopped';

    case Unplaced = 'unplaced';
}
