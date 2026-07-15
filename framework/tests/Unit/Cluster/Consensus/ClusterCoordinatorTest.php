<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Consensus;

use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\Consensus\ConsensusMesh;
use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\LeadershipObserver;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the raft-like consensus state machine (HIL-339).
 *
 * The machine is driven with an injected clock and a fake mesh/observer, so
 * elections, quorum transitions, and step-down are exercised without sockets,
 * sleeps, or a mocked global clock. Timings use a collapsed window (min == max) so
 * the randomized election timeout is deterministic at exactly 1.0s.
 */
final class ClusterCoordinatorTest extends TestCase
{
    /** @var list<string> The three-master expected set: quorum is 2. */
    private const array MASTER_SET = ['a', 'b', 'c'];

    public function testFollowerWithQuorumWinsElectionOnMajority(): void
    {
        $mesh = $this->mesh(self::MASTER_SET);
        $observer = new RecordingLeadershipObserver();
        $coordinator = new ClusterCoordinator($this->config(), $mesh, $observer);

        $coordinator->tick(0.0);
        $this->assertSame(1, $observer->quorumGained);
        $this->assertFalse($coordinator->amLeader());

        $coordinator->tick(0.5);
        $this->assertCount(0, $mesh->requestVotes(), 'No election before the timeout expires');

        $coordinator->tick(1.0);
        $votes = $mesh->requestVotes();
        $this->assertCount(1, $votes);
        $this->assertSame(1, $votes[0]->term);

        $coordinator->onVoteReply(new PeerVoteReplyDTO(1, true, 'b'));
        $this->assertTrue($coordinator->amLeader());
        $this->assertSame([1], $observer->becameLeader);
        $this->assertSame('a', $coordinator->leaderId());

        $coordinator->tick(1.1);
        $this->assertNotEmpty($mesh->heartbeats(), 'A fresh leader heartbeats at once');
    }

    public function testMinorityWithoutQuorumNeverCampaigns(): void
    {
        $mesh = $this->mesh(['a']);
        $observer = new RecordingLeadershipObserver();
        $coordinator = new ClusterCoordinator($this->config(), $mesh, $observer);

        $coordinator->tick(0.0);
        $coordinator->tick(2.0);
        $coordinator->tick(4.0);

        $this->assertCount(0, $mesh->requestVotes());
        $this->assertFalse($coordinator->amLeader());
        $this->assertSame(0, $observer->quorumGained);
    }

    public function testGrantsAtMostOneVotePerTerm(): void
    {
        $mesh = $this->mesh(self::MASTER_SET);
        $coordinator = new ClusterCoordinator($this->config(), $mesh, new RecordingLeadershipObserver());

        $coordinator->onRequestVote(new PeerRequestVoteDTO(1, 'b'));
        $first = $mesh->lastVoteReply();
        $this->assertTrue($first->voteGranted);
        $this->assertSame('b', $mesh->lastUnicastNodeId());

        $coordinator->onRequestVote(new PeerRequestVoteDTO(1, 'c'));
        $second = $mesh->lastVoteReply();
        $this->assertFalse($second->voteGranted, 'A second candidate in the same term is denied');
        $this->assertSame('c', $mesh->lastUnicastNodeId());
    }

