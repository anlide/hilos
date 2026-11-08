<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Generator;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\Consensus\ConsensusMesh;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Cluster\Probe\ProbeFleetSupervisor;
use Hilos\Cluster\StandaloneLeadership;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\WorkerConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Process;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\AgentDeclarationDuty;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Tests\Unit\Cluster\Placement\FakePlacementExecutor;
use Hilos\Tests\Unit\Cluster\Placement\FakePlacementMesh;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Socket;
use WeakReference;

require_once __DIR__ . '/StatusComponentBootstrapTestSupport.php';

/** Same-process native facts, actual declaration owners and complete typed worker output. */
final class DaemonManagerDeclarationNativeTest extends TestCase
{
    private EventLoop $loop;
    private DeclarationNativeManager $manager;
    private DeclarationNativeServer $server;
    private ?ClusterContext $previousCluster;
    private ?SignalRouter $previousRouter;
    private ?EnvAccessor $previousEnv;
    private ?RtContext $previousRuntime;
    private string $previousApp;
    private string|false $previousEnabled;
    private string|false $previousAppEnv;
    private string $logFile;

    /** @var list<Socket> Real native readable sources */
    private array $sockets = [];

    public function testEmptyFinalReportSettlesNativelyWithoutRegistryRevisionAndReleasesBothDuties(): void
    {
        [$placement, $executor] = $this->cluster(['leader', 'peer']);
        $this->attach();
        $placement->onBecameLeader(hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND);
        $revision = $placement->registry()->revision();
        $this->guard();
        self::assertSame([], $executor->executed);
        self::assertFalse($this->runnable());
        self::assertNull($this->retry($this->policy()));
        self::assertNull($this->retry($this->fleet()));
        $this->native(fn() => $placement->onPlacementReport('peer', new PeerPlacementReportDTO([])));
        self::assertSame($revision, $placement->registry()->revision());
        self::assertFalse($this->runnable(), 'receipt alone does not settle the complete import');
        self::assertSame([], $executor->executed, 'native callbacks never declare');
        $this->guard();
        self::assertSame($this->expectedCluster(), $executor->executed);
        self::assertCount(11, $placement->registry()->all());
        self::assertFalse($this->runnable());
        self::assertNull($this->retry($this->policy()));
        self::assertNull($this->retry($this->fleet()));
        self::assertSame([], $this->server->attempts);
    }

    public function testRemoteDeclarationsProduceTheCompleteTypedPeerSequenceOnce(): void
    {
        [$placement, $executor, $mesh] = $this->cluster(['leader', 'peer'], true);
        $this->attach();
        $placement->onBecameLeader(hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND);
        $this->guard();
        $this->native(fn() => $placement->onPlacementReport('peer', PeerPlacementReportDTO::fromArray(
            new PeerPlacementReportDTO([])->toArray(),
        )));
        $this->guard();
        $this->guard();
        $expected = $this->expectedCluster();
        self::assertCount(11, $mesh->sent);
        foreach ($mesh->sent as $index => [$nodeId, $frame]) {
            self::assertSame('peer', $nodeId);
            self::assertInstanceOf(PeerPlaceAgentDTO::class, $frame);
            self::assertSame($expected[$index][0], $frame->agentType);
            self::assertSame($expected[$index][1], $frame->agentIndex);
        }
        self::assertCount(3, $mesh->broadcast);
        self::assertInstanceOf(PeerPlacementQueryDTO::class, $mesh->broadcast[0]);
        self::assertInstanceOf(PeerPlacementViewDTO::class, $mesh->broadcast[1]);
        self::assertSame([], $mesh->broadcast[1]->agents);
        self::assertInstanceOf(PeerPlacementViewDTO::class, $mesh->broadcast[2]);
        self::assertSame('leader', $mesh->broadcast[2]->leaderNodeId);
        self::assertSame($expected, array_map(static fn($entry): array => [$entry->agentType, $entry->agentIndex],
            $mesh->broadcast[2]->agents['peer']));
        self::assertSame([], $executor->executed);
        self::assertFalse($this->runnable());
        self::assertNull($this->retry($this->policy()));
        self::assertNull($this->retry($this->fleet()));
    }

