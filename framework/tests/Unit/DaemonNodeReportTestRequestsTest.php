<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\DaemonNodeAgentDaemon;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\Router\Destination\CommandReplyDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\DaemonSection\DTO\DaemonAgentRestartRequestSignalData;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\CommandServer;
use ReflectionClass;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\DaemonNodeReportTestRequests;
use Hilos\Core\Daemon\MasterSignalSender;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonMasterReportNowSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The master holds a node-report ask across roster delivery until the original agent answers or leaves.
 *
 * Every case names APP_ENV explicitly: the master re-asks the non-production gate at its own door.
 */
final class DaemonNodeReportTestRequestsTest extends TestCase
{
    /** @var class-string<Hilos> */
    private string $previousAppClass;
    private ?EnvAccessor $previousEnv;
    private ?RtContext $previousRt;
    private ?ClusterContext $previousCluster;
    private ?SignalRouter $previousRouter;
    private string|false $previousAppEnv;
    private NodeReportAgentManager $agents;
    private NodeReportSignalCapture $signals;
    private int $forced = 0;
    /** @var list<CommandReplyDTO> */
    private array $replies = [];
    private DaemonNodeReportTestRequests $requests;

    protected function setUp(): void
    {
        $this->previousAppClass = Hilos::appClass();
        $this->previousEnv = Hilos::$env;
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        $this->previousAppEnv = getenv('APP_ENV');
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, NodeReportTestHilos::class);
        Hilos::$rt = null;
        Hilos::$cluster = null;
        $this->environment('test');
        $this->agents = new NodeReportAgentManager();
        $this->signals = new NodeReportSignalCapture();
        $this->requests = new DaemonNodeReportTestRequests(
            $this->agents,
            $this->signals,
            function (): void {
                $this->forced++;
            },
            function (CommandReplyDTO $reply): void {
                $this->replies[] = $reply;
            },
        );
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        Hilos::$env = $this->previousEnv;
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = $this->previousRouter;
        putenv($this->previousAppEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->previousAppEnv);
        parent::tearDown();
    }

    public function testProductionIsRefusedAtTheMasterDoor(): void
    {
        $this->environment('prod');

        $this->requests->request($this->ask('c-1'), 100.0, 100.0);

        $this->assertRefusedAtOnce('c-1', "Test-only command 'test:daemon:node:report' is refused: APP_ENV is production-like or unset");
        self::assertSame(
            TestOnlyCommandOnProductionException::message(CliCommands::DAEMON_TEST_NODE_REPORT),
            $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE] ?? null,
        );
    }

    public function testAFrozenNodeIsRefused(): void
    {
        $runtime = new NodeReportFreezeRtContext();
        $runtime->hilosProtectedModeRuntime->phase = StateProtectedModeRuntime::PHASE_ACTIVE;
        Hilos::$rt = $runtime;

        $this->requests->request($this->ask('c-2'), 100.0, 100.0);

        $this->assertRefusedAtOnce('c-2', 'No Daemon node report during protected mode.');
    }

    public function testANodeWithoutItsDaemonAgentIsRefused(): void
    {
        $this->agents->started = false;

        $this->requests->request($this->ask('c-3'), 100.0, 100.0);

        $this->assertRefusedAtOnce('c-3', 'The Daemon node agent is not running on this node.');
    }

    public function testARosterSentBeforeTheAskDoesNotAnswerIt(): void
    {
        $this->requests->request($this->ask('c-4'), 100.0, 100.0);
        self::assertSame(1, $this->forced);

        $this->requests->dispatchDue(0.0, 100.5);
        $this->requests->dispatchDue(99.9, 100.5);
        self::assertSame([], $this->signals->sent);

        $this->requests->dispatchDue(100.0, 100.5);
        self::assertCount(1, $this->signals->sent);
        self::assertSame(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW, $this->signals->sent[0][2]);
        self::assertSame([], $this->replies, 'The master still holds the console for the node agent outcome');
    }

    public function testAFailedRosterAnswersEveryWait(): void
    {
        $this->requests->request($this->ask('c-5'), 100.0, 100.0);
        $this->requests->request($this->ask('c-6'), 100.2, 100.2);

        $this->requests->fail('The node could not build its process roster: LogicException: no workers');

        self::assertSame(['c-5', 'c-6'], array_map(static fn (CommandReplyDTO $reply): string => $reply->correlationId, $this->replies));
        foreach ($this->replies as $reply) {
            self::assertFalse($reply->isOk());
            self::assertSame(
                'The node could not build its process roster: LogicException: no workers',
                $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null,
            );
        }
        $this->requests->dispatchDue(200.0, 100.5);
        self::assertSame([], $this->signals->sent);
    }

    public function testAnUnansweredWaitEndsAtTheAgentWindow(): void
    {
        $this->requests->request($this->ask('c-7'), 100.0, 100.0);

        $this->requests->tick(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS - 0.1);
        self::assertSame([], $this->replies);

        $this->requests->tick(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS);
        self::assertCount(1, $this->replies);
        self::assertSame('c-7', $this->replies[0]->correlationId);
        self::assertSame('The Daemon node agent did not report in time.', $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE] ?? null);

        $this->requests->dispatchDue(200.0, 100.5);
        self::assertSame([], $this->signals->sent);
    }

    public function testACallerWhoLeftIsNeitherAskedForNorAnswered(): void
    {
        $this->requests->request($this->ask('c-8'), 100.0, 100.0);

        $this->requests->abandon('c-8');
        $this->requests->dispatchDue(100.0, 100.5);
        $this->requests->tick(200.0);

        self::assertSame([], $this->signals->sent);
        self::assertSame([], $this->replies);
    }

    public function testRemovingTheEarliestWaitMovesTheDeadlineToTheNextLiveAsk(): void
    {
        $this->requests->request($this->ask('first'), 100.0, 100.0);
        $this->requests->request($this->ask('second'), 102.0, 102.0);

        $this->requests->abandon('first');

        self::assertSame(
            102.0 + CommandChannelWindows::AGENT_WAIT_SECONDS,
            new ReflectionProperty(DaemonNodeReportTestRequests::class, 'nextDeadline')->getValue($this->requests),
        );
        $this->requests->abandon('second');
        self::assertSame(INF, new ReflectionProperty(DaemonNodeReportTestRequests::class, 'nextDeadline')->getValue($this->requests));
    }

    public function testEpochOrderingAndMonotonicDeadlineStaySeparateAcrossRebind(): void
    {
        $loop = new EventLoop();
        $other = new EventLoop();
        $wakes = 0;
        $wake = static function () use (&$wakes): void {
            ++$wakes;
        };
        $now = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        try {
            $this->requests->attachEventLoop($loop, $wake);
            $this->requests->request($this->ask('first'), 1_767_225_600.0, $now);
            $this->requests->request($this->ask('second'), 1_767_225_601.0, $now + 2);
            $old = new ReflectionProperty(DaemonNodeReportTestRequests::class, 'deadlineTimer')->getValue($this->requests);
            self::assertSame($now + CommandChannelWindows::AGENT_WAIT_SECONDS, $old->getDeadline());
            $this->requests->dispatchDue(1_767_225_600.0, $now + 1);
            self::assertCount(1, $this->signals->sent);
            $deadline = $now + CommandChannelWindows::AGENT_WAIT_SECONDS;
            self::assertSame($deadline, $old->getDeadline());
            $this->requests->attachEventLoop($other, $wake);
            $timer = new ReflectionProperty(DaemonNodeReportTestRequests::class, 'deadlineTimer')->getValue($this->requests);
            self::assertSame($deadline, $timer->getDeadline());
            self::assertNull($old->getDeadline());
            $loop->tick();
            $other->tick();
            self::assertSame(0, $wakes);
            $this->requests->dispatchDue(1_767_225_601.0, $now + 3);
            self::assertCount(2, $this->signals->sent);
            self::assertSame($deadline, $timer->getDeadline());
            $this->requests->acceptReply(CommandReplyDTO::ok('first'), $now + 4);
            self::assertSame($now + 2 + CommandChannelWindows::AGENT_WAIT_SECONDS, $timer->getDeadline());
            $this->requests->acceptReply(CommandReplyDTO::error('second', 'Report refused'), $now + 4);
            self::assertNull($timer->getDeadline());
            self::assertCount(2, $this->replies);
        } finally {
            $this->requests->detachEventLoop();
            $loop->close();
            $other->close();
        }
    }

    public function testManagerRebindingPreservesOverdueWaitsButCancelsTheOldCue(): void
    {
        $manager = new NodeReportTestDaemonManager();
        $loop = new EventLoop();
        $other = new EventLoop();
        $owner = new ReflectionProperty(DaemonManager::class, 'daemonNodeReportTestRequests')->getValue($manager);
        $due = new ReflectionProperty(DaemonManager::class, 'nodeReportDeadlineDue');
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachCommandRequestEventLoop')->invoke($manager);
            $now = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
            $owner->request($this->ask('expired'), 1_767_225_600.0, $now - CommandChannelWindows::AGENT_WAIT_SECONDS);
            $manager->addWorkers(1);
            $owner->dispatchDue(1_767_225_600.0, $now - 1);
            self::assertTrue($owner->hasWaiter('expired'));
            for ($pass = 0; $pass < 4; ++$pass) {
                $loop->tick();
            }
            self::assertTrue($due->getValue($manager));
            self::assertNotSame([], new ReflectionProperty(DaemonNodeReportTestRequests::class, 'deadlines')->getValue($owner));
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $other);
            new ReflectionMethod(DaemonManager::class, 'attachCommandRequestEventLoop')->invoke($manager);
            self::assertFalse($due->getValue($manager));
            $loop->tick();
            self::assertFalse($due->getValue($manager), 'The retired binding cannot cue this manager');
            for ($pass = 0; $pass < 4; ++$pass) {
                $other->tick();
            }
            self::assertTrue($due->getValue($manager));
            new ReflectionMethod(DaemonManager::class, 'dispatchCommandRequestDeadlines')->invoke($manager);
            self::assertFalse($due->getValue($manager));
            self::assertSame([], new ReflectionProperty(DaemonNodeReportTestRequests::class, 'deadlines')->getValue($owner));
            self::assertFalse($other->hasReadyWork());
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachCommandRequestEventLoop')->invoke($manager);
            $loop->close();
            $other->close();
        }
    }

    public function testRosterCompletionCannotForwardAnExpiredWaitBeforeTheTimerPortion(): void
    {
        $this->requests->request($this->ask('expired'), 1_767_225_600.0, 100.0);
        $this->requests->dispatchDue(1_767_225_600.0, 100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS);
        self::assertSame([], $this->signals->sent);
        self::assertCount(1, $this->replies);
        self::assertSame('The Daemon node agent did not report in time.', $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE]);
    }

    public function testTwoAsksGetTwoSignals(): void
    {
        $this->requests->request($this->ask('c-9'), 100.0, 100.0);
        $this->requests->request($this->ask('c-10'), 100.0, 100.0);

        $this->requests->dispatchDue(100.0, 100.5);
        $this->requests->dispatchDue(100.5, 100.5);

        self::assertCount(2, $this->signals->sent);
        $asked = [];
        foreach ($this->signals->sent as [$type, $index, $name, $data]) {
            self::assertSame(HilosAgentType::HILOS_DAEMON_NODE, $type);
            self::assertNull($index);
            self::assertSame(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW, $name);
            self::assertInstanceOf(DaemonMasterReportNowSignalData::class, $data);
            self::assertSame('standalone', $data->nodeId);
            $asked[] = $data->correlationId;
        }
        self::assertSame(['c-9', 'c-10'], $asked);
    }

    /** A correlation of digits only is kept under an int key; the signal to the node agent still names it as a string. */
    public function testADigitOnlyCorrelationIsNamedByTheSignalToTheNodeAgent(): void
    {
        $this->requests->request($this->ask('1234567890123456'), 100.0, 100.0);

        $this->requests->dispatchDue(100.0, 100.5);

        self::assertCount(1, $this->signals->sent);
        $data = $this->signals->sent[0][3];
        self::assertInstanceOf(DaemonMasterReportNowSignalData::class, $data);
        self::assertSame('1234567890123456', $data->correlationId);
        self::assertSame([], $this->replies, 'The master still holds the console for the node agent outcome');
        self::assertTrue($this->requests->hasWaiter('1234567890123456'));
        $this->requests->dispatchDue(200.0, 100.5);
        $this->requests->tick(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS);
        self::assertCount(1, $this->signals->sent);
        self::assertCount(1, $this->replies);
        self::assertSame('1234567890123456', $this->replies[0]->correlationId);
        self::assertSame('The Daemon node agent did not report in time.', $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE]);
    }

    /** A correlation of digits only is kept under an int key; the failed roster still names it as a string. */
    public function testADigitOnlyCorrelationIsAnsweredByAFailedRoster(): void
    {
        $this->requests->request($this->ask('1234567890123456'), 100.0, 100.0);
        $this->requests->request($this->ask('6543210987654321'), 100.2, 100.2);

        $this->requests->fail('The node could not build its process roster: LogicException: no workers');

        self::assertSame(
            ['1234567890123456', '6543210987654321'],
            array_map(static fn (CommandReplyDTO $reply): string => $reply->correlationId, $this->replies),
        );
        foreach ($this->replies as $reply) {
            self::assertFalse($reply->isOk());
            self::assertSame(
                'The node could not build its process roster: LogicException: no workers',
                $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null,
            );
        }
        $this->requests->dispatchDue(200.0, 100.5);
        self::assertSame([], $this->signals->sent);
    }

    /** A correlation of digits only is kept under an int key; the agent window still names it as a string. */
    public function testADigitOnlyCorrelationIsAnsweredByTheAgentWindow(): void
    {
        $this->requests->request($this->ask('1234567890123456'), 100.0, 100.0);

        $this->requests->tick(100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS);

        self::assertCount(1, $this->replies);
        self::assertSame('1234567890123456', $this->replies[0]->correlationId);
        self::assertFalse($this->replies[0]->isOk());
        self::assertSame('The Daemon node agent did not report in time.', $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE] ?? null);
        $this->requests->dispatchDue(200.0, 100.5);
        self::assertSame([], $this->signals->sent);
    }

    /**
     * Driven through the master: a roster built over two passes answers the ask only once sent.
     *
     * A whole roster went out before the ask and does not answer it; the forced roster after it
     * takes two passes, and the node agent is asked in the pass it goes out, after it. The node
     * has no HTTP server, so its HTTP frame never goes out, and the ask is answered without it.
     */
    public function testTheMasterAsksOnlyAfterAMultiPassRosterHasGoneOut(): void
    {
        $manager = new NodeReportTestDaemonManager();
        $server = $manager->addWorkers(150);
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachProcessRosterEventLoop')->invoke($manager);
        new ReflectionMethod(DaemonManager::class, 'attachReportFramesEventLoop')->invoke($manager);
        new ReflectionMethod(DaemonManager::class, 'attachMeasurementsEventLoop')->invoke($manager);
        try {
            $manager->pass();
            self::assertTrue($loop->hasReadyWork(), 'The second portion needs no incoming frame');
            $manager->pass();
            self::assertSame(1, $server->count(HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER));

            $manager->askDuringPass($this->ask('c-11'));
            self::assertSame(1, $server->count(HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER));
            self::assertSame(0, $server->count(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW));

            self::assertTrue($loop->hasReadyWork(), 'The forced whole roster continues natively');
            $manager->pass();
            self::assertSame(2, $server->count(HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER));
            self::assertSame(1, $server->count(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW));
            $last = array_key_last($server->delivered);
            self::assertSame(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW, $server->name($last));
            foreach ([HilosSignalConstants::DAEMON_MASTER_CRON, HilosSignalConstants::DAEMON_MASTER_STANDING,
                HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS] as $name) {
                self::assertSame(2, $server->count($name), 'Each forced whole frame preceded the report ask');
            }
            $ask = $server->delivered[$last]->signal->data->data;
            self::assertInstanceOf(DaemonMasterReportNowSignalData::class, $ask);
            self::assertSame('standalone', $ask->nodeId);
            self::assertSame('c-11', $ask->correlationId);
            self::assertSame(0, $server->count(HilosSignalConstants::DAEMON_MASTER_HTTP));

            $manager->pass();
            self::assertSame(1, $server->count(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW));
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachMeasurementsEventLoop')->invoke($manager);
            new ReflectionMethod(DaemonManager::class, 'detachReportFramesEventLoop')->invoke($manager);
            new ReflectionMethod(DaemonManager::class, 'detachProcessRosterEventLoop')->invoke($manager);
            $loop->cleanup();
        }
    }

    public function testAnUnlinkedOrClosingAgentForcesNoFrames(): void
    {
        $this->agents->removeAgent(HilosAgentType::HILOS_DAEMON_NODE);
        $this->requests->request($this->ask('missing'), 100.0, 100.0);
        $agent = new DaemonNodeAgentDaemon();
        $this->agents->addAgent(HilosAgentType::HILOS_DAEMON_NODE, $agent, 1, false);
        $this->requests->request($this->ask('unlinked'), 100.0, 100.0);
        $worker = $this->createStub(WorkerClient::class);
        $worker->method('shouldClose')->willReturn(true);
        $agent->setWorkerClient($worker);
        $this->requests->request($this->ask('closing'), 100.0, 100.0);

        self::assertSame(0, $this->forced);
        self::assertCount(3, $this->replies);
        foreach ($this->replies as $reply) {
            self::assertSame('The Daemon node agent is not running on this node.', $reply->payload[CommandConstants::FIELD_MESSAGE]);
        }
        $this->assertNoLiveWaits();
    }

    public function testRepliesConsumeOnlyDispatchedWaitsAndPreserveTheOriginalDto(): void
    {
        $this->requests->request($this->ask('ok'), 100.0, 100.0);
        $this->requests->request($this->ask('error'), 100.0, 100.0);
        $ok = CommandReplyDTO::ok('ok', [CommandConstants::FIELD_NODE_ID => 'node', CommandConstants::FIELD_REPORTED_AT => 123]);
        $error = CommandReplyDTO::error('error', 'Could not publish');
        $this->requests->acceptReply($ok, 100.1);
        $this->requests->acceptReply(CommandReplyDTO::ok('foreign'), 100.1);
        self::assertSame([], $this->replies);
        self::assertFalse($this->requests->hasWaiter('ok'));
        $this->requests->dispatchDue(100.0, 100.2);
        self::assertTrue($this->requests->hasWaiter('ok'));
        $this->requests->acceptReply($ok, 100.3);
        $this->requests->acceptReply($error, 100.3);
        $this->requests->acceptReply($ok, 100.3);
        $this->requests->tick(200.0);
        self::assertSame([$ok, $error], $this->replies);
        $this->assertNoLiveWaits();
    }

    public function testReplyAtTheOriginalDeadlineTimesOutBeforeTheTimerPhase(): void
    {
        $this->requests->request($this->ask('late'), 100.0, 100.0);
        $this->requests->dispatchDue(100.0, 100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS - 0.1);
        $this->requests->acceptReply(CommandReplyDTO::ok('late'), 100.0 + CommandChannelWindows::AGENT_WAIT_SECONDS);
        $this->requests->acceptReply(CommandReplyDTO::ok('late'), 200.0);
        self::assertCount(1, $this->replies);
        self::assertSame('The Daemon node agent did not report in time.', $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE]);
        $this->assertNoLiveWaits();
    }

    public function testAbandonReleasesBothStagesWithoutAReply(): void
    {
        $this->requests->request($this->ask('sent'), 100.0, 100.0);
        $this->requests->request($this->ask('roster'), 101.0, 101.0);
        $this->requests->dispatchDue(100.0, 101.1);
        $this->requests->abandon('sent');
        $this->requests->abandon('roster');
        $this->requests->agentStopped();
        $this->requests->tick(200.0);
        self::assertSame([], $this->replies);
        $this->assertNoLiveWaits();
    }

    public function testRosterFailureDoesNotCancelAnAlreadySentReport(): void
    {
        $this->requests->request($this->ask('sent'), 100.0, 100.0);
        $this->requests->dispatchDue(100.0, 100.1);
        $this->requests->request($this->ask('roster'), 101.0, 101.0);
        $this->requests->fail('No process roster');
        self::assertTrue($this->requests->hasWaiter('sent'));
        self::assertCount(1, $this->replies);
        self::assertSame('roster', $this->replies[0]->correlationId);
        self::assertSame('No process roster', $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE]);
        $this->requests->acceptReply(CommandReplyDTO::ok('sent'), 101.1);
        self::assertCount(2, $this->replies);
        self::assertTrue($this->replies[1]->isOk());
        $this->assertNoLiveWaits();
    }

    public function testSendFailureFinishesOnlyOnceWithItsReason(): void
    {
        $this->signals->failure = new LogicException('Could not queue report-now');
        $this->requests->request($this->ask('send'), 100.0, 100.0);
        $this->requests->dispatchDue(100.0, 100.1);
        $this->requests->dispatchDue(200.0, 100.2);
        $this->requests->agentStopped();
        self::assertCount(1, $this->replies);
        self::assertSame('Could not queue report-now', $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE]);
        $this->assertNoLiveWaits();
    }

    public function testTimerFailureWhileFinishingASendFailureRemainsAnEventLoopFailure(): void
    {
        $loop = new EventLoop();
        $sender = $this->createMock(MasterSignalSender::class);
        $owner = new DaemonNodeReportTestRequests($this->agents, $sender, static function (): void {},
            function (CommandReplyDTO $reply): void { $this->replies[] = $reply; });
        $now = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        $owner->attachEventLoop($loop, static function (): void {});
        try {
            $owner->request($this->ask('send-fails'), 100.0, $now);
            $owner->request($this->ask('next'), 101.0, $now + 1);
            $timer = new ReflectionProperty(DaemonNodeReportTestRequests::class, 'deadlineTimer')->getValue($owner);
            $sender->expects(self::once())->method('sendToAgent')->willReturnCallback(static function () use ($timer): void {
                $timer->close();
                throw new LogicException('Send failed');
            });
            try {
                $owner->dispatchDue(100.0, $now);
                self::fail('A closed deadline handle must refuse rearming');
            } catch (EventLoopException $failure) {
                self::assertSame('A timer needs a finite deadline and an open handle.', $failure->getMessage());
            }
            self::assertFalse($owner->hasWaiter('send-fails'));
            self::assertSame([], $this->replies, 'Native timer failure is not a second send refusal');
        } finally {
            $owner->detachEventLoop();
            $loop->close();
        }
    }

    public function testWorkerLossMatchesTheOriginalChannelObjectInBothStages(): void
    {
        $old = $this->agents->getAgent(HilosAgentType::HILOS_DAEMON_NODE)->getWorkerClient();
        $old->setWorkerIndex(7);
        $this->requests->request($this->ask('1234567890123456'), 100.0, 100.0);
        $this->requests->dispatchDue(100.0, 100.1);
        $this->requests->request($this->ask('roster'), 101.0, 101.0);
        $replacement = new ReflectionClass(WorkerClient::class)->newInstanceWithoutConstructor();
        $replacement->setWorkerIndex(7);
        $this->agents->getAgent(HilosAgentType::HILOS_DAEMON_NODE)->setWorkerClient($replacement);
        $this->requests->workerGone($replacement);
        self::assertSame([], $this->replies, 'A reused worker index is not the original channel');
        $this->requests->request($this->ask('replacement'), 102.0, 102.0);
        $this->requests->workerGone($old);
        self::assertSame(['1234567890123456', 'roster'], array_column($this->replies, 'correlationId'));
        foreach ($this->replies as $reply) {
            self::assertSame('The worker running the Daemon node agent went down before it reported.',
                $reply->payload[CommandConstants::FIELD_MESSAGE]);
        }
        $this->requests->workerGone($old);
        self::assertCount(2, $this->replies);
        $this->requests->abandon('replacement');
        $this->assertNoLiveWaits();
    }

    public function testAClosingOriginalChannelRefusesBeforeDispatchAndBeforeAcceptingAReply(): void
    {
        $worker = $this->agents->getAgent(HilosAgentType::HILOS_DAEMON_NODE)->getWorkerClient();
        $this->requests->request($this->ask('roster'), 100.0, 100.0);
        new ReflectionProperty(WorkerClient::class, 'shouldClose')->setValue($worker, true);
        $this->requests->dispatchDue(100.0, 100.1);
        self::assertSame([], $this->signals->sent);
        new ReflectionProperty(WorkerClient::class, 'shouldClose')->setValue($worker, false);
        $this->requests->request($this->ask('sent'), 101.0, 101.0);
        $this->requests->dispatchDue(101.0, 101.1);
        new ReflectionProperty(WorkerClient::class, 'shouldClose')->setValue($worker, true);
        $this->requests->acceptReply(CommandReplyDTO::ok('sent'), 101.2);
        self::assertCount(2, $this->replies);
        foreach ($this->replies as $reply) {
            self::assertSame('The worker running the Daemon node agent went down before it reported.',
                $reply->payload[CommandConstants::FIELD_MESSAGE]);
        }
        $this->assertNoLiveWaits();
    }

    public function testLocalDispatcherConsumesOurWaitAndLeavesOtherRepliesOnTheirExistingPath(): void
    {
        $manager = new NodeReportTestDaemonManager();
        $manager->addWorkers(1);
        $commands = new NodeReportCommandCapture();
        $manager->registerServer($commands);
        $owner = new ReflectionProperty(DaemonManager::class, 'daemonNodeReportTestRequests')->getValue($manager);
        $commands->owner = $owner;
        $now = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        $owner->request($this->ask('ours'), 100.0, $now);
        $owner->dispatchDue(100.0, $now);
        $reply = CommandReplyDTO::ok('ours', [CommandConstants::FIELD_REPORTED_AT => 123]);
        $manager->localReply($reply, $commands);
        self::assertSame([$reply], $commands->replies);
        self::assertSame([false], $commands->holdingAtDelivery, 'The dispatcher consumes before delivery');
        self::assertFalse($owner->hasWaiter('ours'));
        $other = CommandReplyDTO::error('foreign', 'Another command');
        $manager->localReply($other, $commands);
        $manager->localReply($reply, $commands);
        self::assertSame([$reply, $other, $reply], $commands->replies, 'Late replies take the ordinary console path');
        self::assertSame([false, false, false], $commands->holdingAtDelivery);
        $owner->tick($now + CommandChannelWindows::AGENT_WAIT_SECONDS);
        self::assertCount(3, $commands->replies);
    }

    public function testRealSenderRefusalAnswersWithItsReasonAndReleasesTheOriginalDeadline(): void
    {
        $manager = new NodeReportTestDaemonManager();
        $server = $manager->addWorkers(1);
        $commands = new NodeReportCommandCapture();
        $manager->registerServer($commands);
        $owner = new ReflectionProperty(DaemonManager::class, 'daemonNodeReportTestRequests')->getValue($manager);
        $commands->owner = $owner;
        $loop = new EventLoop();
        $owner->attachEventLoop($loop, static function (): void {});
        try {
            $now = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
            $owner->request($this->ask('1234567890123456'), 100.0, $now);
            self::assertSame([], $commands->replies, 'Admission succeeds before the worker server refuses report-now');
            $timer = new ReflectionProperty(DaemonNodeReportTestRequests::class, 'deadlineTimer')->getValue($owner);
            $deadline = $timer->getDeadline();
            $server->reportFailure = new HilosException('The worker could not queue report-now');

            $owner->dispatchDue(100.0, $now);

            self::assertTrue($owner->hasWaiter('1234567890123456'), 'Delivery only queues the refusal for the guarded phase');
            self::assertSame([], $commands->replies);
            self::assertSame($deadline, $timer->getDeadline());
            new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($manager);
            self::assertCount(1, $commands->replies);
            self::assertSame('1234567890123456', $commands->replies[0]->correlationId);
            self::assertFalse($commands->replies[0]->isOk());
            self::assertSame('The worker could not queue report-now', $commands->replies[0]->payload[CommandConstants::FIELD_MESSAGE]);
            self::assertSame([false], $commands->holdingAtDelivery);
            foreach (['requestedAt', 'deadlines', 'workers'] as $name) {
                self::assertSame([], new ReflectionProperty(DaemonNodeReportTestRequests::class, $name)->getValue($owner));
            }
            self::assertSame(INF, new ReflectionProperty(DaemonNodeReportTestRequests::class, 'nextDeadline')->getValue($owner));
            self::assertNull($timer->getDeadline());

            $owner->dispatchDue(200.0, $now);
            $manager->onAgentStopped(HilosAgentType::HILOS_DAEMON_NODE);
            $owner->tick($now + CommandChannelWindows::AGENT_WAIT_SECONDS);
            self::assertCount(1, $commands->replies);
            self::assertSame(1, $server->count(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW));
            $late = CommandReplyDTO::ok('1234567890123456');
            $manager->localReply($late, $commands);
            self::assertSame($late, $commands->replies[1], 'Late replies still reach the ordinary console path');
            self::assertSame([false, false], $commands->holdingAtDelivery);
            $manager->onAgentStopped(HilosAgentType::HILOS_DAEMON_NODE);
            self::assertCount(2, $commands->replies, 'The late reply creates no second owner completion');
        } finally {
            $owner->detachEventLoop();
            $loop->cleanup();
        }
    }

    public function testRealSenderRefusalDoesNotCatchTheReplyOwnersTimerFailure(): void
    {
        $manager = new NodeReportTestDaemonManager();
        $server = $manager->addWorkers(1);
        $commands = new NodeReportCommandCapture();
        $manager->registerServer($commands);
        $owner = new ReflectionProperty(DaemonManager::class, 'daemonNodeReportTestRequests')->getValue($manager);
        $loop = new EventLoop();
        $owner->attachEventLoop($loop, static function (): void {});
        try {
            $now = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
            $owner->request($this->ask('send-fails'), 100.0, $now);
            $owner->request($this->ask('next'), 101.0, $now + 1);
            $server->reportFailure = new HilosException('The worker could not queue report-now');
            $owner->dispatchDue(100.0, $now);
            new ReflectionProperty(DaemonNodeReportTestRequests::class, 'deadlineTimer')->getValue($owner)->close();

            try {
                new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($manager);
                self::fail('A refused deadline rearm must escape the signal dispatch phase');
            } catch (EventLoopException $failure) {
                self::assertSame('A timer needs a finite deadline and an open handle.', $failure->getMessage());
            }
            self::assertSame([], $commands->replies);
            self::assertSame(['next'], array_keys(
                new ReflectionProperty(DaemonNodeReportTestRequests::class, 'deadlines')->getValue($owner),
            ));
        } finally {
            $owner->detachEventLoop();
            $loop->cleanup();
        }
    }

    public function testLocalDispatcherStillConsumesPageAgentRestartReplies(): void
    {
        $manager = new NodeReportTestDaemonManager();
        $manager->addWorkers(1);
        $commands = new NodeReportCommandCapture();
        $manager->registerServer($commands);
        $bridge = new ReflectionProperty(DaemonManager::class, 'pageAgentRestarts')->getValue($manager);
        $bridge->request(new DaemonAgentRestartRequestSignalData('room', 'standalone', 'tab', 'submit', 'pages', null));
        self::assertCount(1, $manager->restarts);
        $correlation = $manager->restarts[0]->correlationId;
        self::assertTrue($bridge->hasWaiter($correlation));
        $manager->localReply(CommandReplyDTO::error($correlation, 'Restart refused'), $commands);
        self::assertFalse($bridge->hasWaiter($correlation));
        self::assertCount(1, $manager->pageAnswers);
        self::assertSame('Restart refused', $manager->pageAnswers[0]->error);
        self::assertSame([], $commands->replies);
    }

    public function testRealStopEventRefusesBothStagesAndRestartCannotInheritThem(): void
    {
        $manager = new NodeReportTestDaemonManager();
        $server = $manager->addWorkers(1);
        $commands = new NodeReportCommandCapture();
        $manager->registerServer($commands);
        $owner = new ReflectionProperty(DaemonManager::class, 'daemonNodeReportTestRequests')->getValue($manager);
        $now = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        $owner->request($this->ask('sent'), 100.0, $now);
        $owner->dispatchDue(100.0, $now);
        $owner->request($this->ask('1234567890123456'), 101.0, $now);
        $manager->onAgentStopped('another');
        self::assertSame([], $commands->replies);
        $manager->onAgentStopped(HilosAgentType::HILOS_DAEMON_NODE);
        self::assertSame(['sent', '1234567890123456'], array_column($commands->replies, 'correlationId'));
        foreach ($commands->replies as $reply) {
            self::assertSame('The Daemon node agent stopped before it reported.', $reply->payload[CommandConstants::FIELD_MESSAGE]);
        }
        // A new started proxy is visible before another master phase, but cannot revive old waits.
        $agent = new DaemonNodeAgentDaemon();
        $agent->setWorkerClient(new ReflectionClass(WorkerClient::class)->newInstanceWithoutConstructor());
        $manager->getAgentManagerDaemon()->addAgent(HilosAgentType::HILOS_DAEMON_NODE, $agent, 1, false);
        $owner->dispatchDue(200.0, $now);
        $manager->onAgentStopped(HilosAgentType::HILOS_DAEMON_NODE);
        $owner->acceptReply(CommandReplyDTO::ok('sent'), $now);
        $owner->tick($now + CommandChannelWindows::AGENT_WAIT_SECONDS);
        self::assertCount(2, $commands->replies);
        self::assertSame(1, $server->count(HilosSignalConstants::DAEMON_MASTER_REPORT_NOW));
    }

    /** Every terminal path releases both stages, channel references and the nearest deadline. */
    private function assertNoLiveWaits(): void
    {
        foreach (['requestedAt', 'deadlines', 'workers'] as $name) {
            self::assertSame([], new ReflectionProperty(DaemonNodeReportTestRequests::class, $name)->getValue($this->requests));
        }
        self::assertSame(INF, new ReflectionProperty(DaemonNodeReportTestRequests::class, 'nextDeadline')->getValue($this->requests));
    }

    /**
     * @param string $correlationId Console correlation
     * @return CommandRequestDTO Held request as the command channel hands it over
     */
    private function ask(string $correlationId): CommandRequestDTO
    {
        return new CommandRequestDTO($correlationId, CliCommands::DAEMON_TEST_NODE_REPORT, []);
    }

    /** @param string $appEnv Environment the master judges */
    private function environment(string $appEnv): void
    {
        putenv("APP_ENV={$appEnv}");
        Hilos::$env = new EnvAccessor();
    }

    /**
     * @param string $correlationId Refused console correlation
     * @param string $message Exact refusal
     */
    private function assertRefusedAtOnce(string $correlationId, string $message): void
    {
        self::assertCount(1, $this->replies);
        self::assertSame($correlationId, $this->replies[0]->correlationId);
        self::assertFalse($this->replies[0]->isOk());
        self::assertSame($message, $this->replies[0]->payload[CommandConstants::FIELD_MESSAGE] ?? null);
        self::assertSame(0, $this->forced, 'A refused ask forces no frames');
        $this->requests->dispatchDue(200.0, 100.5);
        $this->requests->tick(200.0);
        self::assertSame([], $this->signals->sent);
        self::assertCount(1, $this->replies);
    }
}

