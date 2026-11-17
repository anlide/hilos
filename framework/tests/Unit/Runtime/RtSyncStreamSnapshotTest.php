<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Closure;
use Generator;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Hilos;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\Snapshot\RtSnapshotImport;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\Snapshot\RtSnapshotStatus;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\State\Collection\HilosProbeNotes;
use Hilos\Runtime\State\Item\HilosProbeNote;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Runtime\Stream\RtSyncStreamSnapshot;
use Hilos\Runtime\Stream\RtSyncStreamStatus;
use Hilos\Runtime\View\Actions\Collection\HilosProbeNotesActions;
use Hilos\Runtime\View\Actions\Item\HilosProbeNoteActions;
use Hilos\Runtime\View\Collection\HilosProbeNotes as ViewHilosProbeNotes;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\TestCase;

/**
 * A snapshot of a collection travels as an operation of the ordinary RT stream: cut where the
 * channel takes it, in parts between the writes taken before and after it, its rows staged on the
 * receiving end as they arrive, and the next operation read only once it is published - or once it
 * is refused, canceled or never read at all.
 */
final class RtSyncStreamSnapshotTest extends TestCase
{
    private const string WRITES = 'streamRows';

    private const int ROWS = 40;

    /** @var list<RtSyncStreamFrame> Frames on their way to the receiving end */
    private array $toReceiver = [];

    /** @var list<RtSyncStreamFrame> Frames on their way back to the sending end */
    private array $toSender = [];

    /** @var list<string> What the receiving host did, in order */
    private array $applied = [];

    /** @var list<array{int, int, bool, RtSyncStreamStatus}> Final answers the sender got: sequence, part, whether that part was final, status */
    private array $answers = [];

    /** @var array<int, array{int, bool}> Parts each sequence went out in, and whether its last one was final */
    private array $sent = [];

    /** Whether the host took rows of a snapshot before the snapshot was read whole */
    private bool $takenEarly = false;

    /** @var array<string, float> Stale marks the host found on the last snapshot it read whole */
    private array $staleRows = [];

    /** @var ?Closure(): void Live write the host makes once a generation is ready, before it publishes; it stages to be canceled by one */
    private ?Closure $beforePublish = null;

    /** @var list<?int> Interest reports the snapshots the host staged answer, as their heads name them, in order */
    private array $announcements = [];

    protected function tearDown(): void
    {
        Hilos::$rt = null;
        parent::tearDown();
    }

    public function testASnapshotGoesInPartsBetweenTheWritesTakenBeforeAndAfterItsCut(): void
    {
        $receiver = $this->mounted();
        $receiver->notes->add(HilosProbeNote::create('n1', 'node-b', 'an old copy'));
        $receiver->notes->add(HilosProbeNote::create('gone', 'node-b', 'not in the cut'));
        $source = $this->source();
        $sender = $this->sender();

        $sender->send(new RtSyncCreatedSignalData(self::WRITES, 'before', ['jobsDone' => 1]));
        $sender->sendSnapshot(SnapshotStreamRtContext::NOTES, RtSnapshotScope::whole(), staleRows: ['n3' => 12.5]);
        $source->notes->add(HilosProbeNote::create('late', 'node-b', 'written after the cut'));
        $sender->send(new RtSyncCreatedSignalData(self::WRITES, 'after', ['jobsDone' => 2]));
        $expected = $this->texts($source);
        unset($expected['late']);
        Hilos::$rt = $receiver;
        $end = $this->receiver();
        $this->exchange($sender, $end);

        $this->assertSame(['write before', 'staging', 'published', 'write after'], $this->applied,
            'The write taken after the cut is read only behind the published snapshot');
        $this->assertGreaterThan(2, $this->sent[2][0], 'The snapshot does not fit one part');
        $this->assertTrue($this->takenEarly, 'Rows reach the host while the rest of the snapshot is on its way');
        $this->assertSame($expected, $this->texts($receiver), 'The cut is the one taken with the snapshot, not a later one');
        $this->assertSame(['n3' => 12.5], $this->staleRows);
        $this->assertSame([
            [1, 0, true, RtSyncStreamStatus::Applied],
            [2, $this->sent[2][0] - 1, true, RtSyncStreamStatus::Applied],
            [3, 0, true, RtSyncStreamStatus::Applied],
        ], $this->answers);
        $this->assertTrue($sender->isIdle());
        $this->assertTrue($end->isIdle());
    }

