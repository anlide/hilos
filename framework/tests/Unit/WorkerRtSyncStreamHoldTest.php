<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\PageAccessReassessment;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Hilos;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\Ordinary\RtOrdinaryQueue;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\Snapshot\RtWorkScheduler;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\State\Collection\HilosProbeFleetStatuses;
use Hilos\Runtime\State\Item\HilosProbeFleetStatus;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\Stream\RtSyncStreamStatus;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Actions\Item\RtActions as RtItemActions;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceReleasedDTO;
use Hilos\Socket\Worker\DTO\WorkerRtStalenessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncStreamDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronFiredDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * A reading worker hands a streamed RT write to its agents before a signal the master wrote after it.
 * The worker's RT pass applies a streamed operation in portions and leaves what its time does not
 * cover to the next pass; a frame queued behind the part waits for the apply instead of reaching its
 * agent first. A writing worker keeps the same order on its own socket: a signal queued behind its
 * write leaves only behind the write's last part, so the master reads the write whole first.
 */
final class WorkerRtSyncStreamHoldTest extends TestCase
{
    private const string COLLECTION = 'streamRows';

    private const string SIGNAL = 'after-the-write';

    private ?SignalRouter $previousRouter;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        RtOrdinaryAdmission::reset();
        ExecutionContext::clear();
    }

    protected function tearDown(): void
    {
        RtOrdinaryAdmission::reset();
        // run() states what kind of process this is, process-wide.
        SourceInterestRegistry::readsWhatItMounts();
        ExecutionContext::clear();
        Hilos::$sr = $this->previousRouter;

        parent::tearDown();
    }

    public function testASignalQueuedBehindAStreamedWriteReachesTheAgentOnlyAfterTheWriteIsApplied(): void
    {
        $agent = new WorkerRtSyncStreamHoldTestAgent();
        $manager = new WorkerRtSyncStreamHoldTestManager($agent, [
            new WorkerRtSyncStreamDTO($this->createPart()),
            new DaemonAgentMessageDTO($agent->getId(), new SignalDTO(
                new SignalSource(SignalSource::DAEMON),
                new SignalType(SignalTypeConstants::SYSTEM),
                new SignalName(self::SIGNAL),
                new SystemSignalDTO(self::SIGNAL),
            )),
        ]);
        // The first pass has no ordinary share at all, so the write it takes cannot be applied within it.
        new ReflectionProperty(WorkerManager::class, 'rtWork')->setValue($manager, new RtWorkScheduler(1, 0));

        try {
            $manager->run();
        } finally {
            $manager->unbindRtPass();
        }

        $this->assertSame([self::COLLECTION, self::SIGNAL], $agent->heard, 'The agent gets the write before the signal sent after it');
        $this->assertGreaterThan(1, $manager->iterations, 'The write was not applied on the pass that took it');
        $this->assertSame([RtSyncStreamStatus::Applied], $manager->client->answers());
        $this->assertSame([], $manager->failures);
    }

    /**
     * Only what the master writes in its turn behind its writes waits on a reading worker for the write:
     * the stop of another agent, behind a signal that waits, is handled as it comes, ahead of the write.
     * The stop of the agent the signal is for waits behind the signal, so the agent still gets it.
     */
    public function testAStopBehindAWaitingSignalIsHandledAtOnceUnlessItIsForTheAgentTheSignalIsFor(): void
    {
        $agent = new WorkerRtSyncStreamHoldTestAgent();
        $bystander = new WorkerRtSyncStreamHoldTestBystander($agent);
        $manager = new WorkerRtSyncStreamHoldTestManager($agent, [
            new WorkerRtSyncStreamDTO($this->createPart()),
            new DaemonAgentMessageDTO($agent->getId(), new SignalDTO(
                new SignalSource(SignalSource::DAEMON),
                new SignalType(SignalTypeConstants::SYSTEM),
                new SignalName(self::SIGNAL),
                new SystemSignalDTO(self::SIGNAL),
            )),
            new AgentStopDTO($bystander->getId()),
            new AgentStopDTO($agent->getId()),
        ], [$bystander]);
        // The first pass has no ordinary share at all, so the write it takes cannot be applied within it.
        new ReflectionProperty(WorkerManager::class, 'rtWork')->setValue($manager, new RtWorkScheduler(1, 0));

        try {
            $manager->run();
        } finally {
            $manager->unbindRtPass();
        }

        $this->assertSame([WorkerRtSyncStreamHoldTestBystander::STOPPED, self::COLLECTION, self::SIGNAL, WorkerRtSyncStreamHoldTestAgent::STOPPED],
            $agent->heard, 'The other agent stops ahead of the write; the signal\'s own agent gets the write and the signal, then stops');
        $this->assertGreaterThan(1, $manager->iterations, 'The write was not applied on the pass that took it');
        $this->assertSame([], $manager->failures);
    }

    /**
     * A thaw the master wrote behind a streamed write waits on a reading worker until the write is applied,
     * so the agent hears of the write while the row is still frozen here; a freeze of the same row the master
     * wrote after the thaw waits behind it, so the row ends frozen as the master's copy is.
     */
    public function testAThawWaitsForTheWriteBeforeItAndAFreezeOfItsCollectionWaitsBehindTheThaw(): void
    {
        RtStaleness::mark(self::COLLECTION, ['row-1'], 1700000000.25);
        $agent = new WorkerRtSyncStreamHoldTestAgent();
        $manager = new WorkerRtSyncStreamHoldTestManager($agent, [
            new WorkerRtSyncStreamDTO($this->createPart()),
            new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], null),
            new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], 1700000000.5),
            new DaemonAgentMessageDTO($agent->getId(), new SignalDTO(
                new SignalSource(SignalSource::DAEMON),
                new SignalType(SignalTypeConstants::SYSTEM),
                new SignalName(self::SIGNAL),
                new SystemSignalDTO(self::SIGNAL),
            )),
        ]);
        // The first pass has no ordinary share at all, so the write it takes cannot be applied within it.
        new ReflectionProperty(WorkerManager::class, 'rtWork')->setValue($manager, new RtWorkScheduler(1, 0));

        try {
            $manager->run();
            $frozenSince = RtStaleness::staleSince(self::COLLECTION, 'row-1');
        } finally {
            $manager->unbindRtPass();
            RtStaleness::reset();
        }

        $this->assertSame([1700000000.25], $agent->frozenAtWrite, 'The row is still frozen when the agent hears of the write');
        $this->assertSame(1700000000.5, $frozenSince, 'The freeze is handled behind the thaw');
        $this->assertGreaterThan(1, $manager->iterations, 'The write was not applied on the pass that took it');
        $this->assertSame([], $manager->failures);
    }

    /**
     * The writing end: a write too long for one part, and a signal queued right behind it. The second
     * part goes out only on the master's `next`, and the signal waits for it instead of reaching the
     * master between the two parts, where the master would hand it on before the write.
     */
    public function testASignalQueuedBehindATwoPartWriteLeavesTheWorkerOnlyBehindTheWritesLastPart(): void
    {
        $manager = new WorkerRtSyncStreamHoldTestWriter(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 3, [1 => WorkerRtSyncStreamHoldTestWriter::agentSignal(...)]);

        try {
            $manager->run();
        } finally {
            $manager->unbindRtPass();
        }

        $this->assertSame(['part 0', 'final part 1', WorkerAgentMessageDTO::class], $manager->client->written(),
            'The signal leaves behind the write\'s last part');
        $this->assertSame([], $manager->failures);
    }

    /**
     * The writing end, when the pass that took the write had no time to cut it: the signal queued right
     * behind the write waits for its part instead of leaving ahead of it.
     */
    public function testASignalQueuedBehindAWriteThePassCouldNotCutLeavesTheWorkerOnlyBehindTheWrite(): void
    {
        $manager = new WorkerRtSyncStreamHoldTestWriter(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]),
            2, [1 => WorkerRtSyncStreamHoldTestWriter::agentSignal(...)]);
        // The pass that takes the write has no ordinary share at all, so it cannot cut its part.
        new ReflectionProperty(WorkerManager::class, 'rtWork')->setValue($manager, new RtWorkScheduler(1, 0));

        try {
            $manager->run();
        } finally {
            $manager->unbindRtPass();
        }

        $this->assertSame(['final part 0', WorkerAgentMessageDTO::class], $manager->client->written(),
            'The signal leaves behind the write it was queued after');
        $this->assertGreaterThan(2, $manager->iterations, 'The write was not cut on the pass that took it');
        $this->assertSame([], $manager->failures);
    }

    /**
     * The writing end: an agent's word that its work is done waits for a write like a signal does. A
     * command reply queued right behind a write too long for one part, and the report of a stopped
     * agent with the release of what it owned in RT ahead of it, written while the master has not
     * answered its first part, leave only behind the write's last part: the caller acts on the reply,
     * and the master starts the agent elsewhere on the report.
     */
    public function testACommandReplyAndAStopReportLeaveOnlyBehindTheWritesLastPart(): void
    {
        $manager = new WorkerRtSyncStreamHoldTestWriter(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 4, [
            1 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                $writer->client->answering = false;
                WorkerRtSyncStreamHoldTestWriter::commandReply();
            },
            2 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                $writer->reportAgentStopped();
                $writer->client->answer();
            },
        ]);

        try {
            $manager->run();
        } finally {
            $manager->unbindRtPass();
        }

        $this->assertSame(['part 0', 'final part 1', WorkerAgentMessageDTO::class, WorkerRtSourceReleasedDTO::class,
            'agent_stopped ' . WorkerRtSyncStreamHoldTestAgent::AGENT_TYPE], $manager->client->written(),
            'The reply, the release of the RT sources and the stop report leave behind the write\'s last part');
        $this->assertSame([], $manager->failures);
    }

    /**
     * The writing end: an access re-decision of the pages of one session, which the master turns into
     * the connections that carry it and hands to the workers to re-decide against their RT copy, waits
     * for a write like the re-decision by user does. The outcome of a cron rule a console asked to fire
     * is a command's answer, written behind the signals of the tick that fired the rule: queued while the
     * master has not answered the write's first part, both leave only behind its last part, in order.
     */
    public function testASessionReDecisionAndACronFireOutcomeLeaveOnlyBehindTheWritesLastPart(): void
    {
        $manager = new WorkerRtSyncStreamHoldTestWriter(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 4, [
            1 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                $writer->client->answering = false;
                PageAccessReassessment::forSession(hash('sha256', 'session-1'));
            },
            2 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                new ReflectionMethod(WorkerManager::class, 'sendCronFired')->invoke($writer,
                    new WorkerTestCronFiredDTO('cid-1', true, 1_000, null));
                $writer->client->answer();
            },
        ]);

        try {
            $manager->run();
        } finally {
            $manager->unbindRtPass();
        }

        $this->assertSame(['part 0', 'final part 1', WorkerAgentMessageDTO::class, WorkerTestCronFiredDTO::class],
            $manager->client->written(), 'The re-decision and the cron outcome leave behind the write\'s last part');
        $this->assertSame([], $manager->failures);
    }

    /**
     * The writing end, an agent the master stops - as a node's freeze does - while the master has not
     * answered the first part of its write: the release of what it owned in RT and the report of its
     * stop leave behind every part of that write and behind the write its stop hook made, so the master,
     * which starts the agent elsewhere on the report, reads no write of the stopped instance after it,
     * and still owns the collection when it hands the last of them to the peers.
     */
    public function testAStopReportOfAnAgentTheMasterStopsLeavesBehindItsWritesAndItsStopHooksWrite(): void
    {
        $agent = new WorkerRtSyncStreamHoldTestLeavingAgent();
        $manager = new WorkerRtSyncStreamHoldTestWriter(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 5, [
            1 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                $writer->client->answering = false;
            },
            2 => static function (WorkerRtSyncStreamHoldTestWriter $writer) use ($agent): void {
                $writer->client->deliver(new AgentStopDTO($agent->getId()));
            },
            3 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                $writer->client->answer();
            },
        ], [$agent]);

        try {
            $manager->run();
        } finally {
            $manager->unbindRtPass();
        }

        $this->assertSame(['part 0', 'final part 1', 'final part 0', WorkerRtSourceReleasedDTO::class,
            'agent_stopped ' . WorkerRtSyncStreamHoldTestLeavingAgent::AGENT_TYPE], $manager->client->written(),
            'The release of the RT sources and the stop report leave behind the write\'s last part and the stop hook\'s write');
        $this->assertSame([], $manager->failures);
    }

    /**
     * The writing end, an agent that asks to go while the master has not answered the first part of a
     * write: the release of what it owned in RT and the report of its stop leave behind every part of
     * that write and behind the write its stop hook made, as on a stop the master asks for.
     */
    public function testAStopReportOfAnAgentThatAsksToGoLeavesBehindItsWritesAndItsStopHooksWrite(): void
    {
        $agent = new WorkerRtSyncStreamHoldTestLeavingAgent();
        $manager = new WorkerRtSyncStreamHoldTestWriter(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 5, [
            1 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                $writer->client->answering = false;
            },
            2 => static function () use ($agent): void {
                $agent->leave();
            },
            3 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                $writer->client->answer();
            },
        ], [$agent]);

        try {
            $manager->run();
        } finally {
            $manager->unbindRtPass();
        }

        $this->assertSame(['part 0', 'final part 1', 'final part 0', WorkerRtSourceReleasedDTO::class,
            'agent_stopped ' . WorkerRtSyncStreamHoldTestLeavingAgent::AGENT_TYPE], $manager->client->written(),
            'The release of the RT sources and the stop report leave behind the write\'s last part and the stop hook\'s write');
        $this->assertSame([], $manager->failures);
    }

    /**
     * The writing end, an agent whose stop hook leaves two rows with their terminal values while the stream
     * has no room for what its FIFO committed: the service reserve takes the first, the second is owed. The
     * release of what it owned in RT and the report of its stop leave only behind the write that pays the
     * debt, made under the agent's RT claim once the stream frees, so the master, which starts the agent
     * elsewhere on the report, reads no write of the stopped instance after it, and still owns the
     * collection when it hands that write to the peers.
     */
    public function testAStopReportOfAnAgentOwingACleanupLeavesBehindTheWriteThatPaysIt(): void
    {
        $agent = new WorkerRtSyncStreamHoldTestIndebtedAgent();
        $context = new WorkerRtSyncStreamHoldTestRtContext();
        $context->configure();
        $context->bindStateCollectionNames();
        Hilos::$rt = $context;
        RtTruthSourceRegistry::register(HilosProbeFleetStatus::RT_COLLECTION, TruthSourceKeys::all(), $agent->getId());
        ExecutionContext::setCurrentAgentId($agent->getId());
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxCleanupOperations: 1));
        ExecutionContext::clear();
        $room = true;
        $manager = new WorkerRtSyncStreamHoldTestWriter(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]), 5, [
            1 => static function () use ($context): void {
                $context->rowActions()->put('w1', 1);
                $context->rowActions()->put('w2', 2);
            },
            2 => static function () use ($agent, &$room): void {
                $room = false;
                RtOrdinaryAdmission::consumeBy(static function () use (&$room): bool {
                    return $room;
                });
                $agent->leave();
            },
            3 => static function () use (&$room): void {
                $room = true;
            },
        ], [$agent]);

        try {
            $manager->run();
        } finally {
            $manager->unbindRtPass();
            RtTruthSourceRegistry::unregisterAgent($agent->getId());
            Hilos::$rt = null;
        }

        $this->assertSame(['final part 0', 'final part 0', 'final part 0', WorkerRtSourceReleasedDTO::class,
            'agent_stopped ' . WorkerRtSyncStreamHoldTestIndebtedAgent::AGENT_TYPE], $manager->client->written(),
            'The release of the RT sources and the stop report leave behind the write of the terminal value its agent owed');
        $this->assertSame(20, $context->jobsDoneOf('w2'), 'The owed terminal value is written, not dropped');
        $this->assertFalse(RtTruthSourceRegistry::hasTruthSource(HilosProbeFleetStatus::RT_COLLECTION), 'The claim goes with the report');
        $this->assertSame([], $manager->failures);
    }

    /**
     * The writing end, an agent restarted on the same worker while its stop report waits for a write: the
     * report of its new start leaves behind the report of its old stop, so the master never reads the
     * restarted agent as started ahead of its old instance's stop. The start of another agent leaves at once.
     */
    public function testAStartReportLeavesBehindAWaitingStopReportOfTheSameAgent(): void
    {
        $manager = new WorkerRtSyncStreamHoldTestWriter(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 5, [
            1 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                $writer->client->answering = false;
            },
            2 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                $writer->reportAgentStopped();
                $writer->reportAgentStarted(WorkerRtSyncStreamHoldTestAgent::AGENT_TYPE);
                $writer->reportAgentStarted(WorkerRtSyncStreamHoldTestBystander::AGENT_TYPE);
                $writer->client->answer();
            },
        ]);

        try {
            $manager->run();
        } finally {
            $manager->unbindRtPass();
        }

        $this->assertSame(['part 0', 'agent_started ' . WorkerRtSyncStreamHoldTestBystander::AGENT_TYPE, 'final part 1',
            WorkerRtSourceReleasedDTO::class, 'agent_stopped ' . WorkerRtSyncStreamHoldTestAgent::AGENT_TYPE,
            'agent_started ' . WorkerRtSyncStreamHoldTestAgent::AGENT_TYPE],
            $manager->client->written(), 'The restarted agent\'s start leaves behind its old stop, the other start at once');
        $this->assertSame([], $manager->failures);
    }

    /**
     * The writing end, an agent that writes a socket's connection row and answers the browser's handshake
     * right behind it, while the master has not answered the write's first part: the response leaves only
     * behind the write's last part, so the master never hands the browser who it is ahead of the row the
     * browser's next frames are judged against. The rotation ticket the agent sends the same socket behind
     * the response leaves behind the response, and an answer to another socket leaves at once. Once the
     * response is out, the socket waits for nothing: an answer to it behind the next write leaves at once.
     */
    public function testAHandshakeResponseLeavesBehindTheWritesLastPartAndTheSocketsNextAnswerBehindIt(): void
    {
        $manager = new WorkerRtSyncStreamHoldTestWriter(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 8, [
            1 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                $writer->client->answering = false;
                WorkerRtSyncStreamHoldTestWriter::answerSocket('handshake', 'accept-1', new HandshakeResponseSignalData());
                WorkerRtSyncStreamHoldTestWriter::answerSocket('ticket', 'accept-1', new SystemSignalDTO('ticket'));
                WorkerRtSyncStreamHoldTestWriter::answerSocket('answer', 'accept-2', new SystemSignalDTO('answer'));
            },
            2 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                $writer->client->answer();
            },
            3 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                $writer->client->answering = false;
                Hilos::$sr->queueSignal(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::RT_SYNC_CREATED),
                    new SignalName(SignalTypeConstants::RT_SYNC_CREATED), new RtSyncCreatedSignalData(self::COLLECTION, 'row-2',
                        ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]));
                WorkerRtSyncStreamHoldTestWriter::answerSocket('answer', 'accept-1', new SystemSignalDTO('answer'));
            },
            4 => static function (WorkerRtSyncStreamHoldTestWriter $writer): void {
                $writer->client->answer();
            },
        ]);

        try {
            $manager->run();
        } finally {
            $manager->unbindRtPass();
        }

        $this->assertSame(['part 0', 'answer accept-2', 'final part 1', 'handshake accept-1', 'ticket accept-1', 'part 0',
            'answer accept-1', 'final part 1'], $manager->client->written(),
            'The response and the ticket behind it leave behind the write\'s last part, the other socket\'s answer at once');
        $this->assertSame([], $manager->failures);
    }

    /**
     * @return RtSyncStreamFrame The one part of a small streamed create, as the master's end of the stream cuts it
     */
    private function createPart(): RtSyncStreamFrame
    {
        $frames = [];
        $master = new RtSyncStreamChannel(
            static function (RtSyncStreamFrame $frame) use (&$frames): void {
                $frames[] = $frame;
            },
            static fn (): null => null,
        );
        $master->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]));
        $this->assertCount(1, $frames);
        $this->assertTrue($frames[0]->final);

        return $frames[0];
    }
}

