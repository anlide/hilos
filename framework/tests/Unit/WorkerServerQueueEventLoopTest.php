<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\WorkerConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Daemon\AgentStartSink;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Exception\Process\FailedToReadStdOutException;
use Hilos\Core\Process;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Daemon\ParkedAgentSignal;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\Upload\UploadMemoryMaster;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\FrozenAgentPlacement;
use Hilos\ProtectedMode\ProtectedModeEntryGate;
use Hilos\ProtectedMode\ProtectedModeSettingsSink;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\AwaitingWorkerAgent;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartFailedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeSettingsDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Logger;
use Hilos\Utils\LogLevel;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Socket;

/** Same-process native scheduling; live failover and worker lifetimes are covered by the cluster scenarios. */
final class WorkerServerQueueEventLoopTest extends TestCase
{
    private EventLoop $loop;
    private QueueLoopServer $server;
    private QueueLoopAgentManager $agents;
    private QueueLoopDaemon $daemon;
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private ?AnalyticsCollector $previousAnalytics;
    private ?RtContext $previousRt;
    private ?ClusterContext $previousCluster;
    /** @var class-string<Hilos> Previously bound app */
    private string $previousApp;
    /** @var list<WorkerClient> Real socket owners */
    private array $clients = [];
    /** @var list<Socket> Same-process worker ends */
    private array $peers = [];