    /**
     * The head names the worker's interest report the snapshot answers on a master's stream to its
     * worker, and none on a peer link (HIL-1817).
     */
    public function testTheHeadNamesTheInterestReportTheSnapshotAnswers(): void
    {
        $receiver = $this->mounted();
        $this->source();
        $sender = $this->sender();

        $sender->sendSnapshot(SnapshotStreamRtContext::NOTES, RtSnapshotScope::whole(), announcement: 5);
        $sender->sendSnapshot(SnapshotStreamRtContext::NOTES, RtSnapshotScope::whole(), 'node-b');
        Hilos::$rt = $receiver;
        $this->exchange($sender, $this->receiver());

        $this->assertSame([5, null], $this->announcements);
    }

    /**
     * A snapshot torn between its parts spends no pass while the next part is on its way: once the
     * host has taken every row read so far, the stream leaves its staging where it stands and has
     * nothing to do, and the next part's arrival is what moves it on.
     */
    public function testASnapshotTornBetweenItsPartsSpendsNoPassUntilTheNextPartArrives(): void
    {
        $source = $this->source();
        $sender = $this->sender();
        $sender->sendSnapshot(SnapshotStreamRtContext::NOTES, RtSnapshotScope::whole());
        while ($sender->pump(new RtWorkBudget())) {
        }
        $receiver = $this->mounted();
        $end = $this->receiver();
        $this->assertCount(1, $this->toReceiver, 'The next part waits for the credit of the first');
        $end->receive(array_shift($this->toReceiver));
        while ($end->pump(new RtWorkBudget())) {
        }
        $this->assertSame(['staging'], $this->applied);
        $this->assertTrue($this->takenEarly, 'The host took the rows of the first part');

        $idle = new RtWorkBudget();
        $this->assertFalse($end->pump($idle), 'Nothing is left to do until the next part arrives');
        $this->assertSame(0, $idle->usedSteps(), 'The wait spends no step of the pass');
        $this->assertSame(0, $idle->usedBytes());

        Hilos::$rt = $source;
        foreach (array_splice($this->toSender, 0) as $answer) {
            $sender->receive($answer);
        }
        Hilos::$rt = $receiver;
        $this->exchange($sender, $end);
        $this->assertSame(['staging', 'published'], $this->applied);
        $this->assertTrue($end->isIdle());
    }

    public function testASnapshotTheHostRefusesBeforeItsEndIsGivenUpAndTheStreamGoesOn(): void
    {
        $receiver = $this->mounted();
        $receiver->notes->add(HilosProbeNote::create('n1', 'node-b', 'an old copy'));
        $before = $this->texts($receiver);
        $this->source();
        $sender = $this->sender();

        $sender->sendSnapshot(SnapshotStreamRtContext::NOTES, RtSnapshotScope::sets(['node-b']));
        $sender->send(new RtSyncCreatedSignalData(self::WRITES, 'after', ['jobsDone' => 1]));
        Hilos::$rt = $receiver;
        $end = $this->receiver('n5');
        $this->exchange($sender, $end);

        $this->assertSame(['staging', 'refused', 'write after'], $this->applied);
        $this->assertSame($before, $this->texts($receiver), 'Nothing of the refused snapshot is published');
        [$parts, $final] = $this->sent[1];
        $this->assertFalse($final, 'The sender gave up the rest of the snapshot');
        $this->assertSame([[1, $parts - 1, false, RtSyncStreamStatus::Refused], [2, 0, true, RtSyncStreamStatus::Applied]], $this->answers);
        $this->assertTrue($sender->isIdle());
        $this->assertTrue($end->isIdle());
    }