/**
 * Worker manager whose daemon link is a script: the loop runs for real until the case has what it waits for.
 */
abstract class WorkerRtSyncStreamHoldTestLoop extends WorkerManager
{
    /** The longest run the case allows the loop before it gives up */
    protected const int MAX_ITERATIONS = 64;

    /** Link standing in for the daemon's */
    public readonly WorkerRtSyncStreamHoldTestClient $client;

    /** Loop iterations the project tick saw */
    public int $iterations = 0;

    /** @var list<ContainedFailure> Failures the worker contained, in order */
    public array $failures = [];

    /**
     * @param list<WorkerDTO> $frames Frames the daemon wrote, in order
     */
    public function __construct(array $frames)
    {
        parent::__construct(1);
        $this->client = new WorkerRtSyncStreamHoldTestClient($frames);
    }

    /** Lets go of the process arena's wake the loop bound, as the cleanup this case skips does. */
    public function unbindRtPass(): void
    {
        $wake = new ReflectionProperty(WorkerManager::class, 'rtPassWake')->getValue($this);
        if ($wake instanceof Closure) {
            RtRowArena::process()->unbindPassWake($wake);
        }
    }

    /** From this call on, the RT pass has its usual shares. */
    protected function restoreRtShares(): void
    {
        new ReflectionProperty(WorkerManager::class, 'rtWork')->setValue($this, new RtWorkScheduler());
    }

