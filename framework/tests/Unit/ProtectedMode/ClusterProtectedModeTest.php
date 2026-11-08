<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Closure;
use Hilos\Database\DTO\ReHydrateVerdict;
use Hilos\Database\ReHydrateRound;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeSyncDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeSyncedDTO;
use Hilos\Constants\EnvConstants;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\HilosMailer;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReleaseDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeAdmitDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeCircleDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeDisableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeEnableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeLiftDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModePassDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeProgressDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiesceDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiescedDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeRefreezeDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeSettledDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeVerifyDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeWindowDTO;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Hilos;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\ProtectedMode\DTO\ProtectedModeCircleSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DTO\ProtectedModeRefreezeSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeVerifySignalData;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\ProtectedModeExecutor;
use Hilos\ProtectedMode\ProtectedModeInitiatorRelay;
use Hilos\ProtectedMode\ProtectedModeMesh;
use Hilos\ProtectedMode\ProtectedModeRefusalCopy;
use Hilos\ProtectedMode\VerifierCircleSnapshot;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Tests\Unit\Cluster\Peer\ProtectedModePeerFixture;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Hilos\ProtectedMode\ProtectedModeCoordinator;
use Hilos\ProtectedMode\ProtectedModeSourceBinding;
use Hilos\ProtectedMode\ProtectedModeWatchdog;
use Hilos\ProtectedMode\DTO\ProtectedModeWatchdogSnapshot;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use WeakReference;
use Hilos\Constants\TimeConstants;
use ReflectionProperty;

/**
 * Unit tests for the two-phase cluster freeze orchestration (HIL-267 slice 5).
 *
 * The state machine is driven through the {@see ProtectedModeCoordinator} frame handlers and
 * observed through recording fakes of its two ports, so the leader and follower flows are pinned
 * without a live cluster: a leader collects quiesced reports - its own roster's among them - before
 * signalling ready, a single-node cluster activates once its roster has stopped, a follower freezes
 * and reports back when its roster has, and the leader role is gated on holding leadership. The
 * wire frames and the daemon wiring are covered by their own slices.
 *
 * Every case runs with the framework-owned freeze row mounted, as a real project boot leaves it;
 * the fail-closed cases unmount it to stand in for a process that carries no runtime state at all.
 */
final class ClusterProtectedModeTest extends TestCase
{
    use ProtectedModePeerFixture;

    private const string SELF = 'node-a';

    private FakeProtectedModeMesh $mesh;

    private FakeProtectedModeExecutor $executor;

    private ClusterProtectedMode $coordinator;

    private FakeClusterInitiatorRelay $relay;

    protected function setUp(): void
    {
        $this->mountProtectedModePeers();
        $this->mesh = new FakeProtectedModeMesh();
        $this->executor = new FakeProtectedModeExecutor();
        $this->coordinator = new ClusterProtectedMode(self::SELF, $this->mesh, $this->executor);
        $this->relay = new FakeClusterInitiatorRelay();
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerProtectedModeInitiatorRelay($this->relay);
        $this->mount();
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = null;
        Hilos::$rt = null;

        $this->restoreProtectedModePeers();
        parent::tearDown();
    }

