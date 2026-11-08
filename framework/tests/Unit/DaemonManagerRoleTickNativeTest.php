<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\Consensus\ConsensusMesh;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeLifecycleState;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\DaemonDeparture;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Socket;
use WeakReference;

/** Native role cues and actual guarded manager passes, without waiting out any production deadline. */
final class DaemonManagerRoleTickNativeTest extends TestCase
{
    private EventLoop $loop;
    private RoleTickNativeManager $manager;
    private ?ClusterContext $previousCluster;
    private ?SignalRouter $previousRouter;
    private ?EnvAccessor $previousEnv;
    private ?RtContext $previousRuntime;
    private string $previousApp;
    private string|false $previousEnabled;
    private string $logFile;

    /** @var list<Socket> Readable socket-pair proof, never a timer expiry wait */
    private array $sockets = [];

    public function testNativeDueIsConsumedBeforeHookAndSelfArmSurvivesUnrelatedPasses(): void
    {
        $future = hrtime(true) / 1e9 + 60;
        $entries = [];
        $this->manager->onHook = function () use ($future, &$entries): void {
            $entries[] = [$this->get('roleTickDeadline'), $this->get('roleTickDue')];
            if (count($entries) === 1) {
                $this->manager->arm($future);
            }
        };
        $this->manager->arm(hrtime(true) / 1e9 - 1);
        $this->loop->runOnce();
        $this->assertSame([], $this->manager->hooks, 'native expiry only cues the guarded phase');
        $this->assertTrue($this->get('roleTickDue'));
        $this->guard();
        $this->assertSame([[null, false]], $entries);
        $this->assertFalse($this->runnable(), 'a future self-arm is not runnable');
        $this->readableWake();
        $this->guard();
        $this->assertSame([[null, false], [$future, false]], $entries);
        $this->assertSame(['standalone', 'standalone'], $this->manager->hooks);
        $this->assertSame($future, $this->timer()->getDeadline());
    }