    public function testTrackedStatesAndExtrasStayAsleepUnderUnrelatedNativeCapacityAndDiagnosticFacts(): void
    {
        [$placement, $executor] = $this->cluster();
        $this->attach();
        $this->guard();
        $expected = $this->expectedCluster();
        foreach ([PlacementState::Stopped, PlacementState::Failed, PlacementState::Unplaced,
            PlacementState::Refused, PlacementState::Placing, PlacementState::Started] as $index => $state) {
            $placement->registry()->put(new PlacementRecord(HilosAgentType::HILOS_PROBE_FLEET, (string)$index, null, $state));
        }
        $placement->registry()->put(new PlacementRecord(HilosAgentType::HILOS_PROBE_FLEET, '99', null, PlacementState::Failed));
        $placement->registry()->put(new PlacementRecord(HilosAgentType::HILOS_PROBE_FLEET, '2000', null, PlacementState::Stopped));
        $placement->registry()->put(new PlacementRecord('library', null, null, PlacementState::Stopped));
        $this->native(fn() => $this->server->noteWorkerCapacityChanged());
        $this->guard();
        self::assertSame($expected, $executor->executed);
        self::assertFalse($this->runnable());
        self::assertNull($this->retry($this->policy()));
        self::assertNull($this->retry($this->fleet()));
        $placement->registry()->forget('hilos_probe_fleet:99');
        $placement->registry()->forget('hilos_probe_fleet:2000');
        $placement->registry()->forget('hilos_probe_fleet:7');
        $placement->registry()->forget('library');
        self::assertTrue($this->runnable());
        $this->guard();
        self::assertSame([...$expected, ['library', null], [HilosAgentType::HILOS_PROBE_FLEET, '7']], $executor->executed);
        self::assertNull($placement->registry()->get('hilos_probe_fleet:99'));
        self::assertNull($placement->registry()->get('hilos_probe_fleet:2000'));
    }

    public function testActualRegistryResetReopensSettledDeclarationsWithoutSourceReplacement(): void
    {
        [$placement, $executor] = $this->cluster();
        $this->attach();
        $this->guard();
        $expected = $this->expectedCluster();
        self::assertSame($expected, $executor->executed);
        self::assertFalse($this->runnable());
        $this->native(fn() => $placement->registry()->clear());
        self::assertTrue($this->runnable());
        $this->guard();
        self::assertSame([...$expected, ...$expected], $executor->executed);
        self::assertFalse($this->runnable());
        self::assertNull($this->retry($this->policy()));
        self::assertNull($this->retry($this->fleet()));
    }

    public function testRealExternalLinkPaysUntrackedDebtAndCancelsItsFutureRetry(): void
    {
        $this->attach();
        $this->guard();
        self::assertNotNull($this->retry($this->policy()));
        $worker = $this->server->addWorker(17);
        // Another existing sanctioned caller seats the same address before this owner's retry.
        $this->server->executePlacement('library', null);
        self::assertNull($this->retry($this->policy()));
        self::assertFalse($this->runnable());
        $this->native(fn() => $this->server->noteWorkerCapacityChanged());
        $this->guard();
        self::assertSame([['library', null], ['library', null]], $this->server->attempts);
        $this->assertStarts($worker, ['library']);
    }

    public function testActualLinkedWorkerZeroResolvesWithoutTimerOrAnotherDeclaration(): void
    {
        $worker = $this->server->addWorker(0);
        $this->attach();
        $this->guard();
        self::assertSame([['library', null]], $this->server->attempts);
        $this->assertStarts($worker, ['library']);
        self::assertSame(0, $this->server->placedWorkerId('library', null));
        self::assertNull($this->retry($this->policy()));
        $worker->report(new WorkerAgentStartedDTO('library', 'library'));
        $this->native(fn() => $this->server->noteWorkerCapacityChanged());
        $this->guard();
        self::assertSame([['library', null]], $this->server->attempts);
        $this->assertStarts($worker, ['library']);
        self::assertFalse($this->runnable());
    }