    protected function connectToDaemon(): void
    {
        $this->daemonClient = $this->client;
    }

    protected function onTickFailure(ContainedFailure $failure): void
    {
        $this->failures[] = $failure;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerRtSyncStreamHoldTestAgentManager();
    }

    protected function setupErrorHandling(): void
    {
    }

    protected function setupSignalHandlers(): void
    {
    }

    protected function checkDaemonLiveness(float $loopStartTime): void
    {
    }

    protected function sleepWithPreciseTiming(float $loopStartTime, int $targetLoopTimeMicroseconds = 10000): void
    {
    }

    protected function cleanup(): void
    {
    }
}

/**
 * The reading worker: the loop runs until the agent heard the signal.
 */
final class WorkerRtSyncStreamHoldTestManager extends WorkerRtSyncStreamHoldTestLoop
{
    /**
     * @param WorkerRtSyncStreamHoldTestAgent $agent Agent the frames reach
     * @param list<WorkerDTO> $frames Frames the daemon wrote, in order
     * @param list<AbstractAgent> $others Other agents running on the worker
     */
    public function __construct(private readonly WorkerRtSyncStreamHoldTestAgent $agent, array $frames, array $others = [])
    {
        parent::__construct($frames);
        $this->agentManager->addAgent($agent->getId(), $agent);
        foreach ($others as $other) {
            $this->agentManager->addAgent($other->getId(), $other);
        }
    }

