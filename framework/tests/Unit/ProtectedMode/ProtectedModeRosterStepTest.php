<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\State\Item\TableLagRuntime;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeCircleSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeRefreezeSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeVerifySignalData;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\ProtectedMode\ProtectedModeSourceBinding;
use Hilos\Socket\Server\WorkerServer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use ReflectionMethod;
use Closure;
use WeakReference;

/**
 * Unit tests for the protected-mode roster walk taken one agent per master pass (HIL-1012).
 *
 * The freeze used to stop the whole roster inside one call from the master's loop, and the lift
 * used to bring it back the same way, so every client of the node stood behind a walk as long as
 * the roster. What is pinned here is the native step: a request only queues, each portion moves one
 * agent, and the switch hears the end of a walk once and only on the pass that empties it. Two
 * requests can overlap now that a walk takes time, and the case that could lose an agent for good
 * - a stop over an unfinished lift - is named by the agent it would lose.
 *
 * Built like the freeze gate's own test: the server skips its constructor, and a stop reaches no
 * worker, so the roster keeps its records and what the walk did is read off the remembered set.
 */
final class ProtectedModeRosterStepTest extends TestCase
{
    private const string INITIATOR_TYPE = 'restorer';

    /** @var list<string> Roster the node carries into the freeze */
    private const array ROSTER = ['chat', 'poll', 'todo', 'presence', 'search'];

    private RosterStepTestAgentManagerDaemon $manager;

    private RosterStepTestWorkerServer $server;

    private RosterStepTestSwitch $switch;

    private EventLoop $loop;

    protected function setUp(): void
    {
        Hilos::$rt = null;
        Hilos::$sr = new SignalRouter();
        $this->loop = new EventLoop();
        $this->switch = new RosterStepTestSwitch();
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerProtectedMode($this->switch);

        $this->manager = new RosterStepTestAgentManagerDaemon();
        // Skip the constructor: it reads worker env and creates a log directory that the walk
        // does not touch. Only the agent manager is needed.
        $this->server = new ReflectionClass(RosterStepTestWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this->server, $this->manager);
        $this->server->switch = $this->switch;
        $this->server->loop = $this->loop;
        // Bind the production roster/source duties without unrelated worker process machinery.
        new ReflectionMethod(WorkerServer::class, 'attachProtectedModeEventLoop')->invoke($this->server, $this->loop);
    }

    protected function tearDown(): void
    {
        new ReflectionMethod(WorkerServer::class, 'detachProtectedModeEventLoop')->invoke($this->server);
        $this->loop->close();
        RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        Hilos::$cluster = null;
        Hilos::$rt = null;
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testEachPassStopsExactlyOneAgent(): void
    {
        $this->seedRoster(self::ROSTER);

        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->assertSame([], $this->server->getProtectedModeStoppedAgents(), 'The request only queues.');

        $this->server->rosterStepPublic();
        $this->assertSame(['chat'], $this->server->getProtectedModeStoppedAgents());

        $this->steps(4);
        $this->assertSame(self::ROSTER, $this->server->getProtectedModeStoppedAgents());
    }

    public function testTheSwitchHearsTheStoppedRosterOnceAndOnlyOnTheLastPass(): void
    {
        $this->seedRoster(self::ROSTER);
        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);

        $this->steps(4);
        $this->assertSame([], $this->switch->heard, 'Four agents of five stopped is not a frozen node.');

        $this->server->rosterStepPublic();
        $this->assertSame(['stopped'], $this->switch->heard);

        $this->steps(3);
        $this->assertSame(['stopped'], $this->switch->heard);
    }