/** Master test double driving the frame part of one main-loop pass. */
final class NodeReportTestDaemonManager extends DaemonManager
{
    private const array FRAME_PHASES = ['dispatchCronFrame', 'dispatchStanding', 'dispatchHttpFrame', 'dispatchMeasurements'];

    /** @var list<CommandRequestDTO> Requests admitted by the real page bridge */
    public array $restarts = [];

    /** @var list<HandoverAnswerSignalData> Outcomes returned by the real page bridge */
    public array $pageAnswers = [];

    /** @param CommandRequestDTO $request Existing restart door without starting a process */
    public function requestAgentRestart(CommandRequestDTO $request): void
    {
        $this->restarts[] = $request;
    }

    /**
     * @param string $agentType Receiver type
     * @param ?string $agentIndex Receiver index
     * @param string $signalName Master frame or page completion
     * @param SignalDataInterface $data Original payload
     */
    public function sendToAgent(string $agentType, ?string $agentIndex, string $signalName, SignalDataInterface $data): void
    {
        if ($signalName === HilosSignalConstants::DAEMON_AGENT_RESTART_DONE && $data instanceof HandoverAnswerSignalData) {
            $this->pageAnswers[] = $data;
            return;
        }
        parent::sendToAgent($agentType, $agentIndex, $signalName, $data);
    }