    public function testDueWithoutSelfArmBecomesDormantAndSynchronousDueWorksBeforePolling(): void
    {
        $this->manager->arm(hrtime(true) / 1e9 - 1);
        $this->loop->runOnce();
        $this->guard();
        $this->assertNull($this->timer()->getDeadline());
        $this->assertFalse($this->runnable());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->manager->arm(hrtime(true) / 1e9 - 1);
        $this->invoke('dispatchRoleTick');
        $this->assertSame(['standalone', 'standalone'], $this->manager->hooks);
        $this->assertNull($this->get('roleTickDeadline'));
        $this->assertFalse($this->runnable());
        $this->loop->tick();
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testEqualArmReplacementCancelAndReadableIoKeepTheOriginalDeadline(): void
    {
        $this->guard();
        $future = hrtime(true) / 1e9 + 60;
        $this->manager->arm($future);
        $timer = $this->timer();
        $generation = new ReflectionProperty(EventLoopTimer::class, 'generation');
        $original = $generation->getValue($timer);
        $this->manager->arm($future);
        $this->assertSame($original, $generation->getValue($timer), 'equal future arms keep the native generation');
        $this->readableWake();
        $this->guard();
        $this->assertSame($future, $timer->getDeadline());
        $this->assertSame($original, $generation->getValue($timer));
        $this->manager->arm($future + 60);
        $this->assertSame($future + 60, $timer->getDeadline());
        $this->assertGreaterThan($original, $generation->getValue($timer));
        $this->manager->arm(null);
        $this->assertNull($timer->getDeadline());
        $this->assertFalse($this->runnable());
    }

    public function testAllFiveActualPhasesAndSameHookTransitionInvalidateActivatedWork(): void
    {
        $this->guard();
        $leadership = new RoleTickNativeLeadership();
        $this->context($leadership);
        $this->guard();
        $this->assertSame(NodeLifecycleState::MasterFollowerOrCandidate, $this->get('roleTickState'));
        $this->manager->arm(hrtime(true) / 1e9 - 1);
        $old = $this->timer();
        $escaped = $this->timerCallback($old);
        $future = hrtime(true) / 1e9 + 60;
        $activated = false;
        $this->loop->createReadyWork(function () use ($old, $leadership, $future, &$activated): void {
            $activated = $old->getDeadline() === null;
            $leadership->quorum = false;
            $this->invoke('reconcileRoleTickSource');
            $this->manager->arm($future);
        })->markReady();
        $this->loop->runOnce();
        $this->assertTrue($activated, 'old native timer activated before its queued owner callback');
        $this->assertCount(2, $this->manager->hooks);
        $escaped();
        $this->guard();
        $this->assertSame(NodeLifecycleState::MasterNoQuorum, $this->get('roleTickState'));
        $this->assertSame($future, $this->timer()->getDeadline(), 'old callback cannot consume the new phase arm');
        $this->assertFalse($this->runnable());
        $leadership->quorum = true;
        $leadership->leader = true;
        $this->guard();
        $this->assertNull($this->timer()->getDeadline());
        $this->context(new RoleTickNativeLeadership(), NodeRole::Slave);
        $this->guard();
        $this->assertSame(['standalone', 'not-leader', 'not-leader', 'leader', 'slave'], $this->manager->hooks);
        $this->assertSame(NodeLifecycleState::Slave, $this->get('roleTickState'));
        $this->assertFalse($this->runnable());
    }

    public function testLateFacadeAndLeadershipReplacementOweOnePassEvenWithTheSamePhase(): void
    {
        $this->guard();
        $first = new RoleTickNativeLeadership();
        $context = $this->context($first);
        $this->invoke('reconcileRoleTickSource');
        $this->assertTrue($this->runnable());
        $this->guard();
        $this->assertFalse($this->runnable());
        $this->manager->arm(hrtime(true) / 1e9 + 60);
        $escaped = $this->timerCallback($this->timer());
        $weak = WeakReference::create($first);
        $context->registerLeadership(new RoleTickNativeLeadership());
        unset($first);
        $this->assertNull($weak->get(), 'role binding never retains the old leadership source');
        $escaped();
        $this->assertFalse($this->get('roleTickDue'));
        $this->invoke('reconcileRoleTickSource');
        $this->assertTrue($this->runnable());
        $this->assertNull($this->timer()->getDeadline());
        $this->guard();
        $this->assertFalse($this->runnable());
        $weak = WeakReference::create($context);
        $this->context(new RoleTickNativeLeadership());
        unset($context);
        $this->assertNull($weak->get(), 'role binding never retains the replaced cluster graph');
        $this->invoke('reconcileRoleTickSource');
        $this->assertTrue($this->runnable());
        $this->guard();
        Hilos::$cluster = null;
        $this->invoke('reconcileRoleTickSource');
        $this->assertTrue($this->runnable());
        $this->guard();
        $this->assertSame(['standalone', 'not-leader', 'not-leader', 'not-leader', 'standalone'], $this->manager->hooks);
        $this->assertFalse($this->runnable());
    }

    public function testEarlyArmRebindActivatedCancellationAndTerminalDetach(): void
    {
        $this->invoke('detachRoleTickEventLoop');
        $this->manager = new RoleTickNativeManager();
        $future = hrtime(true) / 1e9 + 60;
        $this->manager->arm($future);
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $this->loop);
        $this->invoke('attachRoleTickEventLoop');
        $this->assertSame($future, $this->timer()->getDeadline(), 'early arm survives startup binding');
        $old = $this->timer();
        $escaped = $this->timerCallback($old);
        $this->invoke('attachRoleTickEventLoop');
        $this->guard();
        $escaped();
        $this->assertFalse($this->runnable());
        $this->assertSame($future, $this->timer()->getDeadline());
        $this->assertNull($old->getDeadline());
        $this->manager->arm(hrtime(true) / 1e9 - 1);
        $old = $this->timer();
        $escaped = $this->timerCallback($old);
        $activated = false;
        $this->loop->createReadyWork(function () use ($old, &$activated): void {
            $activated = $old->getDeadline() === null;
            $this->invoke('detachRoleTickEventLoop');
        })->markReady();
        $this->loop->runOnce();
        $this->assertTrue($activated);
        $escaped();
        $this->invoke('reconcileRoleTickSource');
        $this->loop->tick();
        $this->assertFalse($this->runnable());
        $this->assertFalse($this->get('roleTickDue'));
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertCount(1, $this->manager->hooks);
        $this->expectException(EventLoopException::class);
        $this->manager->arm($future);
    }