    public function testTheLiftBringsOneAgentBackPerPassAndFinishesOnce(): void
    {
        $this->freezeWholeRoster();

        $this->server->resumeAgentsForProtectedMode();
        $this->assertSame([], $this->manager->askedFor, 'The request only queues.');

        $this->steps(4);
        $this->assertSame(['chat', 'poll', 'todo', 'presence'], $this->manager->askedFor);
        $this->assertSame([], $this->switch->heard);

        $this->server->rosterStepPublic();
        $this->assertSame(self::ROSTER, $this->manager->askedFor);
        // The application's own hook runs after the replay and before the switch finishes the lift.
        $this->assertSame(['lifted', 'resumed'], $this->switch->heard);

        $this->steps(3);
        $this->assertSame(['lifted', 'resumed'], $this->switch->heard);
    }

    /** Every attempted start here has no suitable worker, so no future AgentStarted report can wake its holds. */
    public function testResumeProgressAndCompletionCueHoldsEvenWhenEveryStartIsRefused(): void
    {
        $this->freezeWholeRoster();
        $facts = [];
        $wake = static function (?string $agentId) use (&$facts): void {
            $facts[] = $agentId;
        };
        $this->server->setAgentHoldWake($wake);
        $this->server->resumeAgentsForProtectedMode();
        $this->steps(count(self::ROSTER));
        self::assertSame([...self::ROSTER, null], $facts);
        $this->steps(2);
        self::assertSame([...self::ROSTER, null], $facts);
        $replacementFacts = [];
        $this->server->setAgentHoldWake(static function (?string $agentId) use (&$replacementFacts): void {
            $replacementFacts[] = $agentId;
        });
        $this->server->removeAgentHoldWake($wake);
        $this->server->resumeAgentsForProtectedMode();
        $this->steps(1);
        self::assertSame([null], $replacementFacts, 'A departed binding must not remove its replacement');
    }

    public function testTheInitiatorAndTheMailPoolAreNeverStopped(): void
    {
        $this->seedRoster(['chat', self::INITIATOR_TYPE, HilosAgentType::HILOS_MAIL, HilosAgentType::HILOS_STATUS, 'poll']);

        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->steps(2);

        $this->assertSame(['chat', 'poll'], $this->server->getProtectedModeStoppedAgents());
        // Two passes close the walk: preserved entries do not cost a stop effect of their own.
        $this->assertSame(['stopped'], $this->switch->heard);
    }

    public function testAStopOverAnUnfinishedLiftKeepsTheAgentsTheLiftHadNotAskedFor(): void
    {
        $this->freezeWholeRoster();
        $this->server->resumeAgentsForProtectedMode();
        $this->steps(2);

        // Closing the verification window back re-freezes the node, and nothing holds it until the
        // lift is done. Only chat and poll were asked for; todo, presence and search are on no
        // roster yet, so the snapshot cannot see them.
        $this->manager->forgetRoster();
        $this->seedRoster(['chat', 'poll']);
        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->steps(2);

        $this->assertSame(
            ['todo', 'presence', 'search', 'chat', 'poll'],
            $this->server->getProtectedModeStoppedAgents(),
            'Without the carry-over the next lift would never ask for todo, presence or search again.',
        );
        $this->assertSame(['stopped'], $this->switch->heard, 'The overtaken lift is never finished.');
    }

    public function testALiftOverAnUnfinishedStopDropsWhatTheStopHadNotReached(): void
    {
        $this->seedRoster(self::ROSTER);
        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->steps(2);

        $this->manager->forgetRoster();
        $this->server->resumeAgentsForProtectedMode();
        $this->steps(5);

        // todo, presence and search were never stopped, so they are still running and nothing
        // about them was remembered.
        $this->assertSame(['chat', 'poll'], $this->manager->askedFor);
        $this->assertSame(['lifted', 'resumed'], $this->switch->heard, 'The overtaken stop is never finished.');
    }

    public function testALiftOverAnUnfinishedLiftKeepsWhatTheFirstHadNotAskedFor(): void
    {
        // The window opened and the mode lifted before the window's replay was done.
        $this->freezeWholeRoster();
        $this->server->resumeAgentsForProtectedMode();
        $this->steps(2);

        $this->server->resumeAgentsForProtectedMode();
        $this->steps(3);

        $this->assertSame(self::ROSTER, $this->manager->askedFor);
        $this->assertSame(['lifted', 'resumed'], $this->switch->heard);
    }