    public function testNativePromotionAndChangedSnapshotsContinueInFinitePortionsWithoutInput(): void
    {
        $loop = new EventLoop();
        $this->mesh->leader = self::SELF;
        $this->mesh->slaves = array_map(static fn(int $id): string => 'slave-' . $id, range(1, 70));
        $this->coordinator->attachSlaveSyncEventLoop($loop);
        try {
            $this->coordinator->onBecameLeader();
            self::assertSame([], $this->mesh->syncs, 'Promotion queues the finite cohort');
            $this->coordinator->dispatchSlaveSyncReady();
            self::assertCount(32, $this->mesh->syncs);
            self::assertTrue($this->coordinator->hasSlaveSyncReadyWork());
            // New facts join behind the already captured prefix instead of starting it over.
            $this->coordinator->noteSlaveSyncChanged();
            $this->coordinator->dispatchSlaveSyncReady();
            self::assertCount(64, $this->mesh->syncs);
            $this->drainSlaveSync($loop);
            self::assertCount(70, $this->mesh->syncs);
            self::assertSame(70, $this->mesh->syncSends);
            $snapshots = $this->mesh->syncs;
            $reads = $this->mesh->slaveReads;
            for ($pass = 0; $pass < 3; ++$pass) {
                $loop->tick();
                $this->coordinator->dispatchSlaveSyncReady();
            }
            self::assertSame($snapshots, $this->mesh->syncs);
            self::assertFalse($this->coordinator->hasSlaveSyncReadyWork());
            self::assertSame($reads, $this->mesh->slaveReads, 'Idle does not query every slave again');
            $this->coordinator->noteSlaveSyncChanged();
            $this->drainSlaveSync($loop);
            self::assertSame($snapshots, $this->mesh->syncs, 'An equal source never refreshes a request id');
            $this->executor->applyRuntime = true;
            $this->withDaemonTruthSource(function () use ($loop): void {
                $this->coordinator->onEnable('node-b', $this->enableData());
                $this->coordinator->onRosterStopped();
                $this->coordinator->dispatchSlaveSyncReady();
                self::assertSame(102, $this->mesh->syncSends);
                $this->coordinator->noteSlaveSyncChanged();
                $this->coordinator->dispatchSlaveSyncReady();
                self::assertSame(134, $this->mesh->syncSends);
                $this->drainSlaveSync($loop);
                self::assertSame(140, $this->mesh->syncSends);
                foreach ($this->mesh->syncs as $frame) {
                    self::assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, $frame->phase);
                }
                $this->coordinator->noteSlaveSyncChanged();
                $this->coordinator->dispatchSlaveSyncReady();
                foreach (range(33, 64) as $id) {
                    $frame = $this->mesh->syncs['slave-' . $id];
                    $this->coordinator->onSynced('slave-' . $id, new PeerProtectedModeSyncedDTO($frame->syncId, $frame->phase));
                }
                $this->coordinator->dispatchSlaveSyncReady();
                $scan = new ReflectionProperty(ClusterProtectedMode::class, 'slaveSyncScan')->getValue($this->coordinator);
                self::assertSame('slave-65', $scan->key(), 'Settled entries still spend the portion budget');
                $this->drainSlaveSync($loop);
                self::assertSame(140, $this->mesh->syncSends);
            });
        } finally {
            $this->coordinator->detachSlaveSyncEventLoop();
            $loop->close();
        }
    }

    public function testNativeInactiveDueWarnsOnceAndRebindRetainsTheOriginalPendingClock(): void
    {
        $loop = new EventLoop();
        $this->mesh->leader = self::SELF;
        $this->mesh->slaves = ['slave'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onSlaveConnected('slave');
        $requests = new ReflectionProperty(ClusterProtectedMode::class, 'slaveSyncRequests');
        $original = $requests->getValue($this->coordinator)['slave'];
        $this->coordinator->attachSlaveSyncEventLoop($loop);
        $alerts = $this->captureSlaveAlerts(function () use ($loop, $requests, $original): void {
            try {
                $this->drainSlaveSync($loop);
                $timerProperty = new ReflectionProperty(ClusterProtectedMode::class, 'slaveSyncTimer');
                $firstTimer = $timerProperty->getValue($this->coordinator);
                self::assertSame($original['deadline'], $firstTimer->getDeadline());
                $this->coordinator->onSlaveConnected('slave');
                $this->drainSlaveSync($loop);
                self::assertSame($original['firstRequestedAt'], $requests->getValue($this->coordinator)['slave']['firstRequestedAt']);
                self::assertSame($original['deadline'], $firstTimer->getDeadline());
                $this->coordinator->detachSlaveSyncEventLoop();
                self::assertNull($firstTimer->getDeadline());
                $this->coordinator->attachSlaveSyncEventLoop($loop);
                $this->drainSlaveSync($loop);
                $timer = $timerProperty->getValue($this->coordinator);
                self::assertNotSame($firstTimer, $timer);
                self::assertSame($original['deadline'], $timer->getDeadline());
                // Bring this private deadline to now; no clock replacement or expiry wait.
                $pending = $requests->getValue($this->coordinator);
                $pending['slave']['deadline'] = hrtime(true) / 1e9 - 1.0;
                $requests->setValue($this->coordinator, $pending);
                $timer->armAt($pending['slave']['deadline']);
                self::assertFalse($this->coordinator->hasSlaveSyncReadyWork());
                $loop->tick();
                self::assertTrue($this->coordinator->hasSlaveSyncReadyWork());
                $this->drainSlaveSync($loop);
                self::assertTrue($requests->getValue($this->coordinator)['slave']['alerted']);
                self::assertNull($timer->getDeadline());
                $this->coordinator->onSlaveConnected('slave');
                $this->drainSlaveSync($loop);
                self::assertNull($timer->getDeadline(), 'Alerted history has no past timer spin');
                $frame = $this->mesh->syncs['slave'];
                $this->coordinator->onSynced('slave', new PeerProtectedModeSyncedDTO($frame->syncId, $frame->phase));
                $this->drainSlaveSync($loop);
                self::assertSame([], $requests->getValue($this->coordinator));
                self::assertNull($timer->getDeadline());
            } finally {
                $this->coordinator->detachSlaveSyncEventLoop();
                $loop->close();
            }
        });
        self::assertCount(1, $alerts);
        self::assertSame('inactive', $alerts[0]['params']['phase']);
        self::assertSame('sync-overdue', $alerts[0]['params']['reason']);
    }

    public function testNativeDeadlineArmingFailureReachesTheGuardWithoutDroppingTheRequest(): void
    {
        $loop = new EventLoop();
        $this->mesh->leader = self::SELF;
        $this->mesh->slaves = ['slave'];
        $this->coordinator->attachSlaveSyncEventLoop($loop);
        $this->coordinator->onBecameLeader();
        try {
            $this->drainSlaveSync($loop);
            $timer = new ReflectionProperty(ClusterProtectedMode::class, 'slaveSyncTimer')->getValue($this->coordinator);
            $timer->close();
            $this->coordinator->noteSlaveSyncChanged();
            $this->expectException(EventLoopException::class);
            $this->coordinator->dispatchSlaveSyncReady();
        } finally {
            self::assertCount(1, new ReflectionProperty(ClusterProtectedMode::class, 'slaveSyncRequests')->getValue($this->coordinator));
            $this->coordinator->detachSlaveSyncEventLoop();
            $loop->close();
        }
    }

    public function testNativeQueuedCallbacksDoNotRetainADiscardedCoordinator(): void
    {
        $loop = new EventLoop();
        $sync = new ClusterProtectedMode(self::SELF, $this->mesh, $this->executor);
        $sync->attachSlaveSyncEventLoop($loop);
        $weak = WeakReference::create($sync);
        unset($sync);
        try {
            self::assertNull($weak->get());
            $loop->tick();
            self::assertFalse($loop->hasReadyWork());
        } finally {
            $loop->close();
        }
    }

    public function testNativeAckCancelsEarliestThenLastDeadlineAndASettledRequestStartsFresh(): void
    {
        $loop = new EventLoop();
        $this->mesh->leader = self::SELF;
        $this->mesh->slaves = ['first', 'last'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->attachSlaveSyncEventLoop($loop);
        try {
            $this->drainSlaveSync($loop);
            $requests = new ReflectionProperty(ClusterProtectedMode::class, 'slaveSyncRequests');
            $pending = $requests->getValue($this->coordinator);
            $mono = hrtime(true) / 1e9;
            $pending['first']['deadline'] = $mono + 10.0;
            $pending['last']['deadline'] = $mono + 20.0;
            $requests->setValue($this->coordinator, $pending);
            $this->coordinator->noteSlaveSyncChanged();
            $this->drainSlaveSync($loop);
            $timer = new ReflectionProperty(ClusterProtectedMode::class, 'slaveSyncTimer')->getValue($this->coordinator);
            self::assertSame($mono + 10.0, $timer->getDeadline());
            foreach (['first', 'last'] as $nodeId) {
                $frame = $this->mesh->syncs[$nodeId];
                $this->coordinator->onSynced($nodeId, new PeerProtectedModeSyncedDTO($frame->syncId, $frame->phase));
                $this->drainSlaveSync($loop);
                self::assertSame($nodeId === 'first' ? $mono + 20.0 : null, $timer->getDeadline());
            }
            self::assertSame([], $requests->getValue($this->coordinator));
            $this->coordinator->onSlaveConnected('first');
            $this->drainSlaveSync($loop);
            $fresh = $requests->getValue($this->coordinator)['first'];
            self::assertFalse($fresh['alerted']);
            self::assertGreaterThan($mono + 20.0, $fresh['deadline']);
            self::assertNotSame($pending['first']['frame'], $fresh['frame']);
            $this->coordinator->onLostLeadership();
            self::assertNull($timer->getDeadline());
            $this->drainSlaveSync($loop);
            self::assertSame([], $requests->getValue($this->coordinator));
        } finally {
            $this->coordinator->detachSlaveSyncEventLoop();
            $loop->close();
        }
    }

    public function testReentrantSyncSendAndStaleAckPreserveTheReplacementRequest(): void
    {
        $loop = new EventLoop();
        $this->mesh->leader = self::SELF;
        $this->mesh->slaves = ['slave'];
        $this->coordinator->attachSlaveSyncEventLoop($loop);
        $this->coordinator->onBecameLeader();
        $old = null;
        $this->mesh->onSyncSend = function (string $nodeId, PeerProtectedModeSyncDTO $frame) use (&$old): void {
            $this->mesh->onSyncSend = null;
            $old = $frame;
            $this->coordinator->onSlaveConnected($nodeId);
            $this->coordinator->onSynced($nodeId, new PeerProtectedModeSyncedDTO($frame->syncId, $frame->phase));
            $this->coordinator->dispatchSlaveSyncReady();
        };
        try {
            $this->drainSlaveSync($loop);
            $requests = new ReflectionProperty(ClusterProtectedMode::class, 'slaveSyncRequests')->getValue($this->coordinator);
            self::assertCount(1, $requests);
            self::assertNotSame($old, $requests['slave']['frame']);
            self::assertSame($this->mesh->syncs['slave'], $requests['slave']['frame']);
            self::assertSame(2, $this->mesh->syncSends);
            self::assertFalse($this->coordinator->hasSlaveSyncReadyWork());
        } finally {
            $this->coordinator->detachSlaveSyncEventLoop();
            $loop->close();
        }
    }

    /** @param string $change Authority or installed-link fact that invalidates the old round */
    #[DataProvider('nativeDatabaseAuthorityChanges')]
    public function testNativeLateDatabaseSuccessCannotCreditANewAuthorityOrLink(string $change): void
    {
        $loop = new EventLoop();
        $this->executor->applyRuntime = true;
        $this->executor->delayDatabase = true;
        $this->mesh->leader = 'leader';
        $this->coordinator->attachSlaveSyncEventLoop($loop);
        $this->captureSlaveAlerts(function () use ($loop, $change): void {
            $this->withDaemonTruthSource(function () use ($loop, $change): void {
                try {
                    $this->coordinator->onSync('leader', $this->slaveFrame('window', StateProtectedModeRuntime::PHASE_VERIFYING));
                    $this->coordinator->onRosterStopped();
                    $this->drainSlaveSync($loop);
                    if ($change === 'leader') {
                        $this->mesh->leader = 'next';
                    } elseif ($change === 'link') {
                        $this->mesh->syncCurrent = false;
                    } else {
                        $this->mesh->leader = self::SELF;
                        $this->coordinator->onBecameLeader();
                    }
                    $this->coordinator->noteSlaveSyncChanged();
                    $this->executor->databaseContinuations[0](new ReHydrateVerdict(null, null, true, []));
                    $this->drainSlaveSync($loop);
                    self::assertSame([], $this->mesh->synced);
                    self::assertNotContains('enterVerifying', $this->executor->calls);
                    self::assertNotContains('enterInactive', $this->executor->calls);
                    self::assertFalse($this->coordinator->hasSlaveSyncReadyWork());
                } finally {
                    $this->coordinator->detachSlaveSyncEventLoop();
                    $loop->close();
                }
            });
        });
    }

    /** @return iterable<string, array{string}> Every local authority invalidation */
    public static function nativeDatabaseAuthorityChanges(): iterable
    {
        foreach (['leader', 'link', 'promotion'] as $change) {
            yield $change => [$change];
        }
    }

    public function testNativeSourceFansOutAndPhaseLiftKeepDeadlineDespiteWallClockChanges(): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = self::SELF;
        $this->mesh->slaves = ['slave'];
        $loop = new EventLoop();
        $watchdog = new ProtectedModeWatchdog();
        $binding = new ProtectedModeSourceBinding();
        Hilos::$cluster->registerProtectedMode($this->coordinator);
        $binding->registerWatchdog($watchdog);
        $binding->reconcile();
        $this->coordinator->attachSlaveSyncEventLoop($loop);
        $this->withDaemonTruthSource(function () use ($loop, $binding, $watchdog): void {
            try {
                $this->coordinator->onBecameLeader();
                $this->coordinator->onEnable('node-b', $this->enableData());
                $this->coordinator->onRosterStopped();
                $this->drainSlaveSync($loop);
                $requests = new ReflectionProperty(ClusterProtectedMode::class, 'slaveSyncRequests');
                $first = $requests->getValue($this->coordinator)['slave'];
                $this->coordinator->onVerify('node-b', 'backup', 0);
                $this->drainSlaveSync($loop);
                $window = $requests->getValue($this->coordinator)['slave'];
                self::assertSame($first['deadline'], $window['deadline']);
                self::assertSame($first['firstRequestedAt'], $window['firstRequestedAt']);
                self::assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, $window['frame']->phase);
                $this->coordinator->onDisable('node-b', 'backup', 0);
                $this->coordinator->noteSlaveSyncChanged();
                $this->coordinator->dispatchSlaveSyncReady(microtime(true) + 10000.0, $first['deadline'] - 1.0);
                $this->drainSlaveSync($loop);
                $lift = $requests->getValue($this->coordinator)['slave'];
                self::assertSame($first['deadline'], $lift['deadline']);
                self::assertSame($first['firstRequestedAt'], $lift['firstRequestedAt']);
                self::assertSame('restore', $lift['operation']);
                self::assertFalse($lift['alerted'], 'Wall-clock movement spends no private process time');
                self::assertTrue($watchdog->hasReadyWork());
                $binding->removeWatchdog($watchdog);
                $this->coordinator->onSlaveConnected('slave');
                $this->drainSlaveSync($loop);
                self::assertSame($first['deadline'], $requests->getValue($this->coordinator)['slave']['deadline']);
            } finally {
                $binding->detach();
                $this->coordinator->detachSlaveSyncEventLoop();
                $loop->close();
            }
        });
    }

    /** @param bool $oldComplete Whether the superseded round succeeded */
    #[DataProvider('oldDatabaseResults')]
    public function testNativeDatabaseCompletionReleasesLatestTargetWithoutAnotherPeerFrame(bool $oldComplete): void
    {
        $this->executor->applyRuntime = true;
        $this->executor->delayDatabase = true;
        $this->mesh->leader = 'leader';
        $loop = new EventLoop();
        $this->coordinator->attachSlaveSyncEventLoop($loop);
        $this->captureSlaveAlerts(function () use ($loop, $oldComplete): void {
            $this->withDaemonTruthSource(function () use ($loop, $oldComplete): void {
                try {
                    $this->coordinator->onSync('leader', $this->slaveFrame('active'));
                    $this->coordinator->onRosterStopped();
                    $this->mesh->synced = [];
                    $this->coordinator->onSync('leader', $this->slaveFrame('old', StateProtectedModeRuntime::PHASE_VERIFYING));
                    $this->drainSlaveSync($loop);
                    self::assertFalse($this->coordinator->hasSlaveSyncReadyWork(), 'DB pending sleeps');
                    $this->coordinator->onSync('leader', $this->inactiveSlaveFrame('latest'));
                    $this->drainSlaveSync($loop);
                    $this->executor->databaseContinuations[0](new ReHydrateVerdict(null, null, $oldComplete, []));
                    self::assertTrue($this->coordinator->hasSlaveSyncReadyWork());
                    $this->drainSlaveSync($loop);
                    self::assertCount(2, $this->executor->databaseContinuations);
                    self::assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
                    self::assertSame([], $this->mesh->synced);
                    $this->executor->databaseContinuations[1](new ReHydrateVerdict(null, null, true, []));
                    $this->drainSlaveSync($loop);
                    self::assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
                    self::assertSame([], $this->mesh->synced, 'Resume remains an external fact');
                    $this->coordinator->onRosterResumed();
                    $this->drainSlaveSync($loop);
                    self::assertSame([['leader', 'latest', StateProtectedModeRuntime::PHASE_INACTIVE]], $this->mesh->synced);
                } finally {
                    $this->coordinator->detachSlaveSyncEventLoop();
                    $loop->close();
                }
            });
        });
    }

    /** @return iterable<string, array{bool}> Both actual verdicts of the old pending round */
    public static function oldDatabaseResults(): iterable
    {
        yield 'old-success' => [true];
        yield 'old-failure' => [false];
    }

    public function testNativeFailedCurrentDatabaseTargetStaysQuietAndClosed(): void
    {
        $loop = new EventLoop();
        $this->executor->applyRuntime = true;
        $this->executor->delayDatabase = true;
        $this->mesh->leader = 'leader';
        $this->coordinator->attachSlaveSyncEventLoop($loop);
        $this->captureSlaveAlerts(function () use ($loop): void {
            $this->withDaemonTruthSource(function () use ($loop): void {
                try {
                    $this->coordinator->onSync('leader', $this->slaveFrame('window', StateProtectedModeRuntime::PHASE_VERIFYING));
                    $this->coordinator->onRosterStopped();
                    $this->drainSlaveSync($loop);
                    $finish = $this->executor->databaseContinuations[0];
                    $finish(new ReHydrateVerdict(null, null, false, ['worker #1: read failed']));
                    $this->drainSlaveSync($loop);
                    $finish(new ReHydrateVerdict(null, null, true, []));
                    $this->drainSlaveSync($loop);
                    self::assertCount(1, $this->executor->databaseContinuations);
                    self::assertSame([], $this->mesh->synced);
                    self::assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, Hilos::$rt->hilosProtectedModeRuntime->phase);
                    self::assertFalse($this->coordinator->hasSlaveSyncReadyWork());
                } finally {
                    $this->coordinator->detachSlaveSyncEventLoop();
                    $loop->close();
                }
            });
        });
    }

    public function testSynchronousRosterAndDatabaseCallbacksDoNotDuplicateEffectsOrEraseReentrantTarget(): void
    {
        $loop = new EventLoop();
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'leader';
        $this->coordinator->attachSlaveSyncEventLoop($loop);
        $this->executor->onActivating = $this->coordinator->onRosterStopped(...);
        $this->executor->onVerifying = $this->coordinator->onRosterResumed(...);
        $this->mesh->onSyncedSend = function (): void {
            $this->mesh->onSyncedSend = null;
            $this->coordinator->onRosterResumed();
            $this->coordinator->onSync('leader', $this->inactiveSlaveFrame('next'));
        };
        $this->executor->clearWindow = $this->coordinator->onRosterResumed(...);
        $this->captureSlaveAlerts(function () use ($loop): void {
            $this->withDaemonTruthSource(function () use ($loop): void {
                try {
                    $this->coordinator->onSync('leader', $this->slaveFrame('window', StateProtectedModeRuntime::PHASE_VERIFYING));
                    $this->drainSlaveSync($loop);
                    self::assertSame([
                        'enterActivating', 'catchUpSlaveDatabase', 'importSlaveSync', 'enterVerifying',
                        'importSlaveSync', 'finishVerifying', 'catchUpSlaveDatabase', 'enterInactive', 'finishLift',
                    ], $this->executor->calls);
                    self::assertSame([
                        ['leader', 'window', StateProtectedModeRuntime::PHASE_VERIFYING],
                        ['leader', 'next', StateProtectedModeRuntime::PHASE_INACTIVE],
                    ], $this->mesh->synced);
                    self::assertFalse($this->coordinator->hasSlaveSyncReadyWork());
                } finally {
                    $this->coordinator->detachSlaveSyncEventLoop();
                    $loop->close();
                }
            });
        });
    }

    public function testConcretePendingShrinkCuesWatchdogBeforeItsReminderWithoutChangingThePhase(): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->mesh->leader = self::SELF;
        Hilos::$cluster->registerProtectedMode($this->coordinator);
        $this->withDaemonTruthSource(function (): void {
            $this->coordinator->onBecameLeader();
            $this->coordinator->onEnable('node-b', $this->enableData());
            $watchdog = new ProtectedModeWatchdog();
            $binding = new ProtectedModeSourceBinding();
            $binding->registerWatchdog($watchdog);
            $binding->reconcile();
            $loop = new EventLoop();
            $snapshots = [];
            $watchdog->attachEventLoop($loop, static fn (): bool => true,
                static function (ProtectedModeWatchdogSnapshot $snapshot) use (&$snapshots): void { $snapshots[] = $snapshot; });
            try {
                $epoch = Hilos::$rt->hilosProtectedModeRuntime->startedAt;
                $mono = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
                $watchdog->dispatchReady((float)$epoch, $mono);
                $watchdog->noteChanged();
                $watchdog->dispatchReady((float)($epoch + 31), $mono + 31.0);
                self::assertSame(['node-b', 'node-c', self::SELF], end($snapshots)->watchdogPendingNodeIds);
                $reminder = new ReflectionProperty(ProtectedModeWatchdog::class, 'reminderDeadline')->getValue($watchdog);
                $this->coordinator->onQuiesced('node-b');
                self::assertTrue($watchdog->hasReadyWork());
                self::assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, Hilos::$rt->hilosProtectedModeRuntime->phase);
                $watchdog->dispatchReady((float)($epoch + 32), $mono + 32.0);
                self::assertSame(['node-c', self::SELF], end($snapshots)->watchdogPendingNodeIds);
                self::assertSame($reminder, new ReflectionProperty(ProtectedModeWatchdog::class, 'reminderDeadline')->getValue($watchdog));
                $this->coordinator->onQuiesced('node-b');
                self::assertFalse($watchdog->hasReadyWork(), 'An already absent slot is not another fact');
                $this->coordinator->onRosterStopped();
                self::assertTrue($watchdog->hasReadyWork());
                $watchdog->dispatchReady((float)($epoch + 33), $mono + 33.0);
                self::assertSame(['node-c'], end($snapshots)->watchdogPendingNodeIds);
            } finally {
                $binding->removeWatchdog($watchdog);
                $watchdog->detachEventLoop();
                $loop->close();
            }
        });
    }

    public function testSwitchReplacementFencesOldPendingCueAndExactRemovalPreservesItsReplacement(): void
    {
        Hilos::$cluster->registerProtectedMode($this->coordinator);
        $watchdog = new ProtectedModeWatchdog();
        $binding = new ProtectedModeSourceBinding();
        $binding->registerWatchdog($watchdog);
        $binding->reconcile();
        $stale = new ReflectionProperty(ClusterProtectedMode::class, 'changeWake')->getValue($this->coordinator);
        $replacement = new ClusterProtectedMode(self::SELF, $this->mesh, $this->executor);
        Hilos::$cluster->registerProtectedMode($replacement);
        self::assertTrue($watchdog->hasReadyWork());
        $binding->reconcile();
        new ReflectionProperty(ProtectedModeWatchdog::class, 'checkReady')->setValue($watchdog, false);
        $stale();
        self::assertFalse($watchdog->hasReadyWork());
        $calls = 0;
        $replacement->setChangeWake(static function () use (&$calls): void { ++$calls; });
        $binding->removeWatchdog($watchdog);
        $replacement->onLostLeadership();
        self::assertGreaterThan(0, $calls);
        self::assertFalse($watchdog->hasReadyWork());
    }

    #[DataProvider('legacyOccupiedPhases')]
    public function testLegacyPromotionBootstrapsLocallyWithoutReplayingEffects(string $phase): void
    {
        $this->withDaemonTruthSource(function () use ($phase): void {
            $actions = Hilos::$rt->hilosProtectedModeRuntime->actions;
            $actions->enterActivating(new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', 'operator'), 'socket');
            $actions->enterActive();
            $actions->admitCircle(new VerifierCircleSnapshot(2, ['circle']));
            if ($phase === StateProtectedModeRuntime::PHASE_VERIFYING) {
                $actions->enterVerifying();
                $actions->issuePass('pass');
                $actions->admitSession('admitted');
            }
            $before = Hilos::$rt->hilosProtectedModeRuntime->toArray();
            $this->mesh->slaves = ['slave'];
            $this->coordinator->onBecameLeader();
            $this->coordinator->tickSlaveSync(time());
            $id = Hilos::$rt->hilosProtectedModeRuntime->freezeId;
            $this->assertNotEmpty($id);
            $after = Hilos::$rt->hilosProtectedModeRuntime->toArray();
            unset($before[StateProtectedModeRuntime::freezeId], $after[StateProtectedModeRuntime::freezeId]);
            $this->assertSame($before, $after);
            $this->assertSame([], $this->mesh->calls);
            $this->assertSame($id, $this->mesh->syncs['slave']->payload->freezeId);
            $this->assertTrue($this->mesh->syncs['slave']->barrierClosed);
            $this->assertSame(['bootstrapFreezeId'], $this->executor->calls);
            $this->coordinator->onLostLeadership();
            $this->coordinator->onBecameLeader();
            $this->assertSame($id, Hilos::$rt->hilosProtectedModeRuntime->freezeId);
            $this->assertSame(['bootstrapFreezeId'], $this->executor->calls);
            $this->assertSame([], $this->coordinator->pendingNodeIds());
        });
    }

    public static function legacyOccupiedPhases(): array
    {
        return [[StateProtectedModeRuntime::PHASE_ACTIVE], [StateProtectedModeRuntime::PHASE_VERIFYING]];
    }

    public function testUnfinishedLegacyPromotionNeitherRestartsStopNorInventsMasterOrLocalAcknowledgements(): void
    {
        $this->withDaemonTruthSource(function (): void {
            Hilos::$rt->hilosProtectedModeRuntime->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null), null,
            );
            $this->mesh->leader = self::SELF;
            $this->mesh->followers = ['master', 'slave'];
            $this->mesh->slaves = ['slave'];
            $this->coordinator->onBecameLeader();
            $this->coordinator->tickSlaveSync(time());
            $frame = $this->mesh->syncs['slave'];
            $this->assertFalse($frame->barrierClosed);
            $this->coordinator->onSynced('slave', new PeerProtectedModeSyncedDTO($frame->syncId, $frame->phase));
            $this->assertSame(['master', self::SELF], $this->coordinator->pendingNodeIds());
            $this->coordinator->onRosterStopped();
            $this->assertSame(['master'], $this->coordinator->pendingNodeIds());
            $this->assertSame(['bootstrapFreezeId'], $this->executor->calls);
            $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, Hilos::$rt->hilosProtectedModeRuntime->phase);
            $this->assertSame([], $this->mesh->calls);
        });
    }

    public function testAConnectedSlaveJoinsAnOpenBarrierAndOnlyItsCurrentSyncedCanCloseItsSlot(): void
    {
        $this->executor->applyRuntime = true;
        $this->withDaemonTruthSource(function (): void {
            $facts = 0;
            $this->coordinator->setChangeWake(static function () use (&$facts): void { ++$facts; });
            $this->mesh->leader = self::SELF;
            $this->coordinator->onBecameLeader();
            $this->coordinator->onEnable('node-b', $this->enableData());
            $this->mesh->slaves = ['slave'];
            $this->coordinator->onSlaveConnected('slave');
            $first = $this->mesh->syncs['slave'];
            $this->assertSame([self::SELF, 'slave'], $this->coordinator->quiesceNodeIds());
            $this->coordinator->onSlaveConnected('slave');
            $current = $this->mesh->syncs['slave'];
            $before = $facts;
            $this->coordinator->onQuiesced('slave');
            $this->coordinator->onSynced('slave', new PeerProtectedModeSyncedDTO($first->syncId, $first->phase));
            $this->coordinator->onSynced('slave', new PeerProtectedModeSyncedDTO($current->syncId, StateProtectedModeRuntime::PHASE_ACTIVE));
            self::assertSame($before, $facts, 'Stale sync or quiesced cannot change the current slave slot');
            $this->coordinator->onRosterStopped();
            $this->assertSame(['slave'], $this->coordinator->pendingNodeIds());
            $before = $facts;
            $this->coordinator->onSynced('slave', new PeerProtectedModeSyncedDTO($current->syncId, $current->phase));
            self::assertGreaterThan($before, $facts);
            $this->assertSame([], $this->coordinator->pendingNodeIds());
            $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
            $calls = $this->mesh->calls;
            $before = $facts;
            $this->coordinator->onSynced('slave', new PeerProtectedModeSyncedDTO($current->syncId, $current->phase));
            $this->assertSame($calls, $this->mesh->calls);
            self::assertSame($before, $facts);
        });
    }

    public function testActiveSlaveSnapshotWaitsForStopAndDuplicateSameIdDoesNotRestartTheWalk(): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'leader';
        $alerts = $this->captureSlaveAlerts(function (): void {
            $this->withDaemonTruthSource(function (): void {
                $this->coordinator->onSync('leader', $this->slaveFrame('sync-1'));
                $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, Hilos::$rt->hilosProtectedModeRuntime->phase);
                $this->assertSame([], $this->mesh->synced);
                $this->coordinator->onSync('leader', $this->slaveFrame('sync-2'));
                $this->assertSame(['enterActivating'], $this->executor->calls);
                $this->coordinator->onSettled('leader');
                $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, Hilos::$rt->hilosProtectedModeRuntime->phase);
                $this->coordinator->onRosterStopped();
                $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
                $this->assertSame([['leader', 'sync-2', StateProtectedModeRuntime::PHASE_ACTIVE]], $this->mesh->synced);
                $this->coordinator->onSync('leader', $this->slaveFrame('sync-3'));
                $this->assertSame(1, count(array_filter($this->executor->calls, static fn(string $call): bool => $call === 'enterActivating')));
                $this->assertNotContains('notifyInitiatorReady', $this->executor->calls);
            });
        });
        $this->assertCount(1, $alerts);
        $this->assertSame('missed-barrier', $alerts[0]['params']['reason']);
        $this->assertNull($alerts[0]['freezeId']);
    }

    public function testVerifyingCatchUpImportsExistingRightsBeforeResumeAndOnlyThenAcknowledges(): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'leader';
        $this->captureSlaveAlerts(function (): void {
            $this->withDaemonTruthSource(function (): void {
                $frame = $this->slaveFrame('window', StateProtectedModeRuntime::PHASE_VERIFYING);
                $this->coordinator->onSync('leader', $frame);
                $this->assertSame([], $this->mesh->synced);
                $this->coordinator->onRosterStopped();
                $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt->hilosProtectedModeRuntime->phase);
                $this->assertSame(['pass'], Hilos::$rt->hilosProtectedModeRuntime->passHashes);
                $this->assertSame(['admitted'], Hilos::$rt->hilosProtectedModeRuntime->admittedSessionTokenHashes);
                $this->assertSame(['circle'], Hilos::$rt->hilosProtectedModeRuntime->circleSessionTokenHashes);
                $this->assertSame(2, Hilos::$rt->hilosProtectedModeRuntime->circleNamedCount);
                $this->assertSame([], $this->mesh->synced);
                $this->assertSame(['enterActivating', 'catchUpSlaveDatabase', 'importSlaveSync', 'enterVerifying'], $this->executor->calls);
                $this->coordinator->onRosterResumed();
                $this->assertSame([['leader', 'window', StateProtectedModeRuntime::PHASE_VERIFYING]], $this->mesh->synced);
                $this->assertSame('finishVerifying', $this->executor->calls[array_key_last($this->executor->calls)]);
                $this->assertNotContains('notifyInitiatorReady', $this->executor->calls);
            });
        });
    }

    /**
     * @param string $phase Target that would release the stopped roster
     * @param bool $complete Whether the numbered local barrier succeeds
     */
    #[DataProvider('databaseCatchUpTargets')]
    public function testDatabaseResultMustSucceedBeforeOpeningResumingOrAcknowledging(string $phase, bool $complete): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'leader';
        $this->captureSlaveAlerts(function () use ($phase, $complete): void {
            $this->withDaemonTruthSource(function () use ($phase, $complete): void {
                $this->coordinator->onSync('leader', $this->slaveFrame('active'));
                $this->coordinator->onRosterStopped();
                $this->mesh->synced = [];
                $this->executor->calls = [];
                $this->executor->delayDatabase = true;
                $frame = $phase === StateProtectedModeRuntime::PHASE_INACTIVE
                    ? $this->inactiveSlaveFrame('release') : $this->slaveFrame('release', $phase);
                $this->coordinator->onSync('leader', $frame);
                $this->coordinator->onVerify('leader', 'backup', 0);
                $this->coordinator->onLift('leader');
                $this->coordinator->tickSlaveSync(time());
                $this->assertSame(['catchUpSlaveDatabase'], $this->executor->calls);
                $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
                $this->assertSame([], $this->mesh->synced);

                $finish = $this->executor->databaseContinuations[0];
                $finish(new ReHydrateVerdict(null, null, $complete, $complete ? [] : ['worker #1: read failed']));
                $this->coordinator->tickSlaveSync(time());
                if (!$complete) {
                    $finish(new ReHydrateVerdict(null, null, true, []));
                    $this->coordinator->tickSlaveSync(time());
                    $this->assertSame(['catchUpSlaveDatabase'], $this->executor->calls);
                    $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
                    $this->assertSame([], $this->mesh->synced);
                    return;
                }
                $this->assertSame($phase, Hilos::$rt->hilosProtectedModeRuntime->phase);
                $this->assertSame([], $this->mesh->synced, 'The actual roster resume is still outstanding');
                $this->coordinator->onRosterResumed();
                $this->assertSame([['leader', 'release', $phase]], $this->mesh->synced);
            });
        });
    }

    /** @return iterable<string, array{string, bool}> Both release phases and barrier outcomes */
    public static function databaseCatchUpTargets(): iterable
    {
        foreach ([StateProtectedModeRuntime::PHASE_VERIFYING, StateProtectedModeRuntime::PHASE_INACTIVE] as $phase) {
            yield $phase . '-success' => [$phase, true];
            yield $phase . '-failure' => [$phase, false];
        }
    }

    /** @param string $change Context invalidating the outstanding database result */
    #[DataProvider('databaseCatchUpContextChanges')]
    public function testOldDatabaseResultCannotOpenOrAcknowledgeANewerTarget(string $change): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'leader';
        $this->captureSlaveAlerts(function () use ($change): void {
            $this->withDaemonTruthSource(function () use ($change): void {
                $this->coordinator->onSync('leader', $this->slaveFrame('active'));
                $this->coordinator->onRosterStopped();
                $this->mesh->synced = [];
                $this->executor->calls = [];
                $this->executor->delayDatabase = true;
                $this->coordinator->onSync('leader', $this->slaveFrame('old-window', StateProtectedModeRuntime::PHASE_VERIFYING));
                if ($change === 'leader') {
                    $this->mesh->leader = 'next';
                } elseif ($change === 'link') {
                    $this->mesh->syncCurrent = false;
                } else {
                    $next = $change === 'phase' ? $this->inactiveSlaveFrame('current')
                        : $this->slaveFrame('current', StateProtectedModeRuntime::PHASE_VERIFYING, generation: 'next-generation');
                    $this->coordinator->onSync('leader', $next);
                }
                $finishOld = $this->executor->databaseContinuations[0];
                $finishOld(new ReHydrateVerdict(null, null, true, []));
                $this->coordinator->tickSlaveSync(time());
                $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
                $this->assertSame([], $this->mesh->synced);
                $this->assertNotContains('enterVerifying', $this->executor->calls);
                $this->assertNotContains('enterInactive', $this->executor->calls);
                if ($change === 'leader' || $change === 'link') {
                    $this->mesh->syncCurrent = true;
                    $this->coordinator->onSync($this->mesh->leader,
                        $this->slaveFrame('current', StateProtectedModeRuntime::PHASE_VERIFYING));
                }
                $this->assertCount(2, $this->executor->databaseContinuations);
                $finishOld(new ReHydrateVerdict(null, null, true, []));
                $this->coordinator->tickSlaveSync(time());
                $this->assertSame([], $this->mesh->synced);
                $this->executor->databaseContinuations[1](new ReHydrateVerdict(null, null, true, []));
                $this->coordinator->tickSlaveSync(time());
                $this->coordinator->onRosterResumed();
                $expectedPhase = $change === 'phase' ? StateProtectedModeRuntime::PHASE_INACTIVE : StateProtectedModeRuntime::PHASE_VERIFYING;
                $this->assertSame([[$this->mesh->leader, 'current', $expectedPhase]], $this->mesh->synced);
                if ($change === 'generation') {
                    $this->assertSame('next-generation', Hilos::$rt->hilosProtectedModeRuntime->freezeId);
                }
            });
        });
    }

    /** @return iterable<string, array{string}> Context that must invalidate an old completion */
    public static function databaseCatchUpContextChanges(): iterable
    {
        foreach (['phase', 'generation', 'leader', 'link'] as $change) {
            yield $change => [$change];
        }
    }

    /** @param string $refresh Snapshot replacement while the old generation is still persisted */
    #[DataProvider('missedBarrierSnapshotRefreshes')]
    public function testMissedBarrierWarningSurvivesSnapshotReplacementUntilDatabaseCatchUpCompletes(string $refresh): void
    {
        $this->executor->applyRuntime = true;
        $this->executor->delayDatabase = true;
        $this->mesh->leader = 'leader';
        $alerts = $this->captureSlaveAlerts(function () use ($refresh): void {
            $this->withDaemonTruthSource(function () use ($refresh): void {
                Hilos::$rt->hilosProtectedModeRuntime->actions->importSlaveSync($this->slaveFrame('stored', generation: 'F1'),
                    StateProtectedModeRuntime::PHASE_ACTIVE);
                $this->coordinator->onSync('leader', $this->slaveFrame('R1', StateProtectedModeRuntime::PHASE_VERIFYING, 'F2'));
                $this->assertSame(['catchUpSlaveDatabase'], $this->executor->calls);
                if ($refresh === 'reconnect') {
                    $this->mesh->syncCurrent = false;
                    $this->coordinator->tickSlaveSync(100);
                    $this->mesh->syncCurrent = true;
                } elseif ($refresh === 'phase') {
                    $this->coordinator->onSync('leader', $this->inactiveSlaveFrame('interim-lift'));
                }
                $current = $this->slaveFrame('R2', StateProtectedModeRuntime::PHASE_VERIFYING, 'F2');
                if ($refresh === 'rights') {
                    $current = new PeerProtectedModeSyncDTO($current->syncId, $current->phase, $current->entryMode,
                        $current->payload, $current->barrierClosed, ['new-pass'], ['new-admission'], 3, ['new-circle']);
                }
                $this->coordinator->onSync('leader', $current);
                $finishOld = $this->executor->databaseContinuations[0];
                $finishOld(new ReHydrateVerdict(null, null, true, []));
                $this->coordinator->tickSlaveSync(101);
                $this->assertSame('F1', Hilos::$rt->hilosProtectedModeRuntime->freezeId);
                $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
                $this->assertSame(['catchUpSlaveDatabase', 'catchUpSlaveDatabase'], $this->executor->calls);
                $this->assertSame([], $this->mesh->synced);

                $round = new ReHydrateRound();
                $round->start(2, [ReHydrateRound::daemonParticipant(), ReHydrateRound::workerParticipant(1)], 200.0);
                $round->ack(2, ReHydrateRound::daemonParticipant(), true, null);
                $this->assertFalse($round->isSettled());
                $finishOld(new ReHydrateVerdict(null, null, true, []));
                $this->coordinator->tickSlaveSync(102);
                $this->assertSame('F1', Hilos::$rt->hilosProtectedModeRuntime->freezeId);
                $this->assertSame([], $this->mesh->synced);
                $round->ack(2, ReHydrateRound::workerParticipant(1), true, null);
                $this->executor->databaseContinuations[1](new ReHydrateVerdict(null, null, $round->isComplete(), $round->problems()));
                $this->coordinator->tickSlaveSync(103);
                $this->assertSame('F2', Hilos::$rt->hilosProtectedModeRuntime->freezeId);
                $this->assertSame($current->passHashes, Hilos::$rt->hilosProtectedModeRuntime->passHashes);
                $this->assertSame([], $this->mesh->synced, 'The current database result still owes the actual resume');
                $this->coordinator->onRosterResumed();
                $this->assertSame([['leader', 'R2', StateProtectedModeRuntime::PHASE_VERIFYING]], $this->mesh->synced);
                $this->assertNotContains('enterActivating', $this->executor->calls, 'The stopped initiator roster is reused');

                $this->coordinator = new ClusterProtectedMode(self::SELF, $this->mesh, $this->executor);
                $this->coordinator->onSync('leader', $this->slaveFrame('restart', StateProtectedModeRuntime::PHASE_VERIFYING, 'F2'));
                $this->executor->databaseContinuations[2](new ReHydrateVerdict(null, null, true, []));
                $this->coordinator->tickSlaveSync(104);
                $this->assertSame(['leader', 'restart', StateProtectedModeRuntime::PHASE_VERIFYING], $this->mesh->synced[1]);
            });
        });
        $this->assertCount(1, $alerts);
        $this->assertSame('missed-barrier', $alerts[0]['params']['reason']);
        $this->assertSame('F1', $alerts[0]['freezeId'], 'The warning precedes importing the new generation');
    }

    /** @return iterable<string, array{string}> Ordinary replacements that retain the freeze generation */
    public static function missedBarrierSnapshotRefreshes(): iterable
    {
        foreach (['reconnect', 'rights', 'phase'] as $refresh) {
            yield $refresh => [$refresh];
        }
    }

    /** @param string $failure Settled database result that cannot apply the new generation */
    #[DataProvider('missedBarrierDatabaseFailures')]
    public function testMissedBarrierWarningSurvivesFailedCatchUpAndRetriesButANewGenerationWarnsAgain(string $failure): void
    {
        $this->executor->applyRuntime = true;
        $this->executor->delayDatabase = true;
        $this->mesh->leader = 'leader';
        $alerts = $this->captureSlaveAlerts(function () use ($failure): void {
            $this->withDaemonTruthSource(function () use ($failure): void {
                Hilos::$rt->hilosProtectedModeRuntime->actions->importSlaveSync($this->slaveFrame('stored', generation: 'F1'),
                    StateProtectedModeRuntime::PHASE_ACTIVE);
                $this->coordinator->onSync('leader', $this->slaveFrame('R1', StateProtectedModeRuntime::PHASE_VERIFYING, 'F2'));
                $finishFailed = $this->executor->databaseContinuations[0];
                $finishFailed(new ReHydrateVerdict(null, null, false, [$failure]));
                $this->coordinator->tickSlaveSync(100);
                $finishFailed(new ReHydrateVerdict(null, null, true, []));
                $this->coordinator->tickSlaveSync(101);
                $this->assertSame(['catchUpSlaveDatabase'], $this->executor->calls);
                $this->assertSame('F1', Hilos::$rt->hilosProtectedModeRuntime->freezeId);
                $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
                $this->assertSame([], $this->mesh->synced);
                $this->coordinator->onSync('leader', $this->slaveFrame('retry', StateProtectedModeRuntime::PHASE_VERIFYING, 'F2'));
                $this->assertSame(['catchUpSlaveDatabase', 'catchUpSlaveDatabase'], $this->executor->calls);
                $this->assertSame('F1', Hilos::$rt->hilosProtectedModeRuntime->freezeId);
                $this->assertSame([], $this->mesh->synced);

                $this->coordinator->onSync('leader', $this->slaveFrame('new', StateProtectedModeRuntime::PHASE_VERIFYING, 'F3'));
                $this->executor->databaseContinuations[1](new ReHydrateVerdict(null, null, true, []));
                $this->coordinator->tickSlaveSync(102);
                $this->assertSame('F1', Hilos::$rt->hilosProtectedModeRuntime->freezeId);
                $this->assertSame([], $this->mesh->synced);
                $this->assertSame(['catchUpSlaveDatabase', 'catchUpSlaveDatabase', 'catchUpSlaveDatabase'], $this->executor->calls);
                $this->executor->databaseContinuations[2](new ReHydrateVerdict(null, null, true, []));
                $this->coordinator->tickSlaveSync(103);
                $this->assertSame('F3', Hilos::$rt->hilosProtectedModeRuntime->freezeId);
                $this->assertSame([], $this->mesh->synced);
                $this->coordinator->onRosterResumed();
                $this->assertSame([['leader', 'new', StateProtectedModeRuntime::PHASE_VERIFYING]], $this->mesh->synced);
                $this->assertNotContains('enterInactive', $this->executor->calls);
                $this->assertNotContains('enterActivating', $this->executor->calls);
            });
        });
        $this->assertCount(2, $alerts, 'Each generation warns once despite retries and failed or stale results');
        $this->assertSame(['F1', 'F1'], array_column($alerts, 'freezeId'));
        $this->assertSame(['missed-barrier', 'missed-barrier'], array_column(array_column($alerts, 'params'), 'reason'));
    }

    /** @return iterable<string, array{string}> Negative, expired and superseded local barrier verdicts */
    public static function missedBarrierDatabaseFailures(): iterable
    {
        foreach (['worker #1: read failed', 'worker #1: timeout', 'superseded'] as $failure) {
            yield $failure => [$failure];
        }
    }

    public function testNewerInactiveTargetAndLateOrdinaryLiftCannotOvertakeAnUnfinishedStop(): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'leader';
        $this->captureSlaveAlerts(function (): void {
            $this->withDaemonTruthSource(function (): void {
                $this->coordinator->onSync('leader', $this->slaveFrame('active'));
                $this->coordinator->onSync('leader', $this->slaveFrame('window', StateProtectedModeRuntime::PHASE_VERIFYING));
                $this->coordinator->onVerify('leader', 'backup', 0);
                $this->coordinator->onSync('leader', $this->inactiveSlaveFrame('lift'));
                $this->coordinator->onLift('leader');
                $this->assertSame(['enterActivating'], $this->executor->calls);
                $this->coordinator->onRosterStopped();
                $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
                $this->assertSame([], $this->mesh->synced);
                $this->assertNotContains('enterVerifying', $this->executor->calls);
                $this->coordinator->onRosterResumed();
                $this->assertSame([['leader', 'lift', StateProtectedModeRuntime::PHASE_INACTIVE]], $this->mesh->synced);
                $this->assertSame('finishLift', $this->executor->calls[array_key_last($this->executor->calls)]);
                $this->coordinator->onLift('leader');
                $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
            });
        });
    }

    public function testTargetChangeWhileResumingWaitsForTheActualResumeBeforeApplyingANewFreeze(): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'leader';
        $this->captureSlaveAlerts(function (): void {
            $this->withDaemonTruthSource(function (): void {
                $this->coordinator->onSync('leader', $this->slaveFrame('window', StateProtectedModeRuntime::PHASE_VERIFYING));
                $this->coordinator->onRosterStopped();
                $this->coordinator->onSync('leader', $this->slaveFrame('refreeze', generation: 'next-generation'));
                $this->assertSame('generation', Hilos::$rt->hilosProtectedModeRuntime->freezeId);
                $this->assertSame([], $this->mesh->synced);
                $this->coordinator->onRosterResumed();
                $this->assertSame('next-generation', Hilos::$rt->hilosProtectedModeRuntime->freezeId);
                $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, Hilos::$rt->hilosProtectedModeRuntime->phase);
                $this->coordinator->onRosterStopped();
                $this->assertSame([['leader', 'refreeze', StateProtectedModeRuntime::PHASE_ACTIVE]], $this->mesh->synced);
            });
        });
    }

    public function testDirectWindowSyncNeverStopsOrWarnsAndImportsTheExistingCircle(): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'leader';
        $alerts = $this->captureSlaveAlerts(function (): void {
            $this->withDaemonTruthSource(function (): void {
                $this->coordinator->onSync('leader', $this->slaveFrame('direct', StateProtectedModeRuntime::PHASE_VERIFYING,
                    entryMode: StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW));
                $this->assertSame(['catchUpSlaveDatabase', 'importSlaveSync', 'finishVerifying'], $this->executor->calls);
                $this->assertSame(['circle'], Hilos::$rt->hilosProtectedModeRuntime->circleSessionTokenHashes);
                $this->assertSame([['leader', 'direct', StateProtectedModeRuntime::PHASE_VERIFYING]], $this->mesh->synced);
            });
        });
        $this->assertSame([], $alerts);
    }

    public function testAnActivatingRowWithTheSameIdCannotInventACompletedStopAfterRestart(): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'leader';
        $this->withDaemonTruthSource(function (): void {
            Hilos::$rt->hilosProtectedModeRuntime->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, 'generation'), null,
            );
            $this->coordinator->onSync('leader', $this->slaveFrame('active'));
            $this->coordinator->tickSlaveSync(time());
            $this->assertSame([], $this->executor->calls);
            $this->assertSame([], $this->mesh->synced);
            $this->coordinator->onRosterStopped();
            $this->assertSame([['leader', 'active', StateProtectedModeRuntime::PHASE_ACTIVE]], $this->mesh->synced);
        });
    }

    public function testAChangedInitiatorDuringAnUnfinishedStopNeedsAnotherActualCompletion(): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'leader';
        $this->withDaemonTruthSource(function (): void {
            $first = $this->slaveFrame('first', StateProtectedModeRuntime::PHASE_ACTIVATING);
            $this->coordinator->onSync('leader', $first);
            $next = new PeerProtectedModeSyncDTO('next', StateProtectedModeRuntime::PHASE_ACTIVATING,
                StateProtectedModeRuntime::ENTRY_MODE_FREEZE,
                new ProtectedModeQuiesceData('restore', 'index', null, self::SELF, null, 'next-generation'), false, [], [], 0, []);
            $this->coordinator->onSync('leader', $next);
            $this->assertSame(['enterActivating'], $this->executor->calls);
            $this->coordinator->onRosterStopped();
            $this->assertSame(['enterActivating', 'enterActivating'], $this->executor->calls);
            $this->assertSame([], $this->mesh->synced, 'The previous initiator has only just been queued for stop');
            $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, Hilos::$rt->hilosProtectedModeRuntime->phase);
            $this->coordinator->onSync('leader', $next);
            $this->coordinator->onRosterStopped();
            $this->assertSame([['leader', 'next', StateProtectedModeRuntime::PHASE_ACTIVATING]], $this->mesh->synced);
        });
    }

    public function testOldLeaderOrReplacedConnectionCannotFinishAnOutstandingSlaveEffect(): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'old';
        $this->captureSlaveAlerts(function (): void {
            $this->withDaemonTruthSource(function (): void {
                $this->coordinator->onSync('old', $this->slaveFrame('old-active'));
                $this->mesh->leader = 'next';
                $this->coordinator->onSync('old', $this->inactiveSlaveFrame('unauthorized-lift'));
                $this->coordinator->onRosterStopped();
                $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, Hilos::$rt->hilosProtectedModeRuntime->phase);
                $this->assertSame([], $this->mesh->synced);
                $this->coordinator->onSync('next', $this->slaveFrame('new-active', generation: 'legacy-successor-id'));
                $this->assertSame([['next', 'new-active', StateProtectedModeRuntime::PHASE_ACTIVE]], $this->mesh->synced);
                $this->assertSame('legacy-successor-id', Hilos::$rt->hilosProtectedModeRuntime->freezeId);
                $this->assertSame(1, count(array_filter($this->executor->calls, static fn(string $call): bool => $call === 'enterActivating')));
            });
        });
    }

    #[DataProvider('syncDeadlinePhases')]
    public function testSlaveDeadlineIsIndependentOfProgressAndReconnectPreservesItsClockAndAlert(string $phase): void
    {
        $this->executor->applyRuntime = true;
        $alerts = $this->captureSlaveAlerts(function () use ($phase): void {
            $this->withDaemonTruthSource(function () use ($phase): void {
                $this->mesh->leader = self::SELF;
                if ($phase !== StateProtectedModeRuntime::PHASE_INACTIVE) {
                    Hilos::$rt->hilosProtectedModeRuntime->actions->enterActivating(
                        new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, 'generation'), null,
                    );
                    Hilos::$rt->hilosProtectedModeRuntime->actions->enterActive();
                    if ($phase === StateProtectedModeRuntime::PHASE_VERIFYING) {
                        Hilos::$rt->hilosProtectedModeRuntime->actions->enterVerifying();
                    }
                }
                $this->coordinator->onBecameLeader();
                $now = time();
                $this->mesh->slaves = ['slave'];
                $this->coordinator->onSlaveConnected('slave');
                $this->coordinator->tickSlaveSync($now + 29);
                $this->coordinator->onSlaveConnected('slave');
                Hilos::$rt->hilosProtectedModeRuntime->actions->markProgress();
                $this->coordinator->tickSlaveSync($now + 30);
                $this->coordinator->onSlaveConnected('slave');
                $this->coordinator->tickSlaveSync($now + 90);
                $frame = $this->mesh->syncs['slave'];
                $this->coordinator->onSynced('slave', new PeerProtectedModeSyncedDTO($frame->syncId, $phase));
                $this->coordinator->tickSlaveSync($now + 120);
            });
        });
        $this->assertCount(1, $alerts);
        $this->assertSame('sync-overdue', $alerts[0]['params']['reason']);
        $this->assertSame($phase, $alerts[0]['params']['phase']);
        $this->assertSame(30, $alerts[0]['params']['timeoutSeconds']);
    }

    public static function syncDeadlinePhases(): array
    {
        return [[StateProtectedModeRuntime::PHASE_ACTIVE], [StateProtectedModeRuntime::PHASE_VERIFYING],
            [StateProtectedModeRuntime::PHASE_INACTIVE]];
    }

    public function testCurrentLeaderCanLiftARestoredActivatingRowWithoutInventingItsLostStopCallback(): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'leader';
        $this->withDaemonTruthSource(function (): void {
            Hilos::$rt->hilosProtectedModeRuntime->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, 'generation'), null,
            );
            $this->coordinator->onSync('leader', $this->inactiveSlaveFrame('lift'));
            $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, Hilos::$rt->hilosProtectedModeRuntime->phase);
            $this->assertSame(['catchUpSlaveDatabase', 'enterInactive'], $this->executor->calls);
            $this->assertSame([], $this->mesh->synced);
            $this->coordinator->onRosterResumed();
            $this->assertSame([['leader', 'lift', StateProtectedModeRuntime::PHASE_INACTIVE]], $this->mesh->synced);
        });
    }

    public function testAlreadyInactiveSyncAcknowledgesWithoutReloadingUninvolvedBrowsers(): void
    {
        $this->mesh->leader = 'leader';
        $this->withDaemonTruthSource(function (): void {
            $this->coordinator->onSync('leader', $this->inactiveSlaveFrame('idle-1'));
            $this->coordinator->onSync('leader', $this->inactiveSlaveFrame('idle-2'));
            $this->assertSame([], $this->executor->calls);
            $this->assertSame([['leader', 'idle-1', StateProtectedModeRuntime::PHASE_INACTIVE],
                ['leader', 'idle-2', StateProtectedModeRuntime::PHASE_INACTIVE]], $this->mesh->synced);
        });
    }

    public function testNormalRefreezeCancelsItsOldResumeWithoutLeavingSyncWaitingForACancelledCallback(): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'leader';
        $this->withDaemonTruthSource(function (): void {
            $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, 'generation');
            $this->coordinator->onQuiesce('leader', $freeze);
            $this->coordinator->onRosterStopped();
            $this->coordinator->onSettled('leader');
            $this->coordinator->onVerify('leader', 'backup', 0);
            $this->coordinator->onQuiesce('leader', $freeze->withFreezeId('next-generation'));
            $this->coordinator->onSync('leader', $this->slaveFrame('new-active', generation: 'next-generation'));
            $this->coordinator->onRosterStopped();
            $this->assertSame([['leader', 'new-active', StateProtectedModeRuntime::PHASE_ACTIVE]], $this->mesh->synced);
        });
    }

    public function testNormalLiftCancelsItsOldStopWithoutLeavingSyncWaitingForACancelledCallback(): void
    {
        $this->executor->applyRuntime = true;
        $this->mesh->leader = 'leader';
        $this->withDaemonTruthSource(function (): void {
            $this->coordinator->onQuiesce('leader', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, 'generation'));
            $this->coordinator->onLift('leader');
            $this->coordinator->onSync('leader', $this->inactiveSlaveFrame('lift'));
            $this->coordinator->onRosterResumed();
            $this->assertSame([['leader', 'lift', StateProtectedModeRuntime::PHASE_INACTIVE]], $this->mesh->synced);
        });
    }

    public function testUnacknowledgedLiftRetainsTheOperationAndDeadlineWhileANewGenerationResetsDeduplication(): void
    {
        $this->executor->applyRuntime = true;
        $alerts = $this->captureSlaveAlerts(function (): void {
            $this->withDaemonTruthSource(function (): void {
                $this->mesh->leader = self::SELF;
                Hilos::$rt->hilosProtectedModeRuntime->actions->enterActivating(
                    new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, 'generation'), null,
                );
                Hilos::$rt->hilosProtectedModeRuntime->actions->enterActive();
                $this->coordinator->onBecameLeader();
                $this->mesh->slaves = ['slave'];
                $now = time();
                $this->coordinator->onSlaveConnected('slave');
                $active = $this->mesh->syncs['slave'];
                $this->coordinator->onDisable('node-b', 'backup', 0);
                $this->coordinator->tickSlaveSync($now + 29);
                $inactive = $this->mesh->syncs['slave'];
                $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $inactive->phase);
                $this->coordinator->onSynced('slave', new PeerProtectedModeSyncedDTO($active->syncId, $active->phase));
                $this->coordinator->onSlaveConnected('slave');
                $this->coordinator->tickSlaveSync($now + 30);
                $this->coordinator->onEnable('node-b', $this->enableData());
                $this->coordinator->tickSlaveSync($now + 31);
                $this->coordinator->tickSlaveSync($now + 60);
                $this->coordinator->tickSlaveSync($now + 61);
            });
        });
        $this->assertCount(2, $alerts);
        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $alerts[0]['params']['phase']);
        $this->assertSame('restore', $alerts[0]['params']['operation']);
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, $alerts[1]['params']['phase']);
    }

    public function testASlaveReturningAfterTheBarrierDoesNotBecomeAHistoricalParticipantOrRepeatReady(): void
    {
        $this->executor->applyRuntime = true;
        $this->withDaemonTruthSource(function (): void {
            $this->mesh->leader = self::SELF;
            $this->coordinator->onBecameLeader();
            $this->coordinator->onEnable('node-b', $this->enableData());
            $this->coordinator->onRosterStopped();
            $round = $this->coordinator->quiesceNodeIds();
            $calls = $this->mesh->calls;
            $this->mesh->slaves = ['slave'];
            $this->coordinator->onSlaveConnected('slave');
            $frame = $this->mesh->syncs['slave'];
            $this->assertTrue($frame->barrierClosed);
            $this->coordinator->onSynced('slave', new PeerProtectedModeSyncedDTO($frame->syncId, $frame->phase));
            $this->assertSame($round, $this->coordinator->quiesceNodeIds());
            $this->assertSame($calls, $this->mesh->calls);
        });
    }

    public function testSlaveInitiatorAddressesItsPlacementLeaderForEveryFreezeRequest(): void
    {
        $server = $this->usePlacementPeerMesh();
        $leader = $this->protectedModePeerLink($server, 'leader', NodeRole::Master);
        $other = $this->protectedModePeerLink($server, 'other', NodeRole::Master);
        socket_write($leader[1], new PeerPlacementQueryDTO()->toJson() . "\n");
        $leader[0]->read();
        $this->protectedModePeerFrames($leader);
        $this->protectedModePeerFrames($other);
        $this->assertNull(Hilos::$cluster->leadership()->leaderId());
        $this->assertSame('leader', $server->leaderNodeId());

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));
        $this->coordinator->requestVerify(new ProtectedModeVerifySignalData('backup', 0));
        $this->coordinator->requestDisable($this->disableData());
        $this->coordinator->requestProgress($this->progressData());
        $this->coordinator->requestPass(new ProtectedModePassSignalData('backup', 0, 'pass-hash'));
        $this->coordinator->requestAdmit('pass-hash', 'session-hash');
        $this->coordinator->requestCircle($this->circleData());
        $this->coordinator->requestRefreeze(new ProtectedModeRefreezeSignalData('backup', 0));

        $frames = $this->protectedModePeerFrames($leader);
        $this->assertSame([
            PeerProtectedModeEnableDTO::MESSAGE_TYPE,
            PeerProtectedModeVerifyDTO::MESSAGE_TYPE,
            PeerProtectedModeDisableDTO::MESSAGE_TYPE,
            PeerProtectedModeProgressDTO::MESSAGE_TYPE,
            PeerProtectedModePassDTO::MESSAGE_TYPE,
            PeerProtectedModeAdmitDTO::MESSAGE_TYPE,
            PeerProtectedModeCircleDTO::MESSAGE_TYPE,
            PeerProtectedModeRefreezeDTO::MESSAGE_TYPE,
        ], array_map(static fn(PeerDTO $frame): string => $frame->getType(), $frames));
        $this->assertSame(self::SELF, $frames[0]->data->initiatorNodeId);
        $this->assertSame('backup', $frames[0]->data->initiatorAgentType);
        $this->assertSame(0, $frames[0]->data->initiatorAgentIndex);
        foreach ([1, 2, 3, 4, 6, 7] as $index) {
            $this->assertSame('backup', $frames[$index]->initiatorAgentType);
            $this->assertSame(0, $frames[$index]->initiatorAgentIndex);
        }
        $this->assertSame('pass-hash', $frames[4]->passHash);
        $this->assertSame('session-hash', $frames[5]->sessionTokenHash);
        $this->assertSame(['hash-a'], $frames[6]->snapshot->sessionTokenHashes);
        $this->assertSame([], $this->protectedModePeerFrames($other));
        $this->assertSame([], $this->relay->refusedCalls);
    }

    public function testSlaveWithoutPlacementLeaderRefusesDespiteAuthenticatedMasters(): void
    {
        $server = $this->usePlacementPeerMesh();
        $master = $this->protectedModePeerLink($server, 'master', NodeRole::Master);
        $this->protectedModePeerFrames($master);

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));

        $this->assertNull($server->leaderNodeId());
        $this->assertSame([], $this->protectedModePeerFrames($master));
        $this->assertSame([
            ['agentType' => 'backup', 'agentIndex' => '0', 'reason' => ProtectedModeRefusalCopy::NO_LEADER],
        ], $this->relay->refusedCalls);
    }

    public function testSlaveForgetsReleasedLeaderAndAddressesThePlacementSuccessor(): void
    {
        $server = $this->usePlacementPeerMesh();
        $old = $this->protectedModePeerLink($server, 'old', NodeRole::Master);
        $next = $this->protectedModePeerLink($server, 'next', NodeRole::Master);
        socket_write($old[1], new PeerPlacementQueryDTO()->toJson() . "\n");
        $old[0]->read();
        $this->assertSame('old', $server->leaderNodeId());
        socket_write($next[1], new PeerPlacementReleaseDTO()->toJson() . "\n");
        $next[0]->read();
        $this->assertSame('old', $server->leaderNodeId());

        socket_write($old[1], new PeerPlacementReleaseDTO()->toJson() . "\n");
        $old[0]->read();
        $this->assertNull($server->leaderNodeId());
        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));
        $this->assertSame(ProtectedModeRefusalCopy::NO_LEADER, $this->relay->refusedCalls[0]['reason']);

        socket_write($next[1], new PeerPlacementQueryDTO()->toJson() . "\n");
        $next[0]->read();
        socket_write($old[1], new PeerPlacementReleaseDTO()->toJson() . "\n");
        $old[0]->read();
        $this->protectedModePeerFrames($old);
        $this->protectedModePeerFrames($next);
        $this->assertSame('next', $server->leaderNodeId());
        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));

        $this->assertSame([], $this->protectedModePeerFrames($old));
        $frames = $this->protectedModePeerFrames($next);
        $this->assertCount(1, $frames);
        $this->assertInstanceOf(PeerProtectedModeEnableDTO::class, $frames[0]);
    }

    public function testSlaveDoesNotAddressAPlacementLeaderWhoseWorkingLinkWasLost(): void
    {
        $server = $this->usePlacementPeerMesh();
        $leader = $this->protectedModePeerLink($server, 'leader', NodeRole::Master);
        $other = $this->protectedModePeerLink($server, 'other', NodeRole::Master);
        socket_write($leader[1], new PeerPlacementQueryDTO()->toJson() . "\n");
        $leader[0]->read();
        $this->assertSame('leader', $server->leaderNodeId());
        $this->protectedModePeerFrames($leader);
        $this->protectedModePeerFrames($other);

        $leader[0]->markShouldClose();
        $this->assertNull($server->leaderNodeId());
        $server->onLinkClosed($leader[0]);
        $this->assertNull($server->leaderNodeId());
        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));

        $this->assertSame(ProtectedModeRefusalCopy::NO_LEADER, $this->relay->refusedCalls[0]['reason']);
        $this->assertSame([], $this->protectedModePeerFrames($leader));
        $this->assertSame([], $this->protectedModePeerFrames($other));
    }

    public function testMasterUsesConsensusAndDoesNotFallBackToThePlacingLeaderAfterLoss(): void
    {
        $server = $this->usePlacementPeerMesh(NodeRole::Master);
        $placing = $this->protectedModePeerLink($server, 'placing', NodeRole::Master);
        $leader = $this->protectedModePeerLink($server, 'leader', NodeRole::Master);
        socket_write($placing[1], new PeerPlacementQueryDTO()->toJson() . "\n");
        $placing[0]->read();
        $this->protectedModePeerFrames($placing);
        $this->protectedModePeerFrames($leader);
        $consensus = new ClusterCoordinator(
            new ClusterConsensusConfig(self::SELF, [self::SELF, 'placing', 'leader'], 2, 1000, 2000, 100),
            $server,
            Hilos::$cluster->leadershipObserver(),
        );
        Hilos::$cluster->registerLeadership($consensus);
        $consensus->onHeartbeat(new PeerHeartbeatDTO(1, 'leader', false));

        $this->assertSame('leader', $server->leaderNodeId());
        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));
        $frames = $this->protectedModePeerFrames($leader);
        $this->assertCount(1, $frames);
        $this->assertInstanceOf(PeerProtectedModeEnableDTO::class, $frames[0]);
        $this->assertSame([], $this->protectedModePeerFrames($placing));

        $consensus->noteNodeOffline('leader');
        $this->assertNull($server->leaderNodeId());
        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));
        $this->assertSame(ProtectedModeRefusalCopy::NO_LEADER, $this->relay->refusedCalls[0]['reason']);
        $this->assertSame([], $this->protectedModePeerFrames($placing));
        $this->assertSame([], $this->protectedModePeerFrames($leader));
    }

    /** @param bool $selfFirst Whether the leader's own roster stops before its slave's */
    #[DataProvider('rosterStopOrders')]
    public function testOnlineMasterSlaveAndSelfMustAllStopBeforeTheSlaveInitiatorGetsReady(bool $selfFirst): void
    {
        $server = $this->protectedModePeerServer();
        $this->protectedModePeerLink($server, 'master', NodeRole::Master);
        $this->protectedModePeerLink($server, 'slave', NodeRole::Slave);
        $this->mesh->followers = $server->followerNodeIds();
        $this->mesh->location = AgentLocation::onNode('slave');
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('slave', $this->enableDataFrom('slave'));
        $this->assertSame(['master', 'slave', self::SELF], $this->coordinator->quiesceNodeIds());
        if ($selfFirst) {
            $this->stopTheRoster();
        }
        $this->coordinator->onQuiesced('master');
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);

        $this->coordinator->onQuiesced('slave');
        if (!$selfFirst) {
            $this->assertSame([self::SELF], $this->coordinator->pendingNodeIds());
            $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);
            $this->stopTheRoster();
        }

        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([
            ['broadcastQuiesce', 'restore'], ['broadcastSettled', null], ['sendReady', 'slave'],
        ], $this->mesh->calls);
    }

    /** @return iterable<string, array{bool}> The two possible final stop orders */
    public static function rosterStopOrders(): iterable
    {
        yield 'slave last' => [true];
        yield 'self last' => [false];
    }

    public function testADirectWindowWaitsForOnlineMasterAndSlaveWithoutStoppingSelf(): void
    {
        $server = $this->protectedModePeerServer();
        $this->protectedModePeerLink($server, 'master', NodeRole::Master);
        $this->protectedModePeerLink($server, 'slave', NodeRole::Slave);
        $this->mesh->followers = $server->followerNodeIds();
        $this->mesh->location = AgentLocation::onNode('slave');
        $this->recordDirectWindowTransitions();
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('slave', $this->directWindowData(initiatorNodeId: 'slave'));
        $this->coordinator->onRosterStopped();
        $this->coordinator->onQuiesced('master');

        $this->assertSame(['slave'], $this->coordinator->pendingNodeIds());
        $this->assertSame([['broadcastWindow', 'manual_maintenance']], $this->mesh->calls);
        $this->coordinator->onQuiesced('slave');
        $this->assertSame(['master', 'slave'], $this->coordinator->quiesceNodeIds());
        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame([
            ['broadcastWindow', 'manual_maintenance'], ['sendReady', 'slave'],
        ], $this->mesh->calls);
    }

    public function testASlaveStopsBeforeAcknowledgingAndRelaysReadyThenVerifiesAndLifts(): void
    {
        $server = $this->protectedModePeerServer(NodeRole::Slave);
        $leader = $this->protectedModePeerLink($server, 'leader', NodeRole::Master);
        $this->protectedModePeerFrames($leader);
        $this->coordinator = new ClusterProtectedMode(self::SELF, $server, $this->executor);
        $server->registerProtectedMode($this->coordinator);
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF, null, 'generation-a');

        socket_write($leader[1], new PeerProtectedModeQuiesceDTO($freeze)->toJson() . "\n");
        $leader[0]->read();
        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([], $this->protectedModePeerFrames($leader));

        $this->stopTheRoster();
        $acks = $this->protectedModePeerFrames($leader);
        $this->assertCount(1, $acks);
        $this->assertInstanceOf(PeerProtectedModeQuiescedDTO::class, $acks[0]);

        socket_write($leader[1], new PeerProtectedModeSettledDTO()->toJson() . "\n"
            . new PeerProtectedModeReadyDTO()->toJson() . "\n");
        $leader[0]->read();
        $this->assertSame(['enterActivating', 'enterActive', 'notifyInitiatorReady'], $this->executor->calls);
        $this->settleTheFreezeOnTheRuntimeRow();

        socket_write($leader[1], new PeerProtectedModeVerifyDTO('backup', 0)->toJson() . "\n");
        $leader[0]->read();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->coordinator->onRosterResumed();
        socket_write($leader[1], new PeerProtectedModeLiftDTO()->toJson() . "\n");
        $leader[0]->read();
        $this->withDaemonTruthSource(static fn() => Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterInactive());
        $this->coordinator->onRosterResumed();
        $this->assertSame([
            'enterActivating', 'enterActive', 'notifyInitiatorReady', 'enterVerifying',
            'finishVerifying', 'enterInactive', 'finishLift',
        ], $this->executor->calls);
    }

    public function testASlaveAppliesADirectWindowImmediatelyWithItsAgentStartGateOpen(): void
    {
        $server = $this->protectedModePeerServer(NodeRole::Slave);
        $leader = $this->protectedModePeerLink($server, 'leader', NodeRole::Master);
        $this->protectedModePeerFrames($leader);
        $this->recordDirectWindowTransitions();
        $this->coordinator = new ClusterProtectedMode(self::SELF, $server, $this->executor);
        $server->registerProtectedMode($this->coordinator);
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', null, self::SELF, null, 'generation-a');

        socket_write($leader[1], new PeerProtectedModeWindowDTO($window)->toJson() . "\n");
        $leader[0]->read();
        $this->coordinator->onRosterStopped();
        $acks = $this->protectedModePeerFrames($leader);
        $this->assertCount(1, $acks);
        $this->assertInstanceOf(PeerProtectedModeQuiescedDTO::class, $acks[0]);
        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $workers = $this->createStub(WorkerServer::class);
        $refusesStart = Closure::bind(static fn(): bool => $workers->protectedModeRefusesStart('ordinary', null), null, WorkerServer::class);
        $this->assertFalse($refusesStart());

        socket_write($leader[1], new PeerProtectedModeReadyDTO()->toJson() . "\n");
        $leader[0]->read();
        socket_write($leader[1], new PeerProtectedModeLiftDTO()->toJson() . "\n");
        $leader[0]->read();
        $this->assertSame([
            'enterVerificationWindow', 'notifyInitiatorReady', 'enterInactive', 'finishLift',
        ], $this->executor->calls);
        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $this->assertFalse($refusesStart());
    }

    public function testAnAdoptedUnfinishedRoundIncludesBothOnlineRolesAndPreservesItsGeneration(): void
    {
        $server = $this->protectedModePeerServer();
        $this->protectedModePeerLink($server, 'master', NodeRole::Master);
        $this->protectedModePeerLink($server, 'slave', NodeRole::Slave);
        $this->mesh->followers = $server->followerNodeIds();
        $this->mesh->location = AgentLocation::onNode('slave');
        $this->withDaemonTruthSource(static function (): void {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', 'backup', 0, 'slave', null, 'known-generation'), null,
            );
        });

        $this->coordinator->onBecameLeader();
        $this->assertSame(['master', 'slave', self::SELF], $this->coordinator->pendingNodeIds());
        $this->assertSame(['master', 'slave', self::SELF], $this->coordinator->quiesceNodeIds());
        $this->coordinator->onQuiesced('master');
        $this->assertSame([], $this->mesh->calls);
        $this->coordinator->onQuiesced('slave');
        $this->assertSame([], $this->mesh->calls);
        $this->coordinator->onRosterStopped();
        $this->assertSame([['broadcastSettled', null], ['sendReady', 'slave']], $this->mesh->calls);
        $this->assertSame('known-generation', Hilos::$rt?->hilosProtectedModeRuntime?->freezeId);
    }

    public function testNewBarriersReplaceGenerationAndActiveEnableAndVerifyKeepIt(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $first = $this->mesh->lastQuiesce?->freezeId;
        $this->assertIsString($first);
        $this->assertNotSame('', $first);
        $this->assertSame($first, $this->executor->freeze?->freezeId);
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();

        $this->mesh->calls = [];
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->assertSame([['sendReady', 'node-b']], $this->mesh->calls);
        $this->assertSame($first, Hilos::$rt?->hilosProtectedModeRuntime?->freezeId);
        $this->assertSame($first, $this->mesh->lastQuiesce?->freezeId);
        $this->coordinator->onVerify('node-b', 'backup', 0);
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->assertSame($first, Hilos::$rt?->hilosProtectedModeRuntime?->freezeId);

        $this->coordinator->onEnable('node-b', $this->enableData());
        $second = $this->mesh->lastQuiesce?->freezeId;
        $this->assertIsString($second);
        $this->assertNotSame($first, $second);
        $this->assertSame($second, $this->executor->freeze?->freezeId);
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();

        $this->coordinator->onRefreeze('node-b', 'backup', 0);
        $third = $this->mesh->lastQuiesce?->freezeId;
        $this->assertIsString($third);
        $this->assertNotContains($third, [$first, $second]);
        $this->assertSame($third, $this->executor->freeze?->freezeId);
    }

    public function testDirectWindowKeepsItsGenerationUntilTheNextEntry(): void
    {
        $this->enterDirectWindowOnLeader();
        $first = $this->mesh->lastQuiesce?->freezeId;
        $this->assertIsString($first);
        $this->assertNotSame('', $first);
        $this->assertSame($first, Hilos::$rt?->hilosProtectedModeRuntime?->freezeId);
        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $this->coordinator->onRefreeze('node-b', 'index', null);
        $this->assertSame($first, Hilos::$rt?->hilosProtectedModeRuntime?->freezeId);
        $this->assertSame($first, $this->mesh->lastQuiesce?->freezeId);

        $this->coordinator->onDisable('node-b', 'index', null);
        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->freezeId);
        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $second = $this->mesh->lastQuiesce?->freezeId;
        $this->assertIsString($second);
        $this->assertNotSame($first, $second);
        $this->assertSame($second, Hilos::$rt?->hilosProtectedModeRuntime?->freezeId);
    }

    /** @param bool $direct Whether the standing row is a direct window */
    #[DataProvider('entryModes')]
    public function testLeadershipAdoptionKeepsAKnownGenerationWithoutAnotherReady(bool $direct): void
    {
        $this->withDaemonTruthSource(function () use ($direct): void {
            $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, 'known-generation');
            if ($direct) {
                Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterVerificationWindow($freeze, null);
            } else {
                Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterActivating($freeze, null);
                Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterActive();
            }
        });
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onLostLeadership();
        $this->coordinator->onBecameLeader();
        $this->coordinator->onQuiesced('node-b');

        $this->assertSame('known-generation', Hilos::$rt?->hilosProtectedModeRuntime?->freezeId);
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    /** @return iterable<string, array{bool}> Both occupied entry modes */
    public static function entryModes(): iterable
    {
        yield 'full freeze' => [false];
        yield 'direct window' => [true];
    }

    public function testFullRosterSurvivesReportsAndMembershipChangesUntilTheLeaderResets(): void
    {
        $this->assertNull($this->coordinator->quiesceNodeIds());
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->coordinator->onBecameLeader();
        $this->assertNull($this->coordinator->quiesceNodeIds());

        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->assertSame(['node-b', 'node-c', self::SELF], $this->coordinator->quiesceNodeIds());
        $this->assertSame(['node-b', 'node-c', self::SELF], $this->coordinator->pendingNodeIds());
        $this->mesh->followers = ['node-new'];
        $this->coordinator->onQuiesced('node-b');
        $this->coordinator->onQuiesced('node-new');
        $this->assertSame(['node-c', self::SELF], $this->coordinator->pendingNodeIds());
        $this->assertSame(['node-b', 'node-c', self::SELF], $this->coordinator->quiesceNodeIds());
        $this->stopTheRoster();
        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->coordinator->onQuiesced('node-c');
        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertSame(['node-b', 'node-c', self::SELF], $this->coordinator->quiesceNodeIds());
        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);

        $this->coordinator->onLostLeadership();
        $this->assertNull($this->coordinator->quiesceNodeIds());
        $this->assertSame([], $this->coordinator->pendingNodeIds());
    }

    public function testRepeatRoundReplacesTheFullRosterWithItsOwnSelectedParticipants(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->assertSame(['node-b', self::SELF], $this->coordinator->quiesceNodeIds());
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->mesh->followers = ['node-c'];
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->assertSame(['node-c', self::SELF], $this->coordinator->quiesceNodeIds());
        $this->assertSame(['node-c', self::SELF], $this->coordinator->pendingNodeIds());
        $this->coordinator->onQuiesced('node-b');
        $this->stopTheRoster();
        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->coordinator->onQuiesced('node-c');
        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);
        $this->assertSame(['node-c', self::SELF], $this->coordinator->quiesceNodeIds());

        $this->coordinator->onDisable('node-b', 'backup', 0);
        $this->assertNull($this->coordinator->quiesceNodeIds());
    }

    public function testLeaderWithFollowersActivatesOnlyAfterEveryQuiescedReport(): void
    {
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->coordinator->onBecameLeader();

        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();

        // The leader freezes its own node and broadcasts, but does not activate yet.
        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame('accept-9', $this->executor->activatingAcceptKey);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);

        $this->coordinator->onQuiesced('node-b');
        $this->assertSame(['enterActivating'], $this->executor->calls);

        $this->coordinator->onQuiesced('node-c');
        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);

        // A late duplicate report does not re-activate.
        $this->coordinator->onQuiesced('node-c');
        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
    }

    public function testSingleNodeLeaderActivatesOnceItsOwnRosterHasStopped(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();

        $this->coordinator->onEnable('node-a', $this->enableData());

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([self::SELF], $this->coordinator->pendingNodeIds());

        $this->stopTheRoster();

        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());
    }

    public function testLeaderWaitsForItsOwnRosterEvenOnceEveryFollowerHasReported(): void
    {
        // A follower with a shorter roster reports before the leader's own has stopped. Counting
        // followers only, that report would hand ready to the initiator while the leader's node
        // was still serving its clients (HIL-1012).
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->coordinator->onQuiesced('node-b');

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([self::SELF], $this->coordinator->pendingNodeIds());

        $this->stopTheRoster();

        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testTheRosterStoppedByClosingTheWindowBackAnswersNobody(): void
    {
        // The close is the entry's quiesce round (HIL-1128): every node stops its roster again and
        // the leader writes active only once all have reported. Its initiator was told ready when
        // the freeze first took hold, so the round tells the followers and nobody else.
        $this->closeTheWindowBackOnTheLeader(acceptKey: 'accept-on-row', sessionTokenHash: 'session-on-row');

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame('accept-on-row', $this->executor->activatingAcceptKey);
        $this->assertSame('session-on-row', $this->executor->activatingSessionTokenHash);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');

        $this->assertSame(['enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastSettled', null]], $this->mesh->calls);
    }

    public function testLeaderClosingWaitsForItsOwnRoster(): void
    {
        $this->closeTheWindowBackOnTheLeader();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesced('node-b');

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);

        $this->stopTheRoster();

        $this->assertSame(['enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastSettled', null]], $this->mesh->calls);
    }

    public function testAnEnableDuringTheCloseRoundIsRefusedAsAnotherOperation(): void
    {
        // Not a ready over agents still running on some node (HIL-1128): the round is open, as it
        // is during the first entry.
        $this->closeTheWindowBackOnTheLeader();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
        ], $this->mesh->calls);
    }

    public function testAnEnableAfterTheCloseRoundIsToldReady(): void
    {
        $this->closeTheWindowBackOnTheLeader();
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testAFollowerIgnoresARefreezeFromItsLeader(): void
    {
        // The leader closes the window with its quiesce round, and sends no follower this frame.
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));
        $this->stopTheRoster();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onRefreeze('node-x', 'backup', 0);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAFollowerWritesActiveOnItsLeadersSettled(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));
        $this->stopTheRoster();
        $this->executor->calls = [];

        $this->coordinator->onSettled('node-x');

        $this->assertSame(['enterActive'], $this->executor->calls);
    }

    public function testAFollowerIgnoresASettledFromAnotherNode(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));
        $this->stopTheRoster();
        $this->executor->calls = [];

        $this->coordinator->onSettled('node-y');

        $this->assertSame([], $this->executor->calls);
    }

    public function testAFollowerIgnoresASettledOffActivating(): void
    {
        // The frame rides every link to the node: a copy over the second link arrives on active,
        // with the circle already on the row, and writing active again would clear it.
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));
        $this->stopTheRoster();
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];

        $this->coordinator->onSettled('node-x');

        $this->assertSame([], $this->executor->calls);

        $this->openTheVerificationWindowOnTheRuntimeRow();

        $this->coordinator->onSettled('node-x');

        $this->assertSame([], $this->executor->calls);
    }

    public function testTheRosterBackFinishesWhicheverLiftTheRowSays(): void
    {
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();

        $this->coordinator->onRosterResumed();
        $this->assertSame(['finishVerifying'], $this->executor->calls);

        $this->withDaemonTruthSource(static fn() => Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterInactive());
        $this->executor->calls = [];

        $this->coordinator->onRosterResumed();
        $this->assertSame(['finishLift'], $this->executor->calls);
    }

    public function testLeaderDisableDeactivatesBroadcastsLiftAndReleasesSelf(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onDisable('node-b', 'backup', 0);

        $this->assertSame(['enterDeactivating', 'enterInactive'], $this->executor->calls);
        $this->assertSame([['broadcastLift', null]], $this->mesh->calls);
    }

    public function testLeaderIgnoresDisableFromAnotherAgent(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onDisable('node-c', 'other-agent', 0);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testMovedInitiatorCanDriveTheFreezeByIdentity(): void
    {
        $this->mesh->followers = [];
        $this->mesh->location = AgentLocation::onNode('node-c');
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->mesh->calls = [];

        $this->coordinator->onVerify('node-c', 'backup', 0);
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->withDaemonTruthSource(function (): void {
            $this->coordinator->onPass('node-c', 'backup', 0, 'pass-hash');
            $this->coordinator->onCircle('node-c', 'backup', 0, $this->circleSnapshot());
        });
        $this->coordinator->onRefreeze('node-c', 'backup', 0);

        $this->assertSame([
            ['broadcastVerify', null],
            ['broadcastPass', 'pass-hash'],
            ['broadcastCircle', '1'],
            ['broadcastQuiesce', 'restore'],
        ], $this->mesh->calls);

        $this->coordinator->onDisable('node-c', 'backup', 0);
        $this->assertContains('enterInactive', $this->executor->calls);
    }

    public function testLeaderRejectsOtherAgentTypesAndIndexesOnEveryInitiatorFrame(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();

        foreach ([['other-agent', 0], ['backup', 1]] as [$agentType, $agentIndex]) {
            $this->executor->calls = [];
            $this->mesh->calls = [];

            $this->coordinator->onVerify('node-c', $agentType, $agentIndex);
            $this->coordinator->onProgress('node-c', $agentType, $agentIndex);
            $this->coordinator->onCircle('node-c', $agentType, $agentIndex, $this->circleSnapshot());
            $this->coordinator->onDisable('node-c', $agentType, $agentIndex);
            $this->openTheVerificationWindowOnTheRuntimeRow();
            $this->coordinator->onPass('node-c', $agentType, $agentIndex, 'pass-hash');
            $this->coordinator->onRefreeze('node-c', $agentType, $agentIndex);

            $this->assertSame([], $this->executor->calls);
            $this->assertSame([], $this->mesh->calls);
            $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
            $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        }
    }

    public function testReadyUsesCurrentAgentPlacementAndDropsUnknown(): void
    {
        $this->mesh->followers = [];
        $this->mesh->location = AgentLocation::unknown();
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();

        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null]], $this->mesh->calls);
        $this->assertNotContains('notifyInitiatorReady', $this->executor->calls);

        $this->mesh->location = AgentLocation::onNode('node-c');
        $this->mesh->calls = [];
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->coordinator->onEnable('node-c', $this->enableDataFrom('node-c'));

        $this->assertSame([['sendReady', 'node-c']], $this->mesh->calls);
    }

    public function testNonLeaderIgnoresEnable(): void
    {
        $this->mesh->followers = ['node-b'];

        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::NO_LEADER],
        ], $this->mesh->calls);
    }

    public function testLeaderIgnoresAConcurrentSecondEnable(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-c', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-c', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
        ], $this->mesh->calls);
    }

    public function testLeaderTellsItsInitiatorReadyAgainWhenTheFreezeAlreadyStands(): void
    {
        // The cluster half of the operator closing the verification window: every node stays
        // frozen so another restore can run, and that restore's enable must not be refused as a
        // duplicate. The quiesce round is not replayed - the followers never thawed.
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testLeaderAnswersMovedInitiatorUnderAFreezeThatStands(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->mesh->location = AgentLocation::onNode('node-c');
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-c', $this->enableDataFrom('node-c'));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendReady', 'node-c']], $this->mesh->calls);
    }

    public function testLeaderRefusesASecondAgentOnTheInitiatorNodeUnderAFreezeThatStands(): void
    {
        // The node id cannot tell apart the two initiators a project holds - the agent that runs
        // real operations and the test driver's carrier share a node - so the identity the freeze
        // records is what refuses this. Answering would send the ready to the recorded agent
        // instead: the one that asked would wait out its timeout, and the other would be told a
        // second time that it may start destroying things.
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'protected-mode-driver',
            initiatorAgentIndex: 0,
            initiatorNodeId: 'node-b',
        ));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::FOREIGN_FREEZE],
        ], $this->mesh->calls);
    }

    public function testLeaderEntersAgainFromTheVerificationWindowAndAnswersOnlyOnceEveryNodeQuiesced(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $secondEnable = new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-second',
            initiatorSessionTokenHash: 'session-second',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            initiatorNodeId: 'node-b',
        );
        $this->coordinator->onEnable('node-b', $secondEnable);

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame('accept-second', $this->executor->activatingAcceptKey);
        $this->assertSame('session-second', $this->executor->activatingSessionTokenHash);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);

        $this->stopTheRoster();

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);

        $this->coordinator->onQuiesced('node-b');

        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testLeaderEnteringAgainWaitsForItsOwnRoster(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-second',
            initiatorSessionTokenHash: 'session-second',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            initiatorNodeId: 'node-b',
        ));

        $this->coordinator->onQuiesced('node-b');

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);

        $this->stopTheRoster();

        $this->assertSame(['enterActivating', 'enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testLeaderAcceptsMovedInitiatorUnderVerifyingWindow(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-c', $this->enableDataFrom('node-c'));

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);
    }

    public function testFollowerRequestEnableWithoutKnownLeaderDeliversRefusalLocally(): void
    {
        $this->mesh->leader = null;

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));

        $this->assertSame([], $this->mesh->calls);
        $this->assertSame([
            [
                'agentType' => 'backup',
                'agentIndex' => '0',
                'reason' => ProtectedModeRefusalCopy::NO_LEADER,
            ],
        ], $this->relay->refusedCalls);
    }

    public function testFollowerInitiatorNodeRelaysRefusedFromLeaderToLocalRelay(): void
    {
        $this->mesh->leader = 'node-x';

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));
        $this->assertSame([['sendEnable', 'node-x']], $this->mesh->calls);

        $this->coordinator->onRefused('node-x', ProtectedModeRefusalCopy::FOREIGN_FREEZE);

        $this->assertSame([
            [
                'agentType' => 'backup',
                'agentIndex' => '0',
                'reason' => ProtectedModeRefusalCopy::FOREIGN_FREEZE,
            ],
        ], $this->relay->refusedCalls);
    }

    public function testInitiatorNodeRelaysReadyAgainWhenItsAgentAsksForAnotherFreeze(): void
    {
        // The once-per-freeze guard is re-armed by this node's own request, and it has to be:
        // a freeze that already stands sends no second quiesce, which is the other place the
        // guard is cleared, so the answer to the next operation would be swallowed.
        $this->mesh->leader = 'node-x';
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF, null, freezeId: 'freeze-fixture'));
        $this->coordinator->onReady('node-x');
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));
        $this->coordinator->onReady('node-x');

        $this->assertSame(['notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([['sendEnable', 'node-x']], $this->mesh->calls);
    }

    public function testLostLeadershipDropsInFlightOrchestration(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onLostLeadership();
        $this->coordinator->onQuiesced('node-b');

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testFollowerFreezesLocallyAndReportsQuiescedOnceItsRosterHasStopped(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture');

        $this->coordinator->onQuiesce('node-x', $freeze);

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertNull($this->executor->activatingAcceptKey);
        // The promise onQuiesce() makes: no quiesced report for a node still serving its clients.
        $this->assertSame([], $this->mesh->calls);

        $this->stopTheRoster();

        $this->assertSame([['sendQuiesced', 'node-x']], $this->mesh->calls);
    }

    public function testFollowerOpensADirectWindowAndReportsQuiescedImmediately(): void
    {
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', null, 'node-b', 'operator-hash', freezeId: 'freeze-fixture');

        $this->coordinator->onWindow('node-x', $window);

        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame($window, $this->executor->freeze);
        $this->assertNull($this->executor->windowAcceptKey);
        $this->assertSame([['sendQuiesced', 'node-x']], $this->mesh->calls);
    }

    public function testFollowerAcknowledgesRepeatedWindowWithoutReenteringOrClearingAdmission(): void
    {
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', null, 'node-b', 'operator-hash', freezeId: 'freeze-fixture');
        $this->coordinator->onWindow('node-x', $window);
        $this->writeDirectWindowOnRuntimeRow($window);
        $this->coordinator->onReady('node-x');
        $this->withDaemonTruthSource(static function (): void {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->issuePass('pass-hash');
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->admitSession('admitted-hash');
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->admitCircle(new VerifierCircleSnapshot(2, ['circle-hash']));
        });

        $this->coordinator->onWindow('node-x', $window);
        $this->coordinator->onWindow('node-x', $window);
        $this->coordinator->onReady('node-x');

        $this->assertSame(['enterVerificationWindow', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([
            ['sendQuiesced', 'node-x'], ['sendQuiesced', 'node-x'], ['sendQuiesced', 'node-x'],
        ], $this->mesh->calls);
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $this->assertSame(['pass-hash'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame(['admitted-hash'], Hilos::$rt?->hilosProtectedModeRuntime?->admittedSessionTokenHashes);
        $this->assertSame(['circle-hash'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(2, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
        $this->assertSame('operator-hash', Hilos::$rt?->hilosProtectedModeRuntime?->initiatorSessionTokenHash);
    }

    public function testFollowerAcceptsRepeatedWindowFromTheCurrentNewLeader(): void
    {
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', 0, 'node-b', null, freezeId: 'freeze-fixture');
        $this->coordinator->onWindow('node-x', $window);
        $this->writeDirectWindowOnRuntimeRow($window);
        $this->mesh->leader = 'node-y';
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onWindow('node-y', $window);
        $this->coordinator->onLift('node-x');

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendQuiesced', 'node-y']], $this->mesh->calls);

        $this->coordinator->onLift('node-y');

        $this->assertSame(['enterInactive', 'finishLift'], $this->executor->calls);
    }

    public function testFollowerDropsDirectWindowFromAnotherLeader(): void
    {
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', null, 'node-b', null, freezeId: 'freeze-fixture');
        $this->coordinator->onWindow('node-x', $window);
        $this->writeDirectWindowOnRuntimeRow($window);
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onWindow('node-other', $window);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testFollowerDropsDirectWindowForAnotherInitiatorTypeOrIndex(): void
    {
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', 0, 'node-b', null, freezeId: 'freeze-fixture');
        $this->coordinator->onWindow('node-x', $window);
        $this->writeDirectWindowOnRuntimeRow($window);
        $this->executor->calls = [];
        $this->mesh->calls = [];

        foreach ([['backup', 0], ['index', 1], ['index', null]] as [$agentType, $agentIndex]) {
            $this->coordinator->onWindow('node-x', new ProtectedModeQuiesceData(
                'manual_maintenance', $agentType, $agentIndex, 'node-b', null,
                freezeId: 'freeze-fixture',
            ));
        }

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame('index', Hilos::$rt?->hilosProtectedModeRuntime?->initiatorAgentType);
        $this->assertSame(0, Hilos::$rt?->hilosProtectedModeRuntime?->initiatorAgentIndex);
    }

    public function testFollowerCannotTurnAFullFreezeIntoADirectWindow(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture');
        $this->coordinator->onQuiesce('node-x', $freeze);
        $this->stopTheRoster();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onWindow('node-x', $freeze);
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);

        $this->settleTheFreezeOnTheRuntimeRow();
        $this->coordinator->onWindow('node-x', $freeze);
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, Hilos::$rt?->hilosProtectedModeRuntime?->phase);

        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->coordinator->onWindow('node-x', $freeze);
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_FREEZE, Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testFollowerWithoutRuntimeStateDoesNotAcknowledgeADirectWindow(): void
    {
        Hilos::$rt = null;
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', null, 'node-b', null, freezeId: 'freeze-fixture');

        $this->coordinator->onWindow('node-x', $window);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);

        $this->mount();
        $this->coordinator->onWindow('node-y', $window);

        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame([['sendQuiesced', 'node-y']], $this->mesh->calls);
    }

    public function testFollowerDropsQuiesceDuringADirectWindow(): void
    {
        $window = new ProtectedModeQuiesceData('manual_maintenance', 'index', null, 'node-b', null, freezeId: 'freeze-fixture');
        $this->coordinator->onWindow('node-x', $window);
        $this->writeDirectWindowOnRuntimeRow($window);
        $this->mesh->leader = 'node-y';
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-x', $window);
        $this->coordinator->onQuiesce('node-y', $window);
        $this->coordinator->onQuiesce('node-other', $window);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW, Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
    }

    public function testFollowerReleasesOnLift(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onLift('node-x');

        $this->assertSame(['enterInactive'], $this->executor->calls);
    }

    public function testFollowerIgnoresLiftWhenNotFrozen(): void
    {
        $this->coordinator->onLift('node-x');

        $this->assertSame([], $this->executor->calls);
    }

    public function testFollowerIgnoresLiftFromANodeOtherThanItsFreezingLeader(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onLift('node-y');

        $this->assertSame([], $this->executor->calls);
    }

    public function testFollowerFollowsNewLeaderForRepeatRoundAndLift(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture');
        $this->coordinator->onQuiesce('node-x', $freeze);
        $this->stopTheRoster();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->mesh->leader = 'node-y';
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-y', $freeze->withFreezeId('next-generation'));
        $this->stopTheRoster();

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([['sendQuiesced', 'node-y']], $this->mesh->calls);

        $this->executor->calls = [];
        $this->coordinator->onLift('node-x');
        $this->assertSame([], $this->executor->calls);

        $this->coordinator->onLift('node-y');
        $this->assertSame(['enterInactive'], $this->executor->calls);
    }

    public function testFollowerAcceptsWindowFramesAndReadyFromNewLeader(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF, null, freezeId: 'freeze-fixture'));
        $this->stopTheRoster();
        $this->mesh->leader = 'node-y';
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onSettled('node-y');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->coordinator->onVerify('node-y', 'backup', 0);
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->withDaemonTruthSource(function (): void {
            $this->coordinator->onPass('node-y', 'backup', 0, 'pass-hash');
            $this->coordinator->onCircle('node-y', 'backup', 0, $this->circleSnapshot());
        });
        $this->coordinator->onReady('node-y');

        $this->assertSame(['enterActive', 'enterVerifying', 'announcePassIssued', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame(['pass-hash'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testFormerLeaderAcceptsLiftFromTheNewLeader(): void
    {
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->coordinator->onBecameLeader();
        $this->coordinator->onLostLeadership();
        $this->mesh->leader = 'node-y';

        $this->coordinator->onLift('node-y');

        $this->assertSame(['enterInactive'], $this->executor->calls);
    }

    public function testRestartedFollowerRefusesFreshQuiesceAndAcceptsCurrentLeadersLift(): void
    {
        // A restored runtime row has a freeze but no in-memory freezing leader.
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->mesh->leader = 'node-y';

        $this->coordinator->onQuiesce('node-y', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));
        $this->assertSame([], $this->executor->calls);

        $this->coordinator->onLift('node-y');
        $this->assertSame(['enterInactive'], $this->executor->calls);
    }

    public function testCurrentLeadersLiftOnAnUnfrozenNodeDoesNothing(): void
    {
        $this->mesh->leader = 'node-y';

        $this->coordinator->onLift('node-y');

        $this->assertSame([], $this->executor->calls);
    }

    public function testFollowerIgnoresARepeatQuiesceWhileAlreadyFrozen(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture');
        $this->coordinator->onQuiesce('node-x', $freeze);
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-x', $freeze);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAFollowerInsideTheWindowEntersAgainOnItsLeadersQuiesceAndReportsOnceStopped(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture');
        $this->coordinator->onQuiesce('node-x', $freeze);
        $this->stopTheRoster();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-x', $freeze->withFreezeId('next-generation'));

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertNull($this->executor->activatingAcceptKey);

        $this->stopTheRoster();

        $this->assertSame([['sendQuiesced', 'node-x']], $this->mesh->calls);
    }

    public function testAFollowerInsideTheWindowIgnoresAQuiesceFromAnotherNode(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture');
        $this->coordinator->onQuiesce('node-x', $freeze);
        $this->stopTheRoster();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-y', $freeze);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAFollowerClosedBackIgnoresARepeatQuiesceFromItsLeader(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture');
        $this->coordinator->onQuiesce('node-x', $freeze);
        $this->stopTheRoster();
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-x', $freeze);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testInitiatorNodeRelaysReadyToItsAgentOnceItsFreezingLeaderConfirms(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF, null, freezeId: 'freeze-fixture'));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onReady('node-x');

        $this->assertSame(['notifyInitiatorReady'], $this->executor->calls);
    }

    public function testInitiatorNodeIgnoresReadyFromANodeOtherThanItsFreezingLeader(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF, null, freezeId: 'freeze-fixture'));
        $this->executor->calls = [];

        $this->coordinator->onReady('node-y');

        $this->assertSame([], $this->executor->calls);
    }

    public function testInitiatorNodeRelaysReadyOnlyOnceForRepeatedConfirmations(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, self::SELF, null, freezeId: 'freeze-fixture'));
        $this->executor->calls = [];

        $this->coordinator->onReady('node-x');
        $this->coordinator->onReady('node-x');

        $this->assertSame(['notifyInitiatorReady'], $this->executor->calls);
    }

    public function testLeaderInitiatorRelaysReadyLocallyOnceFollowersQuiesce(): void
    {
        $this->mesh->location = AgentLocation::here();
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable(self::SELF, $this->enableDataFrom(self::SELF));
        $this->stopTheRoster();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesced('node-b');

        // The leader is the initiator: the ready is handed to the local agent, never sent to itself.
        $this->assertSame(['enterActive', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([['broadcastSettled', null]], $this->mesh->calls);
    }

    public function testInitiatorLeaderHandlesEnableRequestLocally(): void
    {
        $this->mesh->location = AgentLocation::here();
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));
        $this->stopTheRoster();

        // Routed straight into the leader flow; the leader is the initiator, so the ready is relayed
        // to the local agent instead of being sent over the peer channel to itself.
        $this->assertSame(['enterActivating', 'enterActive', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore'], ['broadcastSettled', null]], $this->mesh->calls);
    }

    public function testInitiatorFollowerSendsEnableRequestToLeader(): void
    {
        $this->mesh->leader = 'node-z';

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendEnable', 'node-z']], $this->mesh->calls);
    }

    public function testLeaderDirectWindowWaitsOnlyForEveryFollowerBeforeReady(): void
    {
        $this->recordDirectWindowTransitions();
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->coordinator->onBecameLeader();

        $this->coordinator->onEnable('node-b', $this->directWindowData());

        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame('operator-accept', $this->executor->windowAcceptKey);
        $this->assertSame('operator-hash', $this->executor->freeze?->initiatorSessionTokenHash);
        $this->assertSame('node-b', $this->executor->freeze?->initiatorNodeId);
        $this->assertSame([['broadcastWindow', 'manual_maintenance']], $this->mesh->calls);
        $this->assertSame(['node-b', 'node-c'], $this->coordinator->pendingNodeIds());
        $this->assertSame(['node-b', 'node-c'], $this->coordinator->quiesceNodeIds());

        $this->coordinator->onRosterStopped();
        $this->coordinator->onQuiesced('node-other');
        $this->coordinator->onQuiesced('node-b');
        $this->coordinator->onQuiesced('node-b');

        $this->assertSame(['node-c'], $this->coordinator->pendingNodeIds());
        $this->assertSame(['node-b', 'node-c'], $this->coordinator->quiesceNodeIds());
        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame([['broadcastWindow', 'manual_maintenance']], $this->mesh->calls);

        $this->coordinator->onQuiesced('node-c');
        $this->coordinator->onQuiesced('node-c');

        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame([['broadcastWindow', 'manual_maintenance'], ['sendReady', 'node-b']], $this->mesh->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertSame(['node-b', 'node-c'], $this->coordinator->quiesceNodeIds());
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW, Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
    }

    public function testSingleNodeLeaderDirectWindowAnswersReadyWithoutARosterWalk(): void
    {
        $this->recordDirectWindowTransitions();
        $this->mesh->location = AgentLocation::here();
        $this->coordinator->onBecameLeader();

        $this->coordinator->requestEnable($this->directWindowData(initiatorNodeId: self::SELF));
        $this->coordinator->onRosterStopped();

        $this->assertSame(['enterVerificationWindow', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([['broadcastWindow', 'manual_maintenance']], $this->mesh->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertSame([], $this->coordinator->quiesceNodeIds());
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
    }

    public function testFollowerDirectWindowRequestForwardsAndRearmsTheReadyRelay(): void
    {
        $this->recordDirectWindowTransitions();
        $this->mesh->leader = 'node-x';
        $this->coordinator->requestEnable($this->directWindowData(initiatorNodeId: self::SELF));

        $this->assertSame([['sendEnable', 'node-x']], $this->mesh->calls);
        $this->assertSame([], $this->executor->calls);

        $this->coordinator->onWindow('node-x', new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', null, self::SELF, 'operator-hash',
            freezeId: 'freeze-fixture',
        ));
        $this->coordinator->onReady('node-x');
        $this->coordinator->onReady('node-x');
        $this->coordinator->requestEnable($this->directWindowData(initiatorNodeId: self::SELF));
        $this->coordinator->onReady('node-x');
        $this->coordinator->onReady('node-x');

        $this->assertSame(['enterVerificationWindow', 'notifyInitiatorReady', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame([
            ['sendEnable', 'node-x'], ['sendQuiesced', 'node-x'], ['sendEnable', 'node-x'],
        ], $this->mesh->calls);
    }

    public function testDirectWindowRepeatDuringItsRoundIsRefusedWithoutAnotherEntry(): void
    {
        $this->recordDirectWindowTransitions();
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', $this->directWindowData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame(['node-b'], $this->coordinator->pendingNodeIds());
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
        ], $this->mesh->calls);
    }

    public function testDirectWindowRepeatAnswersCurrentPlacementAndKeepsAdmission(): void
    {
        $this->enterDirectWindowOnLeader();
        $this->withDaemonTruthSource(static function (): void {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->issuePass('pass-hash');
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->admitSession('admitted-hash');
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->admitCircle(new VerifierCircleSnapshot(2, ['circle-hash']));
        });
        $before = Hilos::$rt?->hilosProtectedModeRuntime?->toArray();
        $this->mesh->location = AgentLocation::onNode('node-c');

        $this->coordinator->onEnable('node-c', $this->directWindowData(initiatorNodeId: 'node-c'));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendReady', 'node-c']], $this->mesh->calls);
        $this->assertSame($before, Hilos::$rt?->hilosProtectedModeRuntime?->toArray());
    }

    public function testDirectWindowRefusesAnotherOperationOrEntryMode(): void
    {
        $this->enterDirectWindowOnLeader();

        $this->coordinator->onEnable('node-b', $this->directWindowData(operation: 'other-operation'));
        $this->coordinator->onEnable('node-b', new ProtectedModeEnableSignalData(
            'manual_maintenance', null, null, 'index', null, 'node-b',
        ));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
        ], $this->mesh->calls);
        $this->assertSame('manual_maintenance', Hilos::$rt?->hilosProtectedModeRuntime?->operation);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW, Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
    }

    public function testDirectWindowRefusesForeignInitiatorTypeOrIndex(): void
    {
        $this->enterDirectWindowOnLeader();

        $this->coordinator->onEnable('node-b', $this->directWindowData(agentType: 'backup'));
        $this->coordinator->onEnable('node-b', $this->directWindowData(agentIndex: 0));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::FOREIGN_FREEZE],
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::FOREIGN_FREEZE],
        ], $this->mesh->calls);
    }

    public function testFullFreezeRefusesDirectEntryOnActiveAndVerifying(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onEnable('node-b', $this->directWindowData('restore', 'backup', 0));
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->coordinator->onEnable('node-b', $this->directWindowData('restore', 'backup', 0));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
        ], $this->mesh->calls);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_FREEZE, Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
    }

    public function testLeaderDirectWindowStillRequiresLeadershipInitiatorNodeAndRuntime(): void
    {
        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $this->assertSame([['sendRefused', 'node-b', ProtectedModeRefusalCopy::NO_LEADER]], $this->mesh->calls);

        $this->coordinator->onBecameLeader();
        $this->mesh->calls = [];
        $this->coordinator->onEnable('node-b', $this->directWindowData(initiatorNodeId: null));
        $this->assertSame([['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION]], $this->mesh->calls);

        Hilos::$rt = null;
        $this->mesh->calls = [];
        $this->coordinator->onEnable('node-b', $this->directWindowData());

        $this->assertSame([['sendRefused', 'node-b', ProtectedModeRefusalCopy::NO_RUNTIME_ROW]], $this->mesh->calls);
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());

        $this->mount();
        $this->recordDirectWindowTransitions();
        $this->mesh->calls = [];
        $this->coordinator->onEnable('node-b', $this->directWindowData());

        $this->assertSame(['enterVerificationWindow'], $this->executor->calls);
        $this->assertSame([['broadcastWindow', 'manual_maintenance'], ['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testLeaderDirectWindowAcceptsTheCircleOnceAndFinishesVerifying(): void
    {
        $this->enterDirectWindowOnLeader();

        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-b', 'index', null, $this->circleSnapshot()));
        $this->coordinator->onCircle('node-b', 'index', null, new VerifierCircleSnapshot(0, []));

        $this->assertSame(['finishVerifying'], $this->executor->calls);
        $this->assertSame([['broadcastCircle', '1']], $this->mesh->calls);
        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(1, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
    }

    public function testAnEmptyDirectCirclePhotographAlsoFinishesAndCannotBeReplaced(): void
    {
        $this->enterDirectWindowOnLeader();

        $this->withDaemonTruthSource(fn() => $this->coordinator->requestCircle(new ProtectedModeCircleSignalData(
            'index', null, 0, [],
        )));
        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $this->coordinator->onCircle('node-b', 'index', null, $this->circleSnapshot());

        $this->assertSame(['finishVerifying'], $this->executor->calls);
        $this->assertSame([['broadcastCircle', '0'], ['sendReady', 'node-b']], $this->mesh->calls);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(0, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
    }

    public function testLeaderDirectWindowDropsForeignCircleDisableAndRefreeze(): void
    {
        $this->enterDirectWindowOnLeader();

        $this->coordinator->onCircle('node-b', 'backup', null, $this->circleSnapshot());
        $this->coordinator->onCircle('node-b', 'index', 0, $this->circleSnapshot());
        $this->coordinator->onDisable('node-b', 'backup', null);
        $this->coordinator->onDisable('node-b', 'index', 0);
        $this->coordinator->onRefreeze('node-b', 'index', null);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
    }

    public function testFollowerDirectWindowFinishesOnlyWhenTheLeadersCircleArrives(): void
    {
        $this->recordDirectWindowTransitions();
        $this->mesh->leader = 'node-x';
        $this->coordinator->onWindow('node-x', new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', null, self::SELF, 'operator-hash',
            freezeId: 'freeze-fixture',
        ));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->requestCircle(new ProtectedModeCircleSignalData(
            'index', null, 1, ['hash-a'],
        )));
        $this->coordinator->onCircle('node-other', 'index', null, new VerifierCircleSnapshot(0, []));
        $this->coordinator->onSettled('node-x');

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendCircle', 'node-x']], $this->mesh->calls);
        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);

        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-x', 'index', null, $this->circleSnapshot()));

        $this->assertSame(['finishVerifying'], $this->executor->calls);
        $this->assertSame([['sendCircle', 'node-x']], $this->mesh->calls);
        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
    }

    public function testFollowerDirectWindowFinishesOnAnEmptyLeadersCircleToo(): void
    {
        $this->recordDirectWindowTransitions();
        $this->coordinator->onWindow('node-x', new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', null, 'node-b', null,
            freezeId: 'freeze-fixture',
        ));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-x', 'index', null, new VerifierCircleSnapshot(0, [])));

        $this->assertSame(['finishVerifying'], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame(0, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
    }

    public function testLeaderDirectWindowLiftsImmediatelyAndNextWindowTakesANewCircle(): void
    {
        $this->enterDirectWindowOnLeader();
        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-b', 'index', null, $this->circleSnapshot()));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onDisable('node-b', 'index', null);

        $this->assertSame(['enterInactive', 'finishLift'], $this->executor->calls);
        $this->assertSame([['broadcastLift', null]], $this->mesh->calls);
        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertNull($this->coordinator->quiesceNodeIds());

        $this->executor->calls = [];
        $this->mesh->calls = [];
        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $this->coordinator->onQuiesced('node-b');
        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-b', 'index', null, new VerifierCircleSnapshot(0, [])));

        $this->assertSame(['enterVerificationWindow', 'finishVerifying'], $this->executor->calls);
        $this->assertSame([
            ['broadcastWindow', 'manual_maintenance'], ['sendReady', 'node-b'], ['broadcastCircle', '0'],
        ], $this->mesh->calls);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
    }

    public function testFollowerDirectWindowLiftsImmediatelyOnlyForItsLeader(): void
    {
        $this->recordDirectWindowTransitions();
        $this->coordinator->onWindow('node-x', new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', null, 'node-b', null,
            freezeId: 'freeze-fixture',
        ));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onLift('node-other');
        $this->assertSame([], $this->executor->calls);
        $this->coordinator->onLift('node-x');
        $this->coordinator->onLift('node-x');

        $this->assertSame(['enterInactive', 'finishLift'], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, Hilos::$rt?->hilosProtectedModeRuntime?->phase);
        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->entryMode);
    }

    public function testPromotedLeaderAdoptsDirectWindowWithoutAnotherReadyOrPhotograph(): void
    {
        $this->recordDirectWindowTransitions();
        $this->coordinator->onWindow('node-x', new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', null, 'node-b', 'operator-hash',
            freezeId: 'freeze-fixture',
        ));
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onBecameLeader();
        $this->coordinator->onQuiesced('node-b');
        $this->coordinator->onCircle('node-b', 'index', null, $this->circleSnapshot());

        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertSame([], $this->coordinator->quiesceNodeIds());
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);

        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $this->coordinator->onDisable('node-b', 'backup', null);
        $this->coordinator->onRefreeze('node-b', 'index', null);
        $this->assertSame([['sendReady', 'node-b']], $this->mesh->calls);
        $this->assertSame([], $this->executor->calls);

        $this->coordinator->onDisable('node-b', 'index', null);
        $this->assertSame(['enterInactive', 'finishLift'], $this->executor->calls);
        $this->assertSame([['sendReady', 'node-b'], ['broadcastLift', null]], $this->mesh->calls);
    }

    public function testRestartedLeaderAdoptsDirectWindowFromItsRuntimeRow(): void
    {
        $this->recordDirectWindowTransitions();
        $this->writeDirectWindowOnRuntimeRow(new ProtectedModeQuiesceData(
            'manual_maintenance', 'index', null, 'node-b', null,
            freezeId: 'freeze-fixture',
        ));
        $this->mesh->followers = ['node-b'];

        $this->coordinator->onBecameLeader();
        $this->coordinator->onCircle('node-b', 'index', null, $this->circleSnapshot());
        $this->coordinator->onEnable('node-b', $this->directWindowData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendReady', 'node-b']], $this->mesh->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);

        $this->coordinator->onDisable('node-b', 'index', null);
        $this->assertSame(['enterInactive', 'finishLift'], $this->executor->calls);
    }

    public function testDemotedDirectWindowLeaderAcceptsTheNewLeadersCircleAndLift(): void
    {
        $this->enterDirectWindowOnLeader();
        $this->coordinator->onLostLeadership();
        $this->mesh->leader = 'node-y';

        $this->coordinator->onQuiesced('node-b');
        $this->coordinator->onDisable('node-b', 'index', null);
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertNull($this->coordinator->quiesceNodeIds());

        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-y', 'index', null, $this->circleSnapshot()));
        $this->coordinator->onLift('node-y');

        $this->assertSame(['finishVerifying', 'enterInactive', 'finishLift'], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testInitiatorDropsEnableRequestWhenNoLeaderIsKnown(): void
    {
        $this->mesh->leader = null;

        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testInitiatorLeaderHandlesDisableRequestLocally(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->requestEnable($this->enableDataFrom(self::SELF));
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->requestDisable($this->disableData());

        $this->assertSame(['enterDeactivating', 'enterInactive'], $this->executor->calls);
        $this->assertSame([['broadcastLift', null]], $this->mesh->calls);
    }

    public function testInitiatorFollowerSendsDisableRequestToLeader(): void
    {
        $this->mesh->leader = 'node-z';

        $this->coordinator->requestDisable($this->disableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendDisable', 'node-z']], $this->mesh->calls);
    }

    public function testLeaderStampsAProgressMarkFromTheNodeThatOwnsTheFreeze(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $before = time();
        $this->withDaemonTruthSource(fn() => $this->coordinator->onProgress('node-b', 'backup', 0));

        $stamped = Hilos::$rt?->hilosProtectedModeRuntime?->progressAt;
        $this->assertNotNull($stamped);
        $this->assertGreaterThanOrEqual($before, $stamped);
        // The mark moves no phase and is never fanned onward: only the leader reads it.
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testTheLeaderAnnouncesOnlyTheFirstPassAndFansEveryOneOnward(): void
    {
        // Two things are being separated here: every pass reaches the followers, because a
        // verifier may land on any node, while only the first one is announced to the browsers
        // this node already holds - it is the step that swaps their waiting sentence for a field.
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(function (): void {
            $this->coordinator->onPass('node-b', 'backup', 0, 'hash-a');
            $this->coordinator->onPass('node-b', 'backup', 0, 'hash-b');
        });

        $this->assertSame(['hash-a', 'hash-b'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame(['announcePassIssued'], $this->executor->calls);
        $this->assertSame([['broadcastPass', 'hash-a'], ['broadcastPass', 'hash-b']], $this->mesh->calls);
    }

    public function testAFollowerAnnouncesTheFirstPassItIsToldOfAndFansNothingOnward(): void
    {
        // The other end of the same broadcast: a follower records what the leader minted and owes
        // its own frozen browsers the same one announcement, but has nobody to pass it to.
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(function (): void {
            $this->coordinator->onPass('node-x', 'backup', 0, 'hash-a');
            $this->coordinator->onPass('node-x', 'backup', 0, 'hash-b');
        });

        $this->assertSame(['hash-a', 'hash-b'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame(['announcePassIssued'], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testQuiesceCarriesTheOperatorSessionHashToTheFollower(): void
    {
        $freeze = new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', 'operator-hash', freezeId: 'freeze-fixture');

        $this->coordinator->onQuiesce('node-x', $freeze);

        $this->assertSame('operator-hash', $this->executor->freeze?->initiatorSessionTokenHash);
        $this->assertNull($this->executor->activatingAcceptKey);
    }

    public function testLeaderBroadcastsTheOperatorsSessionHashWithTheFreeze(): void
    {
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', new ProtectedModeEnableSignalData(
            'restore', 'accept-9', 'operator-hash', 'backup', 0, 'node-b',
        ));

        $this->assertSame('operator-hash', $this->mesh->lastQuiesce?->initiatorSessionTokenHash);
        $this->assertSame('operator-hash', $this->executor->activatingSessionTokenHash);
    }

    public function testRepeatEntryCarriesTheNewOperatorSessionHash(): void
    {
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();

        $this->coordinator->onEnable('node-b', new ProtectedModeEnableSignalData(
            'restore', 'accept-new', 'new-operator-hash', 'backup', 0, 'node-b',
        ));

        $this->assertSame('new-operator-hash', $this->mesh->lastQuiesce?->initiatorSessionTokenHash);
        $this->assertSame('new-operator-hash', $this->executor->activatingSessionTokenHash);
    }

    public function testPromotedLeaderClosesTheWindowWithTheOperatorFromItsRow(): void
    {
        $this->settleTheFreezeOnTheRuntimeRow('accept-9', 'operator-hash');
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->coordinator->onBecameLeader();

        $this->coordinator->onRefreeze('node-b', 'backup', 0);

        $this->assertSame('operator-hash', $this->mesh->lastQuiesce?->initiatorSessionTokenHash);
    }

    public function testLeaderAdmissionChecksTheCurrentPassAndBroadcasts(): void
    {
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->mintPassOnRuntimeRow('pass-hash');
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->requestAdmit('pass-hash', 'verifier-hash');

        $this->assertSame(['admitVerifier'], $this->executor->calls);
        $this->assertSame([['broadcastAdmit', 'pass-hash']], $this->mesh->calls);

        $this->executor->calls = [];
        $this->mesh->calls = [];
        $this->coordinator->onAdmit('node-b', 'old-pass', 'other-session');
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testFollowerRecordsAdmissionLocallyThenForwardsItToLeader(): void
    {
        $this->mesh->leader = 'node-x';

        $this->coordinator->requestAdmit('pass-hash', 'verifier-hash');

        $this->assertSame(['admitVerifier'], $this->executor->calls);
        $this->assertSame([['sendAdmit', 'node-x']], $this->mesh->calls);
    }

    public function testAdmissionStaysOnThePresentingMasterWithoutALeader(): void
    {
        $this->coordinator->requestAdmit('pass-hash', 'verifier-hash');

        $this->assertSame(['admitVerifier'], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testFollowerAcceptsOnlyItsLeadersAdmissionForACurrentPass(): void
    {
        $this->mesh->leader = 'node-x';
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->mintPassOnRuntimeRow('pass-hash');
        $this->executor->calls = [];

        $this->coordinator->onAdmit('node-y', 'pass-hash', 'wrong-leader');
        $this->coordinator->onAdmit('node-x', 'old-pass', 'old-code');
        $this->assertSame([], $this->executor->calls);

        $this->coordinator->onAdmit('node-x', 'pass-hash', 'verifier-hash');
        $this->assertSame(['admitVerifier'], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAdmissionOutsideTheVerificationWindowIsDropped(): void
    {
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onAdmit('node-b', 'pass-hash', 'verifier-hash');

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAPassArrivingOutsideTheWindowIsRecordedNowhereAndAnnouncedToNobody(): void
    {
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onPass('node-b', 'backup', 0, 'hash-a');

        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testTheLeaderWritesTheCircleAndFansItToEveryMaster(): void
    {
        // A member's tab connects to whichever node the balancer hands it, and each node decides
        // admission against its own copy of the row - so the photograph has to be on every master,
        // the leader's own among them.
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable(self::SELF, $this->enableDataFrom(self::SELF));
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->requestCircle($this->circleData()));

        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(1, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
        $this->assertSame([['broadcastCircle', '1']], $this->mesh->calls);
    }

    public function testAFollowerWritesTheCircleOnItsOwnRowWithoutWaitingForActive(): void
    {
        // An initiator hosted on a follower reaches `active` only on its leader's settled frame
        // (HIL-1128), so a phase gate here would make the circle wait on one more frame on exactly
        // the topology that has one. The own row is written before the leader is asked to fan it,
        // so the node the initiator sits on does not wait on a round trip.
        $this->mesh->leader = 'node-x';
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-a', null, freezeId: 'freeze-fixture'));
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->requestCircle($this->circleData()));

        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([['sendCircle', 'node-x']], $this->mesh->calls);
    }

    public function testAFollowerThatKnowsNoLeaderKeepsTheCircleOnItsOwnRow(): void
    {
        // Nobody to fan it: the rest of the cluster lets the circle in by code alone, but the node
        // the initiator sits on still recognizes the members already attached to it.
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-a', null, freezeId: 'freeze-fixture'));
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->requestCircle($this->circleData()));

        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testANodeHoldingNoFreezeSendsTheCircleToTheLeaderAndWritesNothing(): void
    {
        // A node with no local freeze still forwards the photograph for the leader to authorize.
        // Run without the truth source on purpose: reaching the row here would throw.
        $this->mesh->leader = 'node-x';

        $this->coordinator->requestCircle($this->circleData());

        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([['sendCircle', 'node-x']], $this->mesh->calls);
    }

    public function testACircleOfferedUnderNoFreezeWithNoKnownLeaderIsDropped(): void
    {
        // Run without the truth source on purpose: reaching the row here would throw.
        $this->coordinator->requestCircle($this->circleData());

        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAFollowerWritesTheCircleItsFreezingLeaderFansAndPassesItNowhere(): void
    {
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-x', 'backup', 0, $this->circleSnapshot()));

        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame(1, Hilos::$rt?->hilosProtectedModeRuntime?->circleNamedCount);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testTheLeaderWritesAndFansTheCircleOfTheNodeThatInitiatedTheFreeze(): void
    {
        // The initiator sent it from a follower or from a slave; the leader treats both alike, and
        // the sender gets the same photograph back - a rewrite of what it already holds.
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->mesh->calls = [];

        $this->withDaemonTruthSource(fn() => $this->coordinator->onCircle('node-b', 'backup', 0, $this->circleSnapshot()));

        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([['broadcastCircle', '1']], $this->mesh->calls);
    }

    public function testTheLeaderDropsACircleFromAnotherAgent(): void
    {
        // The same authorization every frame of the window is given. Run without the truth source
        // on purpose: reaching the row here would throw.
        $this->mesh->followers = ['node-b', 'node-c'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->mesh->calls = [];

        $this->coordinator->onCircle('node-c', 'other-agent', 0, $this->circleSnapshot());

        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testACircleArrivingAfterTheLiftIsRecordedNowhere(): void
    {
        // A frame that outlived its freeze: this node is no longer frozen and leads nothing. Run
        // without the truth source on purpose: reaching the row here would throw.
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));
        $this->coordinator->onLift('node-x');
        $this->mesh->calls = [];

        $this->coordinator->onCircle('node-x', 'backup', 0, $this->circleSnapshot());

        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->circleSessionTokenHashes);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testLeaderDropsAProgressMarkFromAnotherAgent(): void
    {
        // Same authorization the release is given: an agent that did not ask for the freeze could
        // otherwise keep a hung operation looking alive on the leader's row indefinitely.
        $this->mesh->followers = [];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->settleTheFreezeOnTheRuntimeRow();

        $this->withDaemonTruthSource(fn() => $this->coordinator->onProgress('node-c', 'other-agent', 0));

        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->progressAt);
    }

    public function testAProgressMarkForAFreezeThisNodeDoesNotLeadIsDropped(): void
    {
        // The ordinary tail of an operation whose last marks outlived its freeze; run without the
        // truth source on purpose, because reaching the row here would throw.
        $this->coordinator->onProgress('node-b', 'backup', 0);

        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->progressAt);
    }

    public function testInitiatorFollowerSendsItsProgressMarkToTheLeader(): void
    {
        $this->mesh->leader = 'node-z';

        $this->coordinator->requestProgress($this->progressData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([['sendProgress', 'node-z']], $this->mesh->calls);
    }

    public function testInitiatorDropsItsProgressMarkWhenNoLeaderIsKnown(): void
    {
        $this->coordinator->requestProgress($this->progressData());

        $this->assertSame([], $this->mesh->calls);
    }

    public function testLeaderDropsEnableThatNamesNoInitiatorNode(): void
    {
        // A payload with no node id is what a single-node installation sends; a leader cannot
        // orchestrate it — it would have nowhere to send ready and nothing to authorize the
        // later disable against — so it refuses instead of entering a freeze it cannot lift.
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();

        $this->coordinator->onEnable('node-b', $this->enableDataFrom(null));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::ANOTHER_OPERATION],
        ], $this->mesh->calls);
    }

    public function testLeaderWithoutRuntimeStateRefusesToEnter(): void
    {
        // Fail-closed: the initiator waits for ready before it destroys anything, so a refusal
        // leaves it waiting safely, where an inert entry would report a freeze that never happened.
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        Hilos::$rt = null;

        $this->coordinator->onEnable('node-b', $this->enableData());

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::NO_RUNTIME_ROW],
        ], $this->mesh->calls);
    }

    public function testARefusedEnterLeavesNoFreezeBehind(): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        Hilos::$rt = null;
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->assertSame([
            ['sendRefused', 'node-b', ProtectedModeRefusalCopy::NO_RUNTIME_ROW],
        ], $this->mesh->calls);
        $this->mesh->calls = [];

        $this->mount();
        $this->coordinator->onEnable('node-b', $this->enableData());

        // The guard runs above activeFreeze, so the refused attempt left no trace to drop this one
        // against; a leader that recorded the freeze first would reject every later enable forever.
        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([['broadcastQuiesce', 'restore']], $this->mesh->calls);
    }

    public function testFollowerWithoutRuntimeStateRefusesAndReportsNothing(): void
    {
        // Reporting quiesced for a freeze this node never entered would let the leader hand ready
        // to the initiator and run the destructive operation across a node still serving clients.
        Hilos::$rt = null;

        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAPromotedLeaderCanLiftTheFreezeItsNodeIsAlreadyUnder(): void
    {
        // The whole point of the rebuild: leader-side state dies with the leader, so a blank
        // successor drops the disable of a live and healthy initiator and NOTHING can unfreeze the
        // cluster - while the watchdog, by design, never lifts one either.
        $followerMesh = new FakeProtectedModeMesh();
        $followerExecutor = new FakeProtectedModeExecutor();
        $follower = new ClusterProtectedMode('node-b', $followerMesh, $followerExecutor);
        $follower->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));
        $followerExecutor->calls = [];
        $followerMesh->leader = self::SELF;

        $this->mesh->followers = ['node-b'];
        $this->settleTheFreezeOnTheRuntimeRow();

        $this->coordinator->onBecameLeader();
        $this->coordinator->onDisable('node-b', 'backup', 0);

        $this->assertSame(['enterDeactivating', 'enterInactive'], $this->executor->calls);
        $this->assertSame([['broadcastLift', null]], $this->mesh->calls);

        $follower->onLift(self::SELF);
        $this->assertSame(['enterInactive'], $followerExecutor->calls);
    }

    public function testAPromotedLeaderStillIgnoresADisableFromAnotherAgent(): void
    {
        // The identity is rebuilt from the row, not assumed: an inherited freeze is authorized the
        // same way one this leader ordered itself is.
        $this->mesh->followers = ['node-b'];
        $this->settleTheFreezeOnTheRuntimeRow();

        $this->coordinator->onBecameLeader();
        $this->coordinator->onDisable('node-c', 'other-agent', 0);

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    public function testAPromotedLeaderStartsAnUnfinishedRoundOverWithEveryFollowerOutstanding(): void
    {
        // Who had already quiesced lived in the dead leader's memory, so the successor claims no
        // knowledge of it. The round usually will not close from here - a follower already frozen
        // drops the repeat - and that is what the watchdog reports as overdue, naming these nodes.
        $this->mesh->followers = ['node-b', 'node-c', self::SELF];
        $this->withDaemonTruthSource(function (): void {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'),
                null,
            );
        });

        $this->coordinator->onBecameLeader();

        $this->assertSame(['node-b', 'node-c', self::SELF], $this->coordinator->pendingNodeIds());
        $this->assertSame(['node-b', 'node-c', self::SELF], $this->coordinator->quiesceNodeIds());
        $this->coordinator->onQuiesced('node-b');
        $this->assertSame(['node-c', self::SELF], $this->coordinator->pendingNodeIds());
        $this->assertSame(['node-b', 'node-c', self::SELF], $this->coordinator->quiesceNodeIds());
    }

    public function testAPromotedLeaderOwesTheReadyOfAnUnfinishedRound(): void
    {
        // The row does not say whether a close started the round, so an unfinished one is taken
        // for an entry, as it always was.
        $this->mesh->followers = ['node-b'];
        $this->withDaemonTruthSource(function (): void {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'),
                null,
            );
        });
        $this->coordinator->onBecameLeader();

        $this->coordinator->onQuiesced('node-b');
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->coordinator->onRosterStopped();

        $this->assertSame(['enterActive'], $this->executor->calls);
        $this->assertSame([['broadcastSettled', null], ['sendReady', 'node-b']], $this->mesh->calls);
    }

    public function testAPromotedLeaderHandsOutNoSecondReadyForAFreezeAlreadyEstablished(): void
    {
        // A row past activating means the round closed and the initiator has been told to run. A
        // successor that re-collected there would signal ready a second time, in the middle of the
        // operation the first one started.
        $this->mesh->followers = ['node-b'];
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->coordinator->onBecameLeader();

        $this->coordinator->onQuiesced('node-b');

        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertSame([], $this->coordinator->quiesceNodeIds());
    }

    public function testAPromotedLeaderStopsBeingItsOwnFreezesFollower(): void
    {
        // It was frozen by the leader that is gone, and it leads that freeze now. Left standing, the
        // follower-side marker would outlive the lift - only a lift from the same leader clears it,
        // and a leader sends itself none - and the next freeze would find this node "already
        // frozen" and leave it serving clients through somebody else's restore.
        $this->coordinator->onQuiesce('node-x', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: 'freeze-fixture'));
        $this->settleTheFreezeOnTheRuntimeRow();
        $this->coordinator->onBecameLeader();
        $this->coordinator->onDisable('node-b', 'backup', 0);
        $this->withDaemonTruthSource(static fn() => Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterInactive());
        $this->coordinator->onLostLeadership();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onQuiesce('node-y', new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-c', null, freezeId: 'freeze-fixture'));
        $this->stopTheRoster();

        $this->assertSame(['enterActivating'], $this->executor->calls);
        $this->assertSame([['sendQuiesced', 'node-y']], $this->mesh->calls);
    }

    public function testAnIdleNodeBecomingLeaderInheritsNothing(): void
    {
        // The ordinary promotion, and the case the rebuild must not disturb: an inactive row means
        // there is no freeze to lead, so a stray disable is still dropped.
        $this->mesh->followers = ['node-b'];

        $this->coordinator->onBecameLeader();
        $this->coordinator->onDisable('node-b', 'backup', 0);

        $this->assertSame([], $this->coordinator->pendingNodeIds());
        $this->assertSame([], $this->executor->calls);
        $this->assertSame([], $this->mesh->calls);
    }

    /** @param EventLoop $loop Native cue queue, with no real-clock expiry wait */
    private function drainSlaveSync(EventLoop $loop): void
    {
        for ($pass = 0; $pass < 20; ++$pass) {
            $loop->tick();
            $this->coordinator->dispatchSlaveSyncReady();
            if (!$loop->hasReadyWork() && !$this->coordinator->hasSlaveSyncReadyWork()) {
                return;
            }
        }
        self::fail('Finite sync work did not settle into an external wait');
    }

    /**
     * @param string $syncId Request id
     * @param string $phase Requested phase
     * @param string $generation Barrier generation
     * @param string $entryMode Full freeze or direct window
     * @return PeerProtectedModeSyncDTO Full occupied snapshot with the already photographed circle
     */
    private function slaveFrame(
        string $syncId,
        string $phase = StateProtectedModeRuntime::PHASE_ACTIVE,
        string $generation = 'generation',
        string $entryMode = StateProtectedModeRuntime::ENTRY_MODE_FREEZE,
    ): PeerProtectedModeSyncDTO {
        return new PeerProtectedModeSyncDTO($syncId, $phase, $entryMode,
            new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', 'operator', $generation),
            $phase !== StateProtectedModeRuntime::PHASE_ACTIVATING,
            ['pass'], ['admitted'], 2, ['circle']);
    }

    /**
     * @param string $syncId Request id
     * @return PeerProtectedModeSyncDTO Requested removal, including an already missed lift
     */
    private function inactiveSlaveFrame(string $syncId): PeerProtectedModeSyncDTO
    {
        return new PeerProtectedModeSyncDTO($syncId, StateProtectedModeRuntime::PHASE_INACTIVE, null, null, false, [], [], 0, []);
    }

    /**
     * @param callable $work Machine path whose real raw-mail calls are observed
     * @return list<array{params: array<string, mixed>, freezeId: ?string}> Mail and identity at each send
     */
    private function captureSlaveAlerts(callable $work): array
    {
        $previousMail = Hilos::$mail;
        $previousDatabase = Hilos::$db;
        $previousRecipients = getenv(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name);
        putenv(EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name . '=ops@example.com');
        $sent = [];
        $mailer = $this->getMockBuilder(HilosMailer::class)->onlyMethods(['send'])->getMock();
        $mailer->method('send')->willReturnCallback(static function (EmailMessage|MailSendSignalData $message) use (&$sent): void {
            $sent[] = ['params' => $message->params, 'freezeId' => Hilos::$rt->hilosProtectedModeRuntime->freezeId];
        });
        Hilos::$mail = $mailer;
        Hilos::$db = null;
        try {
            $work();
            $this->assertNull(Hilos::$db);
        } finally {
            Hilos::$mail = $previousMail;
            Hilos::$db = $previousDatabase;
            putenv($previousRecipients === false ? EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name
                : EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name . '=' . $previousRecipients);
        }
        return $sent;
    }

    /**
     * Wires the real peer and placement adapters without starting listeners or workers.
     *
     * @param NodeRole $role Local cluster role
     * @return PeerServer Mesh driven by authenticated socket-pair frames
     */
    private function usePlacementPeerMesh(NodeRole $role = NodeRole::Slave): PeerServer
    {
        $server = $this->protectedModePeerServer($role);
        $placement = new ClusterPlacement(self::SELF, $server, $this->createStub(PlacementExecutor::class));
        Closure::bind(static function () use ($server, $placement): void {
            $server->placement = $placement;
        }, null, PeerServer::class)();
        Hilos::$cluster->registerPlacement($placement);
        Hilos::$cluster->registerWorkerPlacement($placement);
        $this->coordinator = new ClusterProtectedMode(self::SELF, $server, $this->executor);
        $server->registerProtectedMode($this->coordinator);

        return $server;
    }

    /**
     * Mirrors the synchronous direct-window entry and lift while retaining the ordered call log.
     * The coordinator reads entryMode within these calls, before an entry returns or a lift clears it.
     */
    private function recordDirectWindowTransitions(): void
    {
        $this->executor->writeWindow = function (ProtectedModeQuiesceData $freeze, ?string $acceptKey): void {
            $this->withDaemonTruthSource(static function () use ($freeze, $acceptKey): void {
                Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterVerificationWindow($freeze, $acceptKey);
            });
        };
        $this->executor->clearWindow = function (): void {
            $this->withDaemonTruthSource(static function (): void {
                Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterInactive();
            });
        };
    }

    /**
     * Establishes a direct window with one follower and clears the entry's recorded calls.
     */
    private function enterDirectWindowOnLeader(): void
    {
        $this->recordDirectWindowTransitions();
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->directWindowData());
        $this->coordinator->onQuiesced('node-b');
        $this->executor->calls = [];
        $this->mesh->calls = [];
    }

    /**
     * @param string $operation Operation named by the window
     * @param string $agentType Initiating agent type
     * @param ?int $agentIndex Initiating agent index, or null for a singleton
     * @param ?string $initiatorNodeId Entry node, or null for a malformed cluster request
     * @return ProtectedModeEnableSignalData Direct-window request
     */
    private function directWindowData(
        string $operation = 'manual_maintenance',
        string $agentType = 'index',
        ?int $agentIndex = null,
        ?string $initiatorNodeId = 'node-b',
    ): ProtectedModeEnableSignalData {
        return new ProtectedModeEnableSignalData(
            $operation, 'operator-accept', 'operator-hash', $agentType, $agentIndex, $initiatorNodeId,
            StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
        );
    }

    /**
     * Settles the freeze on the runtime row, standing in for the real executor's write.
     *
     * The fake port records transitions instead of writing any, so a case that turns on the phase
     * the row actually reads has to put it there - through the same item actions
     * {@see DaemonProtectedModeExecutor} uses, with the daemon registered as the runtime truth
     * source for exactly the length of the write and dropped after, because the registration is
     * process-wide.
     *
     * @param ?string $acceptKey Initiator accept key to leave on the row, none by default
     * @param ?string $sessionTokenHash Initiator session token hash to leave on the row, none by default
     */
    private function settleTheFreezeOnTheRuntimeRow(?string $acceptKey = null, ?string $sessionTokenHash = null): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        $freezeId = $this->executor->freeze?->freezeId ?? 'freeze-fixture';
        $this->withDaemonTruthSource(static function () use ($view, $acceptKey, $sessionTokenHash, $freezeId): void {
            $view->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', $sessionTokenHash, freezeId: $freezeId),
                $acceptKey,
            );
            $view->actions->enterActive();
        });
    }

    /**
     * Leads a freeze for node-b with one follower through its round and its verification window,
     * and closes the window back from node-b.
     *
     * Ends with the close asked for and nothing cleared, so the case reads what the close did.
     *
     * @param ?string $acceptKey Initiator accept key the leader's row carries, none by default
     * @param ?string $sessionTokenHash Initiator session token hash the leader's row carries, none by default
     */
    private function closeTheWindowBackOnTheLeader(?string $acceptKey = null, ?string $sessionTokenHash = null): void
    {
        $this->mesh->followers = ['node-b'];
        $this->coordinator->onBecameLeader();
        $this->coordinator->onEnable('node-b', $this->enableData());
        $this->stopTheRoster();
        $this->coordinator->onQuiesced('node-b');
        $this->settleTheFreezeOnTheRuntimeRow($acceptKey, $sessionTokenHash);
        $this->openTheVerificationWindowOnTheRuntimeRow();
        $this->executor->calls = [];
        $this->mesh->calls = [];

        $this->coordinator->onRefreeze('node-b', 'backup', 0);
    }

    /**
     * Tells the coordinator this node's roster has stopped, with the row on activating as the real
     * executor leaves it for the length of the walk.
     *
     * The fake port writes no phase, and the coordinator only answers a walk that runs on
     * activating - which it reads off the row - so the case has to put the phase there first.
     */
    private function stopTheRoster(): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        $freezeId = $this->executor->freeze?->freezeId ?? 'freeze-fixture';
        $this->withDaemonTruthSource(static function () use ($view, $freezeId): void {
            $view->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', 'backup', 0, 'node-b', null, freezeId: $freezeId), null,
            );
        });
        $this->coordinator->onRosterStopped();
    }

    /**
     * Opens the verification window on this node's row, the phase a pass may be minted into.
     */
    private function openTheVerificationWindowOnTheRuntimeRow(): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        $this->withDaemonTruthSource(static fn() => $view->actions->enterVerifying());
    }

    /**
     * Mirrors the direct-window write the recording executor leaves to the test.
     *
     * @param ProtectedModeQuiesceData $window Window descriptor received from the leader
     */
    private function writeDirectWindowOnRuntimeRow(ProtectedModeQuiesceData $window): void
    {
        $this->assertNotNull(Hilos::$rt?->hilosProtectedModeRuntime);
        $this->withDaemonTruthSource(static function () use ($window): void {
            Hilos::$rt?->hilosProtectedModeRuntime?->actions->enterVerificationWindow($window, null);
        });
    }

    /**
     * @param string $passHash Pass hash minted in the open window
     */
    private function mintPassOnRuntimeRow(string $passHash): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        $this->withDaemonTruthSource(static fn() => $view->actions->issuePass($passHash));
    }

    /**
     * Runs a write with the daemon registered as the runtime truth source, and drops it after.
     *
     * The row refuses a write from anyone else, and the registration is process-wide, so it is
     * held for exactly the length of the write rather than for the length of the test.
     *
     * @param callable(): void $write Write to run as the truth source
     */
    private function withDaemonTruthSource(callable $write): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        try {
            $write();
        } finally {
            RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        }
    }

    /**
     * Mounts the framework-owned protected mode runtime row, as a real project boot does.
     *
     * @throws StateCollectionNotFoundException When a feature definition names a collection it did not mount
     */
    private function mount(): void
    {
        Hilos::$rt = new ClusterProtectedModeTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
    }

    private function enableData(): ProtectedModeEnableSignalData
    {
        return $this->enableDataFrom('node-b');
    }

    private function circleData(): ProtectedModeCircleSignalData
    {
        return new ProtectedModeCircleSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            namedCount: 1,
            sessionTokenHashes: ['hash-a'],
        );
    }

    private function circleSnapshot(): VerifierCircleSnapshot
    {
        return new VerifierCircleSnapshot(1, ['hash-a']);
    }

    private function progressData(): ProtectedModeProgressSignalData
    {
        return new ProtectedModeProgressSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
        );
    }

    private function disableData(): ProtectedModeDisableSignalData
    {
        return new ProtectedModeDisableSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
        );
    }

    private function enableDataFrom(?string $initiatorNodeId): ProtectedModeEnableSignalData
    {
        return new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            initiatorNodeId: $initiatorNodeId,
        );
    }
}

