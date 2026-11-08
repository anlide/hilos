<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Consensus;

use Closure;
use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\Consensus\ConsensusMesh;
use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\LeadershipObserver;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Socket;
use WeakReference;

/** Actual native cues with addressed due-now deadlines, without waiting for expiry. */
final class ClusterCoordinatorEventLoopTest extends TestCase
{
    private EventLoop $loop;
    private ClusterCoordinator $coordinator;
    private NativeConsensusMesh $mesh;
    private NativeConsensusObserver $observer;

    /** @var list<Socket> Ready I/O fixtures released after the loop */
    private array $sockets = [];

    public function testInitialArmAndIdlePassesDoNotRescanMembershipOrRenewTheDeadline(): void
    {
        $before = hrtime(true) / 1e9;
        $this->assertTrue($this->coordinator->hasReadyWork());
        $this->loop->tick();
        $this->assertSame([], $this->observer->events, 'native cues never call project observers');
        $this->coordinator->dispatchReady();
        $timer = $this->timer();
        $deadline = $timer->getDeadline();
        $generation = new ReflectionProperty($timer, 'generation')->getValue($timer);
        $this->assertGreaterThanOrEqual($before + 1.5, $deadline);
        $this->assertLessThanOrEqual(hrtime(true) / 1e9 + 3.0, $deadline);
        $this->assertSame(['gained'], $this->observer->events);
        $this->assertSame(1, $this->mesh->membershipReads);
        for ($pass = 0; $pass < 8; ++$pass) {
            $this->loop->tick();
            $this->coordinator->dispatchReady();
        }
        $this->assertFalse($this->coordinator->hasReadyWork());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertSame($deadline, $timer->getDeadline());
        $this->assertSame($generation, new ReflectionProperty($timer, 'generation')->getValue($timer));
        $this->assertSame(1, $this->mesh->membershipReads);
        $this->assertSame([], $this->mesh->broadcasts);
    }

    public function testSameTermHeartbeatAndGrantedVoteCueTheirOwnResetWithoutAnEpochChange(): void
    {
        $this->coordinator->dispatchReady();
        $this->coordinator->onHeartbeat(new PeerHeartbeatDTO(0, 'b', false));
        $this->coordinator->dispatchReady();
        $revision = $this->coordinator->epochRevision();
        $this->putDeadline('electionDeadline', hrtime(true) / 1e9 + 600.0);
        $this->coordinator->onHeartbeat(new PeerHeartbeatDTO(0, 'b', false));
        $this->assertTrue($this->coordinator->hasReadyWork());
        $this->coordinator->dispatchReady();
        $this->assertLessThan(hrtime(true) / 1e9 + 3.0, $this->timer()->getDeadline());
        $this->assertSame($revision, $this->coordinator->epochRevision());
        $this->putDeadline('electionDeadline', hrtime(true) / 1e9 + 600.0);
        $this->coordinator->onRequestVote(new PeerRequestVoteDTO(0, 'b'));
        $this->assertTrue($this->coordinator->hasReadyWork());
        $this->coordinator->dispatchReady();
        $this->assertLessThan(hrtime(true) / 1e9 + 3.0, $this->timer()->getDeadline());
        $this->assertTrue($this->mesh->replies[0]->voteGranted);
        $this->assertSame($revision, $this->coordinator->epochRevision());
        $this->assertSame(1, $this->mesh->membershipReads, 'ordinary consensus input reads no membership snapshot');
    }