    public function testAcceptedNullDelegatesToRealWorkerRegistrationWithoutOwnRetry(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getStatus')->willReturn([Process::STATUS_RUNNING => true]);
        new ReflectionProperty(WorkerServer::class, 'workers')->setValue($this->server, ['regular:17' => [
            WorkerConstants::FIELD_WORKER_TYPE => WorkerConstants::TYPE_REGULAR,
            WorkerConstants::FIELD_WORKER_INDEX => 17,
            WorkerConstants::FIELD_WORKER_PROCESS => $process,
        ]]);
        $this->server->attachEventLoop($this->loop);
        $this->attach();
        $this->guard();
        self::assertTrue($this->server->isAgentAwaitingWorker('library'));
        self::assertNull($this->retry($this->policy()));
        self::assertFalse($this->runnable());
        $worker = $this->server->addWorker(17, false);
        $this->native(fn() => $worker->report(new WorkerRegisterDTO(17)));
        $this->guard();
        self::assertFalse($this->server->isAgentAwaitingWorker('library'));
        self::assertSame(17, $this->server->placedWorkerId('library', null));
        self::assertSame([['library', null]], $this->server->attempts);
        $frames = $worker->frames();
        self::assertCount(2, $frames);
        self::assertInstanceOf(WorkerRegisteredDTO::class, $frames[0]);
        self::assertInstanceOf(AgentStartDTO::class, $frames[1]);
        self::assertSame('library', $frames[1]->agentId);
        self::assertNull($this->retry($this->policy()));
    }

    public function testAcceptedNullForOldStopWaitsForRealStoppedReportWithoutOwnRetry(): void
    {
        $worker = $this->server->addWorker(17);
        $this->server->attachEventLoop($this->loop);
        $this->attach();
        $this->guard();
        $this->server->revokePlacement('library', null);
        $this->guard();
        self::assertTrue($this->server->isAgentAwaitingStopReport('library'));
        self::assertNull($this->retry($this->policy()));
        self::assertFalse($this->runnable());
        $this->native(fn() => $worker->report(new WorkerAgentStoppedDTO('library')));
        $this->guard();
        self::assertFalse($this->server->isAgentAwaitingStopReport('library'));
        self::assertSame(17, $this->server->placedWorkerId('library', null));
        self::assertSame([['library', null], ['library', null]], $this->server->attempts);
        $frames = $worker->frames();
        self::assertCount(3, $frames);
        self::assertInstanceOf(AgentStartDTO::class, $frames[0]);
        self::assertInstanceOf(AgentStopDTO::class, $frames[1]);
        self::assertInstanceOf(AgentStartDTO::class, $frames[2]);
        self::assertSame('library', $frames[2]->agentId);
    }

    public function testAbsentSourceSleepsUntilLateBindingAndExitCancelsItsDebt(): void
    {
        $this->set('servers', []);
        $this->attach();
        $this->guard();
        self::assertFalse($this->runnable());
        self::assertNull($this->retry($this->policy()));
        self::assertSame([], $this->server->attempts);
        $worker = $this->server->addWorker(17);
        $this->manager->registerServer($this->server);
        $this->guard();
        $this->assertStarts($worker, ['library']);
        $this->manager->getAgentManagerDaemon()->removeAgent('library');
        $this->manager->onAgentStopped('library');
        self::assertTrue($this->runnable());
        $this->set('shouldExit', true);
        $this->invoke('reconcileDeclarationSources');
        $this->invoke('ensurePolicyAgentsPlaced');
        self::assertFalse($this->runnable());
        self::assertSame([['library', null]], $this->server->attempts);
    }

    public function testRealRefusalKeepsOriginalFutureRetryAcrossReadableIoAndSameBinding(): void
    {
        $this->attach();
        $this->guard();
        $duty = $this->policy();
        $timer = $this->retry($duty);
        self::assertNotNull($timer);
        $deadline = $timer->getDeadline();
        self::assertNotNull($deadline);
        self::assertFalse($this->runnable());
        $this->native(static function (): void {});
        $this->attach();
        $this->guard();
        self::assertSame($timer, $this->retry($duty));
        self::assertSame($deadline, $timer->getDeadline());
        self::assertSame([['library', null]], $this->server->attempts);
        self::assertFalse($this->runnable());
    }