/**
 * Project context that registers no runtime state of its own: the framework mount supplies the
 * freeze row.
 *
 * Named apart from its standalone counterpart on purpose - both test files share the namespace, so
 * one context name could not carry two classes and the suite would not load.
 */
final class ClusterProtectedModeTestRtContext extends RtContext
{
    /**
     * Registers no project runtime state: the framework mount supplies the freeze row.
     */
    public function configure(): void
    {
    }
}

/**
 * Recording fake of the outbound peer port: captures each send and the roster it advertises.
 */
final class FakeProtectedModeMesh implements ProtectedModeMesh
{
    /** @var array<string> Follower node ids to advertise to the leader */
    public array $followers = [];

    /** @var list<string> Active slaves selected for catch-up */
    public array $slaves = [];

    /** @var array<string, PeerProtectedModeSyncDTO> Last complete request to each slave */
    public array $syncs = [];

    /** @var list<array{string, string, string}> Slave acknowledgements */
    public array $synced = [];

    public bool $syncCurrent = true;

    public int $syncSends = 0;

    public int $slaveReads = 0;

    public ?Closure $onSyncedSend = null;

    public ?Closure $onSyncSend = null;

    /** @var ?string Leader node id to advertise to an initiator, or null when leadership is unknown */
    public ?string $leader = null;