    public function testAWalkWithNothingToMoveStillFinishes(): void
    {
        // A node running nothing but the initiator is still frozen, and still owed a lift frame.
        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->server->rosterStepPublic();
        $this->assertSame(['stopped'], $this->switch->heard);

        $this->server->resumeAgentsForProtectedMode();
        $this->server->rosterStepPublic();
        $this->assertSame(['stopped', 'lifted', 'resumed'], $this->switch->heard);
    }

    public function testNativePreparationIsFiniteAndContinuesWithoutInput(): void
    {
        $original = array_map(static fn(int $id): string => 'agent-' . $id, range(0, 129));
        $this->seedRoster($original);
        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->server->rosterStepPublic();
        self::assertSame([], $this->server->getProtectedModeStoppedAgents(), 'Preparation spends its own bounded prefix');
        self::assertTrue($this->loop->hasReadyWork());
        $this->manager->registerUnlinked('late-arrival');
        $this->pump();
        self::assertSame($original, $this->server->getProtectedModeStoppedAgents());
        self::assertSame(['stopped'], $this->switch->heard);
        $checks = $this->server->diagnosticChecks;
        $this->steps(3);
        self::assertSame($checks, $this->server->diagnosticChecks);
        self::assertFalse($this->loop->hasReadyWork(), 'Held agents alone are not runnable');
    }

    public function testOriginalCohortDoesNotStopARecreatedAgentWithTheSameId(): void
    {
        $this->seedRoster(['chat', 'poll']);
        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->manager->removeAgent('chat');
        $this->manager->registerUnlinked('chat');
        $this->pump();
        self::assertSame(['poll'], $this->server->getProtectedModeStoppedAgents());
        self::assertSame(['stopped'], $this->switch->heard);
    }

    public function testRebindKeepsTheTailAndOldTicketsCannotReplayIt(): void
    {
        $this->freezeWholeRoster();
        $this->server->resumeAgentsForProtectedMode();
        $this->steps(2);
        $oldWork = new ReflectionProperty(WorkerServer::class, 'protectedModeRosterWork')->getValue($this->server);
        new ReflectionMethod(WorkerServer::class, 'detachProtectedModeEventLoop')->invoke($this->server);
        $oldWork->run();
        self::assertSame(['chat', 'poll'], $this->manager->askedFor);
        new ReflectionMethod(WorkerServer::class, 'attachProtectedModeEventLoop')->invoke($this->server, $this->loop);
        $oldWork->markReady();
        $this->pump();
        self::assertSame(self::ROSTER, $this->manager->askedFor);
        self::assertSame(['lifted', 'resumed'], $this->switch->heard);
    }

    public function testAttemptedResumeCanBeOvertakenInsideItsHoldCue(): void
    {
        $this->freezeWholeRoster();
        $this->server->resumeAgentsForProtectedMode();
        $this->server->setAgentHoldWake(function (?string $agentId): void {
            if ($agentId !== null) {
                $this->server->setAgentHoldWake(null);
                $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
            }
        });
        $this->pump();
        self::assertSame(['chat'], $this->manager->askedFor);
        self::assertSame(['poll', 'todo', 'presence', 'search'], $this->server->getProtectedModeStoppedAgents());
        self::assertSame(['stopped'], $this->switch->heard, 'Superseded resume owes no completion');
    }