    public function testDeclaredPeerStartReportKeepsOtherAddressFutureRetry(): void
    {
        $worker = $this->refusingPair();
        // C is linked before the duty is eligible; only its real start report is late.
        $this->server->executePlacement('library', null);
        $this->attach();
        $this->guard();
        $duty = $this->policy();
        $timer = $this->retry($duty);
        self::assertNotNull($timer);
        $deadline = $timer->getDeadline();
        self::assertNotNull($deadline);
        self::assertSame($deadline, new ReflectionProperty(AgentDeclarationDuty::class, 'retryAt')->getValue($duty));
        self::assertGreaterThan(hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND, $deadline);
        self::assertFalse($duty->hasReadyWork());
        self::assertSame(17, $this->server->placedWorkerId('library', null));
        self::assertFalse($this->manager->getAgentManagerDaemon()->isAgentStarted('library'));
        self::assertFalse($this->manager->getAgentManagerDaemon()->hasAgent('refusing_library'));
        $this->assertStarts($worker, ['library']);
        self::assertSame([['library', null], ['refusing_library', null]], $this->server->attempts);

        $this->native(fn() => $worker->report(new WorkerAgentStartedDTO('library', 'library')));
        self::assertTrue($this->manager->getAgentManagerDaemon()->isAgentStarted('library'));
        self::assertFalse($duty->hasReadyWork(), 'already-declared C cannot release the refused A');
        $this->guard();
        $this->native(static function (): void {});
        $this->attach();
        $this->guard();
        self::assertSame([['library', null], ['refusing_library', null]], $this->server->attempts);
        self::assertSame($timer, $this->retry($duty));
        self::assertSame($deadline, $timer->getDeadline());
        self::assertSame($deadline, new ReflectionProperty(AgentDeclarationDuty::class, 'retryAt')->getValue($duty));
        self::assertFalse($this->runnable());
        $this->assertStarts($worker, ['library']);

        // Existing due-now seam: no production five-second wait or clock replacement.
        new ReflectionProperty(AgentDeclarationDuty::class, 'retryAt')->setValue($duty, 0.0);
        $timer->armAt(0.0);
        $this->loop->runOnce();
        self::assertTrue($duty->hasReadyWork());
        self::assertSame([['library', null], ['refusing_library', null]], $this->server->attempts);
        $this->guard();
        self::assertSame([['library', null], ['refusing_library', null], ['refusing_library', null]], $this->server->attempts);
        self::assertFalse($duty->hasReadyWork());
        self::assertNotNull($this->retry($duty)?->getDeadline());
        $this->guard();
        self::assertCount(3, $this->server->attempts);
        $this->assertStarts($worker, ['library']);
    }

    public function testSynchronousDeclaredPeerHoldDoesNotEscapeLaterRefusal(): void
    {
        $worker = $this->refusingPair();
        $this->attach();
        $this->guard();
        $duty = $this->policy();
        $timer = $this->retry($duty);
        self::assertNotNull($timer);
        $deadline = $timer->getDeadline();
        self::assertNotNull($deadline);
        self::assertSame(17, $this->server->placedWorkerId('library', null));
        self::assertFalse($this->manager->getAgentManagerDaemon()->hasAgent('refusing_library'));
        self::assertSame([['library', null], ['refusing_library', null]], $this->server->attempts);
        $this->assertStarts($worker, ['library']);
        self::assertFalse($duty->hasReadyWork(), 'the real synchronous C hold cue preceded the later A exception');
        $this->guard();
        $this->native(static function (): void {});
        $this->guard();
        self::assertSame([['library', null], ['refusing_library', null]], $this->server->attempts);
        self::assertSame($timer, $this->retry($duty));
        self::assertSame($deadline, $timer->getDeadline());
        self::assertSame($deadline, new ReflectionProperty(AgentDeclarationDuty::class, 'retryAt')->getValue($duty));
        self::assertFalse($this->runnable());
        $this->assertStarts($worker, ['library']);

        // A real registration is an applicable capacity fact and may release A early.
        $this->manager->getAgentManagerDaemon()->failingType = null;
        $registered = $this->server->addWorker(18, false);
        $this->native(fn() => $registered->report(new WorkerRegisterDTO(18)));
        self::assertTrue($duty->hasReadyWork());
        self::assertCount(2, $this->server->attempts);
        $this->guard();
        self::assertSame([['library', null], ['refusing_library', null], ['refusing_library', null]], $this->server->attempts);
        self::assertSame(18, $this->server->placedWorkerId('refusing_library', null));
        self::assertNull($this->retry($duty));
        self::assertFalse($this->runnable());
        $this->assertStarts($worker, ['library']);
        $frames = $registered->frames();
        self::assertCount(2, $frames);
        self::assertInstanceOf(WorkerRegisteredDTO::class, $frames[0]);
        self::assertInstanceOf(AgentStartDTO::class, $frames[1]);
        self::assertSame('refusing_library', $frames[1]->agentId);
    }