    /** Start reports release the hold inside a bounded parser portion, ahead of its next frame. */
    public function testEntryGateReleasesInsideActualWorkerBurstWithoutScanningOtherFrames(): void
    {
        [$client, $peer] = $this->worker(1);
        $this->server->request('regular');
        $enteredAfter = null;
        $settingsSeen = 0;
        $gate = $this->entryGate(static function () use (&$enteredAfter, &$settingsSeen): void {
            $enteredAfter = $settingsSeen;
        });
        $gate->requestEnter($this->entryRequest());
        $settings = $this->createMock(ProtectedModeSettingsSink::class);
        $settings->expects(self::exactly(140))->method('onProtectedModeSettings')->willReturnCallback(
            static function () use (&$settingsSeen, &$enteredAfter): void {
                if ($settingsSeen >= 10) {
                    self::assertSame(10, $enteredAfter, 'the first frame after AgentStarted already sees entry');
                }
                ++$settingsSeen;
            },
        );
        Hilos::$cluster->registerProtectedModeSettingsSink($settings);
        $settingsWire = new WorkerProtectedModeSettingsDTO(true)->toJson() . "\n";
        $wire = str_repeat($settingsWire, 10)
            . new WorkerAgentStartedDTO('regular', 'regular')->toJson() . "\n"
            . str_repeat($settingsWire, 130);
        self::assertSame(strlen($wire), socket_write($peer, $wire));
        $client->read();
        self::assertSame(10, $enteredAfter);
        self::assertLessThan(140, $settingsSeen, 'the parser keeps its bounded tail');
        $this->pump();
        self::assertSame(140, $settingsSeen);
        self::assertSame(2, $this->server->startingReads, 'only admission and the actual report scan the roster');
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** Failed/stopped reports also release before a later frame or an outer master iteration. */
    #[DataProvider('entryEndReports')]
    public function testActualEndReportReleasesEntryWithoutTheNextFrame(WorkerDTO $report): void
    {
        [$client, $peer] = $this->worker(1);
        $this->server->request('regular');
        $entries = 0;
        $gate = $this->entryGate(static function () use (&$entries): void {
            ++$entries;
        });
        $gate->requestEnter($this->entryRequest());
        self::assertSame(0, $entries);
        $this->send($peer, $report);
        $client->read();
        self::assertSame(1, $entries);
        self::assertFalse($this->agents->hasAgent('regular'));
        $this->pump();
        self::assertSame(1, $entries);
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** @return iterable<string, array{WorkerDTO}> Real downstream worker outcomes */
    public static function entryEndReports(): iterable
    {
        yield 'failed' => [new WorkerAgentStartFailedDTO('regular', 'regular', reason: 'start refused')];
        yield 'stopped' => [new WorkerAgentStoppedDTO('regular')];
    }

    /** Worker wait to linked is still starting; only the actual report settles the predicate. */
    public function testEntryGateWaitsAcrossActualWorkerSeatingUntilStartReport(): void
    {
        $this->server->request('mono_a');
        $this->pump();
        $entries = 0;
        $gate = $this->entryGate(static function () use (&$entries): void {
            ++$entries;
        });
        $gate->requestEnter($this->entryRequest());
        $deadline = new ReflectionProperty(ProtectedModeEntryGate::class, 'pendingDeadline')->getValue($gate);
        [$client, $peer] = $this->worker(1, true);
        $this->pump();
        self::assertSame(['mono_a'], $this->server->agentsStillStarting());
        self::assertFalse($this->server->isAgentAwaitingWorker('mono_a'));
        self::assertSame(0, $entries);
        self::assertSame($deadline, new ReflectionProperty(ProtectedModeEntryGate::class, 'pendingDeadline')->getValue($gate));
        self::assertFalse($this->loop->hasReadyWork());
        $this->send($peer, new WorkerAgentStartedDTO('mono_a', 'mono_a'));
        $client->read();
        self::assertSame(1, $entries);
    }

    /** Owner removal and exact worker loss wake native checks without relying on a worker report. */
    #[DataProvider('entryOwnerDepartures')]
    public function testOwnerDepartureSettlesEntryWithoutAnotherSocketInput(string $departure): void
    {
        $this->worker(1);
        $this->server->request('regular');
        $entries = 0;
        $gate = $this->entryGate(static function () use (&$entries): void {
            ++$entries;
        });
        $gate->requestEnter($this->entryRequest());
        if ($departure === 'remove') {
            $this->agents->removeAgent('regular');
        } elseif ($departure === 'unlink') {
            $this->server->dropClient($this->clients[0]);
        } else {
            $this->agents->forgetAgentsOfWorker(1, false);
        }
        self::assertSame(0, $entries, 'the mutation cues, rather than entering inside the owner');
        $this->pump();
        self::assertSame(1, $entries);
        self::assertSame([], $this->server->agentsStillStarting());
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** @return iterable<string, array{string}> Actual roster owners' departure paths */
    public static function entryOwnerDepartures(): iterable
    {
        yield 'remove' => ['remove'];
        yield 'unlink' => ['unlink'];
        yield 'worker loss' => ['loss'];
    }

    /** A canceled or refused worker wait has no future AgentStarted; its real removal wakes entry. */
    #[DataProvider('entryWorkerWaitEnds')]
    public function testWorkerWaitEndSettlesEntryWithoutAFutureStartReport(bool $cancel): void
    {
        $this->server->request('mono_a');
        $this->pump();
        $entries = 0;
        $gate = $this->entryGate(static function () use (&$entries): void {
            ++$entries;
        });
        $gate->requestEnter($this->entryRequest());
        if ($cancel) {
            $this->server->cancel('mono_a');
        } else {
            // Bring this wait's own deadline to now without shortening the production span.
            $expired = new AwaitingWorkerAgent('mono_a', 'mono_a', null, false, hrtime(true) / 1e9 - 1.0, true);
            new ReflectionProperty(WorkerServer::class, 'agentsAwaitingWorker')->setValue($this->server, ['mono_a' => $expired]);
            $this->timer()->armAt($expired->deadline);
        }
        $this->pump();
        self::assertSame(1, $entries);
        self::assertFalse($this->agents->hasAgent('mono_a'));
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** @return iterable<string, array{bool}> Worker wait's independent end conditions */
    public static function entryWorkerWaitEnds(): iterable
    {
        yield 'canceled' => [true];
        yield 'refused' => [false];
    }

    /** An existing unlinked orphan never joins the starting predicate. */
    public function testEntryIgnoresAnUnlinkedOrphanAndIdleFactsDoNoRosterScan(): void
    {
        $this->agents->addAgent('orphan', new QueueLoopAgent(false, false, null), 0, false);
        $entries = 0;
        $gate = $this->entryGate(static function () use (&$entries): void {
            ++$entries;
        });
        $gate->requestEnter($this->entryRequest());
        self::assertSame(1, $entries);
        self::assertSame(1, $this->server->startingReads);
        $this->agents->removeAgent('orphan');
        $gate->noteRosterChanged();
        $this->pump();
        self::assertSame(1, $this->server->startingReads);
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** Replay attempts and empty completion cue the gate even when no later start report exists. */
    #[DataProvider('entryReplayWithoutReport')]
    public function testReplayCompletionCuesEntryWithoutTakingTheIndependentHoldCallback(?string $type): void
    {
        $this->worker(1, true);
        $this->server->request('mono_a');
        $entries = 0;
        $gate = $this->entryGate(static function () use (&$entries): void {
            ++$entries;
        });
        $gate->requestEnter($this->entryRequest());
        $facts = [];
        $this->server->setAgentHoldWake(static function (?string $agentId) use (&$facts): void {
            $facts[] = $agentId;
        });
        if ($type !== null) {
            new ReflectionProperty(WorkerServer::class, 'protectedModeStoppedAgents')->setValue(
                $this->server, [new FrozenAgentPlacement(new AgentId($type), null)],
            );
        }
        $this->server->resumeAgentsForProtectedMode();
        $this->pump();
        self::assertSame($type === null ? [null] : [$type, null], $facts);
        self::assertSame(2, $this->server->startingReads, 'attempt and completion coalesce into one actual-fact check');
        self::assertSame(0, $entries, 'an unrelated linked start remains pending after replay completion');
        self::assertFalse($this->loop->hasReadyWork());
        $this->agents->removeAgent('mono_a');
        $this->pump();
        self::assertSame(1, $entries);
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** @return iterable<string, array{?string}> Completion alone, a quiet skip, and a failed replay */
    public static function entryReplayWithoutReport(): iterable
    {
        yield 'empty' => [null];
        yield 'skipped' => [HilosAgentType::HILOS_PROBE_DB];
        yield 'refused' => ['regular'];
    }

    /** Repeated reports for an already-started agent do not poll a different held start. */
    public function testRepeatedStartedReportDoesNotScanAnUnchangedStartingBasis(): void
    {
        [$client, $peer] = $this->worker(1);
        $this->server->request('regular', 'one');
        $this->server->request('regular', 'two');
        $this->send($peer, new WorkerAgentStartedDTO('regular:one', 'regular', 'one'));
        $client->read();
        $entries = 0;
        $gate = $this->entryGate(static function () use (&$entries): void {
            ++$entries;
        });
        $gate->requestEnter($this->entryRequest());
        $this->pump();
        for ($index = 0; $index < 3; ++$index) {
            $this->send($peer, new WorkerAgentStartedDTO('regular:one', 'regular', 'one'));
            $client->read();
        }
        self::assertSame(0, $entries);
        self::assertSame(1, $this->server->startingReads);
        self::assertFalse($this->loop->hasReadyWork());
        $this->send($peer, new WorkerAgentStartedDTO('regular:two', 'regular', 'two'));
        $client->read();
        self::assertSame(1, $entries);
        self::assertSame(2, $this->server->startingReads);
    }

    /** Acceptance before the ask must not turn the default identity into a linked worker seat. */
    public function testAcceptedChannelWaitsForRegistrationThenStartsAndStopsOnItsActualSeat(): void
    {
        $process = $this->process(8);
        [$client, $peer] = $this->acceptedWorker();
        self::assertFalse($client->isRegistered());
        self::assertSame(0, $client->getWorkerIndex());
        self::assertFalse($client->isMonopolistic());
        $this->pump();
        $before = $process->ticks;
        $started = $this->createMock(AgentStartSink::class);
        $started->expects(self::once())->method('onAgentStarted')->with('regular')->willReturnCallback(function (): void {
            self::assertTrue($this->agents->isAgentStarted('regular'));
            self::assertSame(8, $this->server->placedWorkerId('regular', null));
        });
        $this->agents->registerAgentStartSink($started);

        $this->server->ensureAgentUp('regular', null);
        self::assertSame([], $this->frames($client), 'an accepted placeholder must receive no start');
        self::assertFalse($this->agents->getAgent('regular')->hasWorkerClient());
        self::assertNull($this->server->placedWorkerId('regular', null));
        self::assertFalse($this->agents->isAgentStarted('regular'));
        $wait = $this->waits()['regular'];
        $this->pump();
        self::assertSame([], $this->peerFrames($peer));
        self::assertSame($wait->deadline, $this->timer()->getDeadline());
        $this->server->ensureAgentUp('regular', null);
        $this->pump();
        self::assertSame($wait, $this->waits()['regular']);
        self::assertSame($wait->deadline, $this->timer()->getDeadline());
        self::assertSame(0, $this->server->workerOrders);
        self::assertCount(1, new ReflectionProperty(WorkerServer::class, 'workers')->getValue($this->server));
        self::assertFalse($this->loop->hasReadyWork());

        $this->send($peer, new WorkerRegisterDTO(8, false));
        $this->pump();
        self::assertSame([new WorkerRegisteredDTO(8, false)->toArray(), new AgentStartDTO('regular')->toArray()],
            $this->peerFrames($peer));
        self::assertSame([], $this->waits());
        self::assertNull($this->timer()->getDeadline());
        self::assertNull(new ReflectionProperty(WorkerClient::class, 'registrationTimer')->getValue($client));
        self::assertSame($client, $this->agents->getAgent('regular')->getWorkerClient());
        self::assertSame(8, $this->agents->getAgentWorkerId('regular'));
        self::assertFalse($this->agents->isAgentStarted('regular'));
        self::assertSame($before, $process->ticks);
        self::assertFalse($this->loop->hasReadyWork());

        $this->send($peer, new WorkerAgentStartedDTO('regular', 'regular'));
        $this->pump();
        self::assertTrue($this->agents->isAgentStarted('regular'));
        self::assertSame($client, $this->agents->getAgent('regular')->getWorkerClient());
        self::assertSame(8, $this->server->placedWorkerId('regular', null));
        [$neighbor] = $this->worker(9);

        $logFile = tempnam(sys_get_temp_dir(), 'hilos-registration-stop-');
        self::assertNotFalse($logFile);
        $previousLogFile = new ReflectionProperty(Logger::class, 'logFile')->getValue();
        $previousShowLogLevel = new ReflectionProperty(Logger::class, 'showLogLevel')->getValue();
        $previousWriteLevel = Logger::writeLevel();
        Logger::setLogFile($logFile);
        Logger::setShowLogLevel(true);
        Logger::setWriteLevel(LogLevel::Info);
        try {
            $this->server->stopAgentsForProtectedMode('initiator', null);
            $this->pump();
            self::assertSame([new AgentStopDTO('regular')->toArray()], $this->peerFrames($peer));
            self::assertSame([], $this->frames($neighbor));
            self::assertTrue($client->awaitsStopReport('regular'));
            self::assertFalse($this->agents->hasAgent('regular'));
            self::assertStringNotContainsString("Agent 'regular' stopped", file_get_contents($logFile));
            $this->send($peer, new WorkerAgentStoppedDTO('regular'));
            $this->pump();
            self::assertFalse($client->awaitsStopReport('regular'));
            $stopLog = file_get_contents($logFile);
            self::assertSame(1, preg_match_all("/\\[INFO\\] Agent 'regular' stopped on worker #8\\n/", $stopLog));
            $this->send($peer, new WorkerAgentStoppedDTO('regular'));
            $this->pump();
            self::assertSame($stopLog, file_get_contents($logFile));
            self::assertSame($before, $process->ticks);
            self::assertFalse($this->loop->hasReadyWork());
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

    /** Unknown kind cannot take a regular start; a registered dedicated seat still serves one FIFO waiter. */
    public function testAcceptedFutureMonopolisticChannelKeepsRegularWaitAndDedicatedOccupancy(): void
    {
        $process = $this->process(9);
        [$mono, $monoPeer] = $this->acceptedWorker();
        $this->pump();
        $this->server->request('regular');
        self::assertSame([], $this->frames($mono));
        $regular = $this->waits()['regular'];
        $this->server->request('mono_a');
        $this->server->request('mono_b');
        $this->pump();
        self::assertSame(2, $this->server->workerOrders);
        $before = $process->ticks;
        $this->send($monoPeer, new WorkerRegisterDTO(8, true));
        $this->pump();
        self::assertSame([new WorkerRegisteredDTO(8, true)->toArray(), new AgentStartDTO('mono_a')->toArray()],
            $this->peerFrames($monoPeer));
        self::assertSame($mono, $this->agents->getAgent('mono_a')->getWorkerClient());
        self::assertTrue($this->server->isAgentAwaitingWorker('mono_b'));
        self::assertSame($regular, $this->waits()['regular']);
        self::assertSame($regular->deadline, $this->timer()->getDeadline());
        self::assertFalse($this->loop->hasReadyWork());

        [$plain, $plainPeer] = $this->acceptedWorker();
        $this->send($plainPeer, new WorkerRegisterDTO(9, false));
        $this->pump();
        self::assertSame([new WorkerRegisteredDTO(9, false)->toArray(), new AgentStartDTO('regular')->toArray()],
            $this->peerFrames($plainPeer));
        self::assertSame($plain, $this->agents->getAgent('regular')->getWorkerClient());
        self::assertTrue($this->server->isAgentAwaitingWorker('mono_b'));
        $this->send($monoPeer, new WorkerAgentStoppedDTO('mono_a'));
        $this->pump();
        self::assertSame([new AgentStartDTO('mono_b')->toArray()], $this->peerFrames($monoPeer));
        self::assertSame($mono, $this->agents->getAgent('mono_b')->getWorkerClient());
        self::assertSame([], $this->waits());
        self::assertSame(2, $this->server->workerOrders);
        self::assertSame($before, $process->ticks);
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** Preference passes through the same eligibility gate; a registered zero index remains a valid fallback. */
    #[DataProvider('ineligiblePreferredWorkers')]
    public function testIneligiblePreferredChannelFallsBackBeforeItsDropPortion(bool $closing): void
    {
        [$preferred] = $this->worker(8, false, $closing);
        $preferred->setWorkerIndex(8);
        [$live] = $this->worker(0);
        if ($closing) {
            $preferred->markShouldClose();
        }
        self::assertContains($preferred, $this->server->getClients());
        new ReflectionMethod(WorkerServer::class, 'startAgentInternal')->invoke($this->server, 'regular', null, false, 8);
        self::assertSame([], $this->frames($preferred));
        self::assertSame([new AgentStartDTO('regular')->toArray()], $this->frames($live));
        self::assertSame($live, $this->agents->getAgent('regular')->getWorkerClient());
        self::assertSame(0, $this->server->placedWorkerId('regular', null));
    }

    /** @return iterable<string, array{bool}> Eligibility facts before any queued close is serviced */
    public static function ineligiblePreferredWorkers(): iterable
    {
        yield 'unregistered known index' => [false];
        yield 'registered closing' => [true];
    }

    /** A real registration releases the already parked start while the process sweep stays idle. */
    public function testActualRegistrationAfterInitialReadySeatsWaitsWithoutProcessSweep(): void
    {
        $process = $this->process(1);
        $this->server->request('regular');
        $wait = $this->waits()['regular'];
        $this->pump();
        self::assertSame($wait->deadline, $this->timer()->getDeadline());
        $before = $process->ticks;
        $this->server->request('regular');
        self::assertSame($wait, $this->waits()['regular']);
        self::assertFalse($this->loop->hasReadyWork());

        [$client, $peer] = $this->worker(1, false, false);
        $this->send($peer, new WorkerRegisterDTO(1));
        $this->pump();

        self::assertFalse($this->server->isAgentAwaitingWorker('regular'));
        self::assertSame($client, $this->agents->getAgent('regular')->getWorkerClient());
        self::assertSame($before, $process->ticks);
        self::assertNull($this->timer()->getDeadline());
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** A concrete expired wait is refused by its native deadline, with repeated asks retaining that wait. */
    public function testBlockedWaitSleepsAndNativePastDeadlineRefusesItWithoutRenewingTheAsk(): void
    {
        $this->server->request('mono_a');
        $this->pump();
        $old = $this->waits()['mono_a'];
        $expired = new AwaitingWorkerAgent('mono_a', 'mono_a', null, false, hrtime(true) / 1e9 - 1.0, true);
        new ReflectionProperty(WorkerServer::class, 'agentsAwaitingWorker')->setValue($this->server, ['mono_a' => $expired]);
        $this->timer()->armAt($expired->deadline);
        $this->server->request('mono_a');
        self::assertSame($expired, $this->waits()['mono_a']);
        self::assertGreaterThan($expired->deadline, $old->deadline);
        $this->pump();

        self::assertFalse($this->agents->hasAgent('mono_a'));
        self::assertFalse($this->server->isAgentAwaitingWorker('mono_a'));
        self::assertNull($this->timer()->getDeadline());
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** Old due tickets cannot survive rebind, which also retains shutdown cancellation work. */
    public function testRebindKeepsTheOriginalDeadlineAndShutdownRebindStillCancelsWaits(): void
    {
        $this->server->request('mono_a');
        $this->pump();
        $wait = $this->waits()['mono_a'];
        $oldTimer = $this->timer();
        $oldTimer->armAt(hrtime(true) / 1e9 - 1.0);
        $this->server->attachEventLoop($this->loop);
        $this->pump();
        self::assertSame($wait, $this->waits()['mono_a']);
        self::assertSame($wait->deadline, $this->timer()->getDeadline());
        self::assertNull($oldTimer->getDeadline());
        self::assertFalse($this->loop->hasReadyWork());

        $this->server->prepareShutdown();
        $this->server->attachEventLoop($this->loop);
        $this->pump();
        self::assertSame([], $this->waits());
        self::assertNull($this->timer()->getDeadline());
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** A start append cancels and replaces another wait while its old probe is in flight. */
    public function testCancelAndReenqueueDuringASeatCannotLoseTheNewGeneration(): void
    {
        $this->server->request('mono_a');
        $this->server->request('mono_b');
        $this->pump();
        $old = $this->waits()['mono_b'];
        [$client, $peer] = $this->worker(1, true);
        $client->afterStart = function () use ($client): void {
            $client->afterStart = null;
            $this->server->cancel('mono_b');
            $this->server->request('mono_b');
        };
        $this->pump();
        self::assertNotSame($old, $this->waits()['mono_b']);
        self::assertFalse($this->loop->hasReadyWork());
        $this->send($peer, new WorkerAgentStoppedDTO('mono_a'));
        $this->pump();
        self::assertSame([], $this->waits());
        $starts = array_values(array_filter($this->frames($client),
            static fn(array $frame): bool => $frame[WorkerDTO::TYPE] === AgentStartDTO::MESSAGE_TYPE));
        self::assertSame(['mono_a', 'mono_b'], array_column($starts, 'agentId'));
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** One registration seats the first request; the real departure releases the next request. */
    public function testFifoOneMonopolisticSeatAndActualRosterDepartureReleasesTheNextWait(): void
    {
        $this->server->request('mono_a');
        $this->server->request('mono_b');
        $this->pump();
        [$client, $peer] = $this->worker(1, true);
        $this->pump();
        self::assertTrue($this->agents->getAgent('mono_a')->hasWorkerClient());
        self::assertTrue($this->server->isAgentAwaitingWorker('mono_b'));
        self::assertFalse($this->loop->hasReadyWork());

        $this->send($peer, new WorkerAgentStoppedDTO('mono_a'));
        $this->pump();
        self::assertSame($client, $this->agents->getAgent('mono_b')->getWorkerClient());
        self::assertFalse($this->server->isAgentAwaitingWorker('mono_b'));
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** Free capacity of the wrong kind cannot keep an unfinished blocked probe runnable. */
    public function testLargeBlockedQueueSleepsImmediatelyWhenOnlyTheOtherWorkerKindIsFree(): void
    {
        $this->process(1);
        for ($index = 0; $index < 130; ++$index) {
            $this->server->request('regular', (string)$index);
        }
        $this->pump();
        $this->worker(2, true);
        $this->loop->tick();
        self::assertCount(130, $this->waits());
        self::assertFalse($this->loop->hasReadyWork());
        self::assertNotNull($this->timer()->getDeadline());
    }

    /** Another ready owner observes the unfinished FIFO between bounded seating portions. */
    public function testLargeRegularFifoYieldsAndDoesNotExecuteACanceledGeneration(): void
    {
        $this->process(1);
        for ($index = 0; $index < 130; ++$index) {
            $this->server->request('regular', (string)$index);
        }
        $this->pump();
        [$client] = $this->worker(1);
        $this->server->cancel('regular', '64');
        $sent = [];
        $observer = $this->loop->createReadyWork(function () use (&$sent): void {
            $sent[] = count($this->waits());
        });
        $observer->markReady();
        $this->loop->tick();
        self::assertSame([65], $sent, 'the first portion seats 64 and cancellation removed one');
        self::assertTrue($this->loop->hasReadyWork());
        $this->pump();
        self::assertSame([], $this->waits());
        self::assertFalse($this->agents->hasAgent('regular:64'));
        $frames = $this->frames($client);
        $starts = array_values(array_filter($frames, static fn(array $frame): bool => $frame[WorkerDTO::TYPE] === AgentStartDTO::MESSAGE_TYPE));
        self::assertSame(array_map(static fn(int $i): string => 'regular:' . $i, [...range(0, 63), ...range(65, 129)]),
            array_column($starts, 'agentId'));
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** A matching report ends the wait even after roster removal and repeats the current refusal gate. */
    public function testStopReportAfterRosterRemovalWakesOnlyItsExactLinkAndRevalidatesTheGate(): void
    {
        [$old, $peer] = $this->worker(1);
        $this->server->request('regular');
        $this->server->cancel('regular');
        $this->server->request('regular');
        $this->pump();
        self::assertFalse($this->agents->hasAgent('regular'));
        self::assertTrue($this->server->isAgentAwaitingStopReport('regular'));
        self::assertFalse($this->loop->hasReadyWork());
        [$replacement] = $this->worker(1);
        $this->pump();
        $this->server->noteWorkerStopReport($replacement, 'regular');
        self::assertFalse($this->loop->hasReadyWork());
        $this->server->refuseRtClaim('regular', null);
        $this->send($peer, new WorkerAgentStoppedDTO('regular'));
        $this->pump();
        self::assertFalse($old->awaitsStopReport('regular'));
        self::assertFalse($this->server->isAgentAwaitingStopReport('regular'));
        self::assertFalse($this->agents->hasAgent('regular'));
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** A reused worker id keeps the replacement report obligation independent of the old link. */
    public function testOldLinkCloseReleasesStartEvenWhenReplacementIdStillOwesAReport(): void
    {
        [$old] = $this->worker(1);
        $this->server->request('regular');
        $this->server->cancel('regular');
        $this->server->request('regular');
        [$replacement, $replacementPeer] = $this->worker(1);
        $replacement->sendAgentStop('regular');
        $this->server->dropClient($old);
        $this->pump();
        self::assertTrue($this->server->isAgentAwaitingStopReport('regular'));
        $this->server->noteWorkerStopReport($old, 'regular');
        self::assertFalse($this->loop->hasReadyWork());
        $this->send($replacementPeer, new WorkerAgentStoppedDTO('regular'));
        $this->pump();
        self::assertSame($replacement, $this->agents->getAgent('regular')->getWorkerClient());
        self::assertFalse($this->server->isAgentAwaitingStopReport('regular'));
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** Canceled starts stay absent after their real releasing facts arrive. */
    public function testCancellationFreezeAndShutdownInvalidateReleasedStartsAndDeadlines(): void
    {
        $this->server->request('mono_a');
        $this->server->request('mono_b');
        $this->pump();
        $this->server->noteWorkerCapacityChanged();
        $this->server->stopAgentsForProtectedMode('initiator', null);
        $this->pump();
        self::assertSame([], $this->waits());
        self::assertNull($this->timer()->getDeadline());
        self::assertFalse($this->loop->hasReadyWork());

        [$old, $peer] = $this->worker(1);
        $this->server->request('regular');
        $this->server->cancel('regular');
        $this->server->request('regular');
        $this->send($peer, new WorkerAgentStoppedDTO('regular'));
        $old->read();
        $this->server->cancel('regular');
        $this->pump();
        self::assertFalse($this->agents->hasAgent('regular'));
        self::assertFalse($this->server->isAgentAwaitingStopReport('regular'));

        $this->server->request('mono_a');
        $this->server->prepareShutdown();
        $this->pump();
        self::assertSame([], $this->waits());
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** @return array<string, array{bool, bool}> Journal start reports and late first-wave registration */
    public static function journalReadiness(): array
    {
        return [
            'started' => [true, true],
            'starting' => [false, true],
            'started with late first-wave registration' => [true, false],
            'starting with late first-wave registration' => [false, false],
        ];
    }

    /**
     * @param bool $journalReady Whether the journal has already reported its start
     * @param bool $plainRegistered Whether the first wave's connection has already registered
     */
    #[DataProvider('journalReadiness')]
    public function testDispatchBarrierAndFinalInputPrecedeStopAndOnlyReportAllowsSigterm(bool $journalReady, bool $plainRegistered): void
    {
        $plainProcess = $this->process(1);
        $journalProcess = $this->process(2, true);
        [$plain, $plainPeer] = $this->worker($plainRegistered ? 1 : 0, false, $plainRegistered);
        [$journal, $journalPeer] = $this->worker(2, true);
        $this->server->request(HilosAgentType::HILOS_ANALYTICS_JOURNAL);
        if ($journalReady) {
            $this->send($journalPeer, new WorkerAgentStartedDTO(HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosAgentType::HILOS_ANALYTICS_JOURNAL));
        }
        $this->pump();
        $this->clearFrames($journal);
        Hilos::$ac = new AnalyticsCollector();
        Hilos::$ac->openWsConnection('final-connection', null);
        $this->server->prepareShutdown();
        $plainProcess->running = false;
        posix_kill(getmypid(), SIGCHLD);
        $this->pump();
        self::assertNull($this->server->pendingStopDispatch(), 'exited process still has an open final link');
        self::assertSame(0, $journalProcess->stops);

        if (!$plainRegistered) {
            $this->send($plainPeer, new WorkerRegisterDTO(1));
        }
        $this->send($plainPeer, new WorkerAgentMessageDTO(null, new SignalDTO(
            new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName('final-worker-batch'), new SignalData(['sequence' => 1]),
        )));
        socket_shutdown($plainPeer, 1);
        $this->pump();
        self::assertNotNull($this->server->pendingStopDispatch());
        self::assertNull($plain->getSocket());
        $this->pump();
        self::assertFalse($this->loop->hasReadyWork(), 'a dispatch wait is blocked');
        self::assertSame([], $this->frames($journal));
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->daemon);
        $this->pump();
        if (!$journalReady) {
            self::assertSame([], $this->frames($journal));
            self::assertNotNull($this->server->pendingStopDispatch());
            self::assertFalse($this->loop->hasReadyWork());
            $this->send($journalPeer, new WorkerAgentStartedDTO(HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosAgentType::HILOS_ANALYTICS_JOURNAL));
            $this->pump();
            self::assertSame([], $this->frames($journal), 'a start report still owes the real dispatch');
            new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->daemon);
            $this->pump();
        }
        $frames = $this->frames($journal);
        self::assertSame(['daemon_agent_message', 'daemon_agent_message', 'agent_stop'], array_column($frames, 'type'));
        self::assertSame(['final-worker-batch', HilosSignalConstants::ANALYTICS_JOURNAL_APPEND], array_map(
            static fn(array $frame): string => $frame['signal']['signalName'], array_slice($frames, 0, 2),
        ));
        self::assertSame(0, $journalProcess->stops);
        self::assertFalse($this->agents->hasAgent(HilosAgentType::HILOS_ANALYTICS_JOURNAL));
        self::assertTrue($journal->awaitsStopReport(HilosAgentType::HILOS_ANALYTICS_JOURNAL));
        $this->pump();
        self::assertSame(0, $journalProcess->stops);
        self::assertFalse($this->loop->hasReadyWork());
        $this->send($journalPeer, new WorkerAgentStoppedDTO(HilosAgentType::HILOS_ANALYTICS_JOURNAL));
        $this->pump();
        self::assertSame(1, $journalProcess->stops);
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** A retired child remains a blocker, and old barrier generations cannot stop a replacement. */
    public function testRetiredFirstWaveAndRebindDoNotReleaseStaleDispatchOrReplacementProcess(): void
    {
        $plain = $this->process(1);
        $held = $this->process(2, true);
        $this->worker(2, true);
        $this->server->request(HilosAgentType::HILOS_ANALYTICS_JOURNAL);
        $this->server->prepareShutdown();
        $plain->fail = true;
        posix_kill(getmypid(), SIGCHLD);
        $this->pump();
        self::assertNull($this->server->pendingStopDispatch());
        self::assertSame(0, $held->stops);
        self::assertFalse($this->loop->hasReadyWork());
        $plain->running = false;
        posix_kill(getmypid(), SIGCHLD);
        $this->pump();
        $barrier = $this->server->pendingStopDispatch();
        self::assertNotNull($barrier);
        $this->server->attachEventLoop($this->loop);
        $this->server->completeStopDispatch($barrier);
        $this->pump();
        self::assertNotNull($this->server->pendingStopDispatch());
        $replacement = $this->process(2, true);
        $journal = $this->server->getClients()[0];
        $this->server->dropClient($journal);
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->daemon);
        $this->pump();
        self::assertSame(0, $replacement->stops);
        self::assertSame(0, $held->stops);
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** The enqueue ticket wakes the poll but cannot deliver ahead of the manager's dispatch phase. */
    public function testMasterQueueBindingSeesPreLoopInputAndDoesNotDispatchInTheReadyCallback(): void
    {
        $this->queueFinalSignal(Hilos::$sr, 'before-loop-binding');
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->daemon);
        self::assertTrue($this->loop->hasReadyWork());
        $this->pump();
        self::assertSame(1, Hilos::$sr->getQueuedSignalCount());
        self::assertFalse($this->loop->hasReadyWork());
        self::assertFalse($this->agents->hasAgent(HilosAgentType::HILOS_ANALYTICS_JOURNAL));

        $this->queueFinalSignal(Hilos::$sr, 'after-loop-binding');
        self::assertTrue($this->loop->hasReadyWork());
        $this->pump();
        self::assertSame(2, Hilos::$sr->getQueuedSignalCount());
        self::assertSame('before-loop-binding', Hilos::$sr->getNextQueuedSignal()?->signalName->getName());
        self::assertSame('after-loop-binding', Hilos::$sr->getNextQueuedSignal()?->signalName->getName());
    }

    /** A replaced facade cannot leave the old incarnation waking this master's loop. */
    public function testMasterRebindDetachesTheExactOldRouterAndKeepsQueuedInput(): void
    {
        $oldRouter = Hilos::$sr;
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->daemon);
        $this->queueFinalSignal($oldRouter, 'old-binding');
        Hilos::$sr = new SignalRouter();
        $this->queueFinalSignal(Hilos::$sr, 'before-rebind');

        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->daemon);
        self::assertTrue($this->loop->hasReadyWork());
        $this->pump();
        $this->queueFinalSignal($oldRouter, 'old-router-after-rebind');
        self::assertFalse($this->loop->hasReadyWork());
        $this->queueFinalSignal(Hilos::$sr, 'new-binding');
        self::assertTrue($this->loop->hasReadyWork());

        new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->daemon);
        self::assertFalse($this->loop->hasReadyWork());
        $this->queueFinalSignal(Hilos::$sr, 'after-detach');
        self::assertFalse($this->loop->hasReadyWork());
        self::assertSame(2, $oldRouter->getQueuedSignalCount());
        self::assertSame(3, Hilos::$sr->getQueuedSignalCount());
    }

    /** With analytics absent, FIRST_GONE has no enqueue to wake the dispatch completion owner. */
    public function testAnEmptyStopBarrierWakesItsOwnerAndRebindObservesTheOpenBarrier(): void
    {
        $plain = $this->process(1);
        $journalProcess = $this->process(2, true);
        [$journal, $peer] = $this->worker(2, true);
        $this->server->request(HilosAgentType::HILOS_ANALYTICS_JOURNAL);
        $this->send($peer, new WorkerAgentStartedDTO(HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            HilosAgentType::HILOS_ANALYTICS_JOURNAL));
        $this->pump();
        $this->clearFrames($journal);
        $completionCues = 0;
        $work = $this->loop->createReadyWork(static function () use (&$completionCues): void {
            $completionCues++;
        });
        $this->server->setStopDispatchWake($work->markReady(...));
        self::assertFalse($this->loop->hasReadyWork());

        $this->server->prepareShutdown();
        $plain->running = false;
        posix_kill(getmypid(), SIGCHLD);
        $this->pump();
        self::assertSame(1, $completionCues);
        self::assertFalse(Hilos::$sr->hasQueuedSignals());
        self::assertNotNull($this->server->pendingStopDispatch());
        self::assertSame([], $this->frames($journal));
        self::assertSame(0, $journalProcess->stops);
        self::assertFalse($this->loop->hasReadyWork(), 'an open barrier alone is blocked');

        $this->server->attachEventLoop($this->loop);
        $this->pump();
        self::assertSame(2, $completionCues);
        self::assertNotNull($this->server->pendingStopDispatch());
        self::assertSame([], $this->frames($journal));
        $this->server->setStopDispatchWake(null);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->daemon);
        self::assertTrue($this->loop->hasReadyWork(), 'late master binding must see the empty open barrier');
        $this->pump();
        self::assertNotNull($this->server->pendingStopDispatch());
        self::assertSame([], $this->frames($journal));

        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->daemon);
        $this->pump();
        self::assertNull($this->server->pendingStopDispatch());
        self::assertSame(['agent_stop'], array_column($this->frames($journal), 'type'));
        self::assertSame(0, $journalProcess->stops, 'dispatch completion still awaits the actual stop report');
        $this->send($peer, new WorkerAgentStoppedDTO(HilosAgentType::HILOS_ANALYTICS_JOURNAL));
        $this->pump();
        self::assertSame(1, $journalProcess->stops);
        self::assertFalse($this->loop->hasReadyWork());
        $work->close();
    }

    /** A captured final queue is finite even while new envelopes keep arriving on the same router. */
    public function testCapturedStopPrefixPrecedesAgentStopUnderContinuousLaterIntake(): void
    {
        $plain = $this->process(1);
        $this->process(2, true);
        [$journal, $peer] = $this->worker(2, true);
        $this->server->request(HilosAgentType::HILOS_ANALYTICS_JOURNAL);
        $this->send($peer, new WorkerAgentStartedDTO(HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            HilosAgentType::HILOS_ANALYTICS_JOURNAL));
        $this->pump();
        $this->clearFrames($journal);
        $this->server->prepareShutdown();
        $plain->running = false;
        posix_kill(getmypid(), SIGCHLD);
        $this->pump();
        for ($index = 0; $index < 130; $index++) {
            $this->queueFinalSignal(Hilos::$sr, 'captured-' . $index);
        }
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $dispatch->invoke($this->daemon);
        $this->pump();
        self::assertNotNull($this->server->pendingStopDispatch());
        self::assertNotContains('agent_stop', array_column($this->frames($journal), 'type'));
        for ($portion = 0; $portion < 130 && $this->server->pendingStopDispatch() !== null; $portion++) {
            for ($index = 0; $index < 20; $index++) {
                $this->queueFinalSignal(Hilos::$sr, 'later-' . $portion . '-' . $index);
            }
            $dispatch->invoke($this->daemon);
            $this->pump();
        }
        self::assertNull($this->server->pendingStopDispatch());
        self::assertTrue(Hilos::$sr->hasQueuedSignals());
        $frames = $this->frames($journal);
        self::assertSame(array_map(static fn(int $index): string => 'captured-' . $index, range(0, 129)), array_map(
            static fn(array $frame): string => $frame['signal']['signalName'], array_slice($frames, 0, 130),
        ));
        self::assertSame('agent_stop', $frames[array_key_last($frames)]['type']);
    }

    /** A report releasing more than one cohort portion cannot remove the held journal's debt. */
    public function testALargeHeldJournalReportFinishesEveryOriginalDeliveryBeforeStop(): void
    {
        $plain = $this->process(1);
        $this->process(2, true);
        [$journal, $peer] = $this->worker(2, true);
        $this->server->request(HilosAgentType::HILOS_ANALYTICS_JOURNAL);
        $this->clearFrames($journal);
        for ($index = 0; $index < 130; $index++) {
            $this->queueFinalSignal(Hilos::$sr, 'held-' . $index);
        }
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $runnable = new ReflectionMethod(DaemonManager::class, 'hasRunnableSignalDispatch');
        for ($portion = 0; $portion < 1000 && $runnable->invoke($this->daemon); $portion++) {
            $dispatch->invoke($this->daemon);
        }
        $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($this->daemon);
        self::assertSame(130, $holds->getParkedCount());
        self::assertSame([], $this->frames($journal));
        $this->server->prepareShutdown();
        $plain->running = false;
        posix_kill(getmypid(), SIGCHLD);
        $this->pump();
        $dispatch->invoke($this->daemon);
        self::assertNotNull($this->server->pendingStopDispatch());

        $this->send($peer, new WorkerAgentStartedDTO(HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            HilosAgentType::HILOS_ANALYTICS_JOURNAL));
        $this->pump();
        self::assertGreaterThanOrEqual(66, $holds->getParkedCount(), 'The report itself has a finite mutation budget');
        $dispatch->invoke($this->daemon);
        $this->pump();
        self::assertNotNull($this->server->pendingStopDispatch());
        self::assertNotContains('agent_stop', array_column($this->frames($journal), 'type'));
        for ($portion = 0; $portion < 1000 && $this->server->pendingStopDispatch() !== null; $portion++) {
            $dispatch->invoke($this->daemon);
            $this->pump();
        }
        self::assertNull($this->server->pendingStopDispatch());
        self::assertSame(0, $holds->getParkedCount());
        $frames = $this->frames($journal);
        self::assertCount(131, $frames);
        self::assertSame(array_map(static fn(int $index): string => 'held-' . $index, range(0, 129)), array_map(
            static fn(array $frame): string => $frame['signal']['signalName'], array_slice($frames, 0, 130),
        ));
        self::assertSame('agent_stop', $frames[130]['type']);
    }

    public function testCanceledJournalHoldsRemainDispatchDebtUntilTheirPhysicalSettlement(): void
    {
        $journal = $this->openReadyJournalStopBarrier();
        $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($this->daemon);
        for ($index = 0; $index < 130; $index++) {
            $holds->add(new ParkedAgentSignal(new SignalDTO(new SignalSource(SignalSource::DAEMON),
                new SignalType(SignalTypeConstants::COMMAND_REQUEST), new SignalName('final-caller'),
                new CommandRequestDTO('final-caller', 'final:request')),
                HilosAgentType::HILOS_ANALYTICS_JOURNAL, 0.0, false));
        }
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $dispatch->invoke($this->daemon);
        self::assertNotNull($this->server->pendingStopDispatch());
        $this->daemon->onCommandAbandoned('final-caller');
        self::assertSame(66, $holds->getParkedCount());
        $dispatch->invoke($this->daemon);
        $this->pump();
        self::assertGreaterThan(0, $holds->getParkedCount());
        self::assertNotNull($this->server->pendingStopDispatch());
        self::assertSame([], $this->frames($journal));
        for ($portion = 0; $portion < 1000 && $this->server->pendingStopDispatch() !== null; $portion++) {
            $dispatch->invoke($this->daemon);
            $this->pump();
        }
        self::assertSame(0, $holds->getParkedCount());
        self::assertNull($this->server->pendingStopDispatch());
        self::assertSame(['agent_stop'], array_column($this->frames($journal), 'type'));
    }

    /** Failed accounting cannot acknowledge a prefix; successful later accounting can finish it. */
    public function testAccountingFailureWaitsForSuccessfulAccountingBeforeStopAcknowledgement(): void
    {
        $journal = $this->openReadyJournalStopBarrier();
        $memory = new UploadMemoryMaster('stop-accounting-failure');
        $memory->setTestLimit(0);
        $failure = new RuntimeException('accounting continuation failed');
        $memory->enqueueTest('local', 'probe', null, 1, static function () use ($failure): void {
            throw $failure;
        });
        $memory->setTestLimit(1);
        new ReflectionProperty(DaemonManager::class, 'uploadMemory')->setValue($this->daemon, $memory);
        try {
            new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->daemon);
            self::fail('The existing accounting failure must reach the manager guard');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $this->pump();
        self::assertNotNull($this->server->pendingStopDispatch());
        self::assertSame([], $this->frames($journal));
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->daemon);
        $this->pump();
        self::assertNull($this->server->pendingStopDispatch());
        self::assertSame(['agent_stop'], array_column($this->frames($journal), 'type'));
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testUnrelatedLaterFailureCannotPoisonAnEarlierPrefixButFailsItsOwnBrowserPrefix(): void
    {
        $journal = $this->openReadyJournalStopBarrier();
        $this->queueFinalSignal(Hilos::$sr, 'own-stop-frame');
        new ReflectionMethod(DaemonManager::class, 'captureSignalDispatchPrefixes')->invoke($this->daemon);
        $this->queueFinalSignal(Hilos::$sr, 'later-browser-frame');
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($this->daemon);
        $state->browsers = new ReflectionMethod(DaemonManager::class, 'newSignalDispatchPrefix')->invoke($this->daemon);
        $failure = new RuntimeException('unrelated later route failed');
        Hilos::$sr->failure = $failure;
        Hilos::$sr->failOnlySignal = 'later-browser-frame';
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        try {
            $dispatch->invoke($this->daemon);
            self::fail('The unrelated route must still reach the manager guard');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $this->pump();
        self::assertFalse($state->stop->failed);
        self::assertFalse($state->stop->complete);
        self::assertTrue($state->browsers->failed);
        self::assertFalse($state->browsers->complete);
        self::assertNotNull($this->server->pendingStopDispatch());
        self::assertSame(['own-stop-frame'], array_map(static fn(array $frame): string => $frame['signal']['signalName'],
            $this->frames($journal)));
        Hilos::$sr->failure = null;
        for ($portion = 0; $portion < 16 && $this->server->pendingStopDispatch() !== null; $portion++) {
            $dispatch->invoke($this->daemon);
            $this->pump();
        }
        self::assertTrue($state->stop->complete);
        self::assertNull($this->server->pendingStopDispatch());
        self::assertSame('agent_stop', $this->frames($journal)[1]['type']);
        self::assertTrue($state->browsers->failed);
        self::assertFalse($state->browsers->complete);
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** A failed route cannot turn into a successful empty-prefix acknowledgement on the next pass. */
    public function testRoutingFailurePermanentlyBlocksTheCapturedStopPrefix(): void
    {
        $journal = $this->openReadyJournalStopBarrier();
        $failure = new RuntimeException('route failed');
        Hilos::$sr->failure = $failure;
        $this->queueFinalSignal(Hilos::$sr, 'failed-prefix');
        try {
            new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->daemon);
            self::fail('A route failure must reach the manager guard');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        Hilos::$sr->failure = null;
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->daemon);
        $this->pump();
        self::assertNotNull($this->server->pendingStopDispatch());
        self::assertSame([], $this->frames($journal));
        self::assertFalse($this->loop->hasReadyWork());
    }

    /** @return QueueLoopWorkerClient Real held journal link after the first wave departed */
    private function openReadyJournalStopBarrier(): QueueLoopWorkerClient
    {
        $plain = $this->process(1);
        $this->process(2, true);
        [$journal, $peer] = $this->worker(2, true);
        $this->server->request(HilosAgentType::HILOS_ANALYTICS_JOURNAL);
        $this->send($peer, new WorkerAgentStartedDTO(HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            HilosAgentType::HILOS_ANALYTICS_JOURNAL));
        $this->pump();
        $this->clearFrames($journal);
        $this->server->prepareShutdown();
        $plain->running = false;
        posix_kill(getmypid(), SIGCHLD);
        $this->pump();
        self::assertNotNull($this->server->pendingStopDispatch());
        return $journal;
    }

    /** Wire the real master, native loop and queue owner without launching a daemon. */
    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousAnalytics = Hilos::$ac;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRt = Hilos::$rt;
        $this->previousApp = Hilos::appClass();
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = null;
        Hilos::$rt = null;
        Hilos::$ac = null;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, QueueLoopHilos::class);
        $this->daemon = new QueueLoopDaemon();
        $this->agents = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($this->daemon);
        $this->server = new QueueLoopServer();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this->server, $this->agents);
        foreach (['minRegular', 'maxRegular', 'minMonopolistic'] as $field) {
            new ReflectionProperty(WorkerServer::class, $field)->setValue($this->server, 0);
        }
        new ReflectionProperty(WorkerServer::class, 'cachedLogDirectory')->setValue($this->server, '/tmp');
        $this->loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->daemon, $this->loop);
        $this->daemon->registerServer($this->server);
        $this->server->attachEventLoop($this->loop);
        $this->pump();
        self::assertNotNull(Hilos::$sr->getNextQueuedSignal());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** Release every native binding before closing sockets and restoring the global context. */
    protected function tearDown(): void
    {
        new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->daemon);
        Hilos::$cluster?->protectedModeEntryGate()?->detachEventLoop();
        $this->server->detachEventLoop();
        foreach ($this->clients as $client) {
            $client->detachEventLoop();
            if ($client->getSocket() !== null) {
                $this->server->dropClient($client);
            }
        }
        foreach ($this->peers as $peer) {
            socket_close($peer);
        }
        if ($this->server->getSocket() !== null) {
            socket_close($this->server->getSocket());
        }
        $this->loop->close();
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        Hilos::$ac = $this->previousAnalytics;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRt;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
    }

    private function pump(): void
    {
        for ($portion = 0; $portion < 12; ++$portion) {
            $this->loop->tick();
        }
    }

    /**
     * @param Closure(): void $enter External switch effect observed by the scenario
     * @return ProtectedModeEntryGate Real gate registered over this worker server's domain getter
     */
    private function entryGate(Closure $enter): ProtectedModeEntryGate
    {
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerProtectedModeAgentFreezer($this->server);
        $switch = $this->createMock(ProtectedModeSwitch::class);
        $switch->method('requestEnable')->willReturnCallback($enter);
        Hilos::$cluster->registerProtectedMode($switch);
        $gate = new ProtectedModeEntryGate();
        Hilos::$cluster->registerProtectedModeEntryGate($gate);
        $gate->attachEventLoop($this->loop);
        return $gate;
    }

    /** @return ProtectedModeEnableSignalData Ordinary initiator door, unchanged wire payload */
    private function entryRequest(): ProtectedModeEnableSignalData
    {
        return new ProtectedModeEnableSignalData('restore', null, null, 'backup', null, null);
    }

    /**
     * @param SignalRouter $router Exact master or worker FIFO receiving the frame
     * @param string $name Frame's observable FIFO identity
     */
    private function queueFinalSignal(SignalRouter $router, string $name): void
    {
        $router->queueSignal(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName($name), new SignalData([]));
    }

    /** @return array<string, AwaitingWorkerAgent> Pending worker requests */
    private function waits(): array
    {
        return new ReflectionProperty(WorkerServer::class, 'agentsAwaitingWorker')->getValue($this->server);
    }

    /** @return EventLoopTimer The actual native deadline owner */
    private function timer(): EventLoopTimer
    {
        return new ReflectionProperty(WorkerServer::class, 'workerWaitTimer')->getValue($this->server);
    }

    /**
     * @param int $index Process slot
     * @param bool $mono Worker kind
     * @return QueueLoopChild Same-process exit facts
     */
    private function process(int $index, bool $mono = false): QueueLoopChild
    {
        $process = new QueueLoopChild();
        $rows = new ReflectionProperty(WorkerServer::class, 'workers');
        $workers = $rows->getValue($this->server);
        $workers[($mono ? WorkerConstants::TYPE_MONOPOLISTIC : WorkerConstants::TYPE_REGULAR) . ':' . $index] = [
            WorkerConstants::FIELD_WORKER_PROCESS => $process,
            WorkerConstants::FIELD_WORKER_INDEX => $index,
            WorkerConstants::FIELD_WORKER_TYPE => $mono ? WorkerConstants::TYPE_MONOPOLISTIC : WorkerConstants::TYPE_REGULAR,
        ];
        $rows->setValue($this->server, $workers);
        return $process;
    }

    /** @return array{WorkerClient, Socket} Actual accept with untouched pre-registration identity and native transport */
    private function acceptedWorker(): array
    {
        if (!$this->server->isRunning()) {
            self::assertTrue($this->server->start());
        }
        self::assertTrue(socket_getsockname($this->server->getSocket(), $host, $port));
        $peer = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertInstanceOf(Socket::class, $peer);
        self::assertTrue(socket_connect($peer, $host, $port));
        socket_set_nonblock($peer);
        socket_set_option($peer, SOL_TCP, TCP_NODELAY, 1);
        $this->peers[] = $peer;
        $client = $this->server->acceptConnection();
        self::assertInstanceOf(WorkerClient::class, $client);
        socket_set_option($client->getSocket(), SOL_TCP, TCP_NODELAY, 1);
        $this->clients[] = $client;
        return [$client, $peer];
    }

    /**
     * @param Socket $peer Worker end whose already available native output is observed without waiting
     * @return list<array<string, mixed>> All received frames in their exact wire order
     */
    private function peerFrames(Socket $peer): array
    {
        $wire = socket_read($peer, 65536);
        if ($wire === false) {
            self::assertContains(socket_last_error($peer), [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK]);
            socket_clear_error($peer);
            return [];
        }
        self::assertNotSame('', $wire, 'the worker channel must remain open');
        self::assertStringEndsWith("\n", $wire);
        return array_map(static fn(string $line): array => json_decode($line, true), explode("\n", rtrim($wire, "\n")));
    }

    /**
     * @param int $index Connection identity
     * @param bool $mono Worker kind
     * @param bool $registered Whether registration has already completed
     * @return array{QueueLoopWorkerClient, Socket} Real local socket pair
     */
    private function worker(int $index, bool $mono = false, bool $registered = true): array
    {
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $client = new QueueLoopWorkerClient($pair[0], $this->agents, $this->server);
        if ($registered) {
            $client->setWorkerIndex($index);
            $client->setIsMonopolistic($mono);
            new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($client, true);
        }
        $this->server->includeClient($client);
        // Queue owners use the same base; output is inspected before a transport write.
        $client->attachEventLoop($this->loop, function () use ($client): void {
            $client->read();
            $client->onTick();
            if ($client->shouldClose()) {
                $this->server->dropClient($client);
            }
        });
        $this->clients[] = $client;
        $this->peers[] = $pair[1];
        $this->pump();
        if ($registered) {
            $this->server->noteWorkerRegistered($client);
        }
        return [$client, $pair[1]];
    }

    /**
     * @param Socket $peer Local worker end
     * @param WorkerDTO $message Actual decoder input
     */
    private function send(Socket $peer, WorkerDTO $message): void
    {
        $wire = $message->toJson() . "\n";
        self::assertSame(strlen($wire), socket_write($peer, $wire));
    }

    /**
     * @param WorkerClient $client Worker output owner
     * @return list<array<string, mixed>> Exact queued frames
     */
    private function frames(WorkerClient $client): array
    {
        $buffer = new ReflectionProperty(WorkerClient::class, 'writeBuffer')->getValue($client);
        return $buffer === '' ? [] : array_map(
            static fn(string $line): array => json_decode($line, true),
            explode("\n", rtrim($buffer, "\n")),
        );
    }

    /** @param WorkerClient $client Output whose startup frames are already observed */
    private function clearFrames(WorkerClient $client): void
    {
        new ReflectionProperty(WorkerClient::class, 'writeBuffer')->setValue($client, '');
    }
}

final class QueueLoopServer extends WorkerServer
{
    public int $startingReads = 0;

    public int $workerOrders = 0;

    /** The fixture supplies local dependencies without creating processes or external resources. */
    public function __construct()
    {
        $this->host = '127.0.0.1';
        $this->port = 0;
    }

    /**
     * @param string $type Type addressed through the real start gates
     * @param ?string $index Instance addressed, null for a singleton
     */
    public function request(string $type, ?string $index = null): void
    {
        $this->startAgent($type, $index);
    }

    /**
     * @param string $type Type stopped through the real cancellation path
     * @param ?string $index Instance stopped, null for a singleton
     */
    public function cancel(string $type, ?string $index = null): void
    {
        $this->stopAgent($type, $index);
    }

    /** @return list<string> Actual domain predicate with observable read count */
    public function agentsStillStarting(): array
    {
        ++$this->startingReads;
        return parent::agentsStillStarting();
    }

    /** @param WorkerClient $client Real local connection included in worker selection */
    public function includeClient(WorkerClient $client): void
    {
        $this->addClient($client);
    }

    /** @param bool $isMonopolistic Requested worker kind; the fixture launches no child */
    protected function startWorker(bool $isMonopolistic): void
    {
        ++$this->workerOrders;
    }

    /** A same-process listener performs no project bootstrap or child creation. */
    protected function onStart(): void
    {
    }

    /** Consume the startup latch without project bootstrap agents. */
    protected function onInitialWorkersReady(): void
    {
    }

    /** Protected-mode diagnostics are outside this queue scheduling fixture. */
    protected function publishProtectedModeAgentDiagnostics(): void
    {
    }
}

/** Executes a test mutation at a real start append, preserving the production frame. */
final class QueueLoopWorkerClient extends WorkerClient
{
    public ?Closure $afterStart = null;

    /**
     * @param string $agentType Type whose real start frame is appended before the test callback
     * @param ?string $agentIndex Instance addressed, null for a singleton
     * @param list<string> $liveAcceptKeys Browser identities carried by the real start
     */
    public function sendAgentStart(string $agentType, ?string $agentIndex = null, array $liveAcceptKeys = []): void
    {
        parent::sendAgentStart($agentType, $agentIndex, $liveAcceptKeys);
        ($this->afterStart)?->__invoke();
    }
}

final class QueueLoopAgentManager extends AgentManagerDaemon
{
    /**
     * @param string $agentType Fixture type determining the process and stop policy
     * @param ?string $agentIndex Instance addressed, null for a singleton
     * @return AgentDaemonInterface Real daemon-side roster item
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new QueueLoopAgent($agentType !== 'regular', $agentType === HilosAgentType::HILOS_ANALYTICS_JOURNAL, $agentIndex);
    }
}

final class QueueLoopAgent extends AbstractAgentDaemon
{
    /**
     * @param bool $mono Whether one process belongs to this agent
     * @param bool $last Whether other workers stop first
     * @param ?string $index Instance addressed, null for a singleton
     */
    public function __construct(private readonly bool $mono, private readonly bool $last, ?string $index)
    {
        $this->agentIndex = $index;
    }

    /** @return bool Process policy chosen for this fixture agent */
    public function requiresMonopolisticProcess(): bool
    {
        return $this->mono;
    }

    /** @return bool Whether this agent holds back its worker for the second wave */
    public function stopsAfterOtherWorkers(): bool
    {
        return $this->last;
    }

    /** @param AgentMessageDTOInterface $message Browser output unused by the worker queue fixture */
    public function sendToUser(AgentMessageDTOInterface $message): void
    {
    }
}

final class QueueLoopChild extends Process
{
    public int $ticks = 0;
    public int $stops = 0;
    public bool $running = true;
    public bool $fail = false;

    /** The fixture supplies local dependencies without creating processes or external resources. */
    public function __construct()
    {
    }

    /** Count an actual process-owner visit, with an optional controlled read failure. */
    public function tick(): void
    {
        ++$this->ticks;
        if ($this->fail) {
            throw new FailedToReadStdOutException('retired child');
        }
    }

    /** @return array<string, mixed> Explicit exit fact */
    public function getStatus(): array
    {
        return [self::STATUS_RUNNING => $this->running];
    }

    /** @return bool Whether the explicit child exit fact has not arrived */
    public function isInFlight(): bool
    {
        return $this->running;
    }

    /** A failed binding detaches while the explicit child running fact is retained. */
    public function halt(): void
    {
        $this->detachEventLoop();
    }

    /** @param ?float $shutdownTimeout Original production stop budget; no real timer is waited out */
    public function stop(?float $shutdownTimeout = null): void
    {
        ++$this->stops;
    }

    /** Release inherited native bindings without creating or reaping a process. */
    public function __destruct()
    {
        $this->detachEventLoop();
    }
}

final class QueueLoopDaemon extends DaemonManager
{
    /** @return SignalRouter Controlled destinations for the real master dispatch */
    protected function createSignalRouter(): SignalRouter
    {
        return new QueueLoopRouter();
    }

    /** @return AgentManagerDaemon Real roster and lifecycle callbacks for this fixture */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new QueueLoopAgentManager();
    }
}

final class QueueLoopRouter extends SignalRouter
{
    protected const bool USE_DESTINATION_CALLBACK = true;

    public ?RuntimeException $failure = null;

    public ?string $failOnlySignal = null;

    /**
     * @param SignalDTO $signal Queued final frame whose controlled recipient is the held journal
     * @return list<AgentDestination> One recipient, exercised through the production delivery path
     * @throws RuntimeException Controlled route failure under the existing master guard
     */
    public function getDestinations(SignalDTO $signal): array
    {
        if ($this->failure !== null && ($this->failOnlySignal === null || $this->failOnlySignal === $signal->signalName->getName())) {
            throw $this->failure;
        }
        return [new AgentDestination(HilosAgentType::HILOS_ANALYTICS_JOURNAL)];
    }
}

abstract class QueueLoopHilos extends Hilos
{
    public const array AGENTS = [
        'regular' => [],
        'mono_a' => [],
        'mono_b' => [],
        HilosAgentType::HILOS_ANALYTICS_JOURNAL => [],
        HilosAgentType::HILOS_PROBE_DB => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_DB],
    ];
}