    public function testCompletionConsumesOldEpochBeforeReentrantLiftHook(): void
    {
        $this->server->afterLift = function (): void {
            $this->server->afterLift = null;
            $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        };
        $this->server->resumeAgentsForProtectedMode();
        $this->pump();
        self::assertSame(['lifted', 'stopped'], $this->switch->heard);
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testCompletionNullCueCanStartANewLiftWithoutBeingCanceled(): void
    {
        $facts = [];
        $this->server->setAgentHoldWake(function (?string $id) use (&$facts): void {
            $facts[] = $id;
            if (count($facts) === 1) {
                $this->server->resumeAgentsForProtectedMode();
            }
        });
        $this->server->resumeAgentsForProtectedMode();
        $this->pump();
        self::assertSame([null, null], $facts);
        self::assertSame(['lifted', 'resumed'], $this->switch->heard);
    }

    public function testStoppedCompletionCanImmediatelyRequestAnEmptyLift(): void
    {
        $this->switch->afterStop = function (): void {
            $this->switch->afterStop = null;
            $this->server->resumeAgentsForProtectedMode();
        };
        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->pump();
        self::assertSame(['stopped', 'lifted', 'resumed'], $this->switch->heard);
    }

    public function testOccupiedSourceBeforeAttachAndNodeBasisChangeArePublished(): void
    {
        new ReflectionMethod(WorkerServer::class, 'detachProtectedModeEventLoop')->invoke($this->server);
        $this->mountSource();
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterActive();
        self::assertNull(Hilos::$rt->hilosProtectedModeRuntime->agentStartGateClosed);
        new ReflectionMethod(WorkerServer::class, 'attachProtectedModeEventLoop')->invoke($this->server, $this->loop);
        $this->pump();
        self::assertTrue(Hilos::$rt->hilosProtectedModeRuntime->agentStartGateClosed);
        $previousEnv = Hilos::$env;
        $previousId = getenv(EnvConstants::CLUSTER_NODE_ID->name);
        $previousEnabled = getenv(EnvConstants::CLUSTER_ENABLED->name);
        try {
            putenv(EnvConstants::CLUSTER_ENABLED->name . '=0');
            putenv(EnvConstants::CLUSTER_NODE_ID->name . '=diagnostics-node');
            Hilos::$env = new EnvAccessor();
            Hilos::$cluster = new ClusterContext();
            $this->server->reconcileProtectedModeSource();
            $this->pump();
            self::assertSame('diagnostics-node', Hilos::$rt->hilosProtectedModeRuntime->diagnosticsNodeId);
        } finally {
            putenv(EnvConstants::CLUSTER_NODE_ID->name . ($previousId === false ? '' : '=' . $previousId));
            putenv(EnvConstants::CLUSTER_ENABLED->name . ($previousEnabled === false ? '' : '=' . $previousEnabled));
            Hilos::$env = $previousEnv;
            Hilos::$cluster = null;
        }
    }

    public function testNativeSourceAndWorkClosuresDoNotRetainADepartedServer(): void
    {
        $source = $this->mountSource();
        $this->pump();
        $server = new ReflectionClass(RosterStepTestWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $this->manager);
        new ReflectionMethod(WorkerServer::class, 'attachProtectedModeEventLoop')->invoke($server, $this->loop);
        $weak = WeakReference::create($server);
        unset($server);
        self::assertNull($weak->get());
        $source->noteProtectedModeChanged();
        $this->pump();
    }

    public function testDirtyPublicationIsDeferredAndEqualityBecomesIdle(): void
    {
        $this->mountSource();
        $this->pump();
        self::assertFalse(Hilos::$rt->hilosProtectedModeRuntime->agentStartGateClosed);
        $before = $this->server->diagnosticChecks;
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterActive();
        self::assertFalse(Hilos::$rt->hilosProtectedModeRuntime->agentStartGateClosed, 'The source callback does no publication');
        $this->pump();
        self::assertTrue(Hilos::$rt->hilosProtectedModeRuntime->agentStartGateClosed);
        self::assertSame($before + 2, $this->server->diagnosticChecks, 'One self-sync check settles by equality');
        $checks = $this->server->diagnosticChecks;
        for ($pass = 0; $pass < 3; $pass++) {
            $this->server->onTick();
            $this->loop->tick();
        }
        self::assertSame($checks, $this->server->diagnosticChecks, 'Unrelated client wakes do not compute diagnostics');
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterVerifying();
        $this->pump();
        self::assertFalse(Hilos::$rt->hilosProtectedModeRuntime->agentStartGateClosed);
    }

    public function testFailedLocalSyncStillCuesTheGuardedConsumer(): void
    {
        $this->mountSource();
        $this->pump();
        Hilos::$sr = new RosterStepRefusingRouter();
        try {
            Hilos::$rt->hilosProtectedModeRuntime->actions->enterActive();
            self::fail('Sync must refuse after the row changed');
        } catch (InvalidArgumentException) {
        }
        self::assertTrue($this->loop->hasReadyWork());
        Hilos::$sr = new SignalRouter();
        $this->pump();
        self::assertTrue(Hilos::$rt->hilosProtectedModeRuntime->agentStartGateClosed);
    }

    public function testEchoAndUnrelatedStandaloneDiffDoNotCueDiagnostics(): void
    {
        $source = $this->mountSource();
        $source->mountFeatureItem(TableLagRuntime::RT_ITEM, TableLagRuntime::create());
        $this->pump();
        $checks = $this->server->diagnosticChecks;
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(StateProtectedModeRuntime::RT_ITEM,
            StateProtectedModeRuntime::ID, [StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVE]));
        self::assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $source->hilosProtectedModeRuntime->phase);
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(TableLagRuntime::RT_ITEM,
            TableLagRuntime::ID, [TableLagRuntime::windowMs => 1]), false);
        $this->pump();
        self::assertSame($checks, $this->server->diagnosticChecks);
    }

    public function testRestoreAndSameContextRemountRepublishClearedDiagnostics(): void
    {
        $this->mountSource();
        $this->pump();
        Hilos::$rt->hilosProtectedModeRuntime->actions->restoreFromDisk(StateProtectedModeRuntime::create());
        self::assertNull(Hilos::$rt->hilosProtectedModeRuntime->stoppedAgentCount);
        $this->pump();
        self::assertSame(0, Hilos::$rt->hilosProtectedModeRuntime->stoppedAgentCount);
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::create());
        self::assertNull(Hilos::$rt->hilosProtectedModeRuntime->diagnosticsNodeId);
        $this->pump();
        self::assertNotNull(Hilos::$rt->hilosProtectedModeRuntime->diagnosticsNodeId);
        self::assertSame(0, Hilos::$rt->hilosProtectedModeRuntime->stoppedAgentCount);
    }

    public function testContextReplacementMissingRowAndNullReconcileAtGuardedBoundary(): void
    {
        $old = $this->mountSource();
        $this->pump();
        $stale = new ReflectionProperty(ProtectedModeSourceBinding::class, 'wake')->getValue(
            new ReflectionProperty(WorkerServer::class, 'protectedModeSourceBinding')->getValue($this->server),
        );
        $replacement = new RosterStepTestContext();
        Hilos::$rt = $replacement;
        $this->server->reconcileProtectedModeSource();
        $this->pump();
        self::assertNull($replacement->hilosProtectedModeRuntime, 'An unmounted row remains missing');
        $stale();
        self::assertFalse($this->loop->hasReadyWork());
        $old->noteProtectedModeChanged();
        self::assertFalse($this->loop->hasReadyWork());
        Hilos::$rt = null;
        $this->server->reconcileProtectedModeSource();
        $this->pump();
        self::assertNull(Hilos::$rt);
        $weak = WeakReference::create($old);
        unset($old);
        self::assertNull($weak->get(), 'Neither the callback nor binding retains a departed context');
    }

    public function testExactRemovalKeepsReplacementAndDetachedCallbackIsStale(): void
    {
        $source = $this->mountSource();
        $this->pump();
        $old = new ReflectionProperty(ProtectedModeSourceBinding::class, 'wake')->getValue(
            new ReflectionProperty(WorkerServer::class, 'protectedModeSourceBinding')->getValue($this->server),
        );
        $facts = 0;
        $source->setProtectedModeWake(static function () use (&$facts): void { ++$facts; });
        new ReflectionMethod(WorkerServer::class, 'detachProtectedModeEventLoop')->invoke($this->server);
        $source->noteProtectedModeChanged();
        self::assertSame(1, $facts);
        $old();
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testPartialRemoteDiffAndSnapshotCueOnlyTheExactSingleton(): void
    {
        $this->mountSource();
        $this->pump();
        $before = $this->server->diagnosticChecks;
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
            StateProtectedModeRuntime::RT_ITEM, 'another-id', [StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVE],
        ), false);
        RtSnapshot::replace(StateProtectedModeRuntime::RT_ITEM, []);
        RtSnapshot::replace(StateProtectedModeRuntime::RT_ITEM, ['another-id' => []]);
        $this->pump();
        self::assertSame($before, $this->server->diagnosticChecks);
        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::ID,
            [StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVE, StateProtectedModeRuntime::startedAt => 'invalid'],
        ), false);
        $this->pump();
        self::assertTrue(Hilos::$rt->hilosProtectedModeRuntime->agentStartGateClosed);
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterVerifying();
        $this->pump();
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
            StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::ID,
            [StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVE, StateProtectedModeRuntime::startedAt => 'invalid'],
        ), false);
        $this->pump();
        self::assertTrue(Hilos::$rt->hilosProtectedModeRuntime->agentStartGateClosed);
        RtSnapshot::replaceScope(StateProtectedModeRuntime::RT_ITEM, [StateProtectedModeRuntime::ID], [StateProtectedModeRuntime::ID => [
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_VERIFYING, StateProtectedModeRuntime::startedAt => 'invalid',
        ]]);
        $this->pump();
        self::assertFalse(Hilos::$rt->hilosProtectedModeRuntime->agentStartGateClosed);
        RtSnapshot::replace(StateProtectedModeRuntime::RT_ITEM, [StateProtectedModeRuntime::ID => [
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVE,
        ]]);
        $this->pump();
        self::assertTrue(Hilos::$rt->hilosProtectedModeRuntime->agentStartGateClosed);
    }

    /** @return RosterStepTestContext Mounted source under the ordinary daemon write guard */
    private function mountSource(): RosterStepTestContext
    {
        Hilos::$cluster = null;
        Hilos::$rt = $source = new RosterStepTestContext();
        $source->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::create());
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        $this->server->reconcileProtectedModeSource();
        return $source;
    }

    /** Drains finite native continuations without input or elapsed-time waits. */
    private function pump(): void
    {
        for ($portion = 0; $portion < 1000 && $this->loop->hasReadyWork(); $portion++) {
            $this->loop->tick();
        }
        self::assertFalse($this->loop->hasReadyWork());
    }

    /**
     * Stops the whole seeded roster and forgets it, leaving only the remembered set to lift.
     */
    private function freezeWholeRoster(): void
    {
        $this->seedRoster(self::ROSTER);
        $this->server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
        $this->steps(count(self::ROSTER));
        $this->manager->forgetRoster();
        $this->switch->heard = [];
    }

    /**
     * @param list<string> $agentTypes Singleton agent types to register on the node's roster
     */
    private function seedRoster(array $agentTypes): void
    {
        foreach ($agentTypes as $agentType) {
            $this->manager->registerUnlinked($agentType);
        }
    }

    /**
     * @param int $count Roster steps to take, one per master pass
     */
    private function steps(int $count): void
    {
        for ($step = 0; $step < $count; $step++) {
            $this->server->rosterStepPublic();
        }
    }
}