    /** @var AgentLocation Current location returned for the initiator */
    public AgentLocation $location;

    /** @var array<array{0: string, 1: ?string}> Ordered [method, argument] pairs sent */
    public array $calls = [];

    public ?ProtectedModeQuiesceData $lastQuiesce = null;

    public function __construct()
    {
        $this->location = AgentLocation::onNode('node-b');
    }

    public function followerNodeIds(): array
    {
        return $this->followers;
    }

    public function slaveNodeIds(): array
    {
        ++$this->slaveReads;
        return $this->slaves;
    }

    public function sendProtectedModeSync(string $nodeId, PeerProtectedModeSyncDTO $snapshot): void
    {
        $this->syncs[$nodeId] = $snapshot;
        ++$this->syncSends;
        $this->onSyncSend?->__invoke($nodeId, $snapshot);
    }

    public function isProtectedModeSyncCurrent(string $leaderNodeId, string $syncId): bool
    {
        return $this->syncCurrent && $leaderNodeId === $this->leader;
    }

    public function sendProtectedModeSynced(string $leaderNodeId, string $syncId, string $phase): void
    {
        $this->synced[] = [$leaderNodeId, $syncId, $phase];
        $this->onSyncedSend?->__invoke();
    }

    public function leaderNodeId(): ?string
    {
        return $this->leader;
    }