    public function testSamePhaseLateContextIsANewOwnerAndDropsTheOldDeadline(): void
    {
        $this->guard();
        $this->manager->arm(hrtime(true) / 1e9 + 60);
        $escaped = $this->timerCallback($this->timer());
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership(new StandaloneLeadership());
        $escaped();
        $this->assertFalse($this->get('roleTickDue'));
        $this->invoke('reconcileRoleTickSource');
        $this->assertTrue($this->runnable());
        $this->assertSame(NodeLifecycleState::Standalone, $this->get('roleTickState'));
        $this->assertNull($this->timer()->getDeadline());
        $this->guard();
        $this->assertSame(['standalone', 'standalone'], $this->manager->hooks);
        $this->assertFalse($this->runnable());
    }

    public function testRebindKeepsNativeDueAndNullCancelsAnActivatedCallback(): void
    {
        $this->guard();
        $this->manager->arm(hrtime(true) / 1e9 - 1);
        $this->loop->runOnce();
        $this->invoke('attachRoleTickEventLoop');
        $this->assertTrue($this->runnable(), 'rebind keeps the already fired owner cue');
        $this->assertNull($this->timer()->getDeadline());
        $this->guard();
        $this->assertFalse($this->runnable());
        $this->manager->arm(hrtime(true) / 1e9 - 1);
        $old = $this->timer();
        $activated = false;
        $this->loop->createReadyWork(function () use ($old, &$activated): void {
            $activated = $old->getDeadline() === null;
            $this->manager->arm(null);
        })->markReady();
        $this->loop->runOnce();
        $this->assertTrue($activated);
        $this->assertFalse($this->runnable(), 'null invalidates queued native delivery too');
        $this->assertFalse($this->get('roleTickDue'));
        $this->guard();
        $this->assertSame(['standalone', 'standalone', 'standalone'], $this->manager->hooks);
        $this->assertFalse($this->runnable());
    }

    public function testNativeBindingDoesNotRetainItsManager(): void
    {
        $manager = new ReflectionClass(RoleTickNativeManager::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $this->loop);
        new ReflectionMethod(DaemonManager::class, 'attachRoleTickEventLoop')->invoke($manager);
        new ReflectionMethod(DaemonManager::class, 'armRoleTickAt')->invoke($manager, hrtime(true) / 1e9 - 1);
        $weak = WeakReference::create($manager);
        unset($manager);
        $this->assertNull($weak->get());
        $this->loop->runOnce();
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertSame([], $this->manager->hooks);
    }