    public function testOwnAddressLinkPaysRefusalWhileDeclaredPeerRemainsUnchanged(): void
    {
        $worker = $this->refusingPair();
        $this->server->executePlacement('library', null);
        $this->attach();
        $this->guard();
        $duty = $this->policy();
        self::assertNotNull($this->retry($duty)?->getDeadline());
        $this->manager->getAgentManagerDaemon()->failingType = null;
        $this->server->executePlacement('refusing_library', null);
        self::assertNull($this->retry($duty));
        self::assertFalse($duty->hasReadyWork());
        $this->guard();
        self::assertSame([['library', null], ['refusing_library', null], ['refusing_library', null]], $this->server->attempts);
        self::assertSame(17, $this->server->placedWorkerId('refusing_library', null));
        $this->assertStarts($worker, ['library', 'refusing_library']);
    }

    public function testAgedActualRefusalArmsNativeDueButBusinessWaitsForGuard(): void
    {
        $this->guard();
        $duty = $this->policy();
        $deadline = new ReflectionProperty(AgentDeclarationDuty::class, 'retryAt')->getValue($duty);
        self::assertIsFloat($deadline);
        new ReflectionProperty(AgentDeclarationDuty::class, 'retryAt')->setValue($duty, $deadline - 6.0);
        $this->attach();
        $this->loop->runOnce();
        self::assertSame([['library', null]], $this->server->attempts);
        self::assertTrue($this->runnable());
        $this->guard();
        self::assertSame([['library', null], ['library', null]], $this->server->attempts);
        self::assertFalse($this->runnable());
        self::assertGreaterThan($deadline - 6.0, $this->retry($duty)->getDeadline());
    }

    public function testActualRegistrationWithEmptyWaitQueueReleasesUntrackedRefusalPromptly(): void
    {
        $this->attach();
        $this->guard();
        self::assertFalse($this->server->isAgentAwaitingWorker('library'));
        $worker = $this->server->addWorker(17, false);
        $this->native(fn() => $worker->report(new WorkerRegisterDTO(17)));
        self::assertTrue($this->runnable(), 'the capacity cue precedes the empty-wait early return');
        self::assertCount(1, $this->server->attempts);
        $this->guard();
        self::assertSame([['library', null], ['library', null]], $this->server->attempts);
        self::assertNull($this->retry($this->policy())?->getDeadline());
        $frames = $worker->frames();
        self::assertCount(2, $frames);
        self::assertInstanceOf(WorkerRegisteredDTO::class, $frames[0]);
        self::assertInstanceOf(AgentStartDTO::class, $frames[1]);
        self::assertSame('library', $frames[1]->agentId);
    }

    public function testStartFailureAfterPolicyPhaseLeavesNextPassDebtWithEmptyNativeQueue(): void
    {
        $worker = $this->server->addWorker(17);
        $this->attach();
        $this->guard();
        $worker->report(new WorkerAgentStartFailedDTO('library', 'library', reason: 'actual failure'));
        self::assertFalse($this->loop->hasReadyWork());
        self::assertTrue($this->runnable());
        $this->guard();
        self::assertSame([['library', null], ['library', null]], $this->server->attempts);
        $this->assertStarts($worker, ['library', 'library']);
    }

