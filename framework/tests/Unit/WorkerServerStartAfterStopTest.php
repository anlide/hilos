<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\ProtectedMode\FrozenAgentPlacement;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\State\Item\TableLagRuntime;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Runtime\Stream\RtSyncStreamSettle;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Daemon\AgentStartSink;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\AwaitingStopReportStart;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Utils\LogLevel;
use Hilos\Utils\Logger;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * A stop report from an old worker instance must precede a new start of the same agent.
 */
final class WorkerServerStartAfterStopTest extends TestCase
{
    private const string AGENT_TYPE = 'unit_start_after_stop';

    private const int ORIGINAL_WORKER = 17;

    private const int OTHER_WORKER = 18;

    private const string INITIATOR_TYPE = 'restorer';

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    private ?RtContext $previousRt;

    private ?SignalRouter $previousRouter;

    private ?ClusterContext $previousCluster;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        $this->previousRt = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousCluster = Hilos::$cluster;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StartAfterStopTestHilos::class);
        Hilos::$cluster = null;
        Hilos::$rt = null;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousRouter;
        Hilos::$cluster = $this->previousCluster;

        parent::tearDown();
    }

    /**
     * A received stop must already be logged before advancing the deferred start.
     */
    public function testLateStopReportPrecedesTheNewInstanceAndTheOldWorkerIsPreferred(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'hilos-start-after-stop-');
        $this->assertNotFalse($logFile);
        $previousLogFile = new ReflectionProperty(Logger::class, 'logFile')->getValue();
        $previousShowLogLevel = new ReflectionProperty(Logger::class, 'showLogLevel')->getValue();
        $previousWriteLevel = Logger::writeLevel();
        Logger::setLogFile($logFile);
        Logger::setShowLogLevel(true);
        Logger::setWriteLevel(LogLevel::Info);

        try {
            [$server, $manager, $original] = $this->runningAgent();
            $other = $server->addWorker(self::OTHER_WORKER);

            $server->restartAgentHere(self::AGENT_TYPE, null, false);
            $server->startPublic();
            $server->advancePublic();
            $this->assertTrue($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
            $this->assertTrue($original->awaitsStopReport(self::AGENT_TYPE));
            $this->assertFalse($manager->hasAgent(self::AGENT_TYPE));
            $this->assertCount(1, $original->startedAgentTypes);
            $this->assertSame([], $other->startedAgentTypes);
            $this->assertSame('', file_get_contents($logFile));

            $original->reportStop();

            $this->assertFalse($original->awaitsStopReport(self::AGENT_TYPE));
            $this->assertFalse($manager->hasAgent(self::AGENT_TYPE));
            $this->assertTrue($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
            $this->assertCount(1, $original->startedAgentTypes);
            $this->assertSame([], $other->startedAgentTypes);
            $stopLog = file_get_contents($logFile);
            $this->assertNotFalse($stopLog);
            $this->assertMatchesRegularExpression(
                "/\A\[[^\r\n]+\] \[INFO\] Agent 'unit_start_after_stop' stopped on worker #17\n\z/",
                $stopLog,
            );

            $server->advancePublic();

            $this->assertFalse($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
            $this->assertSame([self::AGENT_TYPE, self::AGENT_TYPE], $original->startedAgentTypes);
            $this->assertSame([], $other->startedAgentTypes);
            $this->assertSame(self::ORIGINAL_WORKER, $manager->getAgentWorkerId(self::AGENT_TYPE));
            $this->assertSame($stopLog, file_get_contents($logFile));
        } finally {
            if ($previousLogFile === null) {
                Logger::resetLogFile();
            } else {
                Logger::setLogFile($previousLogFile);
            }
            Logger::setShowLogLevel($previousShowLogLevel);
            Logger::setWriteLevel($previousWriteLevel);
            unlink($logFile);
        }
    }

    public function testPlacementAcceptedDuringStopWaitKeepsItsSanction(): void
    {
        [$server] = $this->runningAgent();
        $server->stopPublic();
        $server->startPublic();

        $this->assertNull($server->executePlacement(self::AGENT_TYPE, null));
        $awaiting = new ReflectionProperty(WorkerServer::class, 'agentsAwaitingStopReport')->getValue($server);
        $this->assertInstanceOf(AwaitingStopReportStart::class, $awaiting[self::AGENT_TYPE]);
        $this->assertTrue($awaiting[self::AGENT_TYPE]->placedByLeader);
    }

    public function testRestartRefusesWhenTheOldRosterRecordCannotBeStopped(): void
    {
        [$server] = $this->runningAgent();
        $server->disconnectWorker(self::ORIGINAL_WORKER);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('could not be stopped');
        $server->restartAgentHere(self::AGENT_TYPE, null, false);
    }

    public function testAClosedLinkEndsTheStopWaitAndStartsOnAnotherWorker(): void
    {
        [$server, $manager] = $this->runningAgent();
        $server->stopPublic();
        $server->startPublic();
        $other = $server->addWorker(self::OTHER_WORKER);
        $server->disconnectWorker(self::ORIGINAL_WORKER);

        $server->advancePublic();

        $this->assertFalse($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
        $this->assertSame([self::AGENT_TYPE], $other->startedAgentTypes);
        $this->assertSame(self::OTHER_WORKER, $manager->getAgentWorkerId(self::AGENT_TYPE));
    }

    public function testGateRefusalAfterStopReportAnswersTheDeferredStart(): void
    {
        [$server, $manager, $original] = $this->runningAgent();
        $sink = new StartAfterStopTestStartSink();
        $manager->registerAgentStartSink($sink);
        $server->stopPublic();
        $server->startPublic();
        $server->refuseRtClaim(self::AGENT_TYPE, null);
        $original->reportStop();

        $server->advancePublic();

        $this->assertSame([self::AGENT_TYPE], $sink->failedAgentIds);
        $this->assertFalse($manager->hasAgent(self::AGENT_TYPE));
    }

    public function testAnotherStartAfterTheReportLeavesTheWaitForItsVerdict(): void
    {
        [$server, $manager, $original] = $this->runningAgent();
        $sink = new StartAfterStopTestStartSink();
        $manager->registerAgentStartSink($sink);
        $server->stopPublic();
        $server->startPublic();
        $server->refuseRtClaim(self::AGENT_TYPE, null);
        $original->reportStop();

        $server->startPublic();
        $this->assertTrue($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
        $server->advancePublic();

        $this->assertSame([self::AGENT_TYPE], $sink->failedAgentIds);
    }

    public function testAStartFailureAfterTheOldLinkClosesAnswersTheDeferredStart(): void
    {
        [$server, $manager] = $this->runningAgent();
        $sink = new StartAfterStopTestStartSink();
        $manager->registerAgentStartSink($sink);
        $server->stopPublic();
        $server->startPublic();
        $server->disconnectWorker(self::ORIGINAL_WORKER);

        $server->advancePublic();

        $this->assertSame([self::AGENT_TYPE], $sink->failedAgentIds);
        $this->assertFalse($manager->hasAgent(self::AGENT_TYPE));
    }

    public function testStopCancelsAStartWaitingForItsEarlierStopReport(): void
    {
        [$server, , $original] = $this->runningAgent();
        $server->stopPublic();
        $server->startPublic();

        $server->stopPublic();
        $original->reportStop();
        $server->advancePublic();

        $this->assertFalse($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
        $this->assertSame([self::AGENT_TYPE], $original->startedAgentTypes);
    }

    public function testDeferredStartCountsAsUnderWayForTheMastersFrameHold(): void
    {
        [$server, $manager] = $this->runningAgent();
        $server->stopPublic();
        $server->startPublic();
        $this->assertTrue($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
        $daemon = new ReflectionClass(StartAfterStopTestDaemonManager::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->setValue($daemon, $manager);

        $this->assertTrue(new ReflectionClass(DaemonManager::class)
            ->getMethod('isAgentStartUnderWay')->invoke($daemon, $server, self::AGENT_TYPE));
    }

    /** The master's private wait and the report/refreeze window cannot be observed reliably on the stand. */
    public function testReportUnderRefreezeRefusesTheWaitButTheNextLiftReturnsTheAgent(): void
    {
        [$server, $manager, $original] = $this->waitingReplay();
        $sink = new StartAfterStopTestStartSink();
        $manager->registerAgentStartSink($sink);
        $this->close($server);
        $server->rosterStepPublic();
        $this->assertTrue($original->awaitsStopReport(self::AGENT_TYPE));
        $this->assertSame([[self::AGENT_TYPE, null]], $original->stoppedAgents);

        $original->reportStop();
        $server->advancePublic();
        $this->assertSame([self::AGENT_TYPE], $sink->failedAgentIds);
        $this->assertSame([self::AGENT_TYPE], $original->startedAgentTypes);

        $this->lift($server);
        $server->rosterStepPublic();
        $server->advancePublic();
        $server->rosterStepPublic();
        $this->assertSame([self::AGENT_TYPE, self::AGENT_TYPE], $original->startedAgentTypes);
        $this->assertTrue($manager->hasAgent(self::AGENT_TYPE));
    }

    public function testReportAfterLiftBeforeReplayStartsOnlyOneNewInstance(): void
    {
        [$server, , $original] = $this->waitingReplay();
        $this->close($server);
        $server->rosterStepPublic();
        $this->lift($server);
        $this->assertSame([[self::AGENT_TYPE, null]], $original->startedAgents);

        $original->reportStop();
        $server->advancePublic();
        $this->assertSame([[self::AGENT_TYPE, null], [self::AGENT_TYPE, null]], $original->startedAgents);
        $server->rosterStepPublic();
        $server->advancePublic();
        $this->lift($server);
        $server->rosterStepPublic();
        $this->assertSame([[self::AGENT_TYPE, null], [self::AGENT_TYPE, null]], $original->startedAgents);
        $this->assertSame([], $this->waitingReplayIndex($server));
    }

    public function testLiftAndItsReplayPortionStillWaitForTheOldReport(): void
    {
        [$server, , $original] = $this->waitingReplay();
        $this->close($server);
        $server->rosterStepPublic();
        $this->assertTrue($server->awaitsProtectedModeResume(self::AGENT_TYPE, null));
        $this->assertSame(1, $server->getProtectedModeAgentDiagnostics()[ProtectedModeRuntime::stoppedAgentCount]);
        $this->lift($server);
        $server->rosterStepPublic();
        $server->advancePublic();
        $server->rosterStepPublic();
        $this->assertSame([[self::AGENT_TYPE, null]], $original->startedAgents);
        $this->assertTrue($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
        $this->assertFalse($server->awaitsProtectedModeResume(self::AGENT_TYPE, null));
        $this->assertSame(0, $server->getProtectedModeAgentDiagnostics()[ProtectedModeRuntime::stoppedAgentCount]);

        $original->reportStop();
        $server->advancePublic();
        $server->advancePublic();
        $this->lift($server);
        $server->rosterStepPublic();
        $this->assertSame([[self::AGENT_TYPE, null], [self::AGENT_TYPE, null]], $original->startedAgents);
        $this->assertSame([], $this->waitingReplayIndex($server));
    }

    public function testDepartedOldLinkKeepsTheWriteBarrierAndThenUsesAnotherWorker(): void
    {
        [$server, $manager, $original] = $this->waitingReplay();
        $other = $server->addWorker(self::OTHER_WORKER);
        $events = [];
        $this->queueOldWrite($manager, static function (bool $applied) use (&$events): void {
            self::assertTrue($applied);
            $events[] = 'old write';
        });
        $other->onAgentStart = static function (string $type, ?string $index) use (&$events): void {
            self::assertSame(['old write'], $events);
            self::assertSame(11, Hilos::$rt->hilosTableLagRuntime->windowMs);
            $events[] = 'start';
        };
        $this->close($server);
        $server->rosterStepPublic();
        $this->lift($server);
        $server->rosterStepPublic();
        $server->disconnectWorker(self::ORIGINAL_WORKER);
        $server->advancePublic();
        $this->assertSame([[self::AGENT_TYPE, null]], $original->startedAgents);
        $this->assertSame([], $other->startedAgents);
        $this->assertSame([], $events);

        $this->settleOldWrite();
        $server->advancePublic();
        $server->advancePublic();
        $this->lift($server);
        $server->rosterStepPublic();
        $this->assertSame(['old write', 'start'], $events);
        $this->assertSame([[self::AGENT_TYPE, null]], $other->startedAgents);
        $this->assertSame(self::OTHER_WORKER, $manager->getAgentWorkerId(self::AGENT_TYPE));
        $this->assertSame([], $this->waitingReplayIndex($server));
    }

    /** @return array<string, array{bool}> Ordinary request before or after the freeze's replay request */
    public static function ordinaryRequestOrders(): array
    {
        return ['before replay' => [true], 'after replay' => [false]];
    }

    /** @param bool $ordinaryFirst Whether the ordinary request precedes the first replay */
    #[DataProvider('ordinaryRequestOrders')]
    public function testOrdinaryRequestKeepsReplayOriginSanctionAndPreferredWorker(bool $ordinaryFirst): void
    {
        [$server, $manager, $original] = $this->stoppedForFreeze('42');
        $other = $server->addWorker(self::OTHER_WORKER);
        $agentId = self::AGENT_TYPE . AgentConstants::ID_SEPARATOR . '42';
        if ($ordinaryFirst) {
            $server->startPublic('42');
        }
        $this->lift($server);
        $server->rosterStepPublic();
        if (!$ordinaryFirst) {
            $server->startPublic('42');
        }
        $waits = new ReflectionProperty(WorkerServer::class, 'agentsAwaitingStopReport')->getValue($server);
        $this->assertTrue($waits[$agentId]->protectedModeReplay);
        $this->assertTrue($waits[$agentId]->placedByLeader);
        $this->assertSame(self::ORIGINAL_WORKER, $waits[$agentId]->preferredWorkerId);
        $this->assertSame($original, $waits[$agentId]->awaitedWorker);

        // A reentrant refreeze at the wake must already see the merged wait and its replay index.
        $server->setAgentHoldWake(function (?string $woken) use ($server, $agentId): void {
            self::assertSame($agentId, $woken);
            $server->setAgentHoldWake(null);
            self::assertArrayHasKey($agentId, $this->waitingReplayIndex($server));
            $this->close($server);
        });
        $server->startPublic('42');
        $original->reportStop('42');
        $server->advancePublic();
        $server->rosterStepPublic();
        $this->assertTrue($server->awaitsProtectedModeResume(self::AGENT_TYPE, '42'));
        $this->lift($server);
        $server->rosterStepPublic();
        $server->advancePublic();
        $this->assertSame([[self::AGENT_TYPE, '42'], [self::AGENT_TYPE, '42']], $original->startedAgents);
        $this->assertSame([], $other->startedAgents);
        $this->assertSame(self::ORIGINAL_WORKER, $manager->getAgentWorkerId($agentId));
    }

    /** @return array<string, array{bool}> Both ordinary local and leader-sanctioned waits */
    public static function ordinaryWaitKinds(): array
    {
        return ['local start' => [false], 'leader placement' => [true]];
    }

    /** @param bool $placedByLeader Whether placement sanctions the ordinary start */
    #[DataProvider('ordinaryWaitKinds')]
    public function testUnmarkedWaitIsNotInheritedOrRetriedAfterFreezeRefusal(bool $placedByLeader): void
    {
        [$server, $manager, $original] = $this->runningAgent();
        $this->mountProtectedMode();
        $sink = new StartAfterStopTestStartSink();
        $manager->registerAgentStartSink($sink);
        $server->stopPublic();
        if ($placedByLeader) {
            $this->assertNull($server->executePlacement(self::AGENT_TYPE, null));
        } else {
            $server->startPublic();
        }
        $this->assertSame([], $this->waitingReplayIndex($server));
        $this->close($server);
        $server->rosterStepPublic();
        $this->assertSame([], $server->getProtectedModeStoppedAgents());
        $original->reportStop();
        $server->advancePublic();
        $this->assertSame([self::AGENT_TYPE], $sink->failedAgentIds);
        $this->lift($server);
        $server->rosterStepPublic();
        $server->advancePublic();
        $this->assertSame([[self::AGENT_TYPE, null]], $original->startedAgents);
        $this->assertFalse($manager->hasAgent(self::AGENT_TYPE));
    }

    public function testExplicitStopRemovesWaitingReplayBeforeAnotherRefreeze(): void
    {
        [$server, , $original] = $this->waitingReplay();
        $this->assertCount(1, $this->waitingReplayIndex($server));
        $server->stopPublic();
        $this->assertSame([], $this->waitingReplayIndex($server));
        $this->close($server);
        $server->rosterStepPublic();
        $original->reportStop();
        $server->advancePublic();
        $this->lift($server);
        $server->rosterStepPublic();
        $server->advancePublic();
        $this->assertSame([], $server->getProtectedModeStoppedAgents());
        $this->assertSame([[self::AGENT_TYPE, null]], $original->startedAgents);
    }

    public function testReadReportWaitsForRealStreamSettlementBeforeTheNewInstanceWrites(): void
    {
        [$server, $manager, $original] = $this->waitingReplay();
        $events = [];
        $this->queueOldWrite($manager, static function (bool $applied) use (&$events): void {
            self::assertTrue($applied);
            self::assertSame(11, Hilos::$rt->hilosTableLagRuntime->windowMs);
            $events[] = 'old write';
        });
        $original->onAgentStart = static function (string $type, ?string $index) use (&$events): void {
            self::assertSame(['old write'], $events, 'No old write may remain unsettled at the actual start');
            self::assertSame(11, Hilos::$rt->hilosTableLagRuntime->windowMs);
            RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
                TableLagRuntime::RT_ITEM, TableLagRuntime::ID, [TableLagRuntime::windowMs => 29],
            ), false);
            $events[] = 'new instance';
        };
        $this->close($server);
        $server->rosterStepPublic();
        $original->reportStop();
        $this->assertFalse($original->awaitsStopReport(self::AGENT_TYPE));
        $server->advancePublic();
        $this->lift($server);
        $server->rosterStepPublic();
        $server->advancePublic();
        $this->assertSame([], $events);
        $this->assertSame([[self::AGENT_TYPE, null]], $original->startedAgents);
        $this->assertSame(0, Hilos::$rt->hilosTableLagRuntime->windowMs);

        $this->settleOldWrite();
        $server->advancePublic();
        $server->advancePublic();
        $this->lift($server);
        $server->rosterStepPublic();
        $this->assertSame(['old write', 'new instance'], $events);
        $this->assertSame([[self::AGENT_TYPE, null], [self::AGENT_TYPE, null]], $original->startedAgents);
        $this->assertSame(29, Hilos::$rt->hilosTableLagRuntime->windowMs, 'The old tail cannot overwrite the new instance');
        $this->assertSame([], $this->waitingReplayIndex($server));
    }

    public function testReportBeforeFirstPreparationPortionCannotEraseTheRefreezeSnapshot(): void
    {
        [$server, , $original] = $this->waitingReplay();
        $this->close($server);
        $original->reportStop();
        $server->advancePublic();
        $this->assertSame([], $this->waitingReplayIndex($server));
        $this->assertTrue($server->awaitsProtectedModeResume(self::AGENT_TYPE, null));
        $this->assertSame(1, $server->getProtectedModeAgentDiagnostics()[ProtectedModeRuntime::stoppedAgentCount]);
        $server->rosterStepPublic();
        $this->lift($server);
        $server->rosterStepPublic();
        $server->advancePublic();
        $this->assertSame([[self::AGENT_TYPE, null], [self::AGENT_TYPE, null]], $original->startedAgents);
    }

    public function testLiftCancelsPartialPreparationAndAnotherCloseLiftKeepsOneActualStart(): void
    {
        [$server, $manager, $original] = $this->waitingReplay();
        $this->fillPreparation($manager);
        $this->close($server);
        $server->rosterStepPublic();
        $this->assertNotNull(new ReflectionProperty(WorkerServer::class, 'protectedModeStopPreparation')->getValue($server));
        $this->lift($server);
        $this->close($server);
        $original->reportStop();
        $server->advancePublic();
        $server->rosterStepPublic();
        $this->assertNotNull(new ReflectionProperty(WorkerServer::class, 'protectedModeStopPreparation')->getValue($server));
        $this->assertSame(1, $server->getProtectedModeAgentDiagnostics()[ProtectedModeRuntime::stoppedAgentCount]);

        $this->lift($server);
        $server->rosterStepPublic();
        $server->advancePublic();
        $this->lift($server);
        $server->rosterStepPublic();
        $this->assertSame([[self::AGENT_TYPE, null], [self::AGENT_TYPE, null]], $original->startedAgents);
        $this->assertSame([], $this->waitingReplayIndex($server));
    }

    public function testStopLogKeepsTheDistinctRequestCountAfterWaitsEndAndPrintsZeroToo(): void
    {
        [$server, $manager, $original] = $this->waitingReplay();
        $server->startPublic();
        $server->startPublic();
        $this->fillPreparation($manager);
        $log = $this->captureLogs(function () use ($server, $original): void {
            $this->close($server);
            $server->rosterStepPublic();
            $original->reportStop();
            $server->advancePublic();
            $this->assertSame([], $this->waitingReplayIndex($server));
            $server->rosterStepPublic();
            $server->rosterStepPublic();
            $server->rosterStepPublic();

            $this->mountProtectedMode();
            [$withoutReplay] = $this->runningAgent();
            $this->close($withoutReplay);
            $withoutReplay->rosterStepPublic();
            $withoutReplay->rosterStepPublic();
        });
        preg_match_all('/Protected mode: froze this node[^\r\n]+/', $log, $lines);
        $this->assertSame([
            'Protected mode: froze this node for restorer, stopped 0 agent(s) over 1 pass(es), kept 1 waiting replay(s) for the lift',
            'Protected mode: froze this node for restorer, stopped 1 agent(s) over 1 pass(es), kept 0 waiting replay(s) for the lift',
        ], $lines[0]);
    }

    /** Mounts the production phase row without unrelated application runtime data. */
    private function mountProtectedMode(): void
    {
        Hilos::$rt = new StartAfterStopTestRtContext();
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::create());
    }

    /**
     * @param StartAfterStopTestWorkerServer $server Node whose exact replay index is checked
     * @return array<string, FrozenAgentPlacement> Private wait membership, never a replacement for the stop barrier
     */
    private function waitingReplayIndex(StartAfterStopTestWorkerServer $server): array
    {
        return new ReflectionProperty(WorkerServer::class, 'protectedModeWaitingReplays')->getValue($server);
    }

    /**
     * Carries example instance data in an existing scalar row; no table delay is exercised.
     *
     * @param StartAfterStopTestAgentManagerDaemon $manager Real stream settlement owner
     * @param Closure(bool): void $settled Observation after application and hand-over
     */
    private function queueOldWrite(StartAfterStopTestAgentManagerDaemon $manager, Closure $settled): void
    {
        Hilos::$rt->mountFeatureItem(TableLagRuntime::RT_ITEM, TableLagRuntime::create());
        $manager->handleWorkerRtSyncStream(RtSyncStreamOperation::fromSignalData(new RtSyncUpdatedSignalData(
            TableLagRuntime::RT_ITEM, TableLagRuntime::ID, [TableLagRuntime::windowMs => 11],
        ), 1), $settled);
    }

    /** Consumes the real dispatch carrier and applies it before settling the manager's production barrier. */
    private function settleOldWrite(): void
    {
        $entry = Hilos::$sr->consumeDispatchEntry();
        $this->assertInstanceOf(SignalDTO::class, $entry);
        $this->assertInstanceOf(RtSyncUpdatedSignalData::class, $entry->data);
        RtSyncApplicator::applyUpdated($entry->data, false);
        RtSyncStreamSettle::settle($entry->data, true);
        $this->assertNull(Hilos::$sr->consumeDispatchEntry(), 'No old tail remains after this write is handed on');
    }

    /** @param StartAfterStopTestAgentManagerDaemon $manager Roster padded with preserved agents to span preparation portions */
    private function fillPreparation(StartAfterStopTestAgentManagerDaemon $manager): void
    {
        for ($index = 0; $index < 130; $index++) {
            $manager->addAgent(
                (string)$manager->buildAgentId(HilosAgentType::HILOS_MAIL, (string)$index),
                new StartAfterStopTestAgentDaemon(), self::ORIGINAL_WORKER, false,
            );
        }
    }

    /**
     * @param Closure(): void $work Walk whose existing closing log is observed
     * @return string Log produced with all Logger bindings restored afterwards
     */
    private function captureLogs(Closure $work): string
    {
        $logFile = tempnam(sys_get_temp_dir(), 'hilos-replay-log-');
        $this->assertNotFalse($logFile);
        $previousLogFile = new ReflectionProperty(Logger::class, 'logFile')->getValue();
        $previousShowLogLevel = new ReflectionProperty(Logger::class, 'showLogLevel')->getValue();
        $previousWriteLevel = Logger::writeLevel();
        Logger::setLogFile($logFile);
        Logger::setShowLogLevel(true);
        Logger::setWriteLevel(LogLevel::Info);
        try {
            $work();
            $log = file_get_contents($logFile);
            $this->assertNotFalse($log);
            return $log;
        } finally {
            if ($previousLogFile === null) {
                Logger::resetLogFile();
            } else {
                Logger::setLogFile($previousLogFile);
            }
            Logger::setShowLogLevel($previousShowLogLevel);
            Logger::setWriteLevel($previousWriteLevel);
            unlink($logFile);
        }
    }

    /** @param StartAfterStopTestWorkerServer $server Node whose verification window closes */
    private function close(StartAfterStopTestWorkerServer $server): void
    {
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
            ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID,
            [ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE],
        ), false);
        $server->stopAgentsForProtectedMode(self::INITIATOR_TYPE, null);
    }

    /** @param StartAfterStopTestWorkerServer $server Node whose remembered roster is replayed */
    private function lift(StartAfterStopTestWorkerServer $server): void
    {
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
            ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID,
            [ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_VERIFYING],
        ), false);
        $server->resumeAgentsForProtectedMode();
    }

    /** @return array{StartAfterStopTestWorkerServer, StartAfterStopTestAgentManagerDaemon, StartAfterStopTestWorkerClient} Waiting replay */
    private function waitingReplay(): array
    {
        $running = $this->stoppedForFreeze();
        $this->lift($running[0]);
        $running[0]->rosterStepPublic();

        return $running;
    }

    /**
     * @param ?string $agentIndex Instance to stop, or null for the singleton
     * @return array{StartAfterStopTestWorkerServer, StartAfterStopTestAgentManagerDaemon, StartAfterStopTestWorkerClient} Frozen agent
     */
    private function stoppedForFreeze(?string $agentIndex = null): array
    {
        $running = $this->runningAgent($agentIndex);
        $this->mountProtectedMode();
        $this->close($running[0]);
        $running[0]->rosterStepPublic();

        return $running;
    }

    /**
     * @param ?string $agentIndex Instance to start, or null for the singleton
     * @return array{StartAfterStopTestWorkerServer, StartAfterStopTestAgentManagerDaemon, StartAfterStopTestWorkerClient}
     *     Server with one agent on its original worker
     */
    private function runningAgent(?string $agentIndex = null): array
    {
        $manager = new StartAfterStopTestAgentManagerDaemon();
        $server = new ReflectionClass(StartAfterStopTestWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);
        $original = $server->addWorker(self::ORIGINAL_WORKER);
        $server->startPublic($agentIndex);

        return [$server, $manager, $original];
    }
}