    public function locateAgent(string $agentType, ?int $agentIndex): AgentLocation
    {
        return $this->location;
    }

    public function sendEnable(string $leaderNodeId, ProtectedModeEnableSignalData $data): void
    {
        $this->calls[] = ['sendEnable', $leaderNodeId];
    }

    public function sendDisable(string $leaderNodeId, string $agentType, ?int $agentIndex): void
    {
        $this->calls[] = ['sendDisable', $leaderNodeId];
    }

    public function broadcastQuiesce(ProtectedModeQuiesceData $data): void
    {
        $this->lastQuiesce = $data;
        $this->calls[] = ['broadcastQuiesce', $data->operation];
    }

    /**
     * @param ProtectedModeQuiesceData $data Window descriptor to record
     */
    public function broadcastWindow(ProtectedModeQuiesceData $data): void
    {
        $this->lastQuiesce = $data;
        $this->calls[] = ['broadcastWindow', $data->operation];
    }

    public function sendReady(string $initiatorNodeId): void
    {
        $this->calls[] = ['sendReady', $initiatorNodeId];
    }

    public function sendRefused(string $initiatorNodeId, string $reason): void
    {
        $this->calls[] = ['sendRefused', $initiatorNodeId, $reason];
    }

    public function broadcastLift(): void
    {
        $this->calls[] = ['broadcastLift', null];
    }

