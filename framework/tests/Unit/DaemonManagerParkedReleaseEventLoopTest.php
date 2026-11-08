<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\TimeConstants;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\PendingLeadership;
use Hilos\Cluster\Peer\DTO\PeerPlacementVerdictDTO;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementAsks;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Tests\Unit\Cluster\Placement\PlacementViewTestExecutor;
use Hilos\Tests\Unit\Cluster\Placement\PlacementViewTestMesh;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

require_once __DIR__ . '/DaemonManagerHeldAgentSignalTest.php';
require_once __DIR__ . '/Cluster/Placement/ClusterPlacementViewTest.php';

/** Actual owner facts and due-now native deadlines, with no worker processes or production-clock waits. */
final class DaemonManagerParkedReleaseEventLoopTest extends TestCase
{
    private HeldAgentSignalTestManager $manager;
    private EventLoop $loop;
    private ClusterPlacement $placement;
    private PlacementViewTestMesh $mesh;
    private ?ClusterContext $previousCluster;
    private ?SignalRouter $previousRouter;
    /** @var class-string<Hilos> Facade restored after the scenario */
    private string $previousApp;

    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        $this->previousApp = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, ParkedReleaseTestHilos::class);
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership(new PendingLeadership());
        $this->mesh = new PlacementViewTestMesh();
        $this->placement = new ClusterPlacement('here', $this->mesh, new PlacementViewTestExecutor());
        Hilos::$cluster->registerPlacement($this->placement);
        Hilos::$cluster->registerWorkerPlacement($this->placement);
        $this->manager = new HeldAgentSignalTestManager();
        $this->loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->manager, $this->loop);
        $this->attach();
    }

    protected function tearDown(): void
    {
        new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->manager);
        Hilos::$sr = $this->previousRouter;
        Hilos::$cluster = $this->previousCluster;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
    }

    public function testIdleHoldHasNoReadyWorkAndTheNativeRetryUsesTheAskOwnersWindow(): void
    {
        $this->placement->onPlacementQuery('leader');
        $this->mesh->sent = [];
        $this->queue();
        $this->settle();
        self::assertCount(1, $this->manager->heldFrames());
        self::assertCount(1, $this->mesh->sent);
        self::assertFalse($this->loop->hasReadyWork());
        $original = $this->timer()->getDeadline();
        for ($portion = 0; $portion < 4; $portion++) {
            $this->manager->drainQueue();
            $this->loop->tick();
        }
        self::assertSame($original, $this->timer()->getDeadline());
        self::assertCount(1, $this->mesh->sent);
        self::assertFalse($this->loop->hasReadyWork());

        $asks = new ReflectionProperty(ClusterPlacement::class, 'placementAsks')->getValue($this->placement);
        self::assertInstanceOf(PlacementAsks::class, $asks);
        $asks->remember(HeldAgentSignalTestRouter::COLD_AGENT, hrtime(true) / 1e9 - 1.0);
        new ReflectionMethod(DaemonManager::class, 'rearmPlacementSignalTimer')->invoke($this->manager,
            HeldAgentSignalTestRouter::COLD_AGENT);
        $this->loop->tick();
        $this->settle();
        self::assertCount(2, $this->mesh->sent, 'The timer must retry without another incoming frame');
        self::assertGreaterThan($original, $this->timer()->getDeadline());
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testARealLeaderFactBypassesTheOldMissingLeaderSuppression(): void
    {
        $this->queue();
        $this->settle();
        self::assertSame([], $this->mesh->sent);
        self::assertNotNull($this->timer()->getDeadline());
        self::assertFalse($this->loop->hasReadyWork());

        $this->placement->onPlacementQuery('leader');
        $this->mesh->sent = [];
        $this->settle();
        self::assertCount(1, $this->mesh->sent);
        self::assertSame('leader', $this->mesh->sent[0][0]);
        self::assertCount(1, $this->manager->heldFrames());
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testStartedPlacementVerdictReleasesOnlyTheHeldDestinationWithoutAnotherInput(): void
    {
        $this->queue(HeldAgentSignalTestRouter::SHARED_PUSH);
        $this->settle();
        self::assertCount(1, $this->manager->heldFrames());
        self::assertSame([HeldAgentSignalTestRouter::SHARED_PUSH . '@' . HeldAgentSignalTestRouter::UP_AGENT],
            $this->manager->workerServer->deliveries);

        $this->placement->onPlacementVerdict('leader', PeerPlacementVerdictDTO::placed(
            HeldAgentSignalTestRouter::COLD_AGENT, null, 'elsewhere',
        ));
        $this->settle();
        self::assertSame([], $this->manager->heldFrames());
        self::assertSame([HeldAgentSignalTestRouter::SHARED_PUSH . '@' . HeldAgentSignalTestRouter::UP_AGENT],
            $this->manager->workerServer->deliveries, 'An already delivered destination must not receive the frame again');
        self::assertSame([], $this->timers());
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testRebindCancelsOldDueWorkAndKeepsTheOriginalAskDeadline(): void
    {
        $this->placement->onPlacementQuery('leader');
        $this->mesh->sent = [];
        $this->queue();
        $this->settle();
        $old = $this->timer();
        $original = $old->getDeadline();
        $oldPlacementWake = new ReflectionProperty(DaemonManager::class, 'placementHoldWake')->getValue($this->manager);
        $oldWorkerWake = new ReflectionProperty(DaemonManager::class, 'workerHoldWake')->getValue($this->manager);
        $old->armAt(hrtime(true) / 1e9 - 1.0);
        $this->attach();
        $this->settle();
        self::assertNull($old->getDeadline());
        self::assertSame($original, $this->timer()->getDeadline());
        self::assertCount(1, $this->mesh->sent);
        self::assertFalse($this->loop->hasReadyWork());
        $oldPlacementWake(HeldAgentSignalTestRouter::COLD_AGENT);
        $oldWorkerWake(HeldAgentSignalTestRouter::COLD_AGENT);
        self::assertFalse($this->loop->hasReadyWork(), 'Escaped callbacks from an old binding must stay canceled');
    }

    public function testTheLeadersOwnStartedAnswerReleasesAWaitAdmittedAfterTheRegistryFact(): void
    {
        $this->placement->onBecameLeader((hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND), 1);
        $this->placement->registry()->put(new PlacementRecord(
            HeldAgentSignalTestRouter::COLD_AGENT, null, 'elsewhere', PlacementState::Started,
        ));
        new ReflectionMethod(DaemonManager::class, 'parkUntilAgentUp')->invoke($this->manager,
            new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName(HeldAgentSignalTestRouter::COLD_PUSH), new SignalData([])),
            HeldAgentSignalTestRouter::COLD_AGENT, true);
        $this->settle();
        self::assertFalse($this->loop->hasReadyWork());
        new ReflectionMethod(DaemonManager::class, 'requireOnDemandPlacement')->invoke($this->manager,
            HeldAgentSignalTestRouter::COLD_AGENT, null);
        $this->settle();
        self::assertSame([], $this->manager->heldFrames());
        self::assertSame([], $this->timers());
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testLocalPlacementProgressBecomesARealStartWaitWithoutAnotherPlacementTimer(): void
    {
        $this->queue();
        $this->settle();
        $this->placement->onPlacementVerdict('leader', PeerPlacementVerdictDTO::placed(
            HeldAgentSignalTestRouter::COLD_AGENT, null, 'here',
        ));
        $this->settle();
        self::assertCount(1, $this->manager->heldFrames());
        self::assertFalse($this->manager->heldFrames()[0]->awaitingPlacement);
        self::assertSame([], $this->timers());
        self::assertFalse($this->loop->hasReadyWork());
        $this->manager->reportStarted(HeldAgentSignalTestRouter::COLD_AGENT);
        $this->settle();
        self::assertSame([HeldAgentSignalTestRouter::COLD_PUSH . '@' . HeldAgentSignalTestRouter::COLD_AGENT],
            $this->manager->workerServer->deliveries);
        self::assertSame([], $this->manager->heldFrames());
    }

    public function testAForwardedHoldChecksAnAlreadyKnownRemoteAddressOnceAtAdmission(): void
    {
        $this->placement->onPlacementVerdict('leader', PeerPlacementVerdictDTO::placed(
            HeldAgentSignalTestRouter::COLD_AGENT, null, 'elsewhere',
        ));
        $this->manager->deliverSignalToAgent(HeldAgentSignalTestRouter::COLD_AGENT, null,
            new SignalDTO(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName(HeldAgentSignalTestRouter::COLD_PUSH), new SignalData([])));
        $this->settle();
        self::assertSame([], $this->manager->heldFrames());
        self::assertSame([], $this->manager->workerServer->deliveries);
        self::assertSame([], $this->timers());
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testAbandoningTheLastPlacementCallerCancelsItsOldNativeRetry(): void
    {
        $this->placement->onPlacementQuery('leader');
        $this->mesh->sent = [];
        Hilos::$sr->queueSignal(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName(HeldAgentSignalTestRouter::COLD_COMMAND),
            new CommandRequestDTO('departed', HeldAgentSignalTestRouter::COLD_COMMAND));
        $this->settle();
        $old = $this->timer();
        $old->armAt(hrtime(true) / 1e9 - 1.0);
        $this->manager->onCommandAbandoned('departed');
        $this->settle();
        self::assertNull($old->getDeadline());
        self::assertSame([], $this->manager->heldFrames());
        self::assertSame([], $this->timers());
        self::assertCount(1, $this->mesh->sent);
        self::assertSame([], $this->manager->workerServer->deliveries);
        self::assertFalse($this->loop->hasReadyWork());
    }

    private function attach(): void
    {
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->manager);
    }

    /** @param string $name Existing fixture frame, whose actual destination lookup uses the placement owner */
    private function queue(string $name = HeldAgentSignalTestRouter::COLD_PUSH): void
    {
        Hilos::$sr->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName($name), new SignalData([]));
    }

    private function settle(): void
    {
        $runnable = new ReflectionMethod(DaemonManager::class, 'hasRunnableSignalDispatch');
        for ($portion = 0; $portion < 1000; $portion++) {
            $this->loop->tick();
            $this->manager->drainQueue();
            if (!$runnable->invoke($this->manager) && !$this->loop->hasReadyWork()) {
                return;
            }
        }
        self::fail('A blocked hold kept dispatch runnable');
    }

    /** @return array<string, EventLoopTimer> Native retries for unresolved placement addresses only */
    private function timers(): array
    {
        return new ReflectionProperty(DaemonManager::class, 'placementSignalTimers')->getValue($this->manager);
    }

    /** @return EventLoopTimer This scenario's one unresolved address */
    private function timer(): EventLoopTimer
    {
        return $this->timers()[HeldAgentSignalTestRouter::COLD_AGENT];
    }
}

abstract class ParkedReleaseTestHilos extends Hilos
{
    public const array AGENTS = [
        HeldAgentSignalTestRouter::COLD_AGENT => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
        HeldAgentSignalTestRouter::UP_AGENT => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}
