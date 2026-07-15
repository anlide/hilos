<?php

declare(strict_types=1);

namespace Hilos\Cluster\Consensus;

use Hilos\Cluster\Leadership;
use Hilos\Cluster\LeadershipObserver;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeLeavingDTO;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Cluster\PendingLeadership;

/**
 * Self-written raft-like consensus for the master set: leader election and
 * anti-split-brain, without a replicated log.
 *
 * This is the real {@see Leadership} that replaces {@see PendingLeadership}
 * on a clustered master. It is a flat in-process state machine on the daemon master:
 * {@see tick()} drives it once per loop iteration (from the peer server), the on*
 * handlers fold in consensus frames delivered over the existing peer mesh, and it
 * fires four transitions ({@see LeadershipObserver}) into the project surface — it
 * never gates or stops work itself, the neighbouring slices do.
 *
 * What it takes from raft: followers with a randomized election timeout become
 * candidates, a majority of the static master set elects a leader, and a leader
 * that cannot see a quorum steps down (so an isolated minority never keeps leading).
 * What it drops: no replicated log or state machine, and no vote log-recency check.
 * Term is monotonic and in-memory only; on restart it resets and is re-learned from
 * the first heartbeat or request-vote the node sees. Candidacy is gated on a live
 * quorum, so an isolated minority never inflates the term.
 */
final class ClusterCoordinator implements Leadership, ConsensusInspection
{
    /** @var ClusterConsensusConfig Captured consensus configuration for the local node */
    private ClusterConsensusConfig $config;

    /** @var ConsensusMesh Outbound port to the master peers and their liveness */
    private ConsensusMesh $mesh;

    /** @var LeadershipObserver Sink for the four leadership/quorum transitions */
    private LeadershipObserver $observer;

    /** @var ConsensusRole Current consensus role of the local node */
    private ConsensusRole $role = ConsensusRole::Follower;

    /** @var int Current election term, monotonic and in-memory only */
    private int $currentTerm = 0;

    /** @var ?string Candidate this node granted its vote to in the current term, or null */
    private ?string $votedFor = null;

    /** @var ?string Node id this node currently recognises as leader, or null when none */
    private ?string $currentLeaderId = null;

    /** @var float Microtime after which a follower/candidate starts (or restarts) an election */
    private float $electionDeadline = 0.0;

    /** @var float Microtime after which a leader sends its next heartbeat */
    private float $heartbeatDueAt = 0.0;

    /** @var list<string> Distinct grants received in the current term, including the self-vote */
    private array $votesReceived = [];

    /** @var bool Last computed quorum state, used to fire quorum transitions on the edge */
    private bool $lastQuorum = false;

    /** @var bool Whether the election timer should be refreshed on the next tick */
    private bool $deferElectionReset = true;

    /**
     * @param ClusterConsensusConfig $config Consensus configuration for the local node
     * @param ConsensusMesh $mesh Outbound port to the master peers and their liveness
     * @param LeadershipObserver $observer Sink for the leadership and quorum transitions
     */
    public function __construct(ClusterConsensusConfig $config, ConsensusMesh $mesh, LeadershipObserver $observer)
    {
        $this->config = $config;
        $this->mesh = $mesh;
        $this->observer = $observer;
    }

    /**
     * @return bool True when the local node currently holds cluster leadership
     */
    public function amLeader(): bool
    {
        return $this->role === ConsensusRole::Leader;
    }

    /**
     * @return ?string Node id of the current leader, or null when none is known
     */
    public function leaderId(): ?string
    {
        return $this->currentLeaderId;
    }

    /**
     * @return bool True when a quorum of the master set was visible at the last tick
     */
    public function hasQuorum(): bool
    {
        return $this->lastQuorum;
    }

    /**
     * @return int Current election term (monotonic, in-memory only)
     */
    public function term(): int
    {
        return $this->currentTerm;
    }

    /**
     * @return ConsensusRole Current consensus role of the local node
     */
    public function consensusRole(): ConsensusRole
    {
        return $this->role;
    }

    /**
     * Advances the consensus state machine by one master-loop iteration.
     *
     * Refreshes a deferred election timer, recomputes the quorum (firing the quorum
     * transition on an edge), then runs the role-specific work: a follower may start
     * an election, a candidate may retry or step down, a leader heartbeats or steps
     * down. Time is injected so the machine stays testable without touching the
     * clock. Non-blocking: the only outbound work is queuing frames on the mesh.
     *
     * @param float $now Current microtime, injected by the caller
     */
    public function tick(float $now): void
    {
        if ($this->deferElectionReset) {
            $this->electionDeadline = $now + $this->randomElectionTimeout();
            $this->deferElectionReset = false;
        }

        $hasQuorum = $this->computeQuorum();
        $this->applyQuorumTransition($hasQuorum);

        match ($this->role) {
            ConsensusRole::Follower => $this->tickFollower($now, $hasQuorum),
            ConsensusRole::Candidate => $this->tickCandidate($now, $hasQuorum),
            ConsensusRole::Leader => $this->tickLeader($now, $hasQuorum),
        };
    }