    public function sendQuiesced(string $leaderNodeId): void
    {
        $this->calls[] = ['sendQuiesced', $leaderNodeId];
    }

    public function sendVerify(string $leaderNodeId, string $agentType, ?int $agentIndex): void
    {
        $this->calls[] = ['sendVerify', $leaderNodeId];
    }

    public function sendProgress(string $leaderNodeId, string $agentType, ?int $agentIndex): void
    {
        $this->calls[] = ['sendProgress', $leaderNodeId];
    }

    public function broadcastVerify(string $agentType, ?int $agentIndex): void
    {
        $this->calls[] = ['broadcastVerify', null];
    }

    public function sendPass(string $leaderNodeId, string $agentType, ?int $agentIndex, string $passHash): void
    {
        $this->calls[] = ['sendPass', $leaderNodeId];
    }

    public function broadcastPass(string $agentType, ?int $agentIndex, string $passHash): void
    {
        $this->calls[] = ['broadcastPass', $passHash];
    }

    public function sendAdmit(string $leaderNodeId, string $passHash, string $sessionTokenHash): void
    {
        $this->calls[] = ['sendAdmit', $leaderNodeId];
    }

    public function broadcastAdmit(string $passHash, string $sessionTokenHash): void
    {
        $this->calls[] = ['broadcastAdmit', $passHash];
    }