final class StartAfterStopTestWorkerServer extends WorkerServer
{
    /** @param ?string $agentIndex Instance to start, or null for the singleton */
    public function startPublic(?string $agentIndex = null): void
    {
        $this->startAgent(StartAfterStopTestAgentDaemon::AGENT_TYPE, $agentIndex);
    }

    /** @param ?string $agentIndex Instance to stop, or null for the singleton */
    public function stopPublic(?string $agentIndex = null): void
    {
        $this->stopAgent(StartAfterStopTestAgentDaemon::AGENT_TYPE, $agentIndex);
    }

    public function advancePublic(): void
    {
        new ReflectionClass(WorkerServer::class)->getMethod('advanceAgentsAwaitingStopReport')->invoke($this);
    }

    /** Advances one production roster portion without a clock or a second process. */
    public function rosterStepPublic(): void
    {
        $this->advanceProtectedModeRoster();
    }

    /**
     * @param int $workerIndex Index of the connection added to this server
     * @return StartAfterStopTestWorkerClient Connection reporting into this server's real manager
     */
    public function addWorker(int $workerIndex): StartAfterStopTestWorkerClient
    {
        $client = new ReflectionClass(StartAfterStopTestWorkerClient::class)->newInstanceWithoutConstructor();
        $client->setWorkerIndex($workerIndex);
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($client, true);
        new ReflectionProperty(WorkerClient::class, 'agentManager')->setValue(
            $client,
            new ReflectionProperty(WorkerServer::class, 'agentManager')->getValue($this),
        );
        new ReflectionProperty(WorkerClient::class, 'workerServer')->setValue($client, $this);
        $this->addClient($client);

        return $client;
    }

