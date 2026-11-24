<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\Placement\PlacementRecovery;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Tests\Unit\Cluster\Placement\FakePlacementMesh;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Peer\DTO\PeerRtClaimRefusedDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Placement\AgentLocationKind;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementSnapshot;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Process;
use Hilos\Cluster\Placement\PlacementId;
use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\PendingLeadership;
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
use ReflectionMethod;
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

    /** @param bool $failed Whether the first worker request ended with StartFailed instead of Stopped */
    #[DataProvider('settledStopReports')]
    public function testStopCompletionCannotRemoveAReplacementSeatedOnTheReportingLink(bool $failed): void
    {
        [$server, $manager, $worker] = $this->runningAgent();
        if (!$failed) {
            $worker->reportStarted();
        }
        $server->revokePlacement(self::AGENT_TYPE, null, $server->startPublic(...));

        if ($failed) {
            $worker->reportFailure('source wait failed');
        } else {
            $worker->reportStop();
        }
        self::assertSame([self::AGENT_TYPE, self::AGENT_TYPE], $worker->startedAgentTypes);
        self::assertSame($worker, $manager->getAgent(self::AGENT_TYPE)?->getWorkerClient());
        $worker->reportStarted();
        self::assertTrue($manager->isAgentStarted(self::AGENT_TYPE));
    }

    public static function settledStopReports(): iterable
    {
        yield 'worker stopped' => [false];
        yield 'failed start discharged the stop' => [true];
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

    public function testCurrentPolicyReplayReportsASynchronousFailureOnce(): void
    {
        [$server, $manager, $worker, $placement, $mesh, $id] = $this->managedAgent();
        $worker->reportStarted();
        $this->close($server);
        $server->rosterStepPublic();
        $worker->reportStop();
        $server->disconnectWorker(self::ORIGINAL_WORKER);
        $mesh->sent = [];

        $this->lift($server);
        $server->rosterStepPublic();
        $worker->reportFailure('late worker refusal');

        self::assertFalse($manager->hasAgent(self::AGENT_TYPE));
        self::assertNull($placement->localPlacementId(self::AGENT_TYPE));
        self::assertCount(1, $mesh->sent);
        [$leader, $failed] = $mesh->sent[0];
        self::assertSame('leader', $leader);
        self::assertInstanceOf(PeerAgentStatusDTO::class, $failed);
        self::assertSame(PlacementState::Failed, $failed->state);
        self::assertNull($failed->workerId);
        self::assertSame([], $failed->refusals);
        self::assertTrue($id->equals($failed->placementId));
        self::assertSame(7, $failed->authorityTerm);
        self::assertSame($id, $placement->placementRecovery(self::AGENT_TYPE)?->origin);
    }

    public function testOnlyTheCurrentWorkerReportsProofAndFailureForAReplay(): void
    {
        [$server, $manager, $worker, $placement, $mesh, $id] = $this->managedAgent();
        self::assertFalse($mesh->sent[0][1]->workerStarted, 'The early worker address is provisional');
        $worker->reportStarted();
        self::assertTrue($mesh->sent[1][1]->workerStarted);

        $this->close($server);
        $server->rosterStepPublic();
        $this->lift($server);
        $server->rosterStepPublic();
        $worker->reportStop();
        $server->disconnectWorker(self::ORIGINAL_WORKER);
        $replacement = $server->addWorker(self::ORIGINAL_WORKER);
        $server->advancePublic();
        $mesh->sent = [];
        $worker->reportStarted();
        $worker->reportFailure('old link');
        self::assertSame($replacement, $manager->getAgent(self::AGENT_TYPE)?->getWorkerClient());
        self::assertSame([], $mesh->sent);
        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('during-replay', 7));
        self::assertSame(PlacementState::Placing, $mesh->sent[0][1]->agents[0]->state);
        self::assertFalse($mesh->sent[0][1]->agents[0]->workerStarted);

        $sink = new StartAfterStopTestStartSink();
        $manager->registerAgentStartSink($sink);
        $replacement->reportFailure('source did not arrive');
        $replacement->reportFailure('duplicate failure');
        self::assertCount(2, $mesh->sent);
        $failed = $mesh->sent[1][1];
        self::assertSame(PlacementState::Failed, $failed->state);
        self::assertSame('source did not arrive', $failed->error);
        self::assertSame([$id], $sink->failedRecoveryOrders);
        self::assertTrue($id->equals($failed->placementId));
        self::assertNull($placement->localPlacementId(self::AGENT_TYPE));
    }

    public function testReplacementWorkerCloseReportsItsOrderOnlyAfterStreamedWrites(): void
    {
        [, $manager, $worker, $placement, $mesh, $order] = $this->managedAgent();
        self::assertFalse($mesh->sent[0][1]->workerStarted);
        $mesh->sent = [];
        $this->queueOldWrite($manager, static function (bool $applied): void {
            self::assertTrue($applied);
        });

        new ReflectionMethod(WorkerClient::class, 'onClose')->invoke($worker);
        self::assertNull($manager->getAgent(self::AGENT_TYPE));
        self::assertNull($placement->localPlacementId(self::AGENT_TYPE));
        self::assertSame([], $mesh->sent);

        $this->settleOldWrite();
        self::assertCount(1, $mesh->sent);
        [$leader, $stopped] = $mesh->sent[0];
        self::assertSame('leader', $leader);
        self::assertInstanceOf(PeerAgentStatusDTO::class, $stopped);
        self::assertSame(PlacementState::Stopped, $stopped->state);
        self::assertSame($order->term, $stopped->authorityTerm);
        self::assertTrue($order->equals($stopped->placementId));
        self::assertNull($stopped->stopId);
        $worker->reportStarted();
        self::assertCount(1, $mesh->sent);
    }

    public function testRevokingAFrozenPolicyOrderCancelsItsReplayWithoutFailure(): void
    {
        [$server, , $worker, $placement, $mesh] = $this->managedAgent();
        $worker->reportStarted();
        $this->close($server);
        $server->rosterStepPublic();
        $this->lift($server);
        $server->rosterStepPublic();
        $placement->onStopAgent('leader', new PeerStopAgentDTO(self::AGENT_TYPE, null, 7, 'stop', $placement->localPlacementId(self::AGENT_TYPE)));
        $mesh->sent = [];
        $worker->reportStop();
        $server->advancePublic();

        self::assertNull($placement->localPlacementId(self::AGENT_TYPE));
        self::assertSame([self::AGENT_TYPE], $worker->startedAgentTypes);
        self::assertCount(1, $mesh->sent);
        self::assertSame(PlacementState::Stopped, $mesh->sent[0][1]->state);
    }

    /** @param bool $waitForWorker Whether the replay already reached the worker-connection queue */
    #[DataProvider('interruptedReplayWaits')]
    public function testAReplayThatLosesAuthorityWhileWaitingLeavesNoHostedOrderOrFailure(bool $waitForWorker): void
    {
        [$server, $manager, $worker, $placement, $mesh, $order] = $this->managedAgent();
        $worker->reportStarted();
        $this->close($server);
        $server->rosterStepPublic();
        $this->lift($server);
        $server->rosterStepPublic();
        if ($waitForWorker) {
            $this->holdReplayForConnectingWorker($server, $worker);
        } else {
            self::assertTrue($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
        }
        $sink = new StartAfterStopTestStartSink();
        $manager->registerAgentStartSink($sink);
        $placement->onPlacementRelease('leader', 1000.0);
        $mesh->sent = [];
        $mesh->holdRtWritesAnswered = true;

        if ($waitForWorker) {
            $replacement = $server->addWorker(self::OTHER_WORKER);
            $server->advanceWorkerWaitPublic();
            self::assertSame([], $replacement->startedAgentTypes);
        } else {
            $worker->reportStop();
            $server->advancePublic();
        }
        self::assertSame([$order], $sink->failedRecoveryOrders);
        self::assertSame([], $mesh->sent);
        self::assertNull($placement->localPlacementId(self::AGENT_TYPE));
        self::assertSame([self::AGENT_TYPE], $worker->startedAgentTypes);

        $placement->onPlacementQuery('successor', new PeerPlacementQueryDTO('query-in-new-term', 8));
        self::assertSame([], $mesh->sent);
        $mesh->releaseRtWritesAnswered();
        self::assertSame([], $mesh->sent);
        $placement->tick(1000.0);
        self::assertEquals([['successor', new PeerPlacementReportDTO([], queryId: 'query-in-new-term')]], $mesh->sent);
    }

    public function testConfirmedTerminalViewRevokesReplayBeforeTheStopArrives(): void
    {
        [$server, , $worker, $placement, $mesh, $order] = $this->managedAgent();
        $worker->reportStarted();
        $this->close($server);
        $server->rosterStepPublic();
        $this->lift($server);
        $server->rosterStepPublic();
        self::assertTrue($server->isAgentAwaitingStopReport(self::AGENT_TYPE));

        $terminal = new PlacementRecord(self::AGENT_TYPE, null, 'follower', PlacementState::Stopped, placementId: $order);
        $placement->onPlacementView('leader', new PeerPlacementViewDTO('leader', [], new PlacementSnapshot('leader', 7, 1, [$terminal])));
        $mesh->sent = [];
        $worker->reportStop();
        $server->advancePublic();
        self::assertSame([self::AGENT_TYPE], $worker->startedAgentTypes);
        self::assertSame([], $mesh->sent, 'The addressable stop has not arrived and no failure is due');
    }

    /** @param PlacementState $state State of the confirmed successor order */
    #[DataProvider('successorStartStates')]
    public function testConfirmedRemoteReplacementTakesLocalStartSanctionAndKeepsRecovery(PlacementState $state): void
    {
        [$server, , $worker, $placement, $mesh, $order] = $this->managedAgent();
        $worker->reportStarted();
        $this->close($server);
        $server->rosterStepPublic();
        $recovery = $placement->placementRecovery(self::AGENT_TYPE);
        self::assertNotNull($recovery);
        $placement->onPlacementQuery('successor', new PeerPlacementQueryDTO('takeover', 8));
        $new = new PlacementId('successor', 8, 1);
        $next = new PlacementRecord(self::AGENT_TYPE, null, 'other', $state, placementId: $new);
        $placement->onPlacementView('successor', new PeerPlacementViewDTO('successor', [],
            new PlacementSnapshot('successor', 8, 1, [$next])));
        self::assertFalse($placement->isLocalPlacementCurrent(self::AGENT_TYPE, $order));
        $this->lift($server);
        $server->rosterStepPublic();
        $worker->reportStop();
        $server->advancePublic();
        self::assertSame([self::AGENT_TYPE], $worker->startedAgentTypes);
        $placement->onStopAgent('successor', new PeerStopAgentDTO(self::AGENT_TYPE, null, 8, 'stop', $order));
        self::assertFalse($placement->isPlacementRecoveryCanceled(self::AGENT_TYPE, $recovery));
        self::assertNull($placement->startedPlacementRecovery(self::AGENT_TYPE, $recovery));

        $proof = new PlacementRecord(self::AGENT_TYPE, null, 'other', PlacementState::Started, placementId: $new, workerStarted: true);
        $placement->onPlacementView('successor', new PeerPlacementViewDTO('successor', [],
            new PlacementSnapshot('successor', 8, 2, [$proof])));
        self::assertSame(AgentLocationKind::Node, $placement->startedPlacementRecovery(self::AGENT_TYPE, $recovery)?->kind);
    }

    public static function successorStartStates(): iterable
    {
        yield 'pending successor' => [PlacementState::Placing];
        yield 'started without worker proof' => [PlacementState::Started];
    }

    public function testFreshAndAdoptedOrdersCanReplayWithAnEarlierTerminalView(): void
    {
        [$server, $manager, $worker, $placement, $mesh, $order] = $this->managedAgent();
        $earlier = new PlacementId('leader', 6, $order->sequence);
        $record = new PlacementRecord(self::AGENT_TYPE, null, null, PlacementState::Stopped, placementId: $earlier);
        $placement->onPlacementView('leader', new PeerPlacementViewDTO('leader', [], new PlacementSnapshot('leader', 7, 1, [$record])));
        $worker->reportStarted();

        $this->close($server);
        $server->rosterStepPublic();
        $placement->onPlacementRelease('leader', 1000.0);
        $mesh->sent = [];
        $placement->onPlacementQuery('successor', new PeerPlacementQueryDTO('rebuild', 8));
        $report = $mesh->sent[0][1];
        self::assertInstanceOf(PeerPlacementReportDTO::class, $report);
        self::assertTrue($order->equals($report->agents[0]->placementId));
        self::assertSame(PlacementState::Placing, $report->agents[0]->state);

        $this->lift($server);
        $server->rosterStepPublic();
        $worker->reportStop();
        $server->advancePublic();
        $worker->reportStarted();
        self::assertTrue($manager->isAgentStarted(self::AGENT_TYPE));
        $started = array_last($mesh->sent)[1];
        self::assertInstanceOf(PeerAgentStatusDTO::class, $started);
        self::assertTrue($started->workerStarted);
        self::assertTrue($order->equals($started->placementId));
        self::assertSame(8, $started->authorityTerm);
    }

    /** @param bool $waitForWorker Whether the next replay waits for a worker instead of its earlier stop report */
    #[DataProvider('interruptedReplayWaits')]
    public function testRefreezeKeepsTheCurrentPolicyRecoveryUntilTheNextWorkerReport(bool $waitForWorker): void
    {
        [$server, $manager, $worker, $placement, $mesh, $order] = $this->managedAgent();
        $worker->reportStarted();
        $this->close($server);
        $server->rosterStepPublic();
        $this->lift($server);
        $server->rosterStepPublic();

        if ($waitForWorker) {
            $this->holdReplayForConnectingWorker($server, $worker);
        } else {
            self::assertTrue($server->isAgentAwaitingStopReport(self::AGENT_TYPE));
        }
        $sink = new StartAfterStopTestStartSink();
        $manager->registerAgentStartSink($sink);
        $recovery = $placement->placementRecovery(self::AGENT_TYPE);
        $mesh->sent = [];

        $this->close($server);
        $server->rosterStepPublic();
        if (!$waitForWorker) {
            $worker->reportStop();
            $server->advancePublic();
        }
        self::assertSame([$order], $sink->failedRecoveryOrders);
        self::assertSame($recovery, $placement->placementRecovery(self::AGENT_TYPE));
        self::assertSame([], $mesh->sent);
        if ($waitForWorker) {
            $worker = $server->addWorker(self::OTHER_WORKER);
        }

        $this->lift($server);
        $server->rosterStepPublic();
        self::assertFalse($manager->isAgentStarted(self::AGENT_TYPE));
        $worker->reportStarted();
        self::assertTrue($manager->isAgentStarted(self::AGENT_TYPE));
        self::assertSame(AgentLocationKind::Here, $placement->startedPlacementRecovery(self::AGENT_TYPE, $recovery)?->kind);
    }

    /** @return iterable<string, array{bool}> Queues whose replay must survive a second freeze */
    public static function interruptedReplayWaits(): iterable
    {
        yield 'earlier stop report' => [false];
        yield 'worker connection' => [true];
    }

    /** @param bool $freeze Whether to close the start gate while authority remains current */
    #[DataProvider('closedStartSanctions')]
    public function testStartedWithoutSanctionCannotSetTheRosterOrBecomeHostedProof(bool $freeze): void
    {
        [$server, $manager, $worker, $placement, $mesh, $order] = $this->managedAgent();
        $worker->reportStarted();
        $this->close($server);
        $server->rosterStepPublic();
        $this->lift($server);
        $server->rosterStepPublic();
        $worker->reportStop();
        $server->advancePublic();
        self::assertCount(2, $worker->startedAgentTypes);
        $recovery = $placement->placementRecovery(self::AGENT_TYPE);
        $sink = new StartAfterStopTestStartSink();
        $manager->registerAgentStartSink($sink);

        if ($freeze) {
            $this->close($server);
        } else {
            $placement->onPlacementRelease('leader', 1000.0);
        }
        $mesh->sent = [];
        $worker->reportStarted();
        self::assertFalse($manager->isAgentStarted(self::AGENT_TYPE));
        self::assertSame([], $sink->startedAgentIds);
        self::assertSame([], $mesh->sent);
        $mesh->holdRtWritesAnswered = true;
        $placement->onPlacementQuery('successor', new PeerPlacementQueryDTO('query-in-new-term', 8));
        self::assertNull($placement->startedPlacementRecovery(self::AGENT_TYPE, $recovery));
        self::assertTrue($worker->awaitsStopReport(self::AGENT_TYPE));
        if (!$freeze) {
            self::assertSame([], $mesh->sent);
            self::assertNull($placement->localPlacementId(self::AGENT_TYPE));
            $worker->reportStop();
            self::assertSame([], $mesh->sent);
            $mesh->releaseRtWritesAnswered();
            self::assertSame([], $mesh->sent);
            $placement->tick(1000.0);
            self::assertEquals([['successor', new PeerPlacementReportDTO([], queryId: 'query-in-new-term')]], $mesh->sent);
        } else {
            self::assertInstanceOf(PeerPlacementReportDTO::class, $mesh->sent[0][1] ?? null);
            self::assertSame(PlacementState::Placing, $mesh->sent[0][1]->agents[0]->state);
            self::assertFalse($mesh->sent[0][1]->agents[0]->workerStarted);
            self::assertTrue($order->equals($placement->localPlacementId(self::AGENT_TYPE)));
        }
    }

    /** @return iterable<string, array{bool}> Distinct ways a pending worker loses permission to complete */
    public static function closedStartSanctions(): iterable
    {
        yield 'leader released the placement authority' => [false];
        yield 'the freeze gate closed before stopping the roster' => [true];
    }

    public function testARefusedRtClaimRevokesTheLocallyKnownOrderBeforeTheLeadersStop(): void
    {
        [$server, $manager, $worker, $placement, $mesh, $order] = $this->managedAgent();
        $daemon = new StartAfterStopTestDaemonManager();
        $daemon->registerServer($server);
        $worker->reportStarted();
        $mesh->sent = [];
        $mesh->holdRtWritesAnswered = true;

        $daemon->applyRtClaimRefusal(new PeerRtClaimRefusedDTO(
            'test_collection', [], self::AGENT_TYPE, null, self::AGENT_TYPE, 'follower', 'holder', 7,
        ));
        self::assertNull($placement->localPlacementId(self::AGENT_TYPE));
        self::assertTrue($worker->awaitsStopReport(self::AGENT_TYPE));
        self::assertTrue(new ReflectionMethod($server, 'rtClaimRefusesStart')->invoke($server, self::AGENT_TYPE, null));
        $stop = new PeerStopAgentDTO(self::AGENT_TYPE, null, 7, 'exact-stop', $order);
        $placement->onStopAgent('leader', $stop);
        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('after-stop', 7));
        self::assertSame([], $mesh->sent);
        $worker->reportStop();
        self::assertFalse($manager->hasAgent(self::AGENT_TYPE));
        self::assertSame([], $mesh->sent);

        $mesh->releaseRtWritesAnswered();
        self::assertEquals([['leader', $stop->stoppedStatus()]], $mesh->sent);
        $placement->tick(1000.0);
        self::assertEquals([
            ['leader', $stop->stoppedStatus()],
            ['leader', new PeerPlacementReportDTO([], queryId: 'after-stop')],
        ], $mesh->sent);
    }

    public function testLateWorkerFailureAfterAuthorityReleaseKeepsTheOriginalRecovery(): void
    {
        [$server, $manager, $worker, $placement, $mesh, $order] = $this->managedAgent();
        $worker->reportStarted();
        $this->close($server);
        $server->rosterStepPublic();
        $this->lift($server);
        $server->rosterStepPublic();
        $worker->reportStop();
        $server->advancePublic();
        $sink = new StartAfterStopTestStartSink();
        $manager->registerAgentStartSink($sink);
        $placement->onPlacementRelease('leader', 1000.0);
        $mesh->sent = [];
        $worker->reportFailure('source did not arrive');
        self::assertSame([], $mesh->sent);
        self::assertSame([$order], $sink->failedRecoveryOrders);
        self::assertNull($placement->localPlacementId(self::AGENT_TYPE));
    }

    public function testLocalPolicyRetryPublishesProofOnlyAfterTheWorkerStarts(): void
    {
        [$server, , $worker] = $this->serverWithWorker();
        $mesh = new FakePlacementMesh(['leader' => ['slots=10']], online: ['leader']);
        $placement = new ClusterPlacement('leader', $mesh, $server);
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership(new PendingLeadership());
        Hilos::$cluster->registerPlacement($placement);
        $this->mountProtectedMode();
        $placement->onBecameLeader(1000.0, 7);
        $placement->placeAgentOnNode(self::AGENT_TYPE, null, 'leader');
        $old = $placement->registry()->get(self::AGENT_TYPE);
        self::assertFalse($old->workerStarted);
        $worker->reportFailure('source wait failed');

        self::assertSame(PlacementState::Failed, $placement->registry()->get(self::AGENT_TYPE)?->state);
        $due = new ReflectionProperty(ClusterPlacement::class, 'retryAt')->getValue($placement)[self::AGENT_TYPE];
        $placement->tick($due);
        $replacement = $placement->placementSnapshot()?->records[0];
        self::assertSame(PlacementState::Started, $replacement?->state);
        self::assertFalse($replacement->workerStarted);
        self::assertFalse($old->placementId->equals($replacement->placementId));
        $worker->reportStarted();

        $proven = $placement->placementSnapshot()?->records[0];
        self::assertTrue($proven?->workerStarted);
        self::assertTrue($replacement->placementId->equals($proven->placementId));
    }

    public function testRefreezeConsumesTheOldWorkerFailureAsAStopAndKeepsThePolicyOrderForLift(): void
    {
        [$server, $manager, $worker, $placement, $mesh, $order] = $this->managedAgent();
        $worker->reportStarted();
        $this->close($server);
        $server->rosterStepPublic();
        $worker->reportStop();
        $this->lift($server);
        $server->rosterStepPublic();
        self::assertCount(2, $worker->startedAgentTypes);

        $this->close($server);
        $server->rosterStepPublic();
        $mesh->sent = [];
        $worker->reportFailure('late refusal behind refreeze');
        $server->advancePublic();
        self::assertSame([], $mesh->sent);
        self::assertTrue($order->equals($placement->localPlacementId(self::AGENT_TYPE)));
        self::assertFalse($manager->hasAgent(self::AGENT_TYPE));

        $this->lift($server);
        $server->rosterStepPublic();
        self::assertCount(3, $worker->startedAgentTypes, 'The failed worker no longer owes a stop report');
        $worker->reportStarted();
        self::assertCount(1, $mesh->sent);
        self::assertTrue($mesh->sent[0][1]->workerStarted);
        self::assertTrue($order->equals($mesh->sent[0][1]->placementId));
    }

    /** @return array{StartAfterStopTestWorkerServer, StartAfterStopTestAgentManagerDaemon, StartAfterStopTestWorkerClient,
     *     ClusterPlacement, FakePlacementMesh, PlacementId} One managed order on a real master roster */
    private function managedAgent(): array
    {
        [$server, $manager, $worker] = $this->serverWithWorker();
        $mesh = new FakePlacementMesh(['follower' => ['slots=10']], linked: ['leader']);
        $placement = new ClusterPlacement('follower', $mesh, $server);
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerLeadership(new PendingLeadership());
        Hilos::$cluster->registerPlacement($placement);
        $this->mountProtectedMode();
        $id = new PlacementId('leader', 7, 1);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO(self::AGENT_TYPE, null, $id));

        return [$server, $manager, $worker, $placement, $mesh, $id];
    }

    /**
     * @param StartAfterStopTestWorkerServer $server Real owner of both replay waits
     * @param StartAfterStopTestWorkerClient $worker Worker whose earlier stop was still pending
     */
    private function holdReplayForConnectingWorker(StartAfterStopTestWorkerServer $server, StartAfterStopTestWorkerClient $worker): void
    {
        $worker->reportStop();
        $server->disconnectWorker(self::ORIGINAL_WORKER);
        new ReflectionProperty(WorkerServer::class, 'workers')->setValue($server, [
            WorkerConstants::TYPE_REGULAR . WorkerConstants::KEY_SEPARATOR . self::OTHER_WORKER => [
                WorkerConstants::FIELD_WORKER_PROCESS => $this->createStub(Process::class),
                WorkerConstants::FIELD_WORKER_TYPE => WorkerConstants::TYPE_REGULAR,
                WorkerConstants::FIELD_WORKER_INDEX => self::OTHER_WORKER,
            ],
        ]);
        $server->advancePublic();
        self::assertTrue($server->isAgentAwaitingWorker(self::AGENT_TYPE));
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
        $running = $this->serverWithWorker();
        $running[0]->startPublic($agentIndex);
        return $running;
    }

    /** @return array{StartAfterStopTestWorkerServer, StartAfterStopTestAgentManagerDaemon, StartAfterStopTestWorkerClient} Empty roster */
    private function serverWithWorker(): array
    {
        $manager = new StartAfterStopTestAgentManagerDaemon();
        $server = new ReflectionClass(StartAfterStopTestWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($server, $manager);
        $original = $server->addWorker(self::ORIGINAL_WORKER);
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

    public function advanceWorkerWaitPublic(): void
    {
        $this->advanceAgentsAwaitingWorker();
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
    public function sendAgentStart(
        string $agentType,
        ?string $agentIndex = null,
        array $liveAcceptKeys = [],
        ?PlacementId $placementId = null,
    ): void
    {
        $this->startedAgentTypes[] = $agentType;
        $this->startedAgents[] = [$agentType, $agentIndex];
        parent::sendAgentStart($agentType, $agentIndex, $liveAcceptKeys, $placementId);
        $this->onAgentStart?->__invoke($agentType, $agentIndex);
    }

    /**
     * Records the send while retaining the production wait for the worker's report.
     *
     * @param string $agentType Agent type stopped on this worker
     * @param ?string $agentIndex Instance stopped, or null for the singleton
     * @param bool $keepPageMirrors Restart disposition passed through to the worker link
     */
    public function sendAgentStop(string $agentType, ?string $agentIndex = null, bool $keepPageMirrors = false): void
    {
        $this->stoppedAgents[] = [$agentType, $agentIndex];
        parent::sendAgentStop($agentType, $agentIndex, $keepPageMirrors);
    }

    public function send(string $message): void
    {
    }

    public function reportStarted(): void
    {
        $type = StartAfterStopTestAgentDaemon::AGENT_TYPE;
        new ReflectionClass(WorkerClient::class)->getMethod('processMessage')->invoke($this, new WorkerAgentStartedDTO($type, $type)->toJson());
    }

    /** @param string $reason Reason supplied by the worker */
    public function reportFailure(string $reason): void
    {
        $type = StartAfterStopTestAgentDaemon::AGENT_TYPE;
        new ReflectionClass(WorkerClient::class)->getMethod('processMessage')->invoke(
            $this, new WorkerAgentStartFailedDTO($type, $type, reason: $reason)->toJson(),
        );
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
    public const array AGENTS = [
        StartAfterStopTestAgentDaemon::AGENT_TYPE => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
    ];
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
    /** @var list<string> Agents whose start reached the generic sink */
    public array $startedAgentIds = [];

    /** @var list<string> Agents whose start failed */
    public array $failedAgentIds = [];

    /** @var list<PlacementId> Replay origins handed to the general start-failure sink */
    public array $failedRecoveryOrders = [];

    public function onAgentStarted(string $agentId): void
    {
        $this->startedAgentIds[] = $agentId;
    }

    public function onAgentStartFailed(string $agentId, string $reason, ?PlacementRecovery $recovery = null): void
    {
        $this->failedAgentIds[] = $agentId;
        if ($recovery !== null) {
            $this->failedRecoveryOrders[] = $recovery->origin;
        }
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
