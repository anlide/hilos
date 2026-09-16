<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Consensus;

use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\Consensus\ConsensusMesh;
use Hilos\Cluster\LeadershipObserver;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The consensus machine narrates its own transitions into the node's log (HIL-442).
 *
 * The lines are the whole contract here: whoever debugs a cluster run reads a re-election
 * back from one daemon log, with no inspect call. So every assertion is on the exact text,
 * read back from a temporary log file, and the silences are asserted as explicit absences -
 * a heartbeat or a vote reply that changes nothing must add no line at all.
 *
 * Timings use a collapsed window (min == max), so the election timeout is exactly 1.0s.
 */
final class ClusterCoordinatorLogTest extends TestCase
{
    /** @var list<string> The three-master expected set: quorum is 2 */
    private const array MASTER_SET = ['a', 'b', 'c'];

    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

    /** Byte offset of the log up to which lines were already handed to an assertion */
    private int $readOffset = 0;

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-consensus-log');
        $this->readOffset = 0;
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testAWonElectionIsNarratedFromQuorumToLeadership(): void
    {
        $coordinator = $this->coordinator(new ConsensusLogTestMesh(self::MASTER_SET));

        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $coordinator->onVoteReply(new PeerVoteReplyDTO(1, true, 'b'));

        $this->assertSame([
            'Consensus: quorum gained: 3 of the 3-node master set online (quorum 2)',
            'Consensus: becoming candidate in term 1 (was 0): election timeout expired',
            'Consensus: won term 1 with 2 votes of the 3-node master set (quorum 2), now leader',
        ], $this->newLines());
    }

    public function testAGrantedVoteAndBothRefusalsCarryTheirReasons(): void
    {
        $coordinator = $this->coordinator(new ConsensusLogTestMesh(self::MASTER_SET));

        $coordinator->onRequestVote(new PeerRequestVoteDTO(1, 'b'));
        $coordinator->onRequestVote(new PeerRequestVoteDTO(1, 'c'));
        $coordinator->onRequestVote(new PeerRequestVoteDTO(0, 'c'));

        $this->assertSame([
            "Consensus: term 1 adopted from node 'b' (was 0)",
            "Consensus: granted the vote to node 'b' in term 1",
            "Consensus: refused the vote to node 'c' in term 1: already voted for 'b' this term",
            "Consensus: refused the vote to node 'c' in term 0: stale, this node is in term 1",
        ], $this->newLines());
    }

    public function testQuorumLossNamesTheCountAndTheLostLeadership(): void
    {
        $mesh = new ConsensusLogTestMesh(self::MASTER_SET);
        $coordinator = $this->electedLeader($mesh);

        $mesh->online = ['a'];
        $coordinator->tick(1.5);

        $this->assertSame([
            'Consensus: quorum lost: 1 of the 3-node master set online (quorum 2)',
            'Consensus: lost leadership held in term 1: quorum lost',
        ], $this->newLines());
    }

    public function testANewerTermNamesTheNodeThatCarriedIt(): void
    {
        $coordinator = $this->electedLeader(new ConsensusLogTestMesh(self::MASTER_SET));

        $coordinator->onHeartbeat(new PeerHeartbeatDTO(3, 'c'));

        $this->assertSame([
            "Consensus: term 3 adopted from node 'c' (was 1), role follower (was leader)",
            "Consensus: lost leadership held in term 1: term 3 seen from node 'c'",
        ], $this->newLines());
    }