    public function testSourceReplacementAndTerminalDetachCancelActivatedOldRetry(): void
    {
        $this->guard();
        $oldDuty = $this->policy();
        new ReflectionProperty(AgentDeclarationDuty::class, 'retryAt')->setValue($oldDuty, 0.0);
        $this->attach();
        $this->loop->runOnce();
        self::assertTrue($this->runnable());
        $oldServer = $this->server;
        $this->set('servers', []);
        $this->server = $this->buildServer();
        $worker = $this->server->addWorker(0);
        $this->manager->registerServer($this->server);
        $this->guard();
        self::assertSame([['library', null]], $oldServer->attempts);
        self::assertSame([['library', null]], $this->server->attempts);
        $this->assertStarts($worker, ['library']);
        $this->invoke('detachDeclarationEventLoop');
        $this->manager->getAgentManagerDaemon()->removeAgent('library');
        $this->server->noteWorkerCapacityChanged();
        $this->guard();
        self::assertFalse($this->runnable());
        self::assertSame([['library', null]], $this->server->attempts);
    }

    public function testLostRoleBeforeCoordinatorSwapCannotRunAnActivatedRetry(): void
    {
        $mesh = $this->createMock(ConsensusMesh::class);
        $mesh->method('onlineMasterIds')->willReturn(['leader', 'peer', 'third']);
        $coordinator = new ClusterCoordinator(
            new ClusterConsensusConfig('leader', ['leader', 'peer', 'third'], 2, 1000, 1000, 100), $mesh, $this->manager,
        );
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership($coordinator);
        $coordinator->tick(0.0);
        $coordinator->tick(1.0);
        $coordinator->onVoteReply(new PeerVoteReplyDTO(1, true, 'peer'));
        self::assertTrue($coordinator->amLeader());
        $this->guard();
        new ReflectionProperty(AgentDeclarationDuty::class, 'retryAt')->setValue($this->policy(), 0.0);
        $this->attach();
        $this->loop->runOnce();
        $this->native(fn() => $coordinator->onRequestVote(new PeerRequestVoteDTO(2, 'peer')));
        self::assertTrue($this->manager->leaderDuringLoss);
        $this->guard();
        self::assertFalse($coordinator->amLeader());
        self::assertSame([['library', null]], $this->server->attempts);
        self::assertFalse($this->runnable());
    }

    public function testClosedNativeBaseIsNotContainedAsBusinessPlacementRefusal(): void
    {
        $this->attach();
        $timer = $this->loop->createTimer(static function (): void {});
        $timer->close();
        new ReflectionProperty(AgentDeclarationDuty::class, 'retryTimer')->setValue($this->policy(), $timer);
        $this->expectException(EventLoopException::class);
        $this->invoke('ensurePolicyAgentsPlaced');
    }

