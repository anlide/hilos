<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/**
 * Lifecycle state of a placed agent as the leader tracks it.
 *
 * {@see Placing} is a leader-local provisional state: the placement frame was sent
 * to the target node but no status has come back yet, so it never travels the wire.
 * The other three are the states a node reports back in a {@see \Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO}:
 * {@see Started} when the local agent launched, {@see Failed} when it could not, and
 * {@see Stopped} when it was revoked. The value is string-backed for the wire and for
 * logging; placement tracking is soft-state and never persisted.
 */
enum PlacementState: string
{
    case Placing = 'placing';

    case Started = 'started';

    case Failed = 'failed';

    case Stopped = 'stopped';
}