    public function testASecondLeaderInTheSameTermEndsLeadership(): void
    {
        $coordinator = $this->electedLeader(new ConsensusLogTestMesh(self::MASTER_SET));

        $coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'b'));

        $this->assertSame(
            ["Consensus: lost leadership held in term 1: another leader 'b' heartbeats in the same term"],
            $this->newLines(),
        );
    }

    public function testALeaderGoingOfflineIsTheReasonOfTheElectionItForces(): void
    {
        $mesh = new ConsensusLogTestMesh(self::MASTER_SET);
        $coordinator = $this->followerOf($mesh, 'b');

        $coordinator->noteNodeOffline('b');
        $mesh->online = ['a', 'c'];
        $coordinator->tick(0.6);

        $this->assertSame([
            "Consensus: leader 'b' went offline; election timer expired at once",
            "Consensus: becoming candidate in term 2 (was 1): leader 'b' went offline",
        ], $this->newLines());
    }

    public function testANamedSuccessorIsTheReasonOfTheElectionItForces(): void
    {
        $coordinator = $this->followerOf(new ConsensusLogTestMesh(self::MASTER_SET), 'b');

        $coordinator->triggerDesignatedElection();
        $coordinator->tick(0.6);

        $this->assertSame([
            'Consensus: named successor by the leaving leader; campaigning at once',
            'Consensus: becoming candidate in term 2 (was 1): named successor by the leaving leader',
        ], $this->newLines());
    }

    public function testAForcedReasonIsVoidedByALeaderHeardBeforeTheElection(): void
    {
        $coordinator = $this->followerOf(new ConsensusLogTestMesh(self::MASTER_SET), 'b');

        $coordinator->noteNodeOffline('b');
        $coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'c'));
        $coordinator->tick(0.6);
        $this->newLines();

        $coordinator->tick(1.7);

        $this->assertSame(
            ['Consensus: becoming candidate in term 2 (was 1): election timeout expired'],
            $this->newLines(),
            'The timer the heartbeat refreshed ran out on its own, so the departed leader is no longer the cause',
        );
    }

    public function testACandidacyWithoutMajorityRetriesAndIsAbandonedOnQuorumLoss(): void
    {
        $mesh = new ConsensusLogTestMesh(self::MASTER_SET);
        $coordinator = $this->coordinator($mesh);
        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $this->newLines();

        $coordinator->tick(2.0);
        $mesh->online = ['a'];
        $coordinator->tick(2.1);

        $this->assertSame([
            'Consensus: becoming candidate in term 2 (was 1): no majority in term 1, retrying',
            'Consensus: quorum lost: 1 of the 3-node master set online (quorum 2)',
            'Consensus: abandoning candidacy in term 2: quorum lost',
        ], $this->newLines());
    }

    public function testHeartbeatsVoteRepliesAndIdleTicksWriteNothing(): void
    {
        $mesh = new ConsensusLogTestMesh(self::MASTER_SET);
        $leader = $this->electedLeader($mesh);
        $leader->tick(1.1);
        $leader->tick(1.3);
        $leader->tick(1.5);
        $leader->onVoteReply(new PeerVoteReplyDTO(1, true, 'c'));
        $leader->onVoteReply(new PeerVoteReplyDTO(1, false, 'b'));
        $leader->onHeartbeat(new PeerHeartbeatDTO(0, 'b'));
        $leader->onHeartbeat(new PeerHeartbeatDTO(1, 'a'));
        $this->assertNotEmpty($mesh->broadcasts, 'Precondition: the leader heartbeated');
        $this->assertSame([], $this->newLines(), 'A leader heartbeating and hearing late or stale frames writes nothing');

        $follower = $this->followerOf(new ConsensusLogTestMesh(self::MASTER_SET), 'b');
        $follower->onHeartbeat(new PeerHeartbeatDTO(1, 'b'));
        $follower->tick(0.7);
        $follower->onHeartbeat(new PeerHeartbeatDTO(1, 'b'));
        $follower->tick(0.9);

        $this->assertSame([], $this->newLines(), 'A follower hearing its leader writes nothing');
    }

    /**
     * Drives a fresh coordinator through one full election into leadership and consumes its lines.
     *
     * @param ConsensusLogTestMesh $mesh Mesh to drive
     * @return ClusterCoordinator Coordinator now holding leadership in term 1
     */
    private function electedLeader(ConsensusLogTestMesh $mesh): ClusterCoordinator
    {
        $coordinator = $this->coordinator($mesh);
        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $coordinator->onVoteReply(new PeerVoteReplyDTO(1, true, 'b'));
        $this->assertTrue($coordinator->amLeader(), 'Precondition: node is leader');
        $this->newLines();

        return $coordinator;
    }

    /**
     * Builds a follower that recognises a leader in term 1 and consumes its lines.
     *
     * @param ConsensusLogTestMesh $mesh Mesh to drive
     * @param string $leaderId Node id of the leader the follower hears
     * @return ClusterCoordinator Follower of that leader in term 1, its timer refreshed at 0.5s
     */
    private function followerOf(ConsensusLogTestMesh $mesh, string $leaderId): ClusterCoordinator
    {
        $coordinator = $this->coordinator($mesh);
        $coordinator->tick(0.0);
        $coordinator->onHeartbeat(new PeerHeartbeatDTO(1, $leaderId));
        $coordinator->tick(0.5);
        $this->assertSame($leaderId, $coordinator->leaderId(), 'Precondition: node follows the leader');
        $this->newLines();

        return $coordinator;
    }

    /**
     * @param ConsensusLogTestMesh $mesh Mesh to drive
     * @return ClusterCoordinator Coordinator for master 'a' over a 3-node set, quorum 2, 1.0s timeout
     */
    private function coordinator(ConsensusLogTestMesh $mesh): ClusterCoordinator
    {
        return new ClusterCoordinator(
            new ClusterConsensusConfig('a', self::MASTER_SET, 2, 1000, 1000, 100),
            $mesh,
            new ConsensusLogTestObserver(),
        );
    }

    /**
     * Reads the lines written since the previous call, without their timestamps.
     *
     * @return list<string> Messages of the new lines, in the order they were written
     */
    private function newLines(): array
    {
        $text = (string)file_get_contents($this->logFile, false, null, $this->readOffset);
        $this->readOffset += strlen($text);

        $lines = [];
        foreach (explode("\n", trim($text)) as $line) {
            if ($line !== '') {
                $lines[] = (string)preg_replace('/^\[[^\]]+\] /', '', $line);
            }
        }

        return $lines;
    }
}

/**
 * Mesh with a settable liveness that keeps the frames it is handed.
 */
final class ConsensusLogTestMesh implements ConsensusMesh
{
    /** @var list<PeerDTO> Frames broadcast to the master set */
    public array $broadcasts = [];

    /**
     * @param list<string> $online Online master node ids returned to the coordinator
     */
    public function __construct(public array $online)
    {
    }

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
    }
}

/**
 * Observer that ignores every transition: the log is what these tests read.
 */
final class ConsensusLogTestObserver implements LeadershipObserver
{
    public function onBecameLeader(int $term): void
    {
    }

    public function onLostLeadership(int $term): void
    {
    }

    public function onQuorumGained(): void
    {
    }

    public function onQuorumLost(): void
    {
    }
}