    protected function onTick(): void
    {
        // From the second pass on, the RT pass has its usual shares.
        if (++$this->iterations === 1) {
            $this->restoreRtShares();
        }
        if ($this->agent->signalled || $this->iterations >= self::MAX_ITERATIONS) {
            $this->shouldExit = true;
        }
    }
}

/**
 * The writing worker: on its first tick an agent's handler queues an RT write, and the case's step of each
 * tick writes what follows it; the loop runs until the worker wrote the case's count of frames.
 */
final class WorkerRtSyncStreamHoldTestWriter extends WorkerRtSyncStreamHoldTestLoop
{
    private const string SIGNAL = 'after-the-write';

    /**
     * @param RtSyncCreatedSignalData $write RT write the agent makes
     * @param int $frames Frames the worker writes in all, as {@see WorkerRtSyncStreamHoldTestClient::written()} counts them
     * @param array<int, Closure(self): void> $steps What the case does on each tick, by its number, after the write is queued
     * @param list<AbstractAgent> $agents Agents running on the worker
     */
    public function __construct(
        private readonly RtSyncCreatedSignalData $write,
        private readonly int $frames,
        private readonly array $steps,
        array $agents = [],
    ) {
        parent::__construct([]);
        foreach ($agents as $agent) {
            $this->agentManager->addAgent($agent->getId(), $agent);
        }
    }

