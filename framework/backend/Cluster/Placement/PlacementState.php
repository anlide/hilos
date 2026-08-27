<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;

/**
 * Lifecycle state of a placed agent as the leader tracks it.
 *
 * {@see Placing}, {@see Unplaced} and {@see Refused} are leader-local: {@see Placing} means the
 * placement frame was sent to the target node but no status has come back yet, {@see Unplaced}
 * means failover found no capable+online node to host the agent, so it is degraded and
 * awaiting a capable node to join, and {@see Refused} means the agent claimed the right to
 * write an RT collection another node already holds (HIL-696). None of the three travels the
 * wire. The other three are the states a node reports back in a {@see PeerAgentStatusDTO}:
 * {@see Started} when the local agent launched, {@see Failed} when it could not, and
 * {@see Stopped} when it was revoked. The value is string-backed for the wire and for logging;
 * placement tracking is soft-state and never persisted.
 *
 * {@see Refused} is the one state nothing brings an agent out of. It is not a failure to retry:
 * the configuration declares two owners of one collection, and re-placing the loser somewhere
 * else would only move the split. The mark dies with the term, so a fresh leader re-derives it
 * from the reports and says so again — which is also how an administrator who has not fixed the
 * declaration is told a second time.
 */
enum PlacementState: string
{
    case Placing = 'placing';

    case Started = 'started';

    case Failed = 'failed';

    case Stopped = 'stopped';

    case Unplaced = 'unplaced';

    case Refused = 'refused';

    /**
     * Whether an agent in this state is running on no node at all.
     *
     * The question every consumer of the view asks, and the reason it has a name: an agent
     * nothing hosts must not be addressed, must not be published as placed, and must not be
     * failed over. Degraded and refused answer it alike while meaning opposite things — one is
     * waiting for a node, the other for a person.
     *
     * @return bool True when no node hosts the agent in this state
     */
    public function runsNowhere(): bool
    {
        return $this === self::Unplaced || $this === self::Refused;
    }
}