    public function testCandidateYieldsToAnEqualTermLeaderHeartbeat(): void
    {
        $mesh = $this->mesh(self::MASTER_SET);
        $observer = new RecordingLeadershipObserver();
        $coordinator = new ClusterCoordinator($this->config(), $mesh, $observer);

        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $this->assertCount(1, $mesh->requestVotes(), 'Precondition: node is now a candidate');

        $coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'b'));

        $this->assertFalse($coordinator->amLeader());
        $this->assertSame('b', $coordinator->leaderId());
        $this->assertSame([], $observer->lostLeadership, 'A candidate never held leadership to lose');
    }

    public function testLeaderStepsDownOnNewerTerm(): void
    {
        $mesh = $this->mesh(self::MASTER_SET);
        $observer = new RecordingLeadershipObserver();
        $coordinator = $this->electedLeader($mesh, $observer);

        $coordinator->onHeartbeat(new PeerHeartbeatDTO(5, 'b'));

        $this->assertFalse($coordinator->amLeader());
        $this->assertSame([1], $observer->lostLeadership);
        $this->assertSame('b', $coordinator->leaderId());
        $this->assertSame(0, $observer->quorumLost, 'A leader change with a live majority does not stop survivors');
    }

    public function testDesignatedSuccessorCampaignsWithoutWaitingTheTimeout(): void
    {
        $mesh = $this->mesh(self::MASTER_SET);
        $coordinator = new ClusterCoordinator($this->config(), $mesh, new RecordingLeadershipObserver());

        // Follow leader 'b', then it announces a graceful leave naming this node successor.
        $coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'b'));
        $coordinator->tick(0.0);
        $this->assertSame('b', $coordinator->leaderId());
        $this->assertCount(0, $mesh->requestVotes());

        $coordinator->triggerDesignatedElection();
        $coordinator->tick(0.001);

        $votes = $mesh->requestVotes();
        $this->assertCount(1, $votes, 'The designated successor campaigns at once');
        $this->assertSame(2, $votes[0]->term);
        $this->assertNull($coordinator->leaderId(), 'The leaving leader is cleared before the campaign');
    }

    public function testDesignatedElectionIsIgnoredWhenNotAFollower(): void
    {
        $mesh = $this->mesh(self::MASTER_SET);
        $coordinator = $this->electedLeader($mesh, new RecordingLeadershipObserver());
        $before = count($mesh->requestVotes());

        // A stray designation reaching the sitting leader must not restart an election.
        $coordinator->triggerDesignatedElection();
        $coordinator->tick(1.2);

        $this->assertTrue($coordinator->amLeader());
        $this->assertCount($before, $mesh->requestVotes());
    }

    public function testLeaderStepsDownWhenQuorumIsLost(): void
    {
        $mesh = $this->mesh(self::MASTER_SET);
        $observer = new RecordingLeadershipObserver();
        $coordinator = $this->electedLeader($mesh, $observer);

        // A partition drops two peers: only the local node stays online.
        $mesh->online = ['a'];
        $coordinator->tick(2.0);

        $this->assertFalse($coordinator->amLeader());
        $this->assertSame([1], $observer->lostLeadership);
        $this->assertSame(1, $observer->quorumLost);
    }

    public function testQuorumTransitionsFireOnTheEdge(): void
    {
        $mesh = $this->mesh(['a']);
        $observer = new RecordingLeadershipObserver();
        $coordinator = new ClusterCoordinator($this->config(), $mesh, $observer);

        $coordinator->tick(0.0);
        $this->assertSame(0, $observer->quorumGained);

        $mesh->online = ['a', 'b'];
        $coordinator->tick(0.001);
        $this->assertSame(1, $observer->quorumGained);
        $this->assertSame(0, $observer->quorumLost);

        $mesh->online = ['a'];
        $coordinator->tick(0.002);
        $this->assertSame(1, $observer->quorumLost);
    }

    public function testLeaderLossFastPathElectsWithoutWaitingTheTimeout(): void
    {
        $mesh = $this->mesh(self::MASTER_SET);
        $coordinator = new ClusterCoordinator($this->config(), $mesh, new RecordingLeadershipObserver());

        $coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'b'));
        $coordinator->tick(0.0);
        $this->assertSame('b', $coordinator->leaderId());
        $this->assertCount(0, $mesh->requestVotes());

        // The leader's link drops: the transport reports it offline instantly.
        $coordinator->noteNodeOffline('b');
        $coordinator->tick(0.001);

        $votes = $mesh->requestVotes();
        $this->assertCount(1, $votes, 'Election starts at once instead of waiting a full timeout');
        $this->assertSame(2, $votes[0]->term);
    }

    public function testTermAndRoleAreReadableThroughTheInspectionSeam(): void
    {
        $mesh = $this->mesh(self::MASTER_SET);
        $coordinator = new ClusterCoordinator($this->config(), $mesh, new RecordingLeadershipObserver());

        $this->assertSame(0, $coordinator->term());
        $this->assertSame(ConsensusRole::Follower, $coordinator->consensusRole());

        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $coordinator->onVoteReply(new PeerVoteReplyDTO(1, true, 'b'));

        $this->assertTrue($coordinator->amLeader());
        $this->assertSame(1, $coordinator->term());
        $this->assertSame(ConsensusRole::Leader, $coordinator->consensusRole());
    }

    /**
     * Drives a fresh coordinator through one full election into leadership.
     *
     * @param FakeConsensusMesh $mesh Mesh to drive
     * @param RecordingLeadershipObserver $observer Observer to record into
     * @return ClusterCoordinator Coordinator now holding leadership in term 1
     */
    private function electedLeader(FakeConsensusMesh $mesh, RecordingLeadershipObserver $observer): ClusterCoordinator
    {
        $coordinator = new ClusterCoordinator($this->config(), $mesh, $observer);
        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $coordinator->onVoteReply(new PeerVoteReplyDTO(1, true, 'b'));
        $this->assertTrue($coordinator->amLeader(), 'Precondition: node is leader');

        return $coordinator;
    }

    /**
     * @param list<string> $online Online master node ids
     * @return FakeConsensusMesh Fake mesh seeded with the given online set
     */
    private function mesh(array $online): FakeConsensusMesh
    {
        $mesh = new FakeConsensusMesh();
        $mesh->online = $online;

        return $mesh;
    }

    /**
     * @return ClusterConsensusConfig Config for master 'a' over a 3-node set, quorum 2, 1.0s timeout
     */
    private function config(): ClusterConsensusConfig
    {
        return new ClusterConsensusConfig('a', self::MASTER_SET, 2, 1000, 1000, 100);
    }
}