    public function testRoleSchedulingSurvivesReadinessRemovalAndLeavesTheEpochConsumerInstalled(): void
    {
        $facts = [];
        $wake = function () use (&$facts): void {
            $facts[] = $this->coordinator->epochRevision();
        };
        $this->coordinator->setEpochWake($wake);
        $this->coordinator->setReadinessWake(static function (): void {});
        $this->coordinator->dispatchReady();
        $this->coordinator->onHeartbeat(new PeerHeartbeatDTO(0, 'b', true));
        $this->coordinator->dispatchReady();
        $this->coordinator->setReadinessWake(null);
        $this->coordinator->noteWebSocketOpen();
        $this->coordinator->onHeartbeat(new PeerHeartbeatDTO(0, 'b', true));
        $this->assertTrue($this->coordinator->hasReadyWork(), 'same-term input has its own scheduling cue');
        $this->coordinator->dispatchReady();
        $this->assertSame([0, 1, 2], $facts, 'ordinary heartbeat sends no epoch fact');
        $this->expire('electionDeadline');
        $this->loop->runOnce();
        $this->coordinator->dispatchReady();
        $this->assertSame([0, 1, 2, 3], $facts);
        $this->assertSame(1, $this->coordinator->term());
        $this->coordinator->removeEpochWake($wake);
    }