    /** @param int $workerIndex Exact old connection removed through the production settlement door */
    public function disconnectWorker(int $workerIndex): void
    {
        foreach ($this->clients as $client) {
            if ($client->getWorkerIndex() === $workerIndex) {
                $this->removeClient($client);
            }
        }
    }

    protected function onStart(): void
    {
    }
}

final class StartAfterStopTestWorkerClient extends WorkerClient
{
    /** @var list<string> Agent types this worker was told to start */
    public array $startedAgentTypes = [];

    /** @var list<array{string, ?string}> Type and index of every actual start sent */
    public array $startedAgents = [];

    /** @var list<array{string, ?string}> Type and index of every actual stop sent */
    public array $stoppedAgents = [];

    /** @var ?Closure(string, ?string): void Observation at the actual send, after recording its identity */
    public ?Closure $onAgentStart = null;

    /**
     * @param string $agentType Agent type actually sent to this worker
     * @param ?string $agentIndex Instance actually sent, or null for the singleton
     * @param list<string> $liveAcceptKeys Unused browser roster
     */
    public function sendAgentStart(string $agentType, ?string $agentIndex = null, array $liveAcceptKeys = []): void
    {
        $this->startedAgentTypes[] = $agentType;
        $this->startedAgents[] = [$agentType, $agentIndex];
        $this->onAgentStart?->__invoke($agentType, $agentIndex);
    }