/**
 * Fake mesh capturing what the coordinator sends and reporting a settable liveness.
 */
final class FakeConsensusMesh implements ConsensusMesh
{
    /** @var list<string> Online master node ids returned to the coordinator */
    public array $online = [];

    /** @var list<PeerDTO> Frames broadcast to the master set */
    public array $broadcasts = [];

    /** @var list<array{nodeId: string, frame: PeerDTO}> Frames unicast to a single master */
    public array $unicasts = [];

    public function onlineMasterIds(): array
    {
        return $this->online;
    }

    public function broadcastToMasters(PeerDTO $frame): void
    {
        $this->broadcasts[] = $frame;
    }

    public function sendToMaster(string $nodeId, PeerDTO $frame): void
    {
        $this->unicasts[] = ['nodeId' => $nodeId, 'frame' => $frame];
    }

    /**
     * @return list<PeerRequestVoteDTO> Request-vote frames broadcast so far
     */
    public function requestVotes(): array
    {
        return array_values(array_filter(
            $this->broadcasts,
            static fn(PeerDTO $frame): bool => $frame instanceof PeerRequestVoteDTO,
        ));
    }

    /**
     * @return list<PeerHeartbeatDTO> Heartbeat frames broadcast so far
     */
    public function heartbeats(): array
    {
        return array_values(array_filter(
            $this->broadcasts,
            static fn(PeerDTO $frame): bool => $frame instanceof PeerHeartbeatDTO,
        ));
    }

    /**
     * @return PeerVoteReplyDTO The most recent vote-reply unicast
     */
    public function lastVoteReply(): PeerVoteReplyDTO
    {
        $frame = $this->unicasts[array_key_last($this->unicasts)]['frame'];
        \assert($frame instanceof PeerVoteReplyDTO);

        return $frame;
    }

    /**
     * @return string Node id targeted by the most recent unicast
     */
    public function lastUnicastNodeId(): string
    {
        return $this->unicasts[array_key_last($this->unicasts)]['nodeId'];
    }
}

/**
 * Leadership observer that records every transition for assertions.
 */
final class RecordingLeadershipObserver implements LeadershipObserver
{
    /** @var list<int> Terms in which leadership was won */
    public array $becameLeader = [];

    /** @var list<int> Terms in which leadership was lost */
    public array $lostLeadership = [];

    /** @var int Count of quorum-gained transitions */
    public int $quorumGained = 0;

    /** @var int Count of quorum-lost transitions */
    public int $quorumLost = 0;

    public function onBecameLeader(int $term): void
    {
        $this->becameLeader[] = $term;
    }

    public function onLostLeadership(int $term): void
    {
        $this->lostLeadership[] = $term;
    }

    public function onQuorumGained(): void
    {
        $this->quorumGained++;
    }

    public function onQuorumLost(): void
    {
        $this->quorumLost++;
    }
}