    /**
     * @param int $count Live workers without a pid, one roster row each
     * @return NodeReportWorkerServer Worker source registered with this master
     */
    public function addWorkers(int $count): NodeReportWorkerServer
    {
        $server = new NodeReportWorkerServer($count);
        $this->registerServer($server);
        return $server;
    }

    /**
     * @param CommandReplyDTO $reply Original worker reply
     * @param CommandServer $commands Existing local command destination
     */
    public function localReply(CommandReplyDTO $reply, CommandServer $commands): void
    {
        $signal = new SignalDTO(new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_DAEMON_NODE),
            new SignalType(SignalTypeConstants::COMMAND_REPLY), new SignalName(CliCommands::DAEMON_TEST_NODE_REPORT), $reply);
        $dispatch = new SignalDispatch($signal, static function (): Generator { yield; });
        $walk = new ReflectionMethod(DaemonManager::class, 'walkSignalDestination')->invoke(
            $this, $dispatch, new CommandReplyDestination($reply->correlationId),
            new NodeReportWorkerServer(1), null, $commands, null, null,
        );
        foreach ($walk as $_) {
        }
    }

    /** Drives the native roster and all available report phases. */
    public function pass(): void
    {
        $this->framesAndAsks();
    }

    /** @param CommandRequestDTO $request Ask arriving on the command socket in this pass */
    public function askDuringPass(CommandRequestDTO $request): void
    {
        $this->requestNodeReport($request);
        $this->framesAndAsks();
    }

    /** @return SignalRouter Empty routing table for directly addressed master frames */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Manager that reports the node agent started */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new NodeReportAgentManager();
    }

    /** Native callbacks cue only; the whole-roster business phase precedes the completion barrier. */
    private function framesAndAsks(): void
    {
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->getValue($this)->tick();
        new ReflectionMethod(DaemonManager::class, 'dispatchProcessRoster')->invoke($this);
        foreach (self::FRAME_PHASES as $phase) {
            new ReflectionMethod(DaemonManager::class, $phase)->invoke($this);
        }
        new ReflectionMethod(DaemonManager::class, 'dispatchCompletedNodeReport')->invoke($this);
    }
}