    public function testALiveChangeCancelsTheStagedSnapshotAndARetryTakesANewCut(): void
    {
        $receiver = $this->mounted();
        $receiver->notes->add(HilosProbeNote::create('n1', 'node-b', 'an old copy'));
        $source = $this->source();
        $sender = $this->sender();
        $sender->sendSnapshot(SnapshotStreamRtContext::NOTES, RtSnapshotScope::whole());
        $sender->send(new RtSyncCreatedSignalData(self::WRITES, 'after', ['jobsDone' => 1]));
        Hilos::$rt = $receiver;
        $end = $this->receiver();
        $this->beforePublish = static function () use ($receiver): void {
            $receiver->notes['n1']->text = 'written here meanwhile';
            $receiver->notes['n1']->markRtSyncBaseline();
        };
        $this->exchange($sender, $end);

        $this->assertSame(['staging', 'canceled', 'write after'], $this->applied);
        $this->assertSame(['n1' => 'written here meanwhile'], $this->texts($receiver));
        $this->assertSame([[1, $this->sent[1][0] - 1, true, RtSyncStreamStatus::Refused], [2, 0, true, RtSyncStreamStatus::Applied]],
            $this->answers, 'A snapshot canceled after it was read whole is answered on its final part');

        Hilos::$rt = $source;
        $source->notes->add(HilosProbeNote::create('later', 'node-b', 'in the new cut'));
        $sender->sendSnapshot(SnapshotStreamRtContext::NOTES, RtSnapshotScope::whole());
        Hilos::$rt = $receiver;
        $this->applied = [];
        $this->beforePublish = null;
        $this->exchange($sender, $end);

        $this->assertSame(['staging', 'published'], $this->applied);
        $this->assertSame($this->texts($source), $this->texts($receiver), 'The retry brings the new cut');
    }

    public function testAGapAheadOfASnapshotLeavesItUnreadAndTheSenderGivesItUp(): void
    {
        $receiver = $this->mounted();
        $receiver->notes->add(HilosProbeNote::create('n1', 'node-b', 'an old copy'));
        $before = $this->texts($receiver);
        $this->source();
        $sender = $this->sender();
        $sender->send(new RtSyncCreatedSignalData(self::WRITES, 'lost', ['jobsDone' => 1]));
        $sender->pump(new RtWorkBudget());
        $this->assertCount(1, $this->toReceiver);
        $this->toReceiver = [];
        $sender->sendSnapshot(SnapshotStreamRtContext::NOTES, RtSnapshotScope::whole());
        $sender->send(new RtSyncCreatedSignalData(self::WRITES, 'after', ['jobsDone' => 2]));
        Hilos::$rt = $receiver;
        $end = $this->receiver();
        $this->exchange($sender, $end);

        $this->assertSame([], $this->applied, 'Nothing behind the lost write reaches the host');
        $this->assertSame($before, $this->texts($receiver));
        $this->assertSame([1, false], $this->sent[2], 'The snapshot answered gap at its first part goes no further');
        $this->assertSame([[2, 0, false, RtSyncStreamStatus::Gap], [3, 0, true, RtSyncStreamStatus::Gap]], $this->answers);
        $this->assertFalse($sender->isIdle(), 'The lost write is never answered on this channel');
    }

    public function testTransferCancelsQueuedSnapshotsWithoutInsertingAFreshCutAheadOfLaterWrites(): void
    {
        $this->source();
        $donor = $this->sender();
        $donor->send(new RtSyncCreatedSignalData(self::WRITES, 'before', ['value' => 1]));
        $scope = RtSnapshotScope::sets(['node-b']);
        $donor->sendSnapshot(SnapshotStreamRtContext::NOTES, $scope, 'node-b', true, announcement: 7);
        $donor->writeInTurn(function (): void {
            $this->applied[] = 'frame';
        });
        $donor->send(new RtSyncCreatedSignalData(self::WRITES, 'after', ['value' => 2]));
        $donor->afterAnswered(function (): void {
            $this->applied[] = 'answered';
        });
        $successor = $this->sender();
        $canceled = [];
        $donor->moveUnsentTo($successor, static function (RtSyncStreamSnapshot $snapshot) use (&$canceled): void {
            $canceled[] = $snapshot;
        });
        $this->assertCount(1, $canceled);
        $this->assertSame([SnapshotStreamRtContext::NOTES, $scope, 'node-b', true, 7], [$canceled[0]->collectionKey,
            $canceled[0]->scope, $canceled[0]->originNodeId, $canceled[0]->partialOwner, $canceled[0]->announcement]);
        $this->assertSame([], $this->applied);
        $this->mounted();
        $this->exchange($successor, $this->receiver());
        $this->assertSame(['frame', 'write before', 'write after', 'answered'], $this->applied,
            'The canceled snapshot is not delivered or replaced; the later delta keeps its place');
        $this->assertSame([1, 2], array_keys($this->sent), 'Only the ordinary writes occupy the new epoch');
        $this->assertTrue($donor->isIdle());
    }

