<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\AgentConstants;
use Hilos\Auth\AccountOperation\AccountMergeAdmissionManifest;
use Hilos\Auth\AccountOperation\AccountMergeHandover;
use Hilos\Auth\AccountOperation\AccountMergeHandoverCopy;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\DTO\AccountMergeHandoverSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\Testing\AccountOperationProbeAction;
use Hilos\Auth\AccountOperation\Testing\AccountOperationProbeFrameKind;
use Hilos\Auth\AccountOperation\Testing\AccountOperationProbeRequest;
use Hilos\Environment\EnvAccessor;
use Hilos\Socket\Worker\DTO\WorkerTestAccountOperationProbeDTO;
use Hilos\Socket\Worker\DTO\WorkerTestAccountOperationProbedDTO;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
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
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Hilos;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\Ordinary\RtOrdinaryQueue;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\Snapshot\RtWorkScheduler;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\State\Collection\HilosProbeFleetStatuses;
use Hilos\Runtime\State\Collection\HilosProbeNotes;
use Hilos\Runtime\State\Item\HilosProbeFleetStatus;
use Hilos\Runtime\State\Item\HilosProbeNote;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\Stream\RtSyncStreamStatus;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Actions\Collection\HilosProbeNotesActions;
use Hilos\Runtime\View\Actions\Item\RtActions as RtItemActions;
use Hilos\Runtime\View\Actions\Item\HilosProbeNoteActions;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Collection\HilosProbeNotes as ViewHilosProbeNotes;
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
use Hilos\Socket\Worker\DTO\WorkerRtSnapshotRequestDTO;
use Hilos\Socket\Worker\DTO\WorkerRtStalenessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncStreamDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronFiredDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
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
    public const string COLLECTION = 'streamRows';

    private const string SIGNAL = 'after-the-write';

    /** Consumer reading the notes in the snapshot cases */
    private const string READER = 'page:unit-rt-stream-reader';

    /** Consumer reading the notes once the first has gone */
    private const string READER_AGAIN = 'page:unit-rt-stream-reader-again';

    /** @var int The most RT passes a case runs for what one hand-over of parts owes */
    private const int MAX_OWED_PASSES = 16;

    /** @var int The most RT passes a case runs for a snapshot while the stream has one step a round */
    private const int MAX_STARVED_PASSES = 256;

    /** @var int Number of the worker's first interest report, which a reader the cases set up stands for */
    private const int FIRST_REPORT = 1;

    /** @var string Another RT collection a consumer of the worker takes up */
    private const string OTHER_COLLECTION = 'unitRtStreamOtherCollection';

    private ?SignalRouter $previousRouter;

    private ?RtContext $previousRt;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        RtOrdinaryAdmission::reset();
        ExecutionContext::clear();
    }

    protected function tearDown(): void
    {
        RtOrdinaryAdmission::reset();
        // run() states what kind of process this is, process-wide.
        SourceInterestRegistry::readsWhatItMounts();
        SourceInterestRegistry::releaseConsumer(self::READER);
        SourceInterestRegistry::releaseConsumer(self::READER_AGAIN);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(WorkerRtSyncStreamHoldTestAgent::AGENT_TYPE));
        // Process-wide, so the rows one case froze would otherwise be frozen for the next.
        RtStaleness::reset();
        ExecutionContext::clear();
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRt;

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
     * @param string $action Release or replay through the real worker control entrance
     * @param string $kind Product account-operation payload family
     * @param int $copies Number of complete envelopes to send
     */
    #[TestWith(['release', 'ask', 1])]
    #[TestWith(['release', 'answer', 1])]
    #[TestWith(['release', 'handover', 1])]
    #[TestWith(['replay', 'ask', 1])]
    #[TestWith(['replay', 'answer', 2])]
    public function testOperationProbeReleaseAndReplayUseTheRealSenderBehindAPrecedingRtWrite(string $action, string $kind, int $copies): void
    {
        $previousEnv = Hilos::$env;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
        $frameKind = AccountOperationProbeFrameKind::from($kind);
        $agent = new WorkerRtSyncStreamHoldTestAgent();
        if ($frameKind === AccountOperationProbeFrameKind::Handover) {
            $agent = new class extends AbstractAgent {
                public const string AGENT_TYPE = HilosAgentType::HILOS_USER;

                protected ?string $agentIndex = '9';

                public function onStop(): void
                {
                }
            };
        }
        $payload = match ($frameKind) {
            AccountOperationProbeFrameKind::Ask => new AccountOperationAskSignalData(7, 'parts', 11, '42'),
            AccountOperationProbeFrameKind::Answer => new AccountOperationAnswerSignalData(7, 'parts', 11, AccountOperationAnswerKind::Waits),
            AccountOperationProbeFrameKind::Handover => new AccountMergeHandoverSignalData(
                7, AccountMergeStage::Go->value, 11, '42', new AccountMergeHandover(
                    new AccountMergeHandoverCopy(7, 10, 9, 42, null, null, null, [], [], [], []),
                    new AccountMergeAdmissionManifest([], [], [], []),
                ),
            ),
        };
        $frame = new WorkerAgentMessageDTO($agent->getId(), new SignalDTO(
            $agent->getAgentSignalSource(), new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName($frameKind === AccountOperationProbeFrameKind::Handover
                ? HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_HANDOVER : 'fixture-erasure'),
            new AgentSignalData($payload, 91),
            ['trace' => 'erasure-stream', 'sequence' => 9],
        ));
        $manager = new WorkerRtSyncStreamHoldTestWriter(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 5 + $copies, [
            1 => static function (WorkerRtSyncStreamHoldTestWriter $writer) use ($agent, $frameKind, $frame): void {
                $writer->client->answering = false;
                $writer->handleDaemonMessage(new WorkerTestAccountOperationProbeDTO('arm',
                    new AccountOperationProbeRequest($agent->getId(), AccountOperationProbeAction::Arm, $frameKind)));
                // A complete committed envelope in the real drain, including metadata the queue normally stamps.
                new ReflectionProperty(SignalRouter::class, 'queuedSignals')->getValue(Hilos::$sr)->enqueue($frame->signal);
            },
            2 => static function (WorkerRtSyncStreamHoldTestWriter $writer) use ($agent, $action, $copies, $frame): void {
                $writer->handleDaemonMessage(new WorkerTestAccountOperationProbeDTO('inspect',
                    new AccountOperationProbeRequest($agent->getId(), AccountOperationProbeAction::Inspect)));
                $outcomes = array_values(array_filter($writer->client->sentFrames(),
                    static fn ($message): bool => $message instanceof WorkerTestAccountOperationProbedDTO));
                self::assertTrue($outcomes[0]->ok);
                self::assertTrue($outcomes[1]->ok);
                self::assertSame(['armed' => false, 'matched' => 1, 'held' => $frame->toArray()], $outcomes[1]->state->toArray());
                self::assertSame([WorkerTestAccountOperationProbedDTO::class, 'part 0', WorkerTestAccountOperationProbedDTO::class],
                    $writer->client->written(), 'The preceding RT write is still incomplete when the probe is released');
                $payload = [AgentConstants::FIELD_AGENT_ID => $agent->getId(), AccountOperationProbeRequest::ACTION => $action];
                if ($action === 'replay') {
                    $payload += [AccountOperationProbeRequest::FRAME => $outcomes[1]->state->held->toArray(),
                        AccountOperationProbeRequest::COPIES => $copies];
                }
                $writer->handleDaemonMessage(WorkerDTO::factoryWorkerDTO(new WorkerTestAccountOperationProbeDTO('send',
                    AccountOperationProbeRequest::fromArray($payload))->toJson()));
                self::assertSame([], array_values(array_filter($writer->client->sentFrames(),
                    static fn ($message): bool => $message instanceof WorkerAgentMessageDTO)), 'The real sender still owes the RT barrier');
                $writer->client->answer();
            },
        ], [$agent]);
        try {
            $manager->run();
            self::assertSame(array_merge([
                WorkerTestAccountOperationProbedDTO::class, 'part 0', WorkerTestAccountOperationProbedDTO::class,
                WorkerTestAccountOperationProbedDTO::class, 'final part 1',
            ], array_fill(0, $copies, WorkerAgentMessageDTO::class)), $manager->client->written());
            $sent = array_values(array_filter($manager->client->sentFrames(),
                static fn ($message): bool => $message instanceof WorkerAgentMessageDTO));
            self::assertCount($copies, $sent);
            foreach ($sent as $envelope) {
                self::assertSame($frame->toArray(), $envelope->toArray(), 'Every signal, source, receipt and metadata field survives');
            }
            $outcomes = array_values(array_filter($manager->client->sentFrames(),
                static fn ($message): bool => $message instanceof WorkerTestAccountOperationProbedDTO));
            self::assertTrue($outcomes[2]->ok);
            self::assertSame($action === 'release' ? null : $frame->toArray(), $outcomes[2]->state->held?->toArray());
            self::assertSame([], $manager->failures);
        } finally {
            $manager->unbindRtPass();
            Hilos::$env = $previousEnv;
        }
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
        // This observer keeps the projection mounted after its writer leaves.
        SourceInterestRegistry::register(SourceChange::KIND_RT, HilosProbeFleetStatus::RT_COLLECTION, self::READER);
        OwnershipDeclaration::claimRt($agent::class, $agent->getId());
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
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
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
     * The master's snapshot of a collection reaches a reading worker as an operation of the link's RT
     * stream: staged into the worker's copy and published whole, rows the cut lacks swept, the marks
     * of the master's copy taken over, and the collection readable from its publication on.
     */
    public function testAStreamedSnapshotIsPublishedHereAndMakesItsCollectionReadable(): void
    {
        $source = $this->notes();
        $source->notes->add(HilosProbeNote::create('n1', 'node-a', 'the master\'s copy'));
        $source->notes->add(HilosProbeNote::create('n2', 'node-a', 'a row of a node gone quiet'));
        $master = $this->masterEnd($parts);
        $master->sendSnapshot(WorkerRtSyncStreamHoldTestNotes::NOTES, RtSnapshotScope::whole(), staleRows: ['n2' => 12.5],
            announcement: self::FIRST_REPORT);
        $copy = $this->notes();
        $copy->notes->add(HilosProbeNote::create('gone', 'node-a', 'not in the cut'));
        $worker = $this->reader();
        $this->assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES));

        $this->hand($worker, $parts);

        $this->assertSame(['n1' => 'the master\'s copy', 'n2' => 'a row of a node gone quiet'], $this->texts($copy));
        $this->assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES),
            'Readiness comes with the publication');
        $this->assertSame(12.5, RtStaleness::staleSince(WorkerRtSyncStreamHoldTestNotes::NOTES, 'n2'));
        $this->assertNull(RtStaleness::staleSince(WorkerRtSyncStreamHoldTestNotes::NOTES, 'n1'));
        $this->assertSame([RtSyncStreamStatus::Applied], $worker->client->answers());
    }

    /**
     * Rows this worker's own agents hold are left out of the snapshot: the copy here is the one they
     * write, and the master's may lag behind it. The rest of the copy is replaced as before.
     */
    public function testAStreamedSnapshotLeavesTheRowsOfThisWorkersAgentsAsTheyAre(): void
    {
        $source = $this->notes();
        $source->notes->add(HilosProbeNote::create('own', 'node-a', 'the master\'s older copy'));
        $source->notes->add(HilosProbeNote::create('n1', 'node-a', 'the master\'s copy'));
        $master = $this->masterEnd($parts);
        $master->sendSnapshot(WorkerRtSyncStreamHoldTestNotes::NOTES, RtSnapshotScope::whole(), announcement: self::FIRST_REPORT);
        $copy = $this->notes();
        $copy->notes->add(HilosProbeNote::create('own', 'node-a', 'written here by its owner'));
        $copy->notes->add(HilosProbeNote::create('gone', 'node-a', 'not in the cut'));
        $agent = new WorkerRtSyncStreamHoldTestAgent();
        RtTruthSourceRegistry::register(WorkerRtSyncStreamHoldTestNotes::NOTES, TruthSourceKeys::listed('own'), $agent->getId());
        try {
            $worker = $this->reader($agent);

            $this->hand($worker, $parts);
        } finally {
            RtTruthSourceRegistry::unregisterAgent($agent->getId());
        }

        $this->assertSame(['n1' => 'the master\'s copy', 'own' => 'written here by its owner'], $this->texts($copy));
        $this->assertSame([RtSyncStreamStatus::Applied], $worker->client->answers());
    }

    /**
     * An agent that starts on this worker while the master's snapshot still arrives in parts, and
     * writes a row of the cut, is a writer the snapshot did not see - its rows were not left out
     * when the staging began: the generation is canceled before it is merged, nothing of it is
     * published, and the master is asked for one more snapshot, once.
     */
    public function testAnAgentStartedBetweenPartsWritingARowOfTheCutCancelsTheSnapshotAndAsksForAnother(): void
    {
        $source = $this->notes();
        $source->notes->add(HilosProbeNote::create('own', 'node-a', 'the master\'s older copy'));
        foreach (['n1', 'n2', 'n3'] as $id) {
            $source->notes->add(HilosProbeNote::create($id, 'node-a', str_repeat($id, intdiv(RtSyncStreamFrame::MAX_PART_BYTES, 4))));
        }
        $master = $this->masterEnd($parts);
        $master->sendSnapshot(WorkerRtSyncStreamHoldTestNotes::NOTES, RtSnapshotScope::whole(), announcement: self::FIRST_REPORT);
        $copy = $this->notes();
        $copy->notes->add(HilosProbeNote::create('own', 'node-a', 'the copy before'));
        $worker = $this->reader();
        $this->hand($worker, $parts);
        $this->assertSame([RtSyncStreamStatus::Next], $worker->client->answers(), 'The snapshot still arrives');

        $agent = new WorkerRtSyncStreamHoldTestAgent();
        RtTruthSourceRegistry::register(WorkerRtSyncStreamHoldTestNotes::NOTES, TruthSourceKeys::listed('own'), $agent->getId());
        try {
            $copy->notes['own']->text = 'written here by an agent started meanwhile';
            $copy->notes['own']->markRtSyncBaseline();
            $this->relay($worker, $master, $parts);
        } finally {
            RtTruthSourceRegistry::unregisterAgent($agent->getId());
        }

        $this->assertSame(['own' => 'written here by an agent started meanwhile'], $this->texts($copy));
        $this->assertSame([RtSyncStreamStatus::Refused], array_values(array_filter($worker->client->answers(),
            static fn (RtSyncStreamStatus $status): bool => $status !== RtSyncStreamStatus::Next)));
        $this->assertSame([WorkerRtSyncStreamHoldTestNotes::NOTES], $worker->client->snapshotsAsked());
    }

    /**
     * A live change here that cancels the second snapshot in a row is a second owner of the rows -
     * an agent writing them through a claim that leaves them in the cut - and every cut of the
     * master would be canceled the same way: that snapshot is not asked for again. The next event
     * asks once more, a hold of a row among them.
     */
    public function testASnapshotCanceledTwiceInARowByAWriteHereIsNotAskedForAgainUntilTheNextEvent(): void
    {
        $source = $this->notes();
        $source->notes->add(HilosProbeNote::create('own', 'node-a', 'the master\'s older copy'));
        foreach (['n1', 'n2', 'n3'] as $id) {
            $source->notes->add(HilosProbeNote::create($id, 'node-a', str_repeat($id, intdiv(RtSyncStreamFrame::MAX_PART_BYTES, 4))));
        }
        $master = $this->masterEnd($parts);
        $copy = $this->notes();
        $copy->notes->add(HilosProbeNote::create('own', 'node-a', 'the copy before'));
        $worker = $this->reader();

        foreach (['written once', 'written again'] as $text) {
            $this->cut($master, $source, $copy, $this->reported($worker));
            $this->hand($worker, $parts);
            $agent = new WorkerRtSyncStreamHoldTestAgent();
            RtTruthSourceRegistry::register(WorkerRtSyncStreamHoldTestNotes::NOTES, TruthSourceKeys::listed('own'), $agent->getId());
            try {
                $copy->notes['own']->text = $text;
                $copy->notes['own']->markRtSyncBaseline();
                $this->relay($worker, $master, $parts);
            } finally {
                RtTruthSourceRegistry::unregisterAgent($agent->getId());
            }
        }

        $this->assertSame(['own' => 'written again'], $this->texts($copy));
        $this->assertSame([RtSyncStreamStatus::Refused, RtSyncStreamStatus::Refused], array_values(array_filter($worker->client->answers(),
            static fn (RtSyncStreamStatus $status): bool => $status !== RtSyncStreamStatus::Next)));
        $this->assertSame([WorkerRtSyncStreamHoldTestNotes::NOTES], $worker->client->snapshotsAsked(),
            'The second cancellation in a row asks for nothing');

        // An operation naming a node is refused by a worker at once, so its row is held.
        $master->send(new RtSyncCreatedSignalData(WorkerRtSyncStreamHoldTestNotes::NOTES, 'n4', ['id' => 'n4']), 'unit-peer-node');
        $this->relay($worker, $master, $parts);

        $this->assertSame([WorkerRtSyncStreamHoldTestNotes::NOTES, WorkerRtSyncStreamHoldTestNotes::NOTES],
            $worker->client->snapshotsAsked(), 'A hold is the next event, and asks');
    }

    /**
     * The first row the worker's stream holds after refusing its operation asks the master for one
     * snapshot of its collection, and no second request goes while that one waits. The snapshot
     * gives the held rows a base again: the hold and its marks are lifted, and a later hold asks anew.
     */
    public function testARowTheStreamHoldsAsksTheMasterForOneSnapshotWhichLiftsTheHold(): void
    {
        $this->notes();
        $worker = $this->reader();
        $master = $this->masterEnd($parts);
        // An operation naming a node is refused by a worker at once, so its row is held.
        $master->send(new RtSyncCreatedSignalData(WorkerRtSyncStreamHoldTestNotes::NOTES, 'n1', ['id' => 'n1']), 'unit-peer-node');
        $master->send(new RtSyncCreatedSignalData(WorkerRtSyncStreamHoldTestNotes::NOTES, 'n2', ['id' => 'n2']), 'unit-peer-node');

        $this->hand($worker, $parts);

        $this->assertNotNull(RtStaleness::staleSince(WorkerRtSyncStreamHoldTestNotes::NOTES, 'n1'));
        $this->assertNotNull(RtStaleness::staleSince(WorkerRtSyncStreamHoldTestNotes::NOTES, 'n2'));
        $this->assertSame([WorkerRtSyncStreamHoldTestNotes::NOTES], $worker->client->snapshotsAsked(), 'One request for both rows');

        $master->sendSnapshot(WorkerRtSyncStreamHoldTestNotes::NOTES, RtSnapshotScope::whole(), announcement: self::FIRST_REPORT);
        $this->hand($worker, $parts);

        $this->assertNull(RtStaleness::staleSince(WorkerRtSyncStreamHoldTestNotes::NOTES, 'n1'));
        $this->assertNull(RtStaleness::staleSince(WorkerRtSyncStreamHoldTestNotes::NOTES, 'n2'));
        $this->assertSame([RtSyncStreamStatus::Refused, RtSyncStreamStatus::Refused, RtSyncStreamStatus::Applied],
            $worker->client->answers());

        $master->send(new RtSyncCreatedSignalData(WorkerRtSyncStreamHoldTestNotes::NOTES, 'n3', ['id' => 'n3']), 'unit-peer-node');
        $this->hand($worker, $parts);

        $this->assertSame([WorkerRtSyncStreamHoldTestNotes::NOTES, WorkerRtSyncStreamHoldTestNotes::NOTES],
            $worker->client->snapshotsAsked(), 'A hold after the snapshot asks again');
    }

    /**
     * A row held while the snapshot answering the interest that added its collection is still to come
     * asks for nothing: the master cuts that snapshot after the write the hold refused, so it is the
     * base the row lacks, and it lifts the hold. A hold after it asks as usual.
     */
    public function testARowHeldBeforeTheAnswerToTheInterestThatAddedItsCollectionWaitsForThatAnswer(): void
    {
        $source = $this->notes();
        $source->notes->add(HilosProbeNote::create('n1', 'node-a', 'kept'));
        $master = $this->masterEnd($parts);
        $copy = $this->notes();
        SourceInterestRegistry::readsWhatIsDelivered();
        $worker = new WorkerRtSyncStreamHoldTestReader();
        $this->takeUp($worker, self::READER);
        // An operation naming a node is refused by a worker at once, so its row is held.
        $master->send(new RtSyncCreatedSignalData(WorkerRtSyncStreamHoldTestNotes::NOTES, 'held', ['id' => 'held']), 'unit-peer-node');
        $this->relay($worker, $master, $parts);

        $this->assertNotNull(RtStaleness::staleSince(WorkerRtSyncStreamHoldTestNotes::NOTES, 'held'));
        $this->assertSame([], $worker->client->snapshotsAsked(), 'The answer to the interest is on the way');

        $this->cut($master, $source, $copy, $this->reported($worker));
        $this->relay($worker, $master, $parts);

        $this->assertNull(RtStaleness::staleSince(WorkerRtSyncStreamHoldTestNotes::NOTES, 'held'));
        $this->assertSame(['n1' => 'kept'], $this->texts($copy));
        $this->assertSame([], $worker->client->snapshotsAsked());

        $master->send(new RtSyncCreatedSignalData(WorkerRtSyncStreamHoldTestNotes::NOTES, 'later', ['id' => 'later']), 'unit-peer-node');
        $this->relay($worker, $master, $parts);

        $this->assertSame([WorkerRtSyncStreamHoldTestNotes::NOTES], $worker->client->snapshotsAsked(), 'A hold after the answer asks');
    }

    /**
     * A snapshot one row of which its validator refuses here is refused whole, and asks for nothing:
     * the master's next cut of the same rows would be refused the same way. The next event asks, a
     * hold of a row.
     */
    public function testASnapshotARowOfWhichItsValidatorRefusesIsNotAskedForAgainUntilTheNextEvent(): void
    {
        $source = $this->notes();
        $source->notes->add(HilosProbeNote::create('n1', 'node-a', 'fine'));
        $source->notes->add(HilosProbeNote::create('refused', 'not-a-number', 'refused'));
        $master = $this->masterEnd($parts);
        $copy = $this->notes();
        $worker = $this->reader();
        $this->cut($master, $source, $copy, $this->reported($worker));
        // A node the master's copy holds as text and that reaches this worker as a number: its validator refuses it.
        $parts = array_map(static fn (RtSyncStreamFrame $part): RtSyncStreamFrame => RtSyncStreamFrame::part(
            $part->streamId,
            $part->sequence,
            $part->part,
            $part->final,
            str_replace('"not-a-number"', '7', $part->data),
        ), $parts);

        $this->hand($worker, $parts);

        $this->assertSame([], $this->texts($copy));
        $this->assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES));
        $this->assertSame([RtSyncStreamStatus::Refused], $worker->client->answers());
        $this->assertSame([], $worker->client->snapshotsAsked(), 'A refusal asks for nothing');

        // An operation naming a node is refused by a worker at once, so its row is held.
        $master->send(new RtSyncCreatedSignalData(WorkerRtSyncStreamHoldTestNotes::NOTES, 'held', ['id' => 'held']), 'unit-peer-node');
        $this->relay($worker, $master, $parts);

        $this->assertSame([WorkerRtSyncStreamHoldTestNotes::NOTES], $worker->client->snapshotsAsked(), 'A hold is the next event, and asks');
    }

    /**
     * A write the master addressed here before it learned the last reader went is taken and not
     * applied, and answered applied: the copy it would land on is being dropped, and the snapshot
     * of the next reader carries it. Once the collection is read again, writes are applied.
     */
    public function testAWriteOfACollectionNobodyHereReadsIsAnsweredAppliedAndNotApplied(): void
    {
        $copy = $this->notes();
        SourceInterestRegistry::readsWhatIsDelivered();
        $worker = new WorkerRtSyncStreamHoldTestReader();
        $master = $this->masterEnd($parts);

        $master->send(new RtSyncCreatedSignalData(WorkerRtSyncStreamHoldTestNotes::NOTES, 'late',
            HilosProbeNote::create('late', 'node-a', 'written after the last reader went')->toArray()));
        $this->hand($worker, $parts);

        $this->assertSame([], $this->texts($copy));
        $this->assertSame([RtSyncStreamStatus::Applied], $worker->client->answers());

        SourceInterestRegistry::register(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES, self::READER);
        $master->send(new RtSyncCreatedSignalData(WorkerRtSyncStreamHoldTestNotes::NOTES, 'read',
            HilosProbeNote::create('read', 'node-a', 'written for a reader')->toArray()));
        $this->hand($worker, $parts);

        $this->assertSame(['read' => 'written for a reader'], $this->texts($copy));
    }

    /**
     * The copy nobody reads any more is dropped by an empty generation the RT passes stage and
     * publish, not by one write of the whole: its readiness goes with the last reader at once, and
     * its rows with the passes after.
     */
    public function testTheLastReaderLeavingDropsTheCopyOnTheRtPasses(): void
    {
        $copy = $this->notes();
        $copy->notes->add(HilosProbeNote::create('n1', 'node-a', 'a row of the copy'));
        $worker = $this->reader();
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES);

        new ReflectionMethod(WorkerManager::class, 'releaseSourceInterest')->invoke($worker, self::READER);

        $this->assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES));
        $this->assertSame(['n1' => 'a row of the copy'], $this->texts($copy), 'Nothing is written in the release itself');
        $pass = new ReflectionMethod(WorkerManager::class, 'runRtPass');
        $owes = new ReflectionMethod(WorkerManager::class, 'hasRtPassWork');
        for ($passes = 0; $passes < 16 && $owes->invoke($worker); $passes++) {
            $pass->invoke($worker);
        }
        $this->assertSame([], $this->texts($copy));
        $this->assertSame([], new ReflectionProperty(WorkerManager::class, 'rtCopyDrops')->getValue($worker));
    }

    /**
     * A snapshot the master cut while a reader here read the collection, and that arrives only after
     * the reader went, is not the first answer of the next reader: the master may have stopped keeping
     * its copy once it read that the reader went, and a row its source deleted since would stand on
     * the next reader's first read. Its head names the report it answers, older than the one that
     * added the collection again, so it is passed over, and nothing is asked for: the answer to that
     * report is on its way. The next reader is Ready with that one only - whether the snapshot passed
     * over answered the report that added the collection or a request for another.
     *
     * @param bool $asked Whether the snapshot in flight answered a request rather than the interest
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testASnapshotCutForAReaderThatLeftBeforeItCameIsPassedOverForTheNextReader(bool $asked): void
    {
        $source = $this->notes();
        $source->notes->add(HilosProbeNote::create('n1', 'node-a', 'kept'));
        $source->notes->add(HilosProbeNote::create('gone', 'node-a', 'deleted at its source'));
        $master = $this->masterEnd($parts);
        $copy = $this->notes();
        SourceInterestRegistry::readsWhatIsDelivered();
        $worker = new WorkerRtSyncStreamHoldTestReader();
        $this->takeUp($worker, self::READER);
        if ($asked) {
            $this->cut($master, $source, $copy, $this->reported($worker));
            $this->hand($worker, $parts);
            $this->assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES));
            // An operation naming a node is refused by a worker at once, so its row is held.
            $master->send(new RtSyncCreatedSignalData(WorkerRtSyncStreamHoldTestNotes::NOTES, 'held', ['id' => 'held']), 'unit-peer-node');
            $this->relay($worker, $master, $parts);
            $this->assertSame([WorkerRtSyncStreamHoldTestNotes::NOTES], $worker->client->snapshotsAsked());
        }
        $this->cut($master, $source, $copy, $this->reported($worker));

        $this->letGo($worker, self::READER);
        $source->notes->remove('gone');
        $this->takeUp($worker, self::READER_AGAIN);
        $this->relay($worker, $master, $parts);

        $this->assertSame([], $this->texts($copy), 'Nothing of the snapshot cut for the reader that went is published');
        $this->assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES));
        $this->assertSame($asked ? [WorkerRtSyncStreamHoldTestNotes::NOTES] : [], $worker->client->snapshotsAsked(),
            'Nothing more is asked for');

        $this->cut($master, $source, $copy, $this->reported($worker));
        $this->relay($worker, $master, $parts);

        $this->assertSame(['n1' => 'kept'], $this->texts($copy));
        $this->assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES));
    }

    /**
     * Two answers to readers that went are still on their way when the next reader comes: both are
     * passed over by the numbers their heads carry, neither asks for anything, and the next reader is
     * Ready with the answer to the report that added the collection for it, the first that comes.
     */
    public function testTwoAnswersToReadersThatWentArePassedOverAndTheNextReaderIsReadyWithItsOwn(): void
    {
        $source = $this->notes();
        $source->notes->add(HilosProbeNote::create('n1', 'node-a', 'kept'));
        $master = $this->masterEnd($parts);
        $copy = $this->notes();
        SourceInterestRegistry::readsWhatIsDelivered();
        $worker = new WorkerRtSyncStreamHoldTestReader();
        foreach ([self::READER, self::READER_AGAIN] as $consumerId) {
            $this->takeUp($worker, $consumerId);
            $this->cut($master, $source, $copy, $this->reported($worker));
            $this->letGo($worker, $consumerId);
        }
        $this->takeUp($worker, self::READER);
        $this->cut($master, $source, $copy, $this->reported($worker));
        $this->assertCount(3, $parts, 'Each answer goes in one part, all three on their way');

        foreach ([false, false, true] as $ready) {
            $next = [array_shift($parts)];
            $this->hand($worker, $next);
            $this->assertSame($ready, SourceInterestRegistry::isReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES),
                'Ready with the answer to the report that added the notes last, and with that one only');
        }
        $this->relay($worker, $master, $parts);

        $this->assertSame(['n1' => 'kept'], $this->texts($copy));
        $this->assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES));
        $this->assertSame([], $worker->client->snapshotsAsked(), 'Nothing is asked for');
        $this->assertSame([RtSyncStreamStatus::Applied, RtSyncStreamStatus::Applied, RtSyncStreamStatus::Applied],
            $worker->client->answers());
    }

    /**
     * The worker's report names everything it reads under one number. A report that adds another
     * collection while the answer to the report that added the notes is still on its way leaves the
     * notes to that answer: it is published, though its head carries the older number.
     */
    public function testAnAnswerToTheReportThatAddedTheCollectionIsPublishedBehindALaterReport(): void
    {
        $source = $this->notes();
        $source->notes->add(HilosProbeNote::create('n1', 'node-a', 'kept'));
        $master = $this->masterEnd($parts);
        $copy = $this->notes();
        SourceInterestRegistry::readsWhatIsDelivered();
        $worker = new WorkerRtSyncStreamHoldTestReader();
        $this->takeUp($worker, self::READER);
        $added = $this->reported($worker);
        $this->cut($master, $source, $copy, $added);
        new ReflectionMethod(WorkerManager::class, 'raiseSourceInterest')->invoke(
            $worker,
            self::READER_AGAIN,
            [SourceChange::KIND_RT => [self::OTHER_COLLECTION]],
        );
        $this->assertGreaterThan($added, $this->reported($worker), 'A later report');

        $this->relay($worker, $master, $parts);

        $this->assertSame(['n1' => 'kept'], $this->texts($copy));
        $this->assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES));
        $this->assertSame([], $worker->client->snapshotsAsked());
    }

    /**
     * A snapshot whose reader went while it still arrived in parts is not published, though a reader
     * came again before its end: the reader that came has a snapshot of its own on the way, and the
     * one staging was cut for an interest the master may have dropped its copy behind. It is answered
     * applied and asks for nothing - whether the drop of the copy the reader left canceled it first,
     * or the drop still waited for its share of the passes and the snapshot was ready ahead of it.
     *
     * @param bool $dropWaits Whether the drop of the copy is left without a share until the snapshot is through
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testASnapshotWhoseReaderWentWhileItStagedIsNotPublishedForTheReaderThatCameSince(bool $dropWaits): void
    {
        $source = $this->notes();
        foreach (['n1', 'n2', 'n3'] as $id) {
            $source->notes->add(HilosProbeNote::create($id, 'node-a', str_repeat($id, intdiv(RtSyncStreamFrame::MAX_PART_BYTES, 4))));
        }
        $master = $this->masterEnd($parts);
        $copy = $this->notes();
        SourceInterestRegistry::readsWhatIsDelivered();
        $worker = new WorkerRtSyncStreamHoldTestReader();
        $this->takeUp($worker, self::READER);
        $this->cut($master, $source, $copy, $this->reported($worker));
        $this->hand($worker, $parts);
        $this->assertSame([RtSyncStreamStatus::Next], $worker->client->answers(), 'The snapshot still arrives');

        $this->letGo($worker, self::READER);
        $this->takeUp($worker, self::READER_AGAIN);
        if ($dropWaits) {
            // Two steps a round: one for the stream, none for the snapshot share the drop advances on.
            $shares = new ReflectionProperty(WorkerManager::class, 'rtWork');
            $shares->setValue($worker, new RtWorkScheduler(2));
            $pass = new ReflectionMethod(WorkerManager::class, 'runRtPass');
            $through = static fn (): bool => in_array(RtSyncStreamStatus::Applied, $worker->client->answers(), true);
            for ($passes = 0; $passes < self::MAX_STARVED_PASSES && !$through(); $passes++) {
                foreach ($worker->client->takeStreamAnswers() as $answer) {
                    $master->receive($answer);
                }
                foreach (array_splice($parts, 0) as $part) {
                    $worker->handleDaemonMessage(new WorkerRtSyncStreamDTO($part));
                }
                $pass->invoke($worker);
            }
            $this->assertTrue($through(), 'The snapshot is through');
            $this->assertNotSame([], new ReflectionProperty(WorkerManager::class, 'rtCopyDrops')->getValue($worker), 'The drop waits');
            $this->assertSame([], $this->texts($copy), 'Ready ahead of the drop, and not published all the same');
            $shares->setValue($worker, new RtWorkScheduler());
        }
        $this->relay($worker, $master, $parts);

        $this->assertSame([], $this->texts($copy));
        $this->assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES));
        $this->assertSame([RtSyncStreamStatus::Applied], array_values(array_filter($worker->client->answers(),
            static fn (RtSyncStreamStatus $status): bool => $status !== RtSyncStreamStatus::Next)));
        $this->assertSame([], $worker->client->snapshotsAsked());

        $source->notes->remove('n2');
        $this->cut($master, $source, $copy, $this->reported($worker));
        $this->relay($worker, $master, $parts);

        $this->assertSame(['n1', 'n3'], array_keys($this->texts($copy)));
        $this->assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES));
    }

    /**
     * The first snapshot of a reader that came while the copy of the one before was still being
     * dropped takes the drop's place: the drop is canceled at the snapshot's head, so its
     * publication between the snapshot's parts does not cancel the snapshot.
     */
    public function testTheHeadOfTheNextReadersSnapshotCancelsTheDropOfTheCopy(): void
    {
        $source = $this->notes();
        foreach (['n1', 'n2', 'n3'] as $id) {
            $source->notes->add(HilosProbeNote::create($id, 'node-a', str_repeat($id, intdiv(RtSyncStreamFrame::MAX_PART_BYTES, 4))));
        }
        $master = $this->masterEnd($parts);
        $copy = $this->notes();
        SourceInterestRegistry::readsWhatIsDelivered();
        $worker = new WorkerRtSyncStreamHoldTestReader();
        $this->takeUp($worker, self::READER);
        $this->cut($master, $source, $copy, $this->reported($worker));
        $this->relay($worker, $master, $parts);
        $this->letGo($worker, self::READER);
        $this->takeUp($worker, self::READER_AGAIN);
        $this->assertNotSame([], new ReflectionProperty(WorkerManager::class, 'rtCopyDrops')->getValue($worker), 'The drop waits');

        $this->cut($master, $source, $copy, $this->reported($worker));
        $this->relay($worker, $master, $parts);

        $this->assertSame(['n1', 'n2', 'n3'], array_keys($this->texts($copy)));
        $this->assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES));
        $this->assertSame([RtSyncStreamStatus::Applied, RtSyncStreamStatus::Applied], array_values(array_filter($worker->client->answers(),
            static fn (RtSyncStreamStatus $status): bool => $status !== RtSyncStreamStatus::Next)));
        $this->assertSame([], $worker->client->snapshotsAsked());
    }

    /**
     * A snapshot one part of which does not read is lost: nothing of it is published, and it asks for
     * nothing - the next event does, as after a refusal.
     */
    public function testASnapshotLostBeforeItsEndAsksForNothing(): void
    {
        $source = $this->notes();
        foreach (['n1', 'n2', 'n3'] as $id) {
            $source->notes->add(HilosProbeNote::create($id, 'node-a', str_repeat($id, intdiv(RtSyncStreamFrame::MAX_PART_BYTES, 4))));
        }
        $master = $this->masterEnd($parts);
        $copy = $this->notes();
        $worker = $this->reader();
        $this->cut($master, $source, $copy, $this->reported($worker));
        $this->hand($worker, $parts);
        foreach ($worker->client->takeStreamAnswers() as $answer) {
            $master->receive($answer);
        }
        $part = array_shift($parts);
        $parts = [];

        // An escape no JSON knows, where the snapshot's next part goes: it does not read in a string or out of one.
        $worker->handleDaemonMessage(new WorkerRtSyncStreamDTO(RtSyncStreamFrame::part($part->streamId, $part->sequence, $part->part,
            true, '\\q"')));
        $this->hand($worker, $parts);

        $this->assertSame([], $this->texts($copy));
        $this->assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES));
        $this->assertSame([RtSyncStreamStatus::Next, RtSyncStreamStatus::Refused], $worker->client->answers());
        $this->assertSame([], $worker->client->snapshotsAsked());
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

    /**
     * @return WorkerRtSyncStreamHoldTestNotes Fresh runtime of this process, its names bound, current from now on
     */
    private function notes(): WorkerRtSyncStreamHoldTestNotes
    {
        $context = new WorkerRtSyncStreamHoldTestNotes();
        $context->configure();
        $context->bindStateCollectionNames();
        Hilos::$rt = $context;

        return $context;
    }

    /**
     * @param AbstractAgent ...$agents Agents running on the worker
     * @return WorkerRtSyncStreamHoldTestReader A worker that reads the notes, handed the master's frames one by one
     */
    private function reader(AbstractAgent ...$agents): WorkerRtSyncStreamHoldTestReader
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        SourceInterestRegistry::register(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTestNotes::NOTES, self::READER);
        $worker = new WorkerRtSyncStreamHoldTestReader($agents);
        // The notes were named by the worker's first report, and the answer to it came: what such a reader stands for.
        new ReflectionProperty(WorkerManager::class, 'rtInterestNumber')->setValue($worker, self::FIRST_REPORT);
        new ReflectionProperty(WorkerManager::class, 'rtInterestAddedAt')
            ->setValue($worker, [WorkerRtSyncStreamHoldTestNotes::NOTES => self::FIRST_REPORT]);
        new ReflectionProperty(WorkerManager::class, 'rtInterestAnswered')->setValue($worker, [WorkerRtSyncStreamHoldTestNotes::NOTES => true]);

        return $worker;
    }

    /**
     * @param WorkerRtSyncStreamHoldTestReader $worker Worker reporting what it reads
     * @return int Number of the last interest report the worker sent, which its master marks an answer with
     */
    private function reported(WorkerRtSyncStreamHoldTestReader $worker): int
    {
        return new ReflectionProperty(WorkerManager::class, 'rtInterestNumber')->getValue($worker);
    }

    /**
     * @param list<RtSyncStreamFrame>|null $parts Takes the parts the master's end writes, in order
     * @return RtSyncStreamChannel The master's end of the worker's RT stream, pumped as it takes work
     */
    private function masterEnd(?array &$parts): RtSyncStreamChannel
    {
        $parts = [];

        return new RtSyncStreamChannel(
            static function (RtSyncStreamFrame $frame) use (&$parts): void {
                $parts[] = $frame;
            },
            static fn (): null => null,
        );
    }

    /**
     * Hands the worker every part the master's end wrote and has not handed yet, then runs the RT
     * passes the worker still owes for them. A pass ends at its wall-clock budget, so one pass under
     * a loaded box may stop short of a publication an idle one reaches; the loop takes what the
     * worker's next loop iterations would.
     *
     * @param WorkerRtSyncStreamHoldTestReader $worker Worker reading the stream
     * @param list<RtSyncStreamFrame> $parts Parts written so far; emptied as they are handed over
     */
    private function hand(WorkerRtSyncStreamHoldTestReader $worker, array &$parts): void
    {
        foreach (array_splice($parts, 0) as $part) {
            $worker->handleDaemonMessage(new WorkerRtSyncStreamDTO($part));
        }
        $pass = new ReflectionMethod(WorkerManager::class, 'runRtPass');
        $owes = new ReflectionMethod(WorkerManager::class, 'hasRtPassWork');
        for ($passes = 0; $passes < self::MAX_OWED_PASSES && $owes->invoke($worker); $passes++) {
            $pass->invoke($worker);
        }
        $this->assertFalse($owes->invoke($worker), 'The passes the worker owed are run');
    }

    /**
     * Hands the worker the master's parts and the master the worker's answers to them, until the
     * master's end writes no more.
     *
     * @param WorkerRtSyncStreamHoldTestReader $worker Worker reading the stream
     * @param RtSyncStreamChannel $master The master's end of the stream
     * @param list<RtSyncStreamFrame> $parts Parts written so far; emptied as they are handed over
     */
    private function relay(WorkerRtSyncStreamHoldTestReader $worker, RtSyncStreamChannel $master, array &$parts): void
    {
        do {
            $this->hand($worker, $parts);
            foreach ($worker->client->takeStreamAnswers() as $answer) {
                $master->receive($answer);
            }
        } while ($parts !== []);
    }

    /**
     * Cuts the master's copy of the notes into its end of the stream, as the master does when it
     * answers an interest or a request, marked with the worker's report it answers; the worker's
     * runtime is current again afterwards.
     *
     * @param RtSyncStreamChannel $master The master's end of the stream
     * @param WorkerRtSyncStreamHoldTestNotes $source The master's runtime
     * @param WorkerRtSyncStreamHoldTestNotes $copy The worker's runtime
     * @param int $announcement Number of the worker's interest report the snapshot answers
     */
    private function cut(
        RtSyncStreamChannel $master,
        WorkerRtSyncStreamHoldTestNotes $source,
        WorkerRtSyncStreamHoldTestNotes $copy,
        int $announcement,
    ): void {
        Hilos::$rt = $source;
        $master->sendSnapshot(WorkerRtSyncStreamHoldTestNotes::NOTES, RtSnapshotScope::whole(), announcement: $announcement);
        Hilos::$rt = $copy;
    }

    /**
     * A consumer of the worker takes the notes up, and the worker reports it to the master.
     *
     * @param WorkerRtSyncStreamHoldTestReader $worker Worker the consumer runs on
     * @param string $consumerId Consumer taking the notes up
     */
    private function takeUp(WorkerRtSyncStreamHoldTestReader $worker, string $consumerId): void
    {
        new ReflectionMethod(WorkerManager::class, 'raiseSourceInterest')->invoke(
            $worker,
            $consumerId,
            [SourceChange::KIND_RT => [WorkerRtSyncStreamHoldTestNotes::NOTES]],
        );
    }

    /**
     * A consumer of the worker ends, and the worker reports what it reads without it.
     *
     * @param WorkerRtSyncStreamHoldTestReader $worker Worker the consumer ran on
     * @param string $consumerId Consumer that ended
     */
    private function letGo(WorkerRtSyncStreamHoldTestReader $worker, string $consumerId): void
    {
        new ReflectionMethod(WorkerManager::class, 'releaseSourceInterest')->invoke($worker, $consumerId);
    }

    /**
     * @param WorkerRtSyncStreamHoldTestNotes $context Runtime to read
     * @return array<string, string> Texts of its notes by id, sorted by id
     */
    private function texts(WorkerRtSyncStreamHoldTestNotes $context): array
    {
        $texts = [];
        foreach ($context->notes->toArray() as $id => $row) {
            $texts[$id] = $row[HilosProbeNote::text];
        }
        ksort($texts);

        return $texts;
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
        // The agent reads the collection it hears the writes of: a worker applies the writes of what it reads.
        SourceInterestRegistry::register(SourceChange::KIND_RT, WorkerRtSyncStreamHoldTest::COLLECTION, SourceConsumer::agent($agent->getId()));
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

    /** Messages the worker sent that {@see self::takeStreamAnswers()} has gone past */
    private int $relayed = 0;

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

    /** @return list<WorkerDTO|array<string, mixed>> Complete frames written by the actual worker sender */
    public function sentFrames(): array
    {
        return $this->sent;
    }

    /** @return list<string> Collections the worker asked its master one more snapshot of, in order */
    public function snapshotsAsked(): array
    {
        $asked = [];
        foreach ($this->sent as $message) {
            if ($message instanceof WorkerRtSnapshotRequestDTO) {
                $asked[] = $message->collectionKey;
            }
        }

        return $asked;
    }

    /** @return list<RtSyncStreamFrame> Answers the worker's stream gave since the last call, in order, for the master's end */
    public function takeStreamAnswers(): array
    {
        $answers = [];
        foreach (array_slice($this->sent, $this->relayed) as $message) {
            if ($message instanceof WorkerRtSyncStreamDTO && !$message->frame->isPart()) {
                $answers[] = $message->frame;
            }
        }
        $this->relayed = count($this->sent);

        return $answers;
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

    public const array OWNS_RT = [HilosProbeFleetStatus::RT_COLLECTION => TruthSourceOperation::BY_KIND];

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

/**
 * A reading worker handed the master's frames one by one by the case, its loop not run.
 */
final class WorkerRtSyncStreamHoldTestReader extends WorkerRtSyncStreamHoldTestLoop
{
    /**
     * @param list<AbstractAgent> $agents Agents running on the worker
     */
    public function __construct(array $agents = [])
    {
        parent::__construct([]);
        $this->daemonClient = $this->client;
        foreach ($agents as $agent) {
            $this->agentManager->addAgent($agent->getId(), $agent);
        }
    }

    protected function onTick(): void
    {
        $this->shouldExit = true;
    }
}

/** Runtime of one end of the worker's stream in the snapshot cases: probe notes, cut into sets by node. */
final class WorkerRtSyncStreamHoldTestNotes extends RtContext
{
    public const string NOTES = 'streamedNotes';

    public readonly HilosProbeNotes $notes;

    /** Registers the notes and their view. */
    public function configure(): void
    {
        $this->notes = HilosProbeNotes::init();
        $this->_stateCollections[self::NOTES] = $this->notes;
        $this->setRepresent(self::NOTES, ViewHilosProbeNotes::class, HilosProbeNotesActions::class, HilosProbeNoteActions::class);
    }
}