/** Worker source with pid-less workers and captured addressed frames. */
final class NodeReportWorkerServer extends WorkerServer
{
    /** @var list<DaemonAgentMessageDTO> */
    public array $delivered = [];

    public ?HilosException $reportFailure = null;

    /** @param int $workers Number of live workers to report */
    public function __construct(private readonly int $workers)
    {
    }

    /** @return list<DaemonWorkerPicture> Live workers without a pid */
    public function liveWorkerPictures(): array
    {
        $pictures = [];
        for ($index = 1; $index <= $this->workers; $index++) {
            $pictures[] = new DaemonWorkerPicture($index, WorkerConstants::TYPE_REGULAR, null, null, []);
        }
        return $pictures;
    }

    /**
     * @param string $agentType Receiver type
     * @param ?string $agentIndex Receiver index
     * @param DaemonAgentMessageDTO $messageDto Addressed master frame
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $this->delivered[] = $messageDto;
        if ($messageDto->signal->signalName->getName() === HilosSignalConstants::DAEMON_MASTER_REPORT_NOW && $this->reportFailure !== null) {
            throw $this->reportFailure;
        }
    }

    /**
     * @param string $signalName Signal name
     * @return int Frames of that name delivered so far
     */
    public function count(string $signalName): int
    {
        return count(array_filter(
            $this->delivered,
            static fn (DaemonAgentMessageDTO $frame): bool => $frame->signal->signalName->getName() === $signalName,
        ));
    }