    /**
     * Records the send while retaining the production wait for the worker's report.
     *
     * @param string $agentType Agent type stopped on this worker
     * @param ?string $agentIndex Instance stopped, or null for the singleton
     */
    public function sendAgentStop(string $agentType, ?string $agentIndex = null): void
    {
        $this->stoppedAgents[] = [$agentType, $agentIndex];
        parent::sendAgentStop($agentType, $agentIndex);
    }

    public function send(string $message): void
    {
    }

    /**
     * Deliver the worker's stop report through the production decoder and handler.
     *
     * @param ?string $agentIndex Instance reported stopped, or null for the singleton
     */
    public function reportStop(?string $agentIndex = null): void
    {
        new ReflectionClass(WorkerClient::class)->getMethod('processMessage')->invoke(
            $this,
            new WorkerAgentStoppedDTO(StartAfterStopTestAgentDaemon::AGENT_TYPE
                . ($agentIndex === null ? '' : AgentConstants::ID_SEPARATOR . $agentIndex))->toJson(),
        );
    }
}

final class StartAfterStopTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Fixture's one application agent type
     * @param ?string $agentIndex Instance retained through stop and replay
     * @return AgentDaemonInterface Ordinary daemon linked by the production start path
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new StartAfterStopTestAgentDaemon($agentIndex);
    }
}

