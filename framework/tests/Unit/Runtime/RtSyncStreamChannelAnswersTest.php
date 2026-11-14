<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Closure;
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