    public function testTransferCancelsASnapshotWhoseEncoderConsumedCutBeforeItsFirstPart(): void
    {
        $this->source();
        $donor = $this->sender();
        $donor->sendSnapshot(SnapshotStreamRtContext::NOTES, RtSnapshotScope::whole());
        $this->assertTrue($donor->pump(new RtWorkBudget(128, 4096)));
        $this->assertSame([], $this->toReceiver, 'The encoder spent its allowance before its first part');
        $donor->send(new RtSyncCreatedSignalData(self::WRITES, 'after', ['value' => 1]));
        $successor = $this->sender();
        $canceled = [];
        $donor->moveUnsentTo($successor, static function (RtSyncStreamSnapshot $snapshot) use (&$canceled): void {
            $canceled[] = $snapshot;
        });
        $this->assertCount(1, $canceled, 'An encoder-owned cut is canceled even though no part went out');
        $rest = $canceled[0]->nextRows(new RtWorkBudget());
        $this->assertNotNull($rest);
        $this->assertNotContains('n0', $rest->ids, 'The sending cut really advanced before any part went out');
        $this->mounted();
        $this->exchange($successor, $this->receiver());
        $this->assertSame(['write after'], $this->applied, 'The consumed cursor is never replayed as a snapshot');
        $this->assertTrue($donor->isIdle());
    }

    /** Old EOF discards the staging; cancellation releases the snapshot's boundary while the transferred write keeps its own wait. */
    public function testTransferCancelsAPartialSnapshotAndOldEofCancelsRemoteStaging(): void
    {
        $this->source();
        $donor = $this->sender();
        $donor->sendSnapshot(SnapshotStreamRtContext::NOTES, RtSnapshotScope::whole());
        while ($donor->pump(new RtWorkBudget())) {
        }
        $this->assertCount(1, $this->toReceiver);
        $first = array_shift($this->toReceiver);
        $this->assertFalse($first->final);
        $runtime = $this->mounted();
        $runtime->notes->add(HilosProbeNote::create('old', 'node-b', 'the current base'));
        $before = $this->texts($runtime);
        $oldReceiver = $this->receiver();
        $oldReceiver->receive($first);
        while ($oldReceiver->pump(new RtWorkBudget())) {
        }
        $this->assertSame(['staging'], $this->applied);
        $told = [];
        $donor->writeInTurn(static function () use (&$told): void {
            $told[] = 'snapshot frame';
        });
        $donor->afterAnswered(static function () use (&$told): void {
            $told[] = 'snapshot ended';
        });
        $donor->send(new RtSyncCreatedSignalData(self::WRITES, 'after', ['value' => 1]));
        $donor->writeInTurn(static function () use (&$told): void {
            $told[] = 'write frame';
        });
        $donor->afterAnswered(static function () use (&$told): void {
            $told[] = 'write answered';
        });
        $canceled = [];
        $successor = $this->sender();
        $donor->moveUnsentTo($successor, static function (RtSyncStreamSnapshot $snapshot) use (&$canceled): void {
            $canceled[] = $snapshot->collectionKey;
        });
        $this->assertSame(['snapshot frame', 'snapshot ended'], $told,
            'Only the canceled snapshot boundary is ready; the later ordinary write still holds its frame and wait');
        foreach (array_splice($this->toSender, 0) as $credit) {
            $donor->receive($credit);
        }
        $donor->pump(new RtWorkBudget());
        $this->assertSame([], $this->toReceiver, 'Late old credit cannot resume the canceled snapshot');
        $oldReceiver->close();
        while ($oldReceiver->pump(new RtWorkBudget())) {
        }
        $this->assertSame([SnapshotStreamRtContext::NOTES], $canceled);
        $this->assertSame(['staging', 'lost'], $this->applied);
        $this->assertSame($before, $this->texts($runtime), 'The partial snapshot was never published');
        $this->assertTrue($oldReceiver->isIdle(), 'EOF runs the old staging through its cancellation');
        $this->assertSame(['snapshot frame', 'snapshot ended'], $told, 'Old EOF cannot end the transferred ordinary wait');
        $this->exchange($successor, $this->receiver());
        $this->assertSame(['staging', 'lost', 'write after'], $this->applied);
        $this->assertSame(['snapshot frame', 'snapshot ended', 'write frame', 'write answered'], $told);
        $this->assertTrue($donor->isIdle());
    }