    /**
     * Handles a request-vote frame from a candidate.
     *
     * Adopts a newer term (stepping down first), then grants the vote when the term
     * matches and this node has not already voted for someone else this term.
     * Granting refreshes the election timer so the granter does not immediately
     * start a competing election. Always replies so the candidate can count grants.
     *
     * @param PeerRequestVoteDTO $frame Incoming request-vote frame
     */
    public function onRequestVote(PeerRequestVoteDTO $frame): void
    {
        if ($frame->term > $this->currentTerm) {
            $this->stepDownToFollower($frame->term);
        }

        $granted = false;
        if ($frame->term === $this->currentTerm
            && ($this->votedFor === null || $this->votedFor === $frame->candidateId)) {
            $this->votedFor = $frame->candidateId;
            $granted = true;
            $this->deferElectionReset = true;
        }

        $this->mesh->sendToMaster(
            $frame->candidateId,
            new PeerVoteReplyDTO($this->currentTerm, $granted, $this->config->selfNodeId),
        );
    }

    /**
     * Handles a vote-reply frame while campaigning.
     *
     * A newer term ends the candidacy; otherwise a fresh grant for the current term
     * is counted, and reaching a majority of the master set makes this node leader.
     *
     * @param PeerVoteReplyDTO $frame Incoming vote-reply frame
     */
    public function onVoteReply(PeerVoteReplyDTO $frame): void
    {
        if ($frame->term > $this->currentTerm) {
            $this->stepDownToFollower($frame->term);
            return;
        }

        if ($frame->term < $this->currentTerm || $this->role !== ConsensusRole::Candidate) {
            return;
        }

        if (!$frame->voteGranted || !in_array($frame->voterId, $this->config->masterSet, true)) {
            return;
        }

        if (in_array($frame->voterId, $this->votesReceived, true)) {
            return;
        }

        $this->votesReceived[] = $frame->voterId;
        if (count($this->votesReceived) >= $this->config->quorumSize) {
            $this->becomeLeader();
        }
    }

    /**
     * Handles a heartbeat frame from a leader.
     *
     * Ignores its own echo and stale terms; a newer term makes it step down and
     * adopt the term, an equal term makes a candidate yield. Either way it accepts
     * the sender as leader and refreshes its election timer.
     *
     * @param PeerHeartbeatDTO $frame Incoming heartbeat frame
     */
    public function onHeartbeat(PeerHeartbeatDTO $frame): void
    {
        if ($frame->leaderId === $this->config->selfNodeId || $frame->term < $this->currentTerm) {
            return;
        }

        if ($frame->term > $this->currentTerm) {
            $this->stepDownToFollower($frame->term);
        } elseif ($this->role !== ConsensusRole::Follower) {
            if ($this->role === ConsensusRole::Leader) {
                $this->observer->onLostLeadership($this->currentTerm);
            }
            $this->role = ConsensusRole::Follower;
        }

        $this->currentLeaderId = $frame->leaderId;
        $this->deferElectionReset = true;
    }

    /**
     * Fast-path leader-loss signal from the transport when a peer link drops.
     *
     * The membership registry marks a dropped peer offline instantly, well before
     * the election timeout would fire. When the peer that dropped is the current
     * leader, a follower expires its election timer at once so the next tick starts
     * an election (still gated on a quorum), rather than waiting out the timeout.
     *
     * @param string $nodeId Node id the transport just marked offline
     */
    public function noteNodeOffline(string $nodeId): void
    {
        if ($this->role === ConsensusRole::Follower && $nodeId === $this->currentLeaderId) {
            $this->currentLeaderId = null;
            $this->electionDeadline = 0.0;
        }
    }

    /**
     * Immediate-election trigger for a designated successor (raft TimeoutNow-style).
     *
     * A gracefully-leaving leader names its most-recently-heard follower in the
     * {@see PeerNodeLeavingDTO} frame; that follower calls this
     * to campaign at once, bypassing its randomized election timeout, while the other
     * followers keep waiting theirs. Only the successor short-circuits its timer, so it
     * wins cleanly with no split vote — the delay a broadcast-and-race would reintroduce.
     * Still gated on a live quorum by {@see tickFollower()}, so a successor stranded in a
     * minority never inflates the term. A no-op unless this node is a follower.
     */
    public function triggerDesignatedElection(): void
    {
        if ($this->role !== ConsensusRole::Follower) {
            return;
        }

        $this->currentLeaderId = null;
        $this->electionDeadline = 0.0;
        $this->deferElectionReset = false;
    }

    /**
     * Recomputes whether the online master set still forms a quorum.
     *
     * @return bool True when online master-set members meet the quorum size
     */
    private function computeQuorum(): bool
    {
        $onlineInSet = array_intersect($this->mesh->onlineMasterIds(), $this->config->masterSet);

        return count($onlineInSet) >= $this->config->quorumSize;
    }