    /**
     * @param int $index Delivery position
     * @return string Signal name delivered there
     */
    public function name(int $index): string
    {
        return $this->delivered[$index]->signal->signalName->getName();
    }

    protected function onStart(): void
    {
    }
}

/** Capture of the master's addressed sends. */
final class NodeReportSignalCapture implements MasterSignalSender
{
    /** @var list<array{string, ?string, string, SignalDataInterface}> */
    public array $sent = [];

    public ?LogicException $failure = null;

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @param string $signalName Signal name
     * @param SignalDataInterface $data Payload
     */
    public function sendToAgent(string $agentType, ?string $agentIndex, string $signalName, SignalDataInterface $data): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->sent[] = [$agentType, $agentIndex, $signalName, $data];
    }

    /**
     * @param string $signalName Signal name
     * @param SignalDataInterface $data Payload
     * @throws LogicException Always: the ask addresses one agent
     */
    public function sendToWorkers(string $signalName, SignalDataInterface $data): void
    {
        throw new LogicException('The node report ask addresses one agent');
    }
}

/** Agent roster with a switchable node agent. */
final class NodeReportAgentManager extends AgentManagerDaemon
{
    public bool $started = true;

    /** Registers an already-started node proxy without starting a worker process. */
    public function __construct()
    {
        $agent = new DaemonNodeAgentDaemon();
        $agent->setWorkerClient(new ReflectionClass(WorkerClient::class)->newInstanceWithoutConstructor());
        $this->addAgent(HilosAgentType::HILOS_DAEMON_NODE, $agent, 1, false);
    }