    public function testHookFacadeChangeQueuesNextPhaseAndNoSecondHookInTheCurrentGuard(): void
    {
        $this->manager->onHook = function (): void {
            $this->manager->onHook = null;
            $this->manager->arm(hrtime(true) / 1e9 + 60);
            $this->context(new RoleTickNativeLeadership());
        };
        $this->guard();
        $this->assertSame(['standalone'], $this->manager->hooks);
        $this->assertTrue($this->runnable());
        $this->assertNull($this->timer()->getDeadline());
        $this->guard();
        $this->assertSame(['standalone', 'not-leader'], $this->manager->hooks);
        $this->assertFalse($this->runnable());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testRealCoordinatorLossCancelsBeforeRoleSwapWithoutStealingOtherConsumers(): void
    {
        $mesh = $this->createMock(ConsensusMesh::class);
        $mesh->method('onlineMasterIds')->willReturn(['a', 'b', 'c']);
        $coordinator = new ClusterCoordinator(
            new ClusterConsensusConfig('a', ['a', 'b', 'c'], 2, 1000, 1000, 100),
            $mesh,
            $this->manager,
        );
        $this->context($coordinator);
        $epoch = 0;
        $readiness = 0;
        $coordinator->setEpochWake(static function () use (&$epoch): void { ++$epoch; });
        $coordinator->setReadinessWake(static function () use (&$readiness): void { ++$readiness; });
        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $coordinator->onVoteReply(new PeerVoteReplyDTO(1, true, 'b'));
        $this->assertTrue($coordinator->amLeader());
        $this->guard();
        $this->manager->arm(hrtime(true) / 1e9 + 60);
        $old = $this->timer();
        $escaped = $this->timerCallback($old);
        $beforeEpoch = $epoch;
        $beforeReadiness = $readiness;
        $coordinator->onRequestVote(new PeerRequestVoteDTO(2, 'b'));
        $this->assertTrue($this->manager->leaderDuringLoss, 'real observer runs while amLeader is still true');
        $this->assertFalse($coordinator->amLeader());
        $this->assertNull($old->getDeadline(), 'loss cancels without waiting for the role field to swap');
        $this->assertTrue($this->runnable());
        $this->assertGreaterThan($beforeEpoch, $epoch);
        $this->assertGreaterThan($beforeReadiness, $readiness);
        $escaped();
        $this->guard();
        $this->assertSame(['leader', 'not-leader'], $this->manager->hooks);
        $this->assertNull($this->timer()->getDeadline());
        $this->assertFalse($this->runnable());
    }

    public function testThrowingHookConsumesDueAndReachesTheExistingDepartureGuard(): void
    {
        $failure = new RuntimeException('role hook failed');
        $this->manager->onHook = function () use ($failure): void {
            $this->assertNull($this->get('roleTickDeadline'));
            $this->assertFalse($this->get('roleTickDue'));
            throw $failure;
        };
        $this->manager->arm(hrtime(true) / 1e9 - 1);
        $timer = $this->timer();
        $this->loop->runOnce();
        $this->guard(false);
        $this->assertSame(['standalone'], $this->manager->hooks);
        $this->assertCount(1, $this->manager->failures);
        $this->assertSame(MasterFailureUnit::LOOP_ITERATION, $this->manager->failures[0]->unit);
        $this->assertSame($failure, $this->manager->failures[0]->failure);
        $this->assertSame(DaemonDeparture::Failed, $this->manager->departure());
        $this->assertTrue($this->get('shouldExit'));
        $this->assertNull($timer->getDeadline());
        $this->assertFalse($this->runnable());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        $this->previousEnv = Hilos::$env;
        $this->previousRuntime = Hilos::$rt;
        $this->previousApp = Hilos::appClass();
        $this->previousEnabled = getenv(EnvConstants::CLUSTER_ENABLED->name);
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=false');
        Hilos::$cluster = null;
        Hilos::$rt = null;
        Hilos::$env = new EnvAccessor();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, Hilos::class);
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-role-native-');
        Logger::setLogFile($this->logFile);
        $this->loop = new EventLoop();
        $this->manager = new RoleTickNativeManager();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $this->loop);
        $this->invoke('attachRoleTickEventLoop');
    }

    protected function tearDown(): void
    {
        $this->invoke('detachRoleTickEventLoop');
        $this->loop->cleanup();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Logger::resetLogFile();
        unlink($this->logFile);
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = $this->previousRouter;
        Hilos::$env = $this->previousEnv;
        Hilos::$rt = $this->previousRuntime;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        putenv(EnvConstants::CLUSTER_ENABLED->name . ($this->previousEnabled === false ? '' : '=' . $this->previousEnabled));
        parent::tearDown();
    }

    /**
     * @param Leadership $leadership Actual source installed through the facade's existing seam
     * @param NodeRole $role Local identity supplying the phase
     * @return ClusterContext New source incarnation
     */
    private function context(Leadership $leadership, NodeRole $role = NodeRole::Master): ClusterContext
    {
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=true');
        Hilos::$env = new EnvAccessor();
        $context = new ClusterContext();
        new ReflectionProperty(ClusterContext::class, 'identity')->setValue($context, NodeIdentity::of('a', 'Node A', $role, []));
        $context->registerLeadership($leadership);
        Hilos::$cluster = $context;
        return $context;
    }

    /** Readable bytes guarantee that the native blocking entry has immediate real I/O. */
    private function readableWake(): void
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        array_push($this->sockets, ...$pair);
        $read = false;
        $this->loop->registerRead($pair[0], static function () use ($pair, &$read): void {
            $read = socket_read($pair[0], 1) === 'x';
        });
        socket_write($pair[1], 'x');
        $this->loop->runOnce();
        $this->assertTrue($read);
    }

    /** @param bool $expectHealthy Whether the complete pass must reach its end */
    private function guard(bool $expectHealthy = true): void
    {
        $this->invoke('runGuardedIteration', microtime(true));
        if ($expectHealthy) {
            $this->assertSame([], $this->manager->failures);
            $this->assertFalse($this->get('shouldExit'));
        }
    }

    /** @return bool Actual synchronous owner predicate for the future blocking driver */
    private function runnable(): bool
    {
        return $this->invoke('hasRunnableRoleTick');
    }

    /** @return EventLoopTimer Actual native timer, never a replacement scheduler */
    private function timer(): EventLoopTimer
    {
        return $this->get('roleTickTimer');
    }

    /**
     * @param EventLoopTimer $timer Original handle before replacement or detach
     * @return Closure Escaped old owner callback used to prove generation/lifetime rejection
     */
    private function timerCallback(EventLoopTimer $timer): Closure
    {
        return new ReflectionProperty(EventLoopTimer::class, 'callback')->getValue($timer);
    }

    /**
     * @param string $property Existing native basis or guarded outcome
     * @return mixed Observed value
     */
    private function get(string $property): mixed
    {
        return new ReflectionProperty(DaemonManager::class, $property)->getValue($this->manager);
    }

    /**
     * @param string $method Existing phase or local lifecycle seam
     * @param mixed ...$arguments Phase arguments
     * @return mixed Phase result
     */
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager, ...$arguments);
    }
}