final class StartAfterStopTestAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = 'unit_start_after_stop';

    /** @param ?string $agentIndex Instance carried by the real manager's roster */
    public function __construct(?string $agentIndex = null)
    {
        $this->agentIndex = $agentIndex;
    }

    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    public function sendToUser(AgentMessageDTOInterface $message): void
    {
    }
}

abstract class StartAfterStopTestHilos extends Hilos
{
    public const array AGENTS = [StartAfterStopTestAgentDaemon::AGENT_TYPE => []];
}

final class StartAfterStopTestDaemonManager extends DaemonManager
{
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new StartAfterStopTestAgentManagerDaemon();
    }
}

final class StartAfterStopTestStartSink implements AgentStartSink
{
    /** @var list<string> Agents whose start failed */
    public array $failedAgentIds = [];

    public function onAgentStarted(string $agentId): void
    {
    }

    public function onAgentStartFailed(string $agentId, string $reason): void
    {
        $this->failedAgentIds[] = $agentId;
    }

    public function onAgentStopped(string $agentId): void
    {
    }
}

/** Mounts only the existing protected-mode singleton needed by the start gate. */
final class StartAfterStopTestRtContext extends RtContext
{
    /** No application collections are needed for this local ordering test. */
    public function configure(): void
    {
    }
}