    /** An agent's signal to another agent, queued as {@see AbstractAgent::sendToAgent()} queues it. */
    public static function agentSignal(): void
    {
        Hilos::$sr->queueSignal(new SignalSource(SignalSource::AGENT, WorkerRtSyncStreamHoldTestAgent::AGENT_TYPE),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL), new SignalName(self::SIGNAL),
            new AgentSignalData(new SystemSignalDTO(self::SIGNAL)));
    }

    /** An agent's answer to the command channel, queued as {@see AbstractAgent::replyToCommand()} queues it. */
    public static function commandReply(): void
    {
        Hilos::$sr->queueSignal(new SignalSource(SignalSource::AGENT, WorkerRtSyncStreamHoldTestAgent::AGENT_TYPE),
            new SignalType(SignalTypeConstants::COMMAND_REPLY), new SignalName('cid-1'), CommandReplyDTO::ok('cid-1'));
    }

    /**
     * An agent's answer to one browser, queued as {@see AbstractAgent::sendToUser()} queues it.
     *
     * @param string $name Name of the answer
     * @param string $acceptKey Socket it is addressed to
     * @param SignalDataInterface $data What it says
     */
    public static function answerSocket(string $name, string $acceptKey, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(new SignalSource(SignalSource::AGENT, WorkerRtSyncStreamHoldTestAgent::AGENT_TYPE),
            new SignalType(SignalTypeConstants::WS_USER), new SignalName($name), new WebSocketSignalData($data, $acceptKey));
    }

    /** Reports an agent stopped, as the worker does once it has stopped one. */
    public function reportAgentStopped(): void
    {
        new ReflectionMethod(WorkerManager::class, 'notifyAgentStopped')->invoke($this, WorkerRtSyncStreamHoldTestAgent::AGENT_TYPE);
    }

    /**
     * Reports an agent started, as the worker does once it has started one.
     *
     * @param string $agentType Type of the singleton agent started
     */
    public function reportAgentStarted(string $agentType): void
    {
        new ReflectionMethod(WorkerManager::class, 'notifyAgentStarted')->invoke($this, $agentType, $agentType, null);
    }

    protected function onTick(): void
    {
        ++$this->iterations;
        if ($this->iterations === 1) {
            Hilos::$sr->queueSignal(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::RT_SYNC_CREATED),
                new SignalName(SignalTypeConstants::RT_SYNC_CREATED), $this->write);
        } elseif ($this->iterations === 2) {
            // The pass of the write's own iteration ran on the shares the case gave it.
            $this->restoreRtShares();
        }
        if (isset($this->steps[$this->iterations])) {
            ($this->steps[$this->iterations])($this);
        }
        if (count($this->client->written()) >= $this->frames || $this->iterations >= self::MAX_ITERATIONS) {
            $this->shouldExit = true;
        }
    }
}