    public function testRefusedStaleSelfAndDuplicateInputDoNotWakeOrRearm(): void
    {
        $this->coordinator->onRequestVote(new PeerRequestVoteDTO(2, 'b'));
        $this->coordinator->dispatchReady();
        $deadline = $this->timer()->getDeadline();
        $this->coordinator->onRequestVote(new PeerRequestVoteDTO(2, 'c'));
        $this->coordinator->onRequestVote(new PeerRequestVoteDTO(1, 'b'));
        $this->coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'b', false));
        $this->coordinator->onHeartbeat(new PeerHeartbeatDTO(9, 'a', false));
        $this->coordinator->onVoteReply(new PeerVoteReplyDTO(2, true, 'b'));
        $this->assertFalse($this->coordinator->hasReadyWork());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertSame($deadline, $this->timer()->getDeadline());
    }

    public function testHeartbeatThenOfflineElectsInOneGuardedPass(): void
    {
        $this->coordinator->dispatchReady();
        $this->coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'b', false));
        $this->mesh->online = ['a', 'c'];
        $this->coordinator->noteMembershipChanged();
        $this->coordinator->noteNodeOffline('b');
        $this->loop->runOnce();
        $this->assertSame([], $this->mesh->broadcasts);
        $this->coordinator->dispatchReady();
        $this->assertSame(ConsensusRole::Candidate, $this->coordinator->consensusRole());
        $this->assertSame(2, $this->coordinator->term());
        $this->assertInstanceOf(PeerRequestVoteDTO::class, $this->mesh->broadcasts[0]);
        $this->assertFalse($this->coordinator->hasReadyWork());
    }

    public function testOfflineThenAcceptedHeartbeatKeepsTheNewResetAndVoidsTheForcedReason(): void
    {
        $this->coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'b', false));
        $this->coordinator->dispatchReady();
        $this->coordinator->noteNodeOffline('b');
        $this->coordinator->onHeartbeat(new PeerHeartbeatDTO(1, 'c', false));
        $this->loop->runOnce();
        $this->coordinator->dispatchReady();
        $this->assertSame('c', $this->coordinator->leaderId());
        $this->assertSame(1, $this->coordinator->term());
        $this->assertSame([], $this->mesh->broadcasts);
        $this->assertNull(new ReflectionProperty($this->coordinator, 'pendingElectionReason')->getValue($this->coordinator));
        $this->assertGreaterThan(hrtime(true) / 1e9, $this->timer()->getDeadline());
    }

    public function testNativeExpiryOnlyCuesElectionAndCandidateRetry(): void
    {
        $this->coordinator->dispatchReady();
        $this->expire('electionDeadline');
        $this->loop->runOnce();
        $this->assertTrue($this->coordinator->hasReadyWork());
        $this->assertSame(0, $this->coordinator->term());
        $this->assertSame([], $this->mesh->broadcasts);
        $this->coordinator->dispatchReady();
        $this->assertSame(1, $this->coordinator->term());
        $this->assertCount(1, $this->mesh->broadcasts);
        $this->assertFalse($this->coordinator->hasReadyWork(), 'pending votes wait for input or the retry');
        $this->expire('electionDeadline');
        $this->loop->runOnce();
        $this->assertSame(1, $this->coordinator->term());
        $this->coordinator->dispatchReady();
        $this->assertSame(2, $this->coordinator->term());
        $this->assertCount(2, $this->mesh->broadcasts);
    }

    public function testMinorityRollsItsDeadlineAndSleepsWithoutTermInflationOrANewReturnTimeout(): void
    {
        $this->mesh->online = ['a'];
        $this->coordinator->dispatchReady();
        $this->expire('electionDeadline');
        $this->loop->runOnce();
        $this->coordinator->dispatchReady();
        $deadline = $this->timer()->getDeadline();
        $this->assertGreaterThan(hrtime(true) / 1e9, $deadline);
        $this->assertFalse($this->coordinator->hasReadyWork());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertSame(0, $this->coordinator->term());
        $this->mesh->online = ['a', 'c'];
        $this->coordinator->noteMembershipChanged();
        $this->coordinator->dispatchReady();
        $this->assertSame($deadline, $this->timer()->getDeadline(), 'quorum return does not start a full new timeout');
        $this->assertSame([], $this->mesh->broadcasts);
    }

    public function testDesignatedSuccessorNeedsNoFurtherPeerInput(): void
    {
        $this->coordinator->onHeartbeat(new PeerHeartbeatDTO(7, 'b', false));
        $this->coordinator->dispatchReady();
        $this->coordinator->triggerDesignatedElection();
        $this->loop->runOnce();
        $this->assertSame(7, $this->coordinator->term());
        $this->coordinator->dispatchReady();
        $this->assertSame(8, $this->coordinator->term());
        $this->assertInstanceOf(PeerRequestVoteDTO::class, $this->mesh->broadcasts[0]);
    }

    public function testLeaderSendsOneImmediateHeartbeatThenWaitsAndWebSocketOpenDoesNotRetime(): void
    {
        $this->elect();
        $this->assertTrue($this->coordinator->hasReadyWork());
        $this->loop->runOnce();
        $this->assertCount(1, $this->mesh->broadcasts, 'native callback has not sent the immediate heartbeat');
        $this->coordinator->dispatchReady();
        $this->assertCount(2, $this->mesh->broadcasts);
        $this->assertFalse($this->mesh->broadcasts[1]->webSocketOpen);
        $deadline = $this->timer()->getDeadline();
        $revision = $this->coordinator->epochRevision();
        $this->coordinator->noteWebSocketOpen();
        $this->assertFalse($this->coordinator->hasReadyWork());
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $this->expire('heartbeatDueAt');
        $this->loop->runOnce();
        $this->assertCount(2, $this->mesh->broadcasts);
        $this->coordinator->dispatchReady();
        $this->assertCount(3, $this->mesh->broadcasts, 'one send, never a catch-up batch');
        $this->assertTrue($this->mesh->broadcasts[2]->webSocketOpen);
        $this->assertSame($revision, $this->coordinator->epochRevision());
        $this->assertFalse($this->coordinator->hasReadyWork());
    }

    public function testMembershipLossRetiresLeaderBeforeReturnAndBeforeAnyRoleSend(): void
    {
        $this->elect();
        $this->coordinator->dispatchReady();
        $this->observer->events = [];
        $revision = $this->coordinator->epochRevision();
        $this->mesh->online = ['a'];
        $this->coordinator->noteMembershipChanged();
        $this->assertSame(['lost', 'leadershipLost:1'], $this->observer->events);
        $this->assertFalse($this->coordinator->amLeader());
        $this->assertNull($this->coordinator->leaderId());
        $deadline = new ReflectionProperty($this->coordinator, 'electionDeadline')->getValue($this->coordinator);
        $this->mesh->online = ['a', 'b', 'c'];
        $this->coordinator->noteMembershipChanged();
        $this->coordinator->dispatchReady();
        $this->assertSame(['lost', 'leadershipLost:1', 'gained'], $this->observer->events);
        $this->assertSame($revision + 3, $this->coordinator->epochRevision());
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $this->assertCount(2, $this->mesh->broadcasts, 'return does not resurrect the retired leader');
    }

    public function testAQuorumObserverReturningMembershipCannotEraseTheObservedLeaderLoss(): void
    {
        $this->elect();
        $this->coordinator->dispatchReady();
        $this->observer->events = [];
        $this->observer->onLoss = function (): void {
            $this->mesh->online = ['a', 'b', 'c'];
            $this->coordinator->noteMembershipChanged();
        };
        $this->mesh->online = ['a'];
        $this->coordinator->noteMembershipChanged();
        $this->assertTrue($this->coordinator->hasQuorum());
        $this->assertFalse($this->coordinator->amLeader(), 'the observed loss retires the old term despite reentrant return');
        $this->assertSame(['lost', 'gained', 'leadershipLost:1'], $this->observer->events);
        $this->coordinator->dispatchReady();
        $this->assertCount(2, $this->mesh->broadcasts);
        $this->assertFalse($this->coordinator->hasReadyWork());
        $this->observer->onLoss = null;
    }

    public function testCandidateQuorumLossClearsVotesAndArmsItsFollowerDeadline(): void
    {
        $this->coordinator->dispatchReady();
        $this->expire('electionDeadline');
        $this->loop->runOnce();
        $this->coordinator->dispatchReady();
        $this->mesh->online = ['a'];
        $this->coordinator->noteMembershipChanged();
        $this->assertSame(ConsensusRole::Follower, $this->coordinator->consensusRole());
        $this->assertSame([], new ReflectionProperty($this->coordinator, 'votesReceived')->getValue($this->coordinator));
        $this->coordinator->dispatchReady();
        $this->assertGreaterThan(hrtime(true) / 1e9, $this->timer()->getDeadline());
        $this->assertFalse($this->coordinator->hasReadyWork());
    }

    public function testFollowerRemembersCoalescedLossReturnEvenWithTheSameLeaderAndTerm(): void
    {
        $this->coordinator->onHeartbeat(new PeerHeartbeatDTO(5, 'b', true));
        $this->coordinator->dispatchReady();
        $revision = $this->coordinator->epochRevision();
        $deadline = $this->timer()->getDeadline();
        $this->mesh->online = ['a'];
        $this->coordinator->noteMembershipChanged();
        $this->mesh->online = ['a', 'b', 'c'];
        $this->coordinator->noteMembershipChanged();
        $this->assertSame($revision + 2, $this->coordinator->epochRevision());
        $this->assertSame('b', $this->coordinator->leaderId());
        $this->assertSame(5, $this->coordinator->term());
        $this->loop->runOnce();
        $this->coordinator->dispatchReady();
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $this->assertSame(['gained', 'lost', 'gained'], $this->observer->events);
    }

    public function testReadyIoAndRebindingKeepTheOriginalFutureDeadlineAndRejectOldCues(): void
    {
        $this->coordinator->dispatchReady();
        $timer = $this->timer();
        $deadline = $timer->getDeadline();
        $generation = new ReflectionProperty($timer, 'generation')->getValue($timer);
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $this->sockets));
        $this->loop->registerRead($this->sockets[0], function (): void {
            socket_read($this->sockets[0], 1);
        });
        for ($pass = 0; $pass < 3; ++$pass) {
            socket_write($this->sockets[1], 'x');
            $this->loop->runOnce();
            $this->coordinator->dispatchReady();
        }
        $this->assertSame($deadline, $timer->getDeadline());
        $this->assertSame($generation, new ReflectionProperty($timer, 'generation')->getValue($timer));
        $oldCue = new ReflectionProperty($timer, 'callback')->getValue($timer);
        $this->coordinator->detachEventLoop();
        $oldCue();
        $this->assertFalse($this->coordinator->hasReadyWork());
        $this->coordinator->attachEventLoop($this->loop);
        $this->coordinator->dispatchReady();
        $this->assertNotSame($timer, $this->timer());
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $oldCue();
        $this->assertFalse($this->coordinator->hasReadyWork());
    }

    public function testActivatedOldTimerCannotOverwriteTheNewerHeartbeatOrTerm(): void
    {
        $this->coordinator->dispatchReady();
        $this->expire('electionDeadline');
        $activated = false;
        $this->loop->createReadyWork(function () use (&$activated): void {
            $activated = $this->timer()->getDeadline() === null;
            $this->coordinator->onHeartbeat(new PeerHeartbeatDTO(9, 'c', false));
            $this->coordinator->dispatchReady();
        })->markReady();
        $this->loop->runOnce();
        $this->assertTrue($activated);
        $this->assertSame('c', $this->coordinator->leaderId());
        $this->assertSame(9, $this->coordinator->term());
        $this->assertFalse($this->coordinator->hasReadyWork());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertSame([], $this->mesh->broadcasts);
    }

    public function testNativeHandlesDoNotRetainAnAbandonedCoordinator(): void
    {
        $this->coordinator->dispatchReady();
        $reference = WeakReference::create($this->coordinator);
        unset($this->coordinator);
        $this->assertNull($reference->get());
        $this->loop->close();
        $this->assertFalse($this->loop->hasReadyWork());
    }

    protected function setUp(): void
    {
        $this->loop = new EventLoop();
        $this->mesh = new NativeConsensusMesh();
        $this->observer = new NativeConsensusObserver();
        $this->coordinator = new ClusterCoordinator(
            new ClusterConsensusConfig('a', ['a', 'b', 'c'], 2, 1500, 3000, 500), $this->mesh, $this->observer,
        );
        $this->coordinator->attachEventLoop($this->loop);
    }

    protected function tearDown(): void
    {
        if (isset($this->coordinator)) {
            $this->coordinator->detachEventLoop();
        }
        $this->loop->close();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
    }

    private function elect(): void
    {
        $this->coordinator->dispatchReady();
        $this->coordinator->triggerDesignatedElection();
        $this->coordinator->dispatchReady();
        $this->coordinator->onVoteReply(new PeerVoteReplyDTO(1, true, 'b'));
        $this->assertTrue($this->coordinator->amLeader());
    }

    /** @return EventLoopTimer Current owner's actual native role handle */
    private function timer(): EventLoopTimer
    {
        return new ReflectionProperty($this->coordinator, 'roleTimer')->getValue($this->coordinator);
    }

    /** @param string $field Exact role deadline to bring to now */
    private function expire(string $field): void
    {
        $this->putDeadline($field, hrtime(true) / 1e9 - 1.0);
    }

    /**
     * @param string $field Exact duty, without a shared clock override
     * @param float $deadline Addressed monotonic deadline
     */
    private function putDeadline(string $field, float $deadline): void
    {
        new ReflectionProperty($this->coordinator, $field)->setValue($this->coordinator, $deadline);
        $this->timer()->armAt($deadline);
    }
}

