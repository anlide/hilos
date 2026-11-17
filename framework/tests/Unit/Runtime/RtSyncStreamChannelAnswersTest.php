<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Closure;
use Error;
use Generator;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\Stream\RtSyncStreamStatus;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * A host waiting for the answers of an RT stream is told once every operation the channel took
 * before it asked is answered - `applied`, `refused` or `gap` - or dropped before its first part; an
 * operation taken after holds nothing, and a link that brings no more answers ends every wait in the
 * order asked.
 */
final class RtSyncStreamChannelAnswersTest extends TestCase
{
    private const string COLLECTION = 'streamRows';

    /** @var list<string> What reached the link, in order: parts by sequence and part */
    private array $wire = [];

    /** @var list<string> Waits told, in order */
    private array $told = [];

    public function testAWaitHoldsForTheFinalAnswerOfAWriteTakenBeforeItAndNotForOneTakenAfter(): void
    {
        $channel = $this->channel();
        $channel->send($this->twoPartWrite('row-1'));
        $channel->afterAnswered(function () use ($channel): void {
            $this->told[] = 'first';
            $channel->afterAnswered($this->wait('asked again'));
        });
        $channel->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-2', ['owner' => null]));

        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 1, 0, RtSyncStreamStatus::Next));
        $this->assertSame(['part 1.0', 'final part 1.1', 'final part 2.0'], $this->wire);
        $this->assertSame([], $this->told, 'A credit and a final part written are no answer');

        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 1, 1, RtSyncStreamStatus::Applied));
        $this->assertSame(['first'], $this->told, 'The write taken after the wait still waits for its answer and holds nothing');

        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 2, 0, RtSyncStreamStatus::Applied));
        $this->assertSame(['first', 'asked again'], $this->told, 'A wait asked from inside one told holds the write before it, once');
        $this->assertTrue($channel->isIdle());
    }

    public function testARefusedOrGapAnswerEndsTheWait(): void
    {
        $channel = $this->channel();
        $channel->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            $this->rowPastTheDoor(str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES))));
        $channel->afterAnswered($this->wait('refused'));
        $channel->send($this->twoPartWrite('row-2'));
        $channel->afterAnswered($this->wait('gap'));

        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 1, 0, RtSyncStreamStatus::Next));
        $this->assertSame(['part 1.0', 'final part 1.1', 'part 2.0'], $this->wire, 'The unwritable write closes its sequence with a final part');
        $this->assertSame([], $this->told, 'The closing part is not answered yet');

        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 1, 1, RtSyncStreamStatus::Refused));
        $this->assertSame(['refused'], $this->told, 'The closing part answered refused ends the wait for it');

        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 2, 0, RtSyncStreamStatus::Gap));
        $this->assertSame(['refused', 'gap'], $this->told, 'A write answered gap before its end ends the wait for it');
        $this->assertTrue($channel->isIdle());
    }

    public function testAWriteDroppedBeforeItsFirstPartHoldsNoWait(): void
    {
        $channel = $this->channel(static function (): void {
        });
        $channel->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', $this->rowPastTheDoor('')));
        $channel->afterAnswered($this->wait('dropped'));
        $channel->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-2', ['owner' => null]));
        $this->assertSame([], $this->told, 'The pass did not run yet, so the write waits to be cut');

        while ($channel->pump(new RtWorkBudget())) {
        }
        $this->assertSame(['final part 1.0'], $this->wire, 'The dropped write spent no sequence; the later one is out');
        $this->assertSame(['dropped'], $this->told, 'The dropped write ends the wait, the later one holds nothing');
    }

    public function testEndingTheWaitsTellsEveryOneInTheOrderAsked(): void
    {
        $channel = $this->channel();
        $channel->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]));
        $channel->afterAnswered(function () use ($channel): void {
            $this->told[] = 'first';
            $channel->afterAnswered($this->wait('asked while ending'));
        });
        $channel->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-2', ['owner' => null]));
        $channel->afterAnswered($this->wait('second'));

        $channel->endAnswerWaits();
        $this->assertSame(['first', 'second', 'asked while ending'], $this->told);
        $this->assertSame(['final part 1.0', 'final part 2.0'], $this->wire, 'Ending the waits writes no frame');

        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 1, 0, RtSyncStreamStatus::Applied));
        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 2, 0, RtSyncStreamStatus::Applied));
        $this->assertSame(['first', 'second', 'asked while ending'], $this->told, 'A wait ended is never told again');
    }

    public function testEndingTheWaitsTellsEveryOneBehindAWaitThatRaisesAndRaisesTheFirstFailure(): void
    {
        $channel = $this->channel();
        $channel->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]));
        $first = new EventLoopException('The first wait failed');
        $channel->afterAnswered(function () use ($first): void {
            $this->told[] = 'first';
            throw $first;
        });
        $channel->afterAnswered($this->wait('second'));
        $channel->afterAnswered(function (): void {
            $this->told[] = 'third';
            throw new EventLoopException('The third wait failed');
        });

        try {
            $channel->endAnswerWaits();
            $this->fail('A wait that raised is not silenced');
        } catch (EventLoopException $raised) {
            $this->assertSame($first, $raised, 'The first failure reaches whoever ended the waits');
        }
        $this->assertSame(['first', 'second', 'third'], $this->told, 'The waits behind one that raised are told in the order asked');

        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 1, 0, RtSyncStreamStatus::Applied));
        $this->assertSame(['first', 'second', 'third'], $this->told, 'A wait ended is never told again');
    }

    public function testAnAnswerTellsEveryWaitItMadeReadyBehindAWaitThatRaisesAndRaisesTheFirstFailureToTheReceiver(): void
    {
        $channel = $this->channel();
        $channel->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]));
        $first = new EventLoopException('The first wait failed');
        $channel->afterAnswered(function () use ($first): void {
            $this->told[] = 'first';
            throw $first;
        });
        $channel->afterAnswered($this->wait('second'));
        $channel->afterAnswered(function (): void {
            $this->told[] = 'third';
            throw new EventLoopException('The third wait failed');
        });
        $channel->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-2', ['owner' => null]));
        $channel->afterAnswered($this->wait('after the next write'));

        try {
            $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 1, 0, RtSyncStreamStatus::Applied));
            $this->fail('A wait that raised at an answer is not silenced');
        } catch (EventLoopException $raised) {
            $this->assertSame($first, $raised, 'The first failure reaches whoever received the answer');
        }
        $this->assertSame(['first', 'second', 'third'], $this->told, 'The waits made ready by that same answer are all told, in the order asked');

        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 2, 0, RtSyncStreamStatus::Applied));
        $this->assertSame(['first', 'second', 'third', 'after the next write'], $this->told, 'A wait told is never told again');
        $this->assertTrue($channel->isIdle());
    }

    public function testAWaitWithNoWriteLeftEndsAtOnce(): void
    {
        $channel = $this->channel();
        $channel->afterAnswered($this->wait('nothing taken'));
        $this->assertSame(['nothing taken'], $this->told);

        $channel->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]));
        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 1, 0, RtSyncStreamStatus::Applied));
        $channel->afterAnswered($this->wait('everything answered'));
        $this->assertSame(['nothing taken', 'everything answered'], $this->told);
    }

    public function testTransferKeepsTheWaitUntilNewAnswersAndLaterWritesDoNotExtendIt(): void
    {
        $donor = $this->channel();
        $donor->send($this->twoPartWrite('row-1'));
        $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-2', ['value' => 2]));
        $donor->afterAnswered($this->wait('transferred'));
        $successor = $this->channel();
        $donor->moveUnsentTo($successor, static function (): void {
        });
        $successor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-3', ['value' => 3]));
        $this->assertSame([], $this->told, 'Transfer and the new first part are no answer');

        $donor->receive(RtSyncStreamFrame::ack($donor->streamId, 1, 0, RtSyncStreamStatus::Next));
        $donor->receive(RtSyncStreamFrame::ack($donor->streamId, 1, 0, RtSyncStreamStatus::Refused));
        $successor->receive(RtSyncStreamFrame::ack($donor->streamId, 1, 0, RtSyncStreamStatus::Applied));
        $donor->endAnswerWaits();
        $donor->close();
        $donor->releaseWaiting();
        $this->assertSame([], $this->told, 'An old credit, refusal, end or close cannot end a transferred wait');
        $this->assertSame(['part 1.0', 'part 1.0'], $this->wire);

        $successor->receive(RtSyncStreamFrame::ack($successor->streamId, 1, 0, RtSyncStreamStatus::Next));
        $this->assertSame(['part 1.0', 'part 1.0', 'final part 1.1', 'final part 2.0', 'final part 3.0'], $this->wire);
        $successor->receive(RtSyncStreamFrame::ack($successor->streamId, 1, 1, RtSyncStreamStatus::Applied));
        $this->assertSame([], $this->told, 'The wait also covered the second accepted write');
        $successor->receive(RtSyncStreamFrame::ack($successor->streamId, 2, 0, RtSyncStreamStatus::Applied));
        $this->assertSame(['transferred'], $this->told);
        $this->assertFalse($successor->isIdle(), 'W3 still awaits its own answer and does not stretch the old wait');
        $successor->receive(RtSyncStreamFrame::ack($successor->streamId, 3, 0, RtSyncStreamStatus::Applied));
        $this->assertTrue($successor->isIdle());
    }

    public function testRepeatedTransferAndAChainMoveEachWriteFrameAndWaitOnlyOnce(): void
    {
        $first = $this->channel();
        $first->send($this->twoPartWrite('row-1'));
        $first->writeInTurn(function (): void {
            $this->wire[] = 'frame';
        });
        $first->afterAnswered($this->wait('transferred'));
        $second = $this->channel();
        $third = $this->channel();
        $first->moveUnsentTo($second, static function (): void {
        });
        $first->moveUnsentTo($second, static function (): void {
        });
        $second->moveUnsentTo($third, static function (): void {
        });
        $second->moveUnsentTo($third, static function (): void {
        });
        $this->assertSame(['part 1.0', 'part 1.0', 'part 1.0'], $this->wire);
        $this->assertSame([], $this->told);
        $first->close();
        $first->endAnswerWaits();
        $second->close();
        $second->endAnswerWaits();
        $second->receive(RtSyncStreamFrame::ack($second->streamId, 1, 0, RtSyncStreamStatus::Next));
        $third->receive(RtSyncStreamFrame::ack($first->streamId, 1, 0, RtSyncStreamStatus::Next));
        $this->assertSame([], $this->told);

        $third->receive(RtSyncStreamFrame::ack($third->streamId, 1, 0, RtSyncStreamStatus::Next));
        $this->assertSame(['part 1.0', 'part 1.0', 'part 1.0', 'final part 1.1', 'frame'], $this->wire);
        $third->receive(RtSyncStreamFrame::ack($third->streamId, 1, 1, RtSyncStreamStatus::Applied));
        $third->endAnswerWaits();
        $this->assertSame(['transferred'], $this->told);
        $this->assertTrue($first->isIdle());
        $this->assertTrue($second->isIdle());
        $this->assertTrue($third->isIdle());
    }

    public function testFullySentObligationsEndAtTransferButAMixedWaitStillNeedsTheUnsentWrite(): void
    {
        $donor = $this->channel();
        $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'sent', ['value' => 1]));
        $donor->afterAnswered($this->wait('sent only'));
        $donor->send($this->twoPartWrite('unfinished'));
        $donor->afterAnswered($this->wait('mixed'));
        $successor = $this->channel();
        $donor->moveUnsentTo($successor, static function (): void {
        });
        $this->assertSame(['sent only'], $this->told, 'Only the obligation for the wholly sent old operation ends');
        $this->assertSame(['final part 1.0', 'part 2.0', 'part 1.0'], $this->wire, 'The wholly sent operation is not replayed');
        $donor->receive(RtSyncStreamFrame::ack($donor->streamId, 1, 0, RtSyncStreamStatus::Applied));
        $donor->endAnswerWaits();
        $this->assertSame(['sent only'], $this->told);
        $successor->receive(RtSyncStreamFrame::ack($successor->streamId, 1, 0, RtSyncStreamStatus::Next));
        $this->assertSame(['sent only'], $this->told);
        $successor->receive(RtSyncStreamFrame::ack($successor->streamId, 1, 1, RtSyncStreamStatus::Applied));
        $this->assertSame(['sent only', 'mixed'], $this->told);
        $this->assertTrue($donor->isIdle());
        $this->assertTrue($successor->isIdle());
    }

    public function testTransferTellsAllReadyWaitsAndWakesTheSuccessorEvenWhenOneRaises(): void
    {
        $donor = $this->channel();
        $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'sent', ['value' => 1]));
        $firstFailure = new EventLoopException('The first ready wait failed');
        $donor->afterAnswered(function () use ($firstFailure): void {
            $this->told[] = 'first';
            throw $firstFailure;
        });
        $donor->afterAnswered($this->wait('second'));
        $donor->afterAnswered(function (): void {
            $this->told[] = 'third';
            throw new EventLoopException('The third ready wait failed');
        });
        $donor->send($this->twoPartWrite('unfinished'));
        $donor->afterAnswered($this->wait('mixed'));
        $successor = $this->channel();
        try {
            $donor->moveUnsentTo($successor, static function (): void {
            });
            $this->fail('Transfer must return the first callback failure after cleanup');
        } catch (EventLoopException $raised) {
            $this->assertSame($firstFailure, $raised);
        }
        $this->assertSame(['first', 'second', 'third'], $this->told);
        $this->assertSame(['final part 1.0', 'part 2.0', 'part 1.0'], $this->wire, 'The successor was woken despite the failure');
        $donor->endAnswerWaits();
        $successor->receive(RtSyncStreamFrame::ack($successor->streamId, 1, 0, RtSyncStreamStatus::Next));
        $successor->receive(RtSyncStreamFrame::ack($successor->streamId, 1, 1, RtSyncStreamStatus::Applied));
        $this->assertSame(['first', 'second', 'third', 'mixed'], $this->told);
        $this->assertTrue($donor->isIdle());
        $this->assertTrue($successor->isIdle());
    }

    public function testTheNewAnswerTellsAllTransferredReadyWaitsAndReturnsTheFirstFailure(): void
    {
        $donor = $this->channel(static function (): void {
        });
        $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['value' => 1]));
        $firstFailure = new EventLoopException('The first transferred wait failed');
        $donor->afterAnswered(function () use ($firstFailure): void {
            $this->told[] = 'first';
            throw $firstFailure;
        });
        $donor->afterAnswered($this->wait('second'));
        $donor->afterAnswered(function (): void {
            $this->told[] = 'third';
            throw new EventLoopException('The third transferred wait failed');
        });
        $successor = $this->channel();
        $donor->moveUnsentTo($successor, static function (): void {
        });
        try {
            $successor->receive(RtSyncStreamFrame::ack($successor->streamId, 1, 0, RtSyncStreamStatus::Applied));
            $this->fail('The new answer must return the first callback failure');
        } catch (EventLoopException $raised) {
            $this->assertSame($firstFailure, $raised);
        }
        $successor->endAnswerWaits();
        $donor->endAnswerWaits();
        $this->assertSame(['first', 'second', 'third'], $this->told);
        $this->assertTrue($successor->isIdle());
    }

    public function testARaisingTransferredWaitCannotStrandTheTailBehindAFullAnswerWindow(): void
    {
        $donor = $this->channel(static function (): void {
        });
        $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'first', ['value' => 1]));
        $failure = new EventLoopException('The transferred wait failed');
        $donor->afterAnswered(function () use ($failure): void {
            $this->told[] = 'first';
            throw $failure;
        });
        $donor->afterAnswered($this->wait('second'));
        for ($index = 1; $index < RtSyncStreamChannel::WINDOW_OPERATIONS; ++$index) {
            $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-' . $index, ['value' => $index]));
        }
        $successor = $this->channel();
        $donor->moveUnsentTo($successor, static function (): void {
        });
        $successor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'tail', ['value' => 0]));
        $this->assertCount(RtSyncStreamChannel::WINDOW_OPERATIONS, $this->wire);
        try {
            $successor->receive(RtSyncStreamFrame::ack($successor->streamId, 1, 0, RtSyncStreamStatus::Applied));
            $this->fail('The callback failure reaches the receiver after waking the tail');
        } catch (EventLoopException $raised) {
            $this->assertSame($failure, $raised);
        }
        $this->assertSame(['first', 'second'], $this->told);
        $this->assertCount(RtSyncStreamChannel::WINDOW_OPERATIONS + 1, $this->wire, 'Freed credit wakes the tail despite the failure');
        $this->assertFalse($successor->isWriting());
        for ($sequence = 2; $sequence <= RtSyncStreamChannel::WINDOW_OPERATIONS + 1; ++$sequence) {
            $successor->receive(RtSyncStreamFrame::ack($successor->streamId, $sequence, 0, RtSyncStreamStatus::Applied));
        }
        $this->assertTrue($successor->isIdle());
        $this->assertSame(['first', 'second'], $this->told, 'Ready callbacks ran exactly once');
    }

    /**
     * A ready callback can transfer, add a write and raise without losing adjacent waits or frame boundaries.
     *
     * @param bool $atAnswer Whether an answer, rather than a transfer, makes the first callback ready
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testAReadyCallbackCanTransferTheTailWithoutLosingItsNeighborsOrExtendingTheirBoundary(bool $atAnswer): void
    {
        $donor = $this->channel();
        $middle = $this->channel(static function (): void {
        });
        $latest = $this->channel();
        $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'sent', ['value' => 0]));
        $failure = new Error('The reentrant ready callback failed');
        $endpoint = $atAnswer ? $donor : $middle;
        $donor->afterAnswered(function () use ($endpoint, $latest, $failure): void {
            $this->told[] = 'first';
            $endpoint->afterAnswered($this->wait('asked inside'));
            $endpoint->send($this->twoPartWrite('row-3'));
            $endpoint->writeInTurn(function (): void {
                $this->wire[] = 'F3';
            });
            $endpoint->moveUnsentTo($latest, static function (): void {
                self::fail('No snapshot was accepted');
            });
            throw $failure;
        });
        $donor->afterAnswered($this->wait('neighbor'));
        $donor->send($this->twoPartWrite('row-1'));
        $donor->writeInTurn(function (): void {
            $this->wire[] = 'F1';
        });
        $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-2', ['value' => 2]));
        $donor->writeInTurn(function (): void {
            $this->wire[] = 'F2';
        });
        $donor->afterAnswered($this->wait('mixed'));

        try {
            if ($atAnswer) {
                $donor->receive(RtSyncStreamFrame::ack($donor->streamId, 1, 0, RtSyncStreamStatus::Applied));
            } else {
                $donor->moveUnsentTo($middle, static function (): void {
                    self::fail('No snapshot was accepted');
                });
            }
            $this->fail('The first failure returns after the nested transfer and wake');
        } catch (Error $raised) {
            $this->assertSame($failure, $raised);
        }
        $this->assertSame(['first', 'neighbor'], $this->told);
        $this->assertSame(['final part 1.0', 'part 2.0', 'part 1.0'], $this->wire);
        $this->assertTrue($donor->isIdle());
        $this->assertTrue($middle->isIdle());
        $latest->receive(RtSyncStreamFrame::ack($latest->streamId, 1, 0, RtSyncStreamStatus::Next));
        $this->assertSame(['final part 1.0', 'part 2.0', 'part 1.0', 'final part 1.1', 'F1', 'final part 2.0', 'F2', 'part 3.0'],
            $this->wire);
        $latest->receive(RtSyncStreamFrame::ack($latest->streamId, 1, 1, RtSyncStreamStatus::Applied));
        $this->assertSame(['first', 'neighbor'], $this->told);
        $latest->receive(RtSyncStreamFrame::ack($latest->streamId, 2, 0, RtSyncStreamStatus::Applied));
        $this->assertSame(['first', 'neighbor', 'mixed', 'asked inside'], $this->told,
            'The write accepted inside the callback does not stretch either earlier wait');
        $latest->receive(RtSyncStreamFrame::ack($latest->streamId, 3, 0, RtSyncStreamStatus::Next));
        $this->assertSame(['final part 1.0', 'part 2.0', 'part 1.0', 'final part 1.1', 'F1', 'final part 2.0', 'F2',
            'part 3.0', 'final part 3.1', 'F3'], $this->wire);
        $latest->receive(RtSyncStreamFrame::ack($latest->streamId, 3, 1, RtSyncStreamStatus::Applied));
        $donor->endAnswerWaits();
        $middle->endAnswerWaits();
        $latest->endAnswerWaits();
        $this->assertSame(['first', 'neighbor', 'mixed', 'asked inside'], $this->told);
        $this->assertTrue($latest->isIdle());
    }

    /**
     * @param ?Closure(RtSyncStreamChannel): void $wake Host pass the channel is pumped on; null pumps at once
     * @return RtSyncStreamChannel Sending end whose frames go to the wire log
     */
    private function channel(?Closure $wake = null): RtSyncStreamChannel
    {
        return new RtSyncStreamChannel(
            function (RtSyncStreamFrame $frame): void {
                $part = "part {$frame->sequence}.{$frame->part}";
                $this->wire[] = $frame->final ? "final {$part}" : $part;
            },
            static fn (): null => null,
            $wake,
        );
    }

    /**
     * @param string $name Name the wait is logged under once told
     * @return Closure(): void Wait that logs itself when told
     */
    private function wait(string $name): Closure
    {
        return function () use ($name): void {
            $this->told[] = $name;
        };
    }

    /**
     * @param string $stateId Row the write creates
     * @return RtSyncCreatedSignalData A create whose row does not fit one part
     */
    private function twoPartWrite(string $stateId): RtSyncCreatedSignalData
    {
        return new RtSyncCreatedSignalData(self::COLLECTION, $stateId, ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]);
    }

    /**
     * A row no door admits - a text, then a NAN - built past the door, as only a broken writer
     * could hand one to a stream.
     *
     * @param string $blob Text written before the float
     * @return RtRowValues Field map whose float the encoder cannot write
     */
    private function rowPastTheDoor(string $blob): RtRowValues
    {
        $arena = RtRowArena::process();

        return RtWorkDriver::runSynchronously((static function () use ($arena, $blob): Generator {
            $writer = new RtRowWriter($arena, RtRowRole::Resident);
            yield from $writer->write('blob', yield from RtRowWriter::encode($blob, $arena));
            yield from $writer->write('ratio', new RtRowValues($arena, $arena->allocate(RtRowKind::Scalar, NAN, PHP_INT_SIZE),
                RtRowRole::Transient));

            return yield from $writer->finish();
        })());
    }
}