    /**
     * Fires the quorum transition when the quorum state flipped since the last tick.
     *
     * @param bool $hasQuorum Freshly computed quorum state
     */
    private function applyQuorumTransition(bool $hasQuorum): void
    {
        if ($hasQuorum === $this->lastQuorum) {
            return;
        }

        $this->lastQuorum = $hasQuorum;
        if ($hasQuorum) {
            $this->observer->onQuorumGained();
        } else {
            $this->observer->onQuorumLost();
        }
    }

    /**
     * Follower tick: start an election once the timer expires, but only with quorum.
     *
     * Without a quorum the node never campaigns (anti-split-brain): it just keeps
     * pushing its election timer out so it waits a full timeout once quorum returns.
     *
     * @param float $now Current microtime
     * @param bool $hasQuorum Whether the master set forms a quorum this tick
     */
    private function tickFollower(float $now, bool $hasQuorum): void
    {
        if (!$hasQuorum) {
            if ($now >= $this->electionDeadline) {
                $this->electionDeadline = $now + $this->randomElectionTimeout();
            }
            return;
        }

        if ($now >= $this->electionDeadline) {
            $this->startElection($now);
        }
    }

    /**
     * Candidate tick: abandon the campaign on quorum loss, else retry on timeout.
     *
     * @param float $now Current microtime
     * @param bool $hasQuorum Whether the master set forms a quorum this tick
     */
    private function tickCandidate(float $now, bool $hasQuorum): void
    {
        if (!$hasQuorum) {
            $this->role = ConsensusRole::Follower;
            $this->votedFor = null;
            $this->votesReceived = [];
            $this->electionDeadline = $now + $this->randomElectionTimeout();
            return;
        }

        if ($now >= $this->electionDeadline) {
            $this->startElection($now);
        }
    }

    /**
     * Leader tick: step down on quorum loss, else heartbeat on the interval.
     *
     * @param float $now Current microtime
     * @param bool $hasQuorum Whether the master set forms a quorum this tick
     */
    private function tickLeader(float $now, bool $hasQuorum): void
    {
        if (!$hasQuorum) {
            $this->observer->onLostLeadership($this->currentTerm);
            $this->role = ConsensusRole::Follower;
            $this->currentLeaderId = null;
            $this->electionDeadline = $now + $this->randomElectionTimeout();
            return;
        }

        if ($now >= $this->heartbeatDueAt) {
            $this->mesh->broadcastToMasters(new PeerHeartbeatDTO($this->currentTerm, $this->config->selfNodeId));
            $this->heartbeatDueAt = $now + $this->config->heartbeatIntervalMs / 1000.0;
        }
    }

    /**
     * Opens a new election term: vote for self and solicit the master set.
     *
     * A single-master set (quorum of one) elects the node immediately from its own
     * vote; otherwise it awaits replies.
     *
     * @param float $now Current microtime
     */
    private function startElection(float $now): void
    {
        $this->currentTerm++;
        $this->role = ConsensusRole::Candidate;
        $this->votedFor = $this->config->selfNodeId;
        $this->votesReceived = [$this->config->selfNodeId];
        $this->currentLeaderId = null;
        $this->electionDeadline = $now + $this->randomElectionTimeout();

        $this->mesh->broadcastToMasters(new PeerRequestVoteDTO($this->currentTerm, $this->config->selfNodeId));

        if (count($this->votesReceived) >= $this->config->quorumSize) {
            $this->becomeLeader();
        }
    }

    /**
     * Promotes this node to leader and schedules an immediate heartbeat.
     */
    private function becomeLeader(): void
    {
        $this->role = ConsensusRole::Leader;
        $this->currentLeaderId = $this->config->selfNodeId;
        $this->heartbeatDueAt = 0.0;
        $this->observer->onBecameLeader($this->currentTerm);
    }

    /**
     * Steps down to follower and adopts a newer observed term.
     *
     * Firing {@see LeadershipObserver::onLostLeadership()} first when leadership was
     * actually held keeps the event tied to the term it was lost in, before the term
     * is advanced. Clears the vote so the newer term can be granted afresh.
     *
     * @param int $newTerm Newer term observed on the wire
     */
    private function stepDownToFollower(int $newTerm): void
    {
        if ($this->role === ConsensusRole::Leader) {
            $this->observer->onLostLeadership($this->currentTerm);
        }

        $this->role = ConsensusRole::Follower;
        $this->currentTerm = $newTerm;
        $this->votedFor = null;
        $this->votesReceived = [];
        $this->currentLeaderId = null;
        $this->deferElectionReset = true;
    }

    /**
     * Draws a randomized election timeout in seconds from the configured window.
     *
     * The jitter spreads simultaneous bootstraps so nodes do not all become
     * candidates in the same instant and split the vote.
     *
     * @return float Election timeout in seconds
     */
    private function randomElectionTimeout(): float
    {
        return mt_rand($this->config->electionTimeoutMinMs, $this->config->electionTimeoutMaxMs) / 1000.0;
    }
}