    public function sendCircle(string $leaderNodeId, string $agentType, ?int $agentIndex, VerifierCircleSnapshot $snapshot): void
    {
        $this->calls[] = ['sendCircle', $leaderNodeId];
    }

    public function broadcastCircle(string $agentType, ?int $agentIndex, VerifierCircleSnapshot $snapshot): void
    {
        $this->calls[] = ['broadcastCircle', (string)$snapshot->namedCount];
    }

    public function sendRefreeze(string $leaderNodeId, string $agentType, ?int $agentIndex): void
    {
        $this->calls[] = ['sendRefreeze', $leaderNodeId];
    }

    public function broadcastSettled(): void
    {
        $this->calls[] = ['broadcastSettled', null];
    }
}

/**
 * Recording fake of the local-node port: captures the phase transitions the coordinator drives.
 */
final class FakeProtectedModeExecutor implements ProtectedModeExecutor
{
    public bool $applyRuntime = false;

    /** @var array<string> Ordered method names invoked */
    public array $calls = [];

    /** @var ?string Accept key passed to the most recent enterActivating call */
    public ?string $activatingAcceptKey = null;

    /** @var ?string Session token hash passed to the most recent enterActivating call */
    public ?string $activatingSessionTokenHash = null;

    /** @var ?string Accept key passed to the most recent direct window call */
    public ?string $windowAcceptKey = 'not-called';