/**
 * Worker server that exposes the roster step and records its lift hook onto the switch's log, so
 * the order of the two is observable.
 */
final class RosterStepTestWorkerServer extends WorkerServer
{
    /** @var RosterStepTestSwitch Switch whose log the lift hook writes to */
    public RosterStepTestSwitch $switch;

    public EventLoop $loop;

    public int $diagnosticChecks = 0;

    public ?Closure $afterLift = null;

    public function rosterStepPublic(): void
    {
        $this->loop->tick();
    }

    protected function publishProtectedModeAgentDiagnostics(): void
    {
        $this->diagnosticChecks++;
        parent::publishProtectedModeAgentDiagnostics();
    }

    protected function onProtectedModeLifted(): void
    {
        $this->switch->heard[] = 'lifted';
        $this->afterLift?->__invoke();
    }

    protected function onStart(): void
    {
        // Not used in this test
    }
}

/**
 * Agent manager whose factory answers for every type and remembers it was asked.
 */
final class RosterStepTestAgentManagerDaemon extends AgentManagerDaemon
{
    /** Worker index a record carries before a worker is picked for it */
    private const int UNLINKED_WORKER_INDEX = 0;

    /** @var list<string> Ids of the agents the factory was asked for after the roster was last forgotten */
    public array $askedFor = [];