    public function testDutyWeakOriginsDoNotRetainRetiredSourceGraph(): void
    {
        $server = new ReflectionClass(DeclarationNativeServer::class)->newInstanceWithoutConstructor();
        $duty = new AgentDeclarationDuty(5.0);
        $duty->reconcile($server, true);
        $weak = WeakReference::create($server);
        unset($server);
        self::assertNull($weak->get());
        $duty->detachEventLoop();
        self::assertFalse($duty->hasReadyWork());
    }

    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        $this->previousEnv = Hilos::$env;
        $this->previousRuntime = Hilos::$rt;
        $this->previousApp = Hilos::appClass();
        $this->previousEnabled = getenv(EnvConstants::CLUSTER_ENABLED->name);
        $this->previousAppEnv = getenv('APP_ENV');
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=false');
        putenv('APP_ENV=test');
        Hilos::$cluster = null;
        Hilos::$rt = null;
        Hilos::$env = new EnvAccessor();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, DeclarationNativeHilos::class);
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-declaration-native-');
        Logger::setLogFile($this->logFile);
        $this->loop = new EventLoop();
        $this->manager = new DeclarationNativeManager();
        $this->set('eventLoop', $this->loop);
        $this->set('workersReady', true);
        $this->server = $this->buildServer();
        $this->manager->registerServer($this->server);
    }

    protected function tearDown(): void
    {
        $this->invoke('detachDeclarationEventLoop');
        $this->server->detachEventLoop();
        Hilos::$cluster?->placement()?->detachEventLoop();
        $this->invoke('detachRoleTickEventLoop');
        $this->invoke('detachCronEventLoop');
        $this->invoke('detachReadinessEventLoop');
        $this->invoke('discardSignalDispatch');
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
        putenv('APP_ENV' . ($this->previousAppEnv === false ? '' : '=' . $this->previousAppEnv));
    }

    /** @return DeclarationNativeServer In-memory links retain real worker selection, writes and receipts */
    private function buildServer(): DeclarationNativeServer
    {
        $server = new ReflectionClass(DeclarationNativeServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(AbstractServer::class, 'isRunning')->setValue($server, true);
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $this->manager->getAgentManagerDaemon());
        foreach (['minRegular', 'maxRegular', 'minMonopolistic'] as $field) {
            new ReflectionProperty(WorkerServer::class, $field)->setValue($server, 0);
        }
        new ReflectionProperty(WorkerServer::class, 'cachedLogDirectory')->setValue($server, sys_get_temp_dir());
        new ReflectionProperty(WorkerServer::class, 'initialWorkersReadyCalled')->setValue($server, true);
        return $server;
    }

    /** @return StatusBootstrapTestWorkerClient Two policy addresses share the real worker and factory */
    private function refusingPair(): StatusBootstrapTestWorkerClient
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, DeclarationQualifiedHilos::class);
        $this->manager->getAgentManagerDaemon()->failingType = 'refusing_library';
        return $this->server->addWorker(17);
    }

    /** @param list<string> $online Actual mesh membership for the real placement owner
     * @param bool $remote Whether only the remote peer declares capacity
     * @return array{ClusterPlacement, FakePlacementExecutor, FakePlacementMesh} Native owner and complete outbound ports
     */
    private function cluster(array $online = ['leader'], bool $remote = false): array
    {
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=true');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership(new StandaloneLeadership());
        $executor = new FakePlacementExecutor();
        $mesh = new FakePlacementMesh($remote ? ['peer' => ['slots=20']] : ['leader' => ['slots=20']],
            linked: $remote ? ['peer'] : [], online: $online);
        $placement = new ClusterPlacement('leader', $mesh, $executor);
        Hilos::$cluster->registerPlacement($placement);
        $placement->attachEventLoop($this->loop);
        return [$placement, $executor, $mesh];
    }

    /** @return list<array{string, ?string}> Literal policy address followed by exactly the framework fleet */
    private function expectedCluster(): array
    {
        return [['library', null], [HilosAgentType::HILOS_PROBE_FLEET, '0'], [HilosAgentType::HILOS_PROBE_FLEET, '1'],
            [HilosAgentType::HILOS_PROBE_FLEET, '2'], [HilosAgentType::HILOS_PROBE_FLEET, '3'],
            [HilosAgentType::HILOS_PROBE_FLEET, '4'], [HilosAgentType::HILOS_PROBE_FLEET, '5'],
            [HilosAgentType::HILOS_PROBE_FLEET, '6'], [HilosAgentType::HILOS_PROBE_FLEET, '7'],
            [HilosAgentType::HILOS_PROBE_FLEET, '8'], [HilosAgentType::HILOS_PROBE_FLEET, '9']];
    }

    private function attach(): void
    {
        $this->invoke('attachDeclarationEventLoop');
        $this->invoke('reconcileDeclarationSources');
    }

    private function guard(): void
    {
        $this->invoke('runGuardedIteration', microtime(true));
        self::assertFalse(new ReflectionProperty(DaemonManager::class, 'shouldExit')->getValue($this->manager),
            file_get_contents($this->logFile));
    }

    /** @param Closure $mutation Finite receipt or source fact; no business declaration in the native callback */
    private function native(Closure $mutation): void
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        array_push($this->sockets, ...$pair);
        $read = false;
        $this->loop->registerRead($pair[0], static function () use ($pair, $mutation, &$read): void {
            $read = socket_read($pair[0], 1) === 'x';
            $mutation();
        });
        socket_write($pair[1], 'x');
        $this->loop->runOnce();
        self::assertTrue($read);
    }

    /** @return AgentDeclarationDuty Actual policy owner */
    private function policy(): AgentDeclarationDuty
    {
        return new ReflectionProperty(DaemonManager::class, 'policyDeclarations')->getValue($this->manager);
    }

    /** @return AgentDeclarationDuty Actual probe owner */
    private function fleet(): AgentDeclarationDuty
    {
        $supervisor = new ReflectionProperty(DaemonManager::class, 'probeFleetSupervisor')->getValue($this->manager);
        return new ReflectionProperty(ProbeFleetSupervisor::class, 'declarations')->getValue($supervisor);
    }

    /** @param AgentDeclarationDuty $duty Actual declaration owner
     * @return ?EventLoopTimer Actual retry handle; no timer is created for accepted waits
     */
    private function retry(AgentDeclarationDuty $duty): ?EventLoopTimer
    {
        return new ReflectionProperty(AgentDeclarationDuty::class, 'retryTimer')->getValue($duty);
    }

    /** @return bool Current synchronous debt, including after native queues empty */
    private function runnable(): bool
    {
        return $this->invoke('hasRunnableDeclarations');
    }

    /** @param StatusBootstrapTestWorkerClient $worker Real serialized downstream link
     * @param list<string> $ids Exact expected complete frame sequence, with no filtering
     */
    private function assertStarts(StatusBootstrapTestWorkerClient $worker, array $ids): void
    {
        $frames = $worker->frames();
        self::assertCount(count($ids), $frames);
        foreach ($frames as $index => $frame) {
            self::assertInstanceOf(AgentStartDTO::class, $frame);
            self::assertSame($ids[$index], $frame->agentId);
        }
    }

    /** @param string $property Existing manager binding
     * @param mixed $value Fixture value
     */
    private function set(string $property, mixed $value): void
    {
        new ReflectionProperty(DaemonManager::class, $property)->setValue($this->manager, $value);
    }

    /** @param string $method Actual owner or guarded phase
     * @param mixed ...$args Phase inputs
     * @return mixed Phase result
     */
    private function invoke(string $method, mixed ...$args): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($this->manager, ...$args);
    }
}