/**
 * Agent manager stub: the case hands the manager its agent.
 */
final class WorkerRtSyncStreamHoldTestAgentManager extends AgentManager
{
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new WorkerRtSyncStreamHoldTestAgent();
    }
}

/**
 * Daemon link stub: connected, handing out the scripted frames once, and keeping what the worker sends.
 * It answers the parts the worker streams as the master's end does: `next` to a part that is not the
 * last, `applied` to the last.
 */
final class WorkerRtSyncStreamHoldTestClient extends WorkerDaemonClient
{
    /** Whether the master answers the worker's parts as they arrive; otherwise the answers wait for {@see self::answer()} */
    public bool $answering = true;

    /** @var list<WorkerDTO|array<string, mixed>> Messages the worker sent, in order */
    private array $sent = [];

    /** @var list<WorkerDTO> Answers the master holds back */
    private array $unanswered = [];

    /**
     * @param list<WorkerDTO> $frames Frames to hand out, in order
     */
    public function __construct(private array $frames)
    {
        $this->state = DaemonConnectionState::CONNECTED;
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function read(): void
    {
    }

    public function write(): void
    {
    }

    public function getNextMessage(): ?WorkerDTO
    {
        return array_shift($this->frames);
    }

    /**
     * @param WorkerDTO $frame Frame the daemon writes now, behind the ones not handed out yet
     */
    public function deliver(WorkerDTO $frame): void
    {
        $this->frames[] = $frame;
    }

    /**
     * @param WorkerDTO|array<string, mixed> $data Message the worker sent
     */
    public function send(WorkerDTO|array $data): void
    {
        $this->sent[] = $data;
        if ($data instanceof WorkerRtSyncStreamDTO && $data->frame->isPart()) {
            $part = $data->frame;
            $answer = new WorkerRtSyncStreamDTO(RtSyncStreamFrame::ack($part->streamId, $part->sequence, $part->part,
                $part->final ? RtSyncStreamStatus::Applied : RtSyncStreamStatus::Next));
            if ($this->answering) {
                $this->frames[] = $answer;
            } else {
                $this->unanswered[] = $answer;
            }
        }
    }

    /** The master answers what it held back, and from now on answers at once. */
    public function answer(): void
    {
        array_push($this->frames, ...$this->unanswered);
        $this->unanswered = [];
        $this->answering = true;
    }

    /**
     * @return list<string> What the worker wrote, in order: its parts by number, its message arrays by type and agent,
     *     its answers to browsers by name and socket, its other frames by class; the stream's answers left out
     */
    public function written(): array
    {
        $written = [];
        foreach ($this->sent as $message) {
            if (is_array($message)) {
                $written[] = $message[WorkerDTO::TYPE] . ' ' . $message[AgentConstants::FIELD_AGENT_ID];
            } elseif ($message instanceof WorkerAgentMessageDTO && $message->signal->data instanceof WebSocketSignalData) {
                $written[] = $message->signal->signalName->getName() . ' ' . $message->signal->data->targetAcceptKey;
            } elseif (!$message instanceof WorkerRtSyncStreamDTO) {
                $written[] = get_debug_type($message);
            } elseif ($message->frame->isPart()) {
                $written[] = $message->frame->final ? "final part {$message->frame->part}" : "part {$message->frame->part}";
            }
        }

        return $written;
    }

    /** @return list<RtSyncStreamStatus> Answers the worker's stream gave, in order */
    public function answers(): array
    {
        $answers = [];
        foreach ($this->sent as $message) {
            if ($message instanceof WorkerRtSyncStreamDTO && !$message->frame->isPart()) {
                $answers[] = $message->frame->status;
            }
        }

        return $answers;
    }
}

/**
 * Agent that writes down, in order, the collection of each RT create, the name of each system signal it gets, and its stop.
 */
final class WorkerRtSyncStreamHoldTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_rt_stream_hold';