    /**
     * @param string $agentType Singleton agent type to register unlinked from any worker
     */
    public function registerUnlinked(string $agentType): void
    {
        $this->createAndAddAgent($agentType, null, self::UNLINKED_WORKER_INDEX, false);
        $this->askedFor = [];
    }

    /**
     * Empties the roster, standing in for the stops a live worker link completes, and forgets
     * what the factory was asked so far.
     */
    public function forgetRoster(): void
    {
        foreach (array_keys($this->getAgents()) as $agentId) {
            $this->removeAgent($agentId);
        }
        $this->askedFor = [];
    }

    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        $this->askedFor[] = $agentType;

        return new RosterStepTestAgentDaemon($agentIndex);
    }
}

/**
 * Agent daemon that requires nothing, so only the walk decides its fate.
 */
final class RosterStepTestAgentDaemon extends AbstractAgentDaemon
{
    public function __construct(?string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    public function sendToUser(AgentMessageDTOInterface $message): void
    {
        // Not used in this test
    }
}

/**
 * Switch fake that records the two walk endings and nothing else.
 */
final class RosterStepTestSwitch implements ProtectedModeSwitch
{
    /** @var list<string> What was heard, in order: stopped, resumed, and the lift hook's lifted */
    public array $heard = [];

    public ?Closure $afterStop = null;

