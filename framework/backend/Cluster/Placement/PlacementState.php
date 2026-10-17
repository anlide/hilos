<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;

/**
 * Lifecycle state of a placed agent as the leader tracks it.
 *
 * {@see Placing}, {@see Unplaced} and {@see Refused} are leader-local: {@see Placing} means the
 * placement frame was sent to the target node but no status has come back yet — a wait that is
 * no longer open-ended, since `CLUSTER_PLACEMENT_ACK_TIMEOUT_MS` makes the leader ask the node
 * what it hosts (HIL-930) — {@see Unplaced} means no capable+online node could host new or lost
 * work. Failed and Unplaced share the leader's bounded retry queue (HIL-446), with joins and
 * released reservations waking only Unplaced. Refused means the agent claimed the right to
 * write an RT collection another node already holds (HIL-696). These three are not node status
 * reports. The other three are the states a node reports back in a {@see PeerAgentStatusDTO}:
 * {@see Started} when the local agent launched, {@see Failed} when it could not, and
 * {@see Stopped} when it was revoked. The value is string-backed for the wire and for logging;
 * placement tracking is soft-state and never persisted. Complete snapshots retain waiting
 * intent and cancellation across terms; inherited Placing/Started without a confirmed host
 * becomes Unplaced only after the rebuild/self-fence barrier.
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
     * failed over. Failed and stopped have released their host too. Refused is separately
     * terminal: unlike a failed or unplaced agent, it waits for a person to fix the declaration.
     *
     * @return bool True when no node hosts the agent in this state
     */
    public function runsNowhere(): bool
    {
        return !$this->hostsAgent();
    }

    /**
     * Whether a node currently hosts an agent in this state, as an address a frame may travel to.
     *
     * Only a start that is under way or has been reported occupies a node. Failed used to read
     * as an address because it is neither unplaced nor refused, so a frame for an agent the
     * node tried and could not start was sent to that same node (HIL-1041). Stopped is the
     * same kind of leftover: the node that last ran it is not a host any more.
     *
     * @return bool True when this state is an address
     */
    public function hostsAgent(): bool
    {
        return $this === self::Placing || $this === self::Started;
    }
}