    /**
     * @param string $agentId Receiver id
     * @return bool Whether the node agent is up
     */
    public function isAgentStarted(string $agentId): bool
    {
        return $this->started && $agentId === HilosAgentType::HILOS_DAEMON_NODE;
    }

    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused instance index
     * @return AgentDaemonInterface Never returned
     * @throws LogicException Always: the test starts no agents
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('The node report test starts no agents');
    }
}

/** Runtime facade exposing only the phase the master reads. */
final class NodeReportFreezeRtContext extends RtContext
{
    public object $hilosProtectedModeRuntime;

    public function __construct()
    {
        $this->hilosProtectedModeRuntime = (object)['phase' => StateProtectedModeRuntime::PHASE_INACTIVE];
    }

    public function configure(): void
    {
    }
}

/** Topology with the node receiver declared as a per-node agent. */
abstract class NodeReportTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_DAEMON_NODE => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}

/** Existing command delivery capture records whether a node wait survived until delivery. */
final class NodeReportCommandCapture extends CommandServer
{
    /** @var list<CommandReplyDTO> */
    public array $replies = [];
    /** @var list<bool> */
    public array $holdingAtDelivery = [];
    public ?DaemonNodeReportTestRequests $owner = null;

    /** This fixture opens no command listener. */
    public function __construct()
    {
    }

    /**
     * @param string $correlationId Console correlation
     * @param CommandReplyDTO $reply Reply handed to the existing channel
     */
    public function deliver(string $correlationId, CommandReplyDTO $reply): void
    {
        $this->holdingAtDelivery[] = $this->owner?->hasWaiter($correlationId) ?? false;
        $this->replies[] = $reply;
    }
}
