<?php

declare(strict_types=1);

namespace Hilos\Cluster\Consensus;

/**
 * Consensus role of the local master node in the raft-like election.
 *
 * A master node is always exactly one of these. A follower listens for a leader's
 * heartbeat; a candidate has timed out and is soliciting votes; a leader won a
 * majority and asserts its term by heartbeat. Slaves take no part in consensus and
 * never hold a role. The value is string-backed for logging and diagnostics; the
 * role is never persisted (coordination state is in-memory only).
 */
enum ConsensusRole: string
{
    case Follower = 'follower';

    case Candidate = 'candidate';

    case Leader = 'leader';
}