    public function testACancellationFailureDoesNotHideOtherCutsReadyFramesWaitsOrTheSuccessorWake(): void
    {
        $this->source();
        $donor = $this->sender();
        $donor->sendSnapshot(SnapshotStreamRtContext::NOTES, RtSnapshotScope::whole());
        $donor->sendSnapshot(SnapshotStreamRtContext::NOTES, RtSnapshotScope::sets(['node-b']));
        $donor->writeInTurn(function (): void {
            $this->applied[] = 'frame-1';
            throw new EventLoopException('The first frame failed');
        });
        $donor->writeInTurn(function (): void {
            $this->applied[] = 'frame-2';
        });
        $donor->afterAnswered(function (): void {
            $this->applied[] = 'wait';
        });
        $donor->send(new RtSyncCreatedSignalData(self::WRITES, 'after', ['value' => 1]));
        $successor = new RtSyncStreamChannel(
            function (RtSyncStreamFrame $frame): void {
                $this->toReceiver[] = $frame;
            },
            static fn (): null => null,
        );
        $failure = new EventLoopException('The first canceled snapshot failed');
        $cuts = [];
        try {
            $donor->moveUnsentTo($successor, static function (RtSyncStreamSnapshot $snapshot) use (&$cuts, $failure): void {
                $cuts[] = $snapshot->revision;
                throw $failure;
            });
            $this->fail('Transfer must return the first failure after completing the transfer');
        } catch (EventLoopException $raised) {
            $this->assertSame($failure, $raised);
        }
        $this->assertSame([1, 2], $cuts, 'Every canceled cut is reported despite the failure');
        $this->assertSame(['frame-1', 'frame-2', 'wait'], $this->applied);
        $this->assertCount(1, $this->toReceiver, 'The successor was woken despite callback failures');
        $this->assertTrue($this->toReceiver[0]->final);
        $successor->receive(RtSyncStreamFrame::ack($successor->streamId, 1, 0, RtSyncStreamStatus::Applied));
        $donor->releaseWaiting();
        $donor->endAnswerWaits();
        $this->assertTrue($successor->isIdle());
        $this->assertTrue($donor->isIdle());
        $this->assertSame(['frame-1', 'frame-2', 'wait'], $this->applied, 'No callback runs twice');
    }

    /**
     * @return SnapshotStreamRtContext Fresh runtime of this process, with names bound
     */
    private function mounted(): SnapshotStreamRtContext
    {
        $context = new SnapshotStreamRtContext();
        $context->configure();
        $context->bindStateCollectionNames();
        Hilos::$rt = $context;

        return $context;
    }

    /**
     * @return SnapshotStreamRtContext The sending end's runtime, current, holding rows too long for one part together
     */
    private function source(): SnapshotStreamRtContext
    {
        $source = $this->mounted();
        for ($index = 0; $index < self::ROWS; ++$index) {
            $source->notes->add(HilosProbeNote::create('n' . $index, 'node-b', str_repeat(chr(ord('a') + $index % 26), 1000)));
        }

        return $source;
    }

    /**
     * @param SnapshotStreamRtContext $context Runtime to read
     * @return array<string, string> Texts of its notes by id, sorted by id
     */
    private function texts(SnapshotStreamRtContext $context): array
    {
        $texts = [];
        foreach ($context->notes->toArray() as $id => $row) {
            $texts[$id] = $row[HilosProbeNote::text];
        }
        ksort($texts);

        return $texts;
    }

    /** @return RtSyncStreamChannel Sending end, pumped only by the case */
    private function sender(): RtSyncStreamChannel
    {
        return new RtSyncStreamChannel(
            function (RtSyncStreamFrame $frame): void {
                $this->sent[$frame->sequence] = [($this->sent[$frame->sequence][0] ?? 0) + 1, $frame->final];
                $this->toReceiver[] = $frame;
            },
            static fn (): null => null,
            static function (): void {
            },
        );
    }

