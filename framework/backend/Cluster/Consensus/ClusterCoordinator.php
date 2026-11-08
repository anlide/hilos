<?php

declare(strict_types=1);

namespace Hilos\Cluster\Consensus;

use Closure;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\EventLoop\ReadyWork;
use WeakReference;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\LeadershipObserver;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeLeavingDTO;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Cluster\PendingLeadership;
use Hilos\Constants\TimeConstants;
use Hilos\HilosException;
use Hilos\Utils\Logger;

/**
 * Self-written raft-like consensus for the master set: leader election and
 * anti-split-brain, without a replicated log.
 *
 * This is the real {@see Leadership} that replaces {@see PendingLeadership}
 * on a clustered master. It is a flat in-process state machine on the daemon master:
 * Native deadlines cue a guarded peer-server portion; the on* handlers fold in
 * consensus frames delivered over the existing peer mesh, and it
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

    /** @var ?string Leader whose open WebSocket this node last heard about */
    private ?string $webSocketOpenLeaderId = null;

    /** @var bool Whether this node has opened its WebSocket; never reset after opening */
    private bool $webSocketOpen = false;

    /** @var float Process-clock seconds after which a follower/candidate starts (or restarts) an election */
    private float $electionDeadline = 0.0;

    /** @var float Process-clock seconds after which a leader sends its next heartbeat */
    private float $heartbeatDueAt = 0.0;

    /** @var list<string> Distinct grants received in the current term, including the self-vote */
    private array $votesReceived = [];

    /** @var bool Last computed quorum state, used to fire quorum transitions on the edge */
    private bool $lastQuorum = false;

    /** @var int Masters visible on the last tick, including this node */
    private int $lastOnlineMasterCount = 0;

    /** @var bool Whether the election timer should be refreshed on the next tick */
    private bool $deferElectionReset = true;

    /** @var ?string Why the next election starts, set by whoever forced the timer; null means the timer simply ran out */
    private ?string $pendingElectionReason = null;

    /** Local notification only; the daemon evaluates its gate in its own guarded portion. */
    private ?Closure $readinessWake = null;

    /** Independent process-local cue for effective leader, term and quorum changes. */
    private ?Closure $epochWake = null;

    private int $epochRevision = 0;

    private ?EventLoop $eventLoop = null;

    private ?EventLoopTimer $roleTimer = null;

    private ?ReadyWork $roleWork = null;

    private int $bindingGeneration = 0;

    private bool $roleReady = true;

    private bool $membershipDirty = true;

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
     * @return bool True when the current remote leader reported its WebSocket open
     */
    public function leaderWebSocketOpen(): bool
    {
        return $this->role !== ConsensusRole::Leader
            && $this->currentLeaderId !== null
            && $this->webSocketOpenLeaderId === $this->currentLeaderId;
    }

    /**
     * @param ?Closure(): void $wake Local readiness fact sink, null to release its binding
     */
    public function setReadinessWake(?Closure $wake): void
    {
        $this->readinessWake = $wake;
        $this->readinessWake?->__invoke();
    }

    /**
     * Supplies one initial fact; later callbacks only cue guarded work, never deliver it.
     * This binding survives the separate startup readiness binding's departure.
     *
     * @param Closure(): void $wake Process-local epoch fact sink
     */
    public function setEpochWake(Closure $wake): void
    {
        $this->epochWake = $wake;
        $wake();
    }

    /**
     * @param Closure(): void $wake Exact binding to release, leaving a replacement intact
     */
    public function removeEpochWake(Closure $wake): void
    {
        if ($this->epochWake === $wake) {
            $this->epochWake = null;
        }
    }

    /** @return int Effective tuple changes, including availability lost and regained between guarded portions */
    public function epochRevision(): int
    {
        return $this->epochRevision;
    }

    /** Records the local WebSocket opening for every later leader heartbeat. */
    public function noteWebSocketOpen(): void
    {
        $this->webSocketOpen = true;
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

    /** @return int Masters visible on the last tick, or zero before the first tick */
    public function onlineMasterCount(): int
    {
        return $this->lastOnlineMasterCount;
    }

    /** @return int Masters in the static set */
    public function masterSetSize(): int
    {
        return count($this->config->masterSet);
    }

    /** @return int Majority required by the static set */
    public function quorumSize(): int
    {
        return $this->config->quorumSize;
    }

    /**
     * Native callbacks only cue this owner; the peer server dispatches under its guard.
     * Rebinding retains the original role deadline and any unfinished reset.
     *
     * @param EventLoop $loop Master's existing native base
     */
    public function attachEventLoop(EventLoop $loop): void
    {
        if ($this->eventLoop === $loop) {
            return;
        }
        $this->detachEventLoop();
        $this->eventLoop = $loop;
        $generation = $this->bindingGeneration;
        $owner = WeakReference::create($this);
        $cue = static function () use ($owner, $generation): void {
            $coordinator = $owner->get();
            if ($coordinator !== null && $coordinator->bindingGeneration === $generation) {
                $coordinator->roleReady = true;
            }
        };
        $this->roleTimer = $loop->createTimer($cue);
        $this->roleWork = $loop->createReadyWork($cue);
        $this->membershipDirty = true;
        $this->markRoleReady();
    }

    /** Releases exact native and queued generations without renewing the domain deadline. */
    public function detachEventLoop(): void
    {
        ++$this->bindingGeneration;
        $this->roleTimer?->close();
        $this->roleTimer = null;
        $this->roleWork?->close();
        $this->roleWork = null;
        $this->eventLoop = null;
    }

    /** @return bool Initial/reset work or a due role, never a waiting follower or pending vote */
    public function hasReadyWork(): bool
    {
        return $this->eventLoop !== null && $this->roleReady;
    }

    /**
     * @throws HilosException When native arming or the project's leadership duties fail
     */
    public function dispatchReady(): void
    {
        if (!$this->hasReadyWork()) {
            return;
        }
        $this->advanceRole(self::monotonicNow(), $this->membershipDirty);
    }

    /**
     * Records each actual registry mutation inside the peer input guard, before its
     * membership observer and before subsequent frames. Observing loss immediately
     * preserves loss/return edges even when the next role dispatch sees the same tuple.
     * No election or heartbeat is sent here; quorum loss can only retire a role.
     *
     * @throws HilosException When a quorum or leadership-loss observer fails
     */
    public function noteMembershipChanged(): void
    {
        $previousCount = $this->lastOnlineMasterCount;
        $observedTerm = $this->currentTerm;
        $observedCount = $this->refreshQuorum(true);
        if ($previousCount === $observedCount) {
            return;
        }
        if ($observedCount < $this->config->quorumSize
            && $this->currentTerm === $observedTerm && $this->role !== ConsensusRole::Follower) {
            $now = self::monotonicNow();
            if ($this->role === ConsensusRole::Leader) {
                $this->tickLeader($now, false);
            } else {
                $this->tickCandidate($now, false);
            }
            $this->deferElectionReset = false;
        }
    }

    /**
     * Explicit state-machine observation for deterministic callers. Production uses
     * dispatchReady() and monotonic seconds; persisted and wire moments stay epoch.
     *
     * @param float $now Caller-selected process-clock seconds
     * @throws HilosException When native arming or the project's leadership duties fail
     */
    public function tick(float $now): void
    {
        $this->advanceRole($now, true);
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
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onRequestVote(PeerRequestVoteDTO $frame): void
    {
        if ($frame->term > $this->currentTerm) {
            $this->stepDownToFollower($frame->term, $frame->candidateId);
        }

        $granted = false;
        if ($frame->term === $this->currentTerm
            && ($this->votedFor === null || $this->votedFor === $frame->candidateId)) {
            $this->votedFor = $frame->candidateId;
            $granted = true;
            $this->deferElectionReset = true;
            $this->markRoleReady();
            Logger::info("Consensus: granted the vote to node '{$frame->candidateId}' in term {$this->currentTerm}");
        } elseif ($frame->term < $this->currentTerm) {
            Logger::info("Consensus: refused the vote to node '{$frame->candidateId}' in term {$frame->term}:"
                . " stale, this node is in term {$this->currentTerm}");
        } else {
            Logger::info("Consensus: refused the vote to node '{$frame->candidateId}' in term {$frame->term}:"
                . " already voted for '{$this->votedFor}' this term");
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
     * @throws HilosException When this node's leadership duties fail while gaining or losing a term
     */
    public function onVoteReply(PeerVoteReplyDTO $frame): void
    {
        if ($frame->term > $this->currentTerm) {
            $this->stepDownToFollower($frame->term, $frame->voterId);
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
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onHeartbeat(PeerHeartbeatDTO $frame): void
    {
        if ($frame->leaderId === $this->config->selfNodeId || $frame->term < $this->currentTerm) {
            return;
        }

        $previousLeader = $this->currentLeaderId;
        $previousTerm = $this->currentTerm;
        $previousOpen = $this->leaderWebSocketOpen();
        $wasLeader = $this->amLeader();
        if ($frame->term > $this->currentTerm) {
            $this->stepDownToFollower($frame->term, $frame->leaderId);
        } elseif ($this->role !== ConsensusRole::Follower) {
            if ($this->role === ConsensusRole::Leader) {
                Logger::info("Consensus: lost leadership held in term {$this->currentTerm}:"
                    . " another leader '{$frame->leaderId}' heartbeats in the same term");
                $this->observer->onLostLeadership($this->currentTerm);
            }
            $this->role = ConsensusRole::Follower;
        }

        $this->currentLeaderId = $frame->leaderId;
        $this->webSocketOpenLeaderId = $frame->webSocketOpen ? $frame->leaderId : null;
        $this->deferElectionReset = true;
        $this->markRoleReady();
        if ($previousLeader !== $this->currentLeaderId || $previousOpen !== $this->leaderWebSocketOpen() || $wasLeader) {
            $this->readinessWake?->__invoke();
        }
        if ($previousLeader !== $this->currentLeaderId || $previousTerm !== $this->currentTerm) {
            $this->publishEpochChange();
        }
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
            Logger::info("Consensus: leader '{$nodeId}' went offline; election timer expired at once");
            $this->currentLeaderId = null;
            $this->electionDeadline = 0.0;
            $this->deferElectionReset = false;
            $this->pendingElectionReason = "leader '{$nodeId}' went offline";
            $this->markRoleReady();
            $this->readinessWake?->__invoke();
            $this->publishEpochChange();
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

        Logger::info('Consensus: named successor by the leaving leader; campaigning at once');
        $hadLeader = $this->currentLeaderId !== null;
        $this->currentLeaderId = null;
        $this->electionDeadline = 0.0;
        $this->deferElectionReset = false;
        $this->pendingElectionReason = 'named successor by the leaving leader';
        $this->markRoleReady();
        $this->readinessWake?->__invoke();
        if ($hadLeader) {
            $this->publishEpochChange();
        }
    }

    /**
     * @param float $now Process-clock seconds for this role portion
     * @param bool $observeMembership Initial binding or explicit deterministic observation
     * @throws HilosException When native arming or the project's leadership duties fail
     */
    private function advanceRole(float $now, bool $observeMembership): void
    {
        $this->roleReady = false;
        $this->roleWork?->cancel();
        if ($this->deferElectionReset) {
            $this->electionDeadline = $now + $this->randomElectionTimeout();
            $this->deferElectionReset = false;
            $this->pendingElectionReason = null;
        }
        if ($observeMembership) {
            $this->refreshQuorum();
        }
        match ($this->role) {
            ConsensusRole::Follower => $this->tickFollower($now, $this->lastQuorum),
            ConsensusRole::Candidate => $this->tickCandidate($now, $this->lastQuorum),
            ConsensusRole::Leader => $this->tickLeader($now, $this->lastQuorum),
        };
        $this->roleTimer?->armAt($this->role === ConsensusRole::Leader ? $this->heartbeatDueAt : $this->electionDeadline);
    }

    /**
     * @param bool $cueRole True for a source mutation, before invoking reentrant observers
     * @return int Count observed before any observer changes membership again
     * @throws HilosException When the quorum observer fails
     */
    private function refreshQuorum(bool $cueRole = false): int
    {
        $this->membershipDirty = false;
        $onlineCount = count(array_intersect($this->mesh->onlineMasterIds(), $this->config->masterSet));
        if ($cueRole && $onlineCount !== $this->lastOnlineMasterCount) {
            $this->markRoleReady();
        }
        $this->lastOnlineMasterCount = $onlineCount;
        $this->applyQuorumTransition($onlineCount >= $this->config->quorumSize, $onlineCount);
        return $onlineCount;
    }

    private function markRoleReady(): void
    {
        $this->roleReady = true;
        $this->roleWork?->markReady();
    }

    /** @return float Monotonic seconds for process-local consensus waits */
    private static function monotonicNow(): float
    {
        return hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
    }

    /**
     * Fires the quorum transition when the quorum state flipped since the last tick.
     *
     * @param bool $hasQuorum Freshly computed quorum state
     * @param int $onlineCount Master-set members online this tick, the count the quorum state was computed from
     * @throws HilosException When the quorum observer fails
     */
    private function applyQuorumTransition(bool $hasQuorum, int $onlineCount): void
    {
        if ($hasQuorum === $this->lastQuorum) {
            return;
        }

        $this->lastQuorum = $hasQuorum;
        $this->readinessWake?->__invoke();
        $this->publishEpochChange();
        $edge = $hasQuorum ? 'gained' : 'lost';
        Logger::info("Consensus: quorum {$edge}: {$onlineCount} of the " . count($this->config->masterSet)
            . "-node master set online (quorum {$this->config->quorumSize})");
        if ($hasQuorum) {
            $this->observer->onQuorumGained();
        } else {
            $this->observer->onQuorumLost();
        }
    }

    /**
     * Follower tick: start an election once the timer expires, but only with quorum.
     *
     * Without a quorum an expired deadline rolls to another randomized timeout,
     * without changing the term. Quorum return keeps that original deadline.
     *
     * @param float $now Current process-clock seconds
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
            $this->startElection($now, 'election timeout expired');
        }
    }

    /**
     * Candidate tick: abandon the campaign on quorum loss, else retry on timeout.
     *
     * @param float $now Current process-clock seconds
     * @param bool $hasQuorum Whether the master set forms a quorum this tick
     */
    private function tickCandidate(float $now, bool $hasQuorum): void
    {
        if (!$hasQuorum) {
            Logger::info("Consensus: abandoning candidacy in term {$this->currentTerm}: quorum lost");
            $this->role = ConsensusRole::Follower;
            $this->votedFor = null;
            $this->votesReceived = [];
            $this->electionDeadline = $now + $this->randomElectionTimeout();
            $this->readinessWake?->__invoke();
            return;
        }

        if ($now >= $this->electionDeadline) {
            $this->startElection($now, "no majority in term {$this->currentTerm}, retrying");
        }
    }

    /**
     * Leader tick: step down on quorum loss, else heartbeat on the interval.
     *
     * @param float $now Current process-clock seconds
     * @param bool $hasQuorum Whether the master set forms a quorum this tick
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    private function tickLeader(float $now, bool $hasQuorum): void
    {
        if (!$hasQuorum) {
            Logger::info("Consensus: lost leadership held in term {$this->currentTerm}: quorum lost");
            $this->observer->onLostLeadership($this->currentTerm);
            $this->role = ConsensusRole::Follower;
            $this->currentLeaderId = null;
            $this->electionDeadline = $now + $this->randomElectionTimeout();
            $this->readinessWake?->__invoke();
            $this->publishEpochChange();
            return;
        }

        if ($now >= $this->heartbeatDueAt) {
            $this->mesh->broadcastToMasters(new PeerHeartbeatDTO(
                $this->currentTerm,
                $this->config->selfNodeId,
                $this->webSocketOpen,
            ));
            $this->heartbeatDueAt = $now + $this->config->heartbeatIntervalMs / TimeConstants::MS_PER_SECOND;
        }
    }

    /**
     * Opens a new election term: vote for self and solicit the master set.
     *
     * A single-master set (quorum of one) elects the node immediately from its own
     * vote; otherwise it awaits replies. A reason left by a path that forced the
     * timer wins over the caller's own, and is spent here.
     *
     * @param float $now Current process-clock seconds
     * @param string $reason Why this election starts when no forcing path left a reason of its own
     */
    private function startElection(float $now, string $reason): void
    {
        $previousTerm = $this->currentTerm;
        $this->currentTerm++;
        Logger::info("Consensus: becoming candidate in term {$this->currentTerm} (was {$previousTerm}): "
            . ($this->pendingElectionReason ?? $reason));
        $this->pendingElectionReason = null;
        $this->role = ConsensusRole::Candidate;
        $this->votedFor = $this->config->selfNodeId;
        $this->votesReceived = [$this->config->selfNodeId];
        $this->currentLeaderId = null;
        $this->electionDeadline = $now + $this->randomElectionTimeout();
        $this->readinessWake?->__invoke();
        $this->publishEpochChange();

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
        $this->markRoleReady();
        $this->readinessWake?->__invoke();
        $this->publishEpochChange();
        Logger::info("Consensus: won term {$this->currentTerm} with " . count($this->votesReceived) . ' votes of the '
            . count($this->config->masterSet) . "-node master set (quorum {$this->config->quorumSize}), now leader");
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
     * @param string $fromNodeId Node id of the frame that carried the newer term
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    private function stepDownToFollower(int $newTerm, string $fromNodeId): void
    {
        $adopted = "Consensus: term {$newTerm} adopted from node '{$fromNodeId}' (was {$this->currentTerm})";
        if ($this->role !== ConsensusRole::Follower) {
            $adopted .= ", role follower (was {$this->role->value})";
        }
        Logger::info($adopted);
        if ($this->role === ConsensusRole::Leader) {
            Logger::info("Consensus: lost leadership held in term {$this->currentTerm}: term {$newTerm} seen from node '{$fromNodeId}'");
            $this->observer->onLostLeadership($this->currentTerm);
        }

        $this->role = ConsensusRole::Follower;
        $this->currentTerm = $newTerm;
        $this->votedFor = null;
        $this->votesReceived = [];
        $this->currentLeaderId = null;
        $this->deferElectionReset = true;
        $this->markRoleReady();
        $this->readinessWake?->__invoke();
        $this->publishEpochChange();
    }

    /** Publishes only mutations of leader, term or quorum; ordinary heartbeat activity is silent. */
    private function publishEpochChange(): void
    {
        ++$this->epochRevision;
        $this->epochWake?->__invoke();
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
        return mt_rand($this->config->electionTimeoutMinMs, $this->config->electionTimeoutMaxMs) / TimeConstants::MS_PER_SECOND;
    }
}