/** Hooks record actual business entry; the rest of the manager pass remains production code. */
final class RoleTickNativeManager extends DaemonManager
{
    /** @var list<string> One entry per actual guarded pass */
    public array $hooks = [];

    /** @var list<ContainedFailure> Failures caught by the real manager guard */
    public array $failures = [];

    public ?Closure $onHook = null;
    public ?bool $leaderDuringLoss = null;

    /** @param ?float $at Absolute monotonic deadline, or cancellation */
    public function arm(?float $at): void
    {
        $this->armRoleTickAt($at);
    }

    /** @return DaemonDeparture Actual departure reason after a failed hook */
    public function departure(): DaemonDeparture
    {
        return $this->departure;
    }

    /** @param int $term Actual term lost before the coordinator swaps its role */
    public function onLostLeadership(int $term): void
    {
        $this->leaderDuringLoss = Hilos::$cluster?->amLeader();
        parent::onLostLeadership($term);
    }

    /** @return SignalRouter Empty local router for the real guarded phases */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon No agent is started by this role-only fixture */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new RoleTickNativeAgentManager();
    }

    /** Records one standalone business entry. */
    protected function onTickStandalone(): void
    {
        $this->recordHook('standalone');
    }

    /** Records one leader business entry. */
    protected function onTickLeaderMaster(): void
    {
        $this->recordHook('leader');
    }

    /** Records one business entry for either non-leader phase. */
    protected function onTickNotLeaderMaster(): void
    {
        $this->recordHook('not-leader');
    }

    /** Records one slave business entry. */
    protected function onTickSlave(): void
    {
        $this->recordHook('slave');
    }

    /** @param ContainedFailure $failure Failure caught by the enclosing real manager guard */
    protected function onContainedFailure(ContainedFailure $failure): void
    {
        $this->failures[] = $failure;
    }

    /** @param string $hook Actual matching hook that entered business */
    private function recordHook(string $hook): void
    {
        $this->hooks[] = $hook;
        $this->onHook?->__invoke();
    }
}

/** The fixture declares no worker or agent work. */
final class RoleTickNativeAgentManager extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused fixture address
     * @param ?string $agentIndex Unused instance address
     * @return AgentDaemonInterface No agent is declared by this fixture
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('No agent is used by the role fixture.');
    }
}

/** Mutable business role basis, read through the actual ClusterContext. */
final class RoleTickNativeLeadership implements Leadership
{
    public bool $leader = false;
    public bool $quorum = true;

    /** @return bool Current leader fact */
    public function amLeader(): bool
    {
        return $this->leader;
    }

    /** @return ?string No remote routing in this fixture */
    public function leaderId(): ?string
    {
        return null;
    }

    /** @return bool No browser entrance in this fixture */
    public function leaderWebSocketOpen(): bool
    {
        return false;
    }

    /** @return bool Current quorum fact */
    public function hasQuorum(): bool
    {
        return $this->quorum;
    }
}