    /** @var ?Closure(ProtectedModeQuiesceData, ?string): void Optional synchronous window write */
    public ?Closure $writeWindow = null;

    /** @var ?Closure(): void Optional synchronous inactive write */
    public ?Closure $clearWindow = null;

    public ?Closure $onActivating = null;

    public ?Closure $onVerifying = null;

    /** @var bool Whether a case will deliver the local barrier's result explicitly */
    public bool $delayDatabase = false;

    /** @var list<Closure(ReHydrateVerdict): void> Local barrier continuations in request order */
    public array $databaseContinuations = [];

    public ?ProtectedModeQuiesceData $freeze = null;

    public function bootstrapFreezeId(string $freezeId): void
    {
        if (Hilos::$rt?->hilosProtectedModeRuntime?->freezeId !== null) {
            return;
        }
        $this->calls[] = 'bootstrapFreezeId';
        Hilos::$rt?->hilosProtectedModeRuntime?->actions->bootstrapFreezeId($freezeId);
    }

    public function importSlaveSync(PeerProtectedModeSyncDTO $snapshot, string $phase): void
    {
        $this->calls[] = 'importSlaveSync';
        Hilos::$rt?->hilosProtectedModeRuntime?->actions->importSlaveSync($snapshot, $phase);
    }

    /**
     * @param string $leaderNodeId Leader whose target requested catch-up
     * @param Closure(ReHydrateVerdict): void $onComplete Local round continuation
     */
    public function catchUpSlaveDatabase(string $leaderNodeId, Closure $onComplete): void
    {
        $this->calls[] = 'catchUpSlaveDatabase';
        $this->databaseContinuations[] = $onComplete;
        if (!$this->delayDatabase) {
            $onComplete(new ReHydrateVerdict(null, null, true, []));
        }
    }

    public function enterActivating(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
    ): void {
        $this->calls[] = 'enterActivating';
        $this->activatingAcceptKey = $initiatorAcceptKey;
        $this->activatingSessionTokenHash = $freeze->initiatorSessionTokenHash;
        $this->freeze = $freeze;
        if ($this->applyRuntime) {
            Hilos::$rt->hilosProtectedModeRuntime->actions->enterActivating($freeze, $initiatorAcceptKey);
        }
        $this->onActivating?->__invoke();
    }

    /**
     * @param ProtectedModeQuiesceData $freeze Window descriptor to record
     * @param ?string $initiatorAcceptKey Initiating connection, or null on a follower
     */
    public function enterVerificationWindow(
        ProtectedModeQuiesceData $freeze,
        ?string $initiatorAcceptKey,
    ): void {
        $this->calls[] = 'enterVerificationWindow';
        $this->windowAcceptKey = $initiatorAcceptKey;
        $this->freeze = $freeze;
        $this->writeWindow?->__invoke($freeze, $initiatorAcceptKey);
    }

    public function enterActive(): void
    {
        $this->calls[] = 'enterActive';
        if ($this->applyRuntime) {
            Hilos::$rt->hilosProtectedModeRuntime->actions->enterActive();
        }
    }

    public function enterDeactivating(): void
    {
        $this->calls[] = 'enterDeactivating';
        if ($this->applyRuntime) {
            Hilos::$rt->hilosProtectedModeRuntime->actions->enterDeactivating();
        }
    }

    public function enterVerifying(): void
    {
        $this->calls[] = 'enterVerifying';
        if ($this->applyRuntime) {
            Hilos::$rt->hilosProtectedModeRuntime->actions->enterVerifying();
        }
        $this->onVerifying?->__invoke();
    }

    public function finishVerifying(): void
    {
        $this->calls[] = 'finishVerifying';
    }

    public function announcePassIssued(): void
    {
        $this->calls[] = 'announcePassIssued';
    }

    public function admitVerifier(string $sessionTokenHash): void
    {
        $this->calls[] = 'admitVerifier';
    }

    public function enterInactive(): void
    {
        $this->calls[] = 'enterInactive';
        if ($this->applyRuntime) {
            Hilos::$rt->hilosProtectedModeRuntime->actions->enterInactive();
        }
        $this->clearWindow?->__invoke();
    }

    public function finishLift(): void
    {
        $this->calls[] = 'finishLift';
    }

    public function notifyInitiatorReady(): void
    {
        $this->calls[] = 'notifyInitiatorReady';
    }
}

/**
 * Recording fake of the initiator relay port: captures ready and refusal notices.
 */
final class FakeClusterInitiatorRelay implements ProtectedModeInitiatorRelay
{
    /** @var list<array{agentType: string, agentIndex: ?string}> */
    public array $readyCalls = [];

    /** @var list<array{agentType: string, agentIndex: ?string, reason: string}> */
    public array $refusedCalls = [];

    public function deliverProtectedModeReady(string $agentType, ?string $agentIndex): void
    {
        $this->readyCalls[] = [
            'agentType' => $agentType,
            'agentIndex' => $agentIndex,
        ];
    }

    public function deliverProtectedModeRefused(string $agentType, ?string $agentIndex, string $reason): void
    {
        $this->refusedCalls[] = [
            'agentType' => $agentType,
            'agentIndex' => $agentIndex,
            'reason' => $reason,
        ];
    }
}