final class NativeConsensusMesh implements ConsensusMesh
{
    /** @var list<string> Own live master links */
    public array $online = ['a', 'b', 'c'];
    /** @var list<PeerDTO> Actual consensus broadcasts */
    public array $broadcasts = [];
    /** @var list<PeerVoteReplyDTO> Actual vote replies */
    public array $replies = [];
    public int $membershipReads = 0;

    public function onlineMasterIds(): array
    {
        ++$this->membershipReads;
        return $this->online;
    }

    public function broadcastToMasters(PeerDTO $frame): void
    {
        $this->broadcasts[] = $frame;
    }

    public function sendToMaster(string $nodeId, PeerDTO $frame): void
    {
        if ($frame instanceof PeerVoteReplyDTO) {
            $this->replies[] = $frame;
        }
    }
}

final class NativeConsensusObserver implements LeadershipObserver
{
    /** @var list<string> Actual transition order */
    public array $events = [];
    public ?Closure $onLoss = null;

    public function onBecameLeader(int $term): void
    {
        $this->events[] = 'leader:' . $term;
    }

    public function onLostLeadership(int $term): void
    {
        $this->events[] = 'leadershipLost:' . $term;
    }

    public function onQuorumGained(): void
    {
        $this->events[] = 'gained';
    }

    public function onQuorumLost(): void
    {
        $this->events[] = 'lost';
        $this->onLoss?->__invoke();
    }
}