/** Uses the existing real agent roster and records the coordinator's pre-swap observation. */
final class DeclarationNativeManager extends DaemonManager
{
    public ?bool $leaderDuringLoss = null;

    /** @param int $term Actual lost term */
    public function onLostLeadership(int $term): void
    {
        $this->leaderDuringLoss = Hilos::$cluster?->amLeader();
        parent::onLostLeadership($term);
    }

    /** @return SignalRouter Production router */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Real daemon roster with the existing regular-worker factory */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new StatusBootstrapTestAgentManager();
    }
}

/** Socket-free worker buffers; the guard still drives actual native placement before declarations. */
final class DeclarationNativeServer extends WorkerServer
{
    /** @var list<array{string, ?string}> Every actual local declaration entry */
    public array $attempts = [];

    /** @param string $agentType Real placement type
     * @param ?string $agentIndex Real placement index
     * @return ?int Production linked worker or accepted wait
     */
    public function executePlacement(string $agentType, ?string $agentIndex): ?int
    {
        $this->attempts[] = [$agentType, $agentIndex];
        return parent::executePlacement($agentType, $agentIndex);
    }

    /** @param int $index Worker identity, including zero
     * @param bool $registered Whether this link has already completed registration
     * @return StatusBootstrapTestWorkerClient Existing real buffer and receipt adapter
     */
    public function addWorker(int $index, bool $registered = true): StatusBootstrapTestWorkerClient
    {
        $client = new ReflectionClass(StatusBootstrapTestWorkerClient::class)->newInstanceWithoutConstructor();
        $client->setWorkerIndex($index);
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($client, $registered);
        new ReflectionProperty(WorkerClient::class, 'agentManager')->setValue(
            $client, new ReflectionProperty(WorkerServer::class, 'agentManager')->getValue($this),
        );
        new ReflectionProperty(WorkerClient::class, 'workerServer')->setValue($client, $this);
        $this->addClient($client);
        return $client;
    }

    /** Leaves socket-free buffers intact; real placement effects stay inside the manager guard. */
    public function onTick(): void
    {
        Hilos::$cluster?->placement()?->dispatchReady();
    }

    /** Fixture transport is already running and starts no OS worker. */
    protected function onStart(): void
    {
    }
}

abstract class DeclarationNativeHilos extends Hilos
{
    public const array AGENTS = [
        'library' => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
        HilosAgentType::HILOS_PROBE_FLEET => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_FLEET],
    ];
}

abstract class DeclarationQualifiedHilos extends Hilos
{
    public const array AGENTS = [
        'library' => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
        'refusing_library' => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
    ];
}