    /**
     * @param ?string $refuses Row whose arrival makes the host refuse the snapshot, as a row it owns itself would
     * @return RtSyncStreamChannel Receiving end whose host stages snapshots into the current runtime
     */
    private function receiver(?string $refuses = null): RtSyncStreamChannel
    {
        return new RtSyncStreamChannel(
            function (RtSyncStreamFrame $frame): void {
                $this->toSender[] = $frame;
            },
            function (RtSyncStreamOperation|RtSyncStreamSnapshot $operation, Closure $settled) use ($refuses): ?Generator {
                if ($operation instanceof RtSyncStreamSnapshot) {
                    return $this->stage($operation, $settled, $refuses);
                }
                $this->applied[] = 'write ' . $operation->stateId;
                $settled(true);

                return null;
            },
            static function (): void {
            },
        );
    }

    /**
     * The receiving host's staging of one snapshot: its rows appended as they arrive and prepared
     * before the next are taken, then the generation built, published and answered.
     *
     * @param RtSyncStreamSnapshot $snapshot Snapshot handed over at its head
     * @param Closure(bool): void $settled Answer of the snapshot
     * @param ?string $refuses Row whose arrival refuses the snapshot
     * @return Generator<int, RtWork, int, void> The staging, on the channel's allowance
     */
    private function stage(RtSyncStreamSnapshot $snapshot, Closure $settled, ?string $refuses): Generator
    {
        $this->applied[] = 'staging';
        $this->announcements[] = $snapshot->announcement;
        $beforePublish = $this->beforePublish;
        $import = RtSnapshot::stage(
            $snapshot->collectionKey,
            $snapshot->scope,
            $snapshot->cutRevision,
            liveChangeCancels: $beforePublish !== null,
        );
        while (!$snapshot->isWhole() || $snapshot->hasRows()) {
            if ($snapshot->isLost()) {
                $import->cancel();
                $this->applied[] = 'lost';
                $settled(false);

                return;
            }
            $this->assertFalse($snapshot->isLost());
            $rows = $snapshot->takeRows(RtSnapshotImport::DEFAULT_RESERVE_BYTES);
            if ($rows === null) {
                yield new RtWork();
                continue;
            }
            $this->takenEarly = $this->takenEarly || !$snapshot->isWhole();
            if ($refuses !== null && in_array($refuses, $rows->ids, true)) {
                $import->cancel();
                $this->applied[] = 'refused';
                $settled(false);

                return;
            }
            $import->append($rows);
            do {
                $budget = new RtWorkBudget(4, 512);
                $import->advance($budget);
                yield new RtWork();
            } while ($budget->take() === null);
        }
        $this->staleRows = $snapshot->staleRows();
        $import->finishInput();
        do {
            $status = $import->advance(new RtWorkBudget(4, 512));
            yield new RtWork();
        } while ($status === RtSnapshotStatus::Building);
        if ($status === RtSnapshotStatus::Ready && $beforePublish !== null) {
            $beforePublish();
            $status = $import->advance(new RtWorkBudget(0, 0));
        }
        if ($status !== RtSnapshotStatus::Ready) {
            $this->applied[] = strtolower($status->name);
            $settled(false);

            return;
        }
        $import->publish();
        $this->applied[] = 'published';
        $settled(true);
    }

    /**
     * Moves frames between the ends under small allowances until neither has anything left to do.
     *
     * @param RtSyncStreamChannel $sender Sending end
     * @param RtSyncStreamChannel $receiver Receiving end
     */
    private function exchange(RtSyncStreamChannel $sender, RtSyncStreamChannel $receiver): void
    {
        $passes = 0;
        do {
            $busy = $sender->pump(new RtWorkBudget(8, 2048));
            $moved = false;
            foreach (array_splice($this->toReceiver, 0) as $frame) {
                $moved = true;
                $receiver->receive($frame);
            }
            $busy = $receiver->pump(new RtWorkBudget(8, 2048)) || $busy;
            foreach (array_splice($this->toSender, 0) as $frame) {
                $moved = true;
                if ($frame->status !== RtSyncStreamStatus::Next) {
                    $this->answers[] = [$frame->sequence, $frame->part, $this->sent[$frame->sequence][1], $frame->status];
                }
                $sender->receive($frame);
            }
            $this->assertLessThan(5000, ++$passes, 'The stream must keep progressing');
        } while ($busy || $moved);
    }
}

/** Runtime of one end of the stream: probe notes, cut into sets by node. */
final class SnapshotStreamRtContext extends RtContext
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