    /** What it writes down when it stops */
    public const string STOPPED = 'stopped';

    /** @var list<string> What reached it, in order */
    public array $heard = [];

    /** Whether a system signal reached it */
    public bool $signalled = false;

    /** @var list<?float> Since when this worker's copy of each row it heard created was frozen then */
    public array $frozenAtWrite = [];

    public function onSignalRtSyncCreated(RtSyncCreatedSignalData $data, string $source, string $name): void
    {
        $this->heard[] = $data->collectionKey;
        $this->frozenAtWrite[] = RtStaleness::staleSince($data->collectionKey, $data->stateId);
    }

    public function onSignalSystem(SignalDataInterface $data, string $source, string $name): void
    {
        $this->heard[] = $name;
        $this->signalled = true;
    }

    public function onStop(): void
    {
        $this->heard[] = self::STOPPED;
    }
}

/**
 * Another agent on the same worker, which writes its stop down where the case's agent writes what it hears.
 */
final class WorkerRtSyncStreamHoldTestBystander extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_rt_stream_hold_bystander';

    /** What it writes down when it stops */
    public const string STOPPED = 'bystander stopped';

    /**
     * @param WorkerRtSyncStreamHoldTestAgent $agent Agent whose record its stop goes to
     */
    public function __construct(private readonly WorkerRtSyncStreamHoldTestAgent $agent)
    {
    }

    public function onStop(): void
    {
        $this->agent->heard[] = self::STOPPED;
    }
}