    public function requestEnable(ProtectedModeEnableSignalData $data): void
    {
    }

    public function requestDisable(ProtectedModeDisableSignalData $data): void
    {
    }

    public function requestVerify(ProtectedModeVerifySignalData $data): void
    {
    }

    public function requestProgress(ProtectedModeProgressSignalData $data): void
    {
    }

    public function requestPass(ProtectedModePassSignalData $data): void
    {
    }

    public function requestAdmit(string $passHash, string $sessionTokenHash): void
    {
    }

    public function requestCircle(ProtectedModeCircleSignalData $data): void
    {
    }

    public function requestRefreeze(ProtectedModeRefreezeSignalData $data): void
    {
    }

    public function onRosterStopped(): void
    {
        $this->heard[] = 'stopped';
        $this->afterStop?->__invoke();
    }

    public function onRosterResumed(): void
    {
        $this->heard[] = 'resumed';
    }
}

/** Minimal runtime whose singleton is mounted explicitly by the source tests. */
final class RosterStepTestContext extends RtContext
{
    public function configure(): void
    {
    }
}

/** Refuses sync after the action has already assigned its phase. */
final class RosterStepRefusingRouter extends SignalRouter
{
    /**
     * @param string $signalName RT sync signal name
     * @param RtSyncSignalDataInterface $signalData Changed row payload
     */
    public function queueRtSyncSignal(string $signalName, RtSyncSignalDataInterface $signalData): void
    {
        throw new InvalidArgumentException('Refused test sync');
    }
}