/**
 * Agent that leaves a terminal row behind: its stop hook writes one, as a run that ends writes its outcome.
 */
final class WorkerRtSyncStreamHoldTestLeavingAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_rt_stream_hold_leaving';

    /** Asks to go, as an agent whose work is over does. */
    public function leave(): void
    {
        $this->selfStop();
    }

    public function onStop(): void
    {
        Hilos::$sr->queueSignal(new SignalSource(SignalSource::AGENT, self::AGENT_TYPE), new SignalType(SignalTypeConstants::RT_SYNC_CREATED),
            new SignalName(SignalTypeConstants::RT_SYNC_CREATED), new RtSyncCreatedSignalData('streamRows', 'row-terminal', ['owner' => null]));
    }
}

/**
 * Agent whose stop hook leaves two rows of its own with their terminal values, as a run that ends writes its outcome.
 */
final class WorkerRtSyncStreamHoldTestIndebtedAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_rt_stream_hold_indebted';

    /** Asks to go, as an agent whose work is over does. */
    public function leave(): void
    {
        $this->selfStop();
    }

    public function onStop(): void
    {
        $context = Hilos::$rt;
        if (!$context instanceof WorkerRtSyncStreamHoldTestRtContext) {
            throw new LogicException('The probe rows are not mounted');
        }
        foreach (['w1' => 10, 'w2' => 20] as $id => $jobsDone) {
            $context->rowActionsOf($id)->finish([HilosProbeFleetStatus::jobsDone => $jobsDone]);
        }
    }
}

/** Mounts the probe fleet rows the indebted agent owns, behind the framework's own doors. */
final class WorkerRtSyncStreamHoldTestRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections[HilosProbeFleetStatus::RT_COLLECTION] = HilosProbeFleetStatuses::init();
        $this->setRepresent(
            HilosProbeFleetStatus::RT_COLLECTION,
            WorkerRtSyncStreamHoldTestRtCollection::class,
            WorkerRtSyncStreamHoldTestRtActions::class,
            WorkerRtSyncStreamHoldTestRtItemActions::class,
        );
    }

    /** @return WorkerRtSyncStreamHoldTestRtActions Doors of the whole collection */
    public function rowActions(): WorkerRtSyncStreamHoldTestRtActions
    {
        $actions = $this->getRtCollection(HilosProbeFleetStatus::RT_COLLECTION)?->actions;
        if (!$actions instanceof WorkerRtSyncStreamHoldTestRtActions) {
            throw new LogicException('The probe row doors are not mounted');
        }

        return $actions;
    }

    /**
     * @param string $id Row key
     * @return WorkerRtSyncStreamHoldTestRtItemActions Doors of that row
     */
    public function rowActionsOf(string $id): WorkerRtSyncStreamHoldTestRtItemActions
    {
        $item = $this->getRtCollection(HilosProbeFleetStatus::RT_COLLECTION)[$id] ?? null;
        $actions = $item instanceof WorkerRtSyncStreamHoldTestRtItem ? $item->actions : null;
        if (!$actions instanceof WorkerRtSyncStreamHoldTestRtItemActions) {
            throw new LogicException('No probe row ' . $id);
        }

        return $actions;
    }

    /**
     * @param string $id Row key
     * @return mixed The row's jobs done as it stands, null when there is no such row
     */
    public function jobsDoneOf(string $id): mixed
    {
        $item = $this->getRtCollection(HilosProbeFleetStatus::RT_COLLECTION)[$id] ?? null;

        return $item?->toArray()[HilosProbeFleetStatus::jobsDone] ?? null;
    }
}

final class WorkerRtSyncStreamHoldTestRtCollection extends RtCollection
{
    protected function createRtItem(RtState $state): RtItem
    {
        return new WorkerRtSyncStreamHoldTestRtItem($state);
    }
}

final class WorkerRtSyncStreamHoldTestRtItem extends RtItem
{
    public function __get(string $name): mixed
    {
        return match ($name) {
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

final class WorkerRtSyncStreamHoldTestRtActions extends RtActions
{
    public function put(string $id, int $jobsDone): void
    {
        $state = HilosProbeFleetStatus::create($id);
        $state->jobsDone = $jobsDone;
        $this->addStateToCollection($state);
    }
}

final class WorkerRtSyncStreamHoldTestRtItemActions extends RtItemActions
{
    /**
     * @param array<string, mixed> $diff Terminal values
     */
    public function finish(array $diff): void
    {
        $this->applyDiffAsCleanup($diff);
    }
}
