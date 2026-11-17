<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Closure;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Runtime\Stream\RtSyncStreamStatus;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * A frame of another kind a host writes to the link of an RT stream goes in its turn among the
 * stream's operations: behind the final part of every operation taken before it, ahead of every
 * operation taken after it, and at once when nothing taken before it is still being sent.
 */
final class RtSyncStreamChannelTurnTest extends TestCase
{
    private const string COLLECTION = 'streamRows';

    private const string SIGNAL = 'signal';

    /** @var list<string> What reached the link, in order: parts by sequence and part, and the host's own frames */
    private array $wire = [];

    public function testAFrameWrittenBetweenTheTwoPartsOfAWriteGoesRightBehindItsFinalPartAndAheadOfTheNextWrite(): void
    {
        $channel = $this->channel();
        $channel->send($this->twoPartWrite('row-1'));
        $this->assertSame(['part 1.0'], $this->wire, 'The second part waits for the receiver\'s next');

        $this->signal($channel);
        $channel->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-2', ['owner' => null]));
        $this->assertSame(['part 1.0'], $this->wire, 'Neither the signal nor the later write overtakes the part still owed');

        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 1, 0, RtSyncStreamStatus::Next));
        $this->assertSame(['part 1.0', 'final part 1.1', self::SIGNAL, 'final part 2.0'], $this->wire);
    }

    public function testAFrameGoesAtOnceWhenEveryWriteTakenBeforeItIsOutWhole(): void
    {
        $channel = $this->channel();
        $this->signal($channel);
        $channel->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]));
        $this->signal($channel);

        $this->assertSame([self::SIGNAL, 'final part 1.0', self::SIGNAL], $this->wire,
            'A write out whole holds nothing back while it waits for its answer');
    }

    public function testAFrameWrittenBeforeThePassCutTheWriteTakenAheadOfItGoesBehindThatWrite(): void
    {
        $channel = $this->channel(static function (): void {
        });
        $channel->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]));
        $this->signal($channel);
        $this->assertSame([], $this->wire, 'The pass did not run yet, so the signal waits for the write');

        while ($channel->pump(new RtWorkBudget())) {
        }
        $this->assertSame(['final part 1.0', self::SIGNAL], $this->wire);
    }

    /**
     * A snapshot is an operation of the stream like a write: a frame the host writes after the
     * snapshot was taken - the thaw of a row, a signal to an agent - goes behind its final part.
     */
    public function testAFrameWrittenAfterASnapshotWasTakenGoesBehindItsFinalPart(): void
    {
        $channel = $this->channel(static function (): void {
        });
        // Cut where this process holds no RT state, so the snapshot is one empty part.
        $previous = Hilos::$rt;
        Hilos::$rt = null;
        try {
            $channel->sendSnapshot(self::COLLECTION, RtSnapshotScope::whole());
        } finally {
            Hilos::$rt = $previous;
        }
        $this->signal($channel);
        $this->assertSame([], $this->wire, 'The pass did not cut the snapshot yet, so the signal waits for it');

        while ($channel->pump(new RtWorkBudget())) {
        }
        $this->assertSame(['final part 1.0', self::SIGNAL], $this->wire);
    }

    public function testAFrameBehindAWriteAnsweredBeforeItsEndGoesOnceTheWriteIsGivenUp(): void
    {
        $channel = $this->channel();
        $channel->send($this->twoPartWrite('row-1'));
        $this->signal($channel);

        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 1, 0, RtSyncStreamStatus::Refused));
        $this->assertSame(['part 1.0', self::SIGNAL], $this->wire, 'A write given up does not hold the signal');
        $this->assertTrue($channel->isIdle());
    }

    public function testQueuedWritesKeepTheirFrameBoundariesAndRevisionsAcrossTransfer(): void
    {
        $donor = $this->channel(static function (): void {
        });
        $donor->send(new RtSyncUpdatedSignalData(self::COLLECTION, 'row-1', ['value' => 1], previous: ['value' => 0]));
        $donor->writeInTurn(function (): void {
            $this->wire[] = 'F1';
        });
        $donor->send(new RtSyncUpdatedSignalData(self::COLLECTION, 'row-1', ['value' => 2], previous: ['value' => 1]));
        $donor->writeInTurn(function (): void {
            $this->wire[] = 'F2';
        });
        $parts = [];
        $successor = $this->channel(static function (): void {
        }, static function (RtSyncStreamFrame $part) use (&$parts): void {
            $parts[] = $part;
        });
        $donor->moveUnsentTo($successor, static function (): void {
            self::fail('This transfer has no snapshot');
        });
        $successor->send(new RtSyncUpdatedSignalData(self::COLLECTION, 'row-1', ['value' => 3], previous: ['value' => 2]));
        $donor->pump(new RtWorkBudget());
        $this->assertSame([], $this->wire, 'The old scheduled pump has nothing left to write');

        $operations = $this->deliver($successor, $parts);
        $this->assertSame(['final part 1.0', 'F1', 'final part 2.0', 'F2', 'final part 3.0'], $this->wire);
        $this->assertSame([1, 2, 3], array_map(static fn (RtSyncStreamOperation $operation): int => $operation->revision, $operations));
        $this->assertSame([['value' => 1], ['value' => 2], ['value' => 3]],
            array_map(static fn (RtSyncStreamOperation $operation): array => $operation->signalData()->row, $operations));
        $this->assertSame([['value' => 0], ['value' => 1], ['value' => 2]],
            array_map(static fn (RtSyncStreamOperation $operation): array => $operation->signalData()->previous, $operations));
        $this->assertTrue($donor->isIdle());
    }

    public function testAPartialWriteRestartsAtPartZeroWithItsAcceptedRowPreviousAndOrigin(): void
    {
        $oldParts = [];
        $donor = $this->channel(sent: static function (RtSyncStreamFrame $part) use (&$oldParts): void {
            $oldParts[] = $part;
        });
        $row = ['blob' => str_repeat('accepted', RtSyncStreamFrame::MAX_PART_BYTES), 'nested' => ['value' => 1]];
        $previous = ['blob' => 'before', 'nested' => ['value' => 0]];
        $donor->send(new RtSyncUpdatedSignalData(self::COLLECTION, 'row-1', $row, 'accept-1', 'request-1', $previous, 'worker-1'),
            'node-a', true);
        $donor->send(new RtSyncUpdatedSignalData(self::COLLECTION, 'row-1', ['nested' => ['value' => 2]],
            previous: ['nested' => ['value' => 1]]));
        $this->assertCount(1, $oldParts);
        $this->assertFalse($oldParts[0]->final);

        $parts = [];
        $successor = $this->channel(sent: static function (RtSyncStreamFrame $part) use (&$parts): void {
            $parts[] = $part;
        });
        $donor->moveUnsentTo($successor, static function (): void {
            self::fail('This transfer has no snapshot');
        });
        $this->assertSame([1, 0, false], [$parts[0]->sequence, $parts[0]->part, $parts[0]->final]);
        $this->assertNotSame($oldParts[0]->streamId, $parts[0]->streamId);
        $this->assertSame($oldParts[0]->data, $parts[0]->data, 'The new encoder starts with the same accepted object');
        $donor->receive(RtSyncStreamFrame::ack($donor->streamId, 1, 0, RtSyncStreamStatus::Next));
        $donor->pump(new RtWorkBudget());
        $this->assertCount(1, $oldParts, 'Neither a late credit nor an old scheduled pump resumes the donor');

        $operations = $this->deliver($successor, $parts);
        $this->assertCount(2, $operations);
        $this->assertSame($row, $operations[0]->signalData()->row);
        $this->assertSame($previous, $operations[0]->signalData()->previous);
        $this->assertSame(['accept-1', 'request-1', 'worker-1', 'node-a', true], [$operations[0]->origin,
            $operations[0]->originRequestId, $operations[0]->emitter, $operations[0]->originNodeId, $operations[0]->partialOwner]);
        $this->assertSame(['nested' => ['value' => 2]], $operations[1]->signalData()->row);
        $this->assertTrue($donor->isIdle());
    }

    public function testAnEncoderThatSpentBudgetBeforeItsFirstPartCanRestartOrdinaryWork(): void
    {
        $donor = $this->channel(static function (): void {
        });
        $donor->send($this->twoPartWrite('row-1'));
        $this->assertTrue($donor->pump(new RtWorkBudget(3, 64)));
        $this->assertSame([], $this->wire);
        $parts = [];
        $successor = $this->channel(static function (): void {
        }, static function (RtSyncStreamFrame $part) use (&$parts): void {
            $parts[] = $part;
        });
        $donor->moveUnsentTo($successor, static function (): void {
        });
        $this->assertTrue($successor->pump(new RtWorkBudget(3, 64)));
        $this->assertSame([], $parts, 'Restarted encoding still spends the normal allowance');
        $operations = $this->deliver($successor, $parts);
        $this->assertSame(['row-1'], array_map(static fn (RtSyncStreamOperation $operation): string => $operation->stateId, $operations));
        $this->assertSame(['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)], $operations[0]->signalData()->row);
    }

    public function testTransferKeepsACurrentWriteInAdditionToAFullOutbox(): void
    {
        $donor = $this->channel();
        $donor->send($this->twoPartWrite('current'));
        for ($index = 0; $index < RtSyncStreamChannel::OUTBOX_OPERATIONS; ++$index) {
            $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'queued-' . $index, ['index' => $index]));
        }
        $this->assertFalse($donor->hasRoom());
        $parts = [];
        $successor = $this->channel(static function (): void {
        }, static function (RtSyncStreamFrame $part) use (&$parts): void {
            $parts[] = $part;
        });
        $donor->moveUnsentTo($successor, static function (): void {
        });
        $this->assertFalse($successor->hasRoom(), 'The current write does not consume an outbox slot');
        $operations = $this->deliver($successor, $parts);
        $this->assertCount(RtSyncStreamChannel::OUTBOX_OPERATIONS + 1, $operations);
        $this->assertSame('current', $operations[0]->stateId);
        $this->assertSame('queued-' . (RtSyncStreamChannel::OUTBOX_OPERATIONS - 1), $operations[array_key_last($operations)]->stateId);
        $this->assertTrue($successor->hasRoom());
    }

    public function testASuccessorMayHaveIncomingWorkButMustHaveAnUnusedSendingDirection(): void
    {
        $parts = [];
        $incoming = $this->channel(sent: static function (RtSyncStreamFrame $part) use (&$parts): void {
            $parts[] = $part;
        });
        $incoming->send($this->twoPartWrite('incoming'));
        $successor = $this->channel(static function (): void {
        });
        $successor->receive($parts[0]);
        $this->assertFalse($successor->isIdle());
        $donor = $this->channel(static function (): void {
        });
        $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'outgoing', ['value' => 1]));
        $donor->moveUnsentTo($successor, static function (): void {
        });
        $this->assertTrue($successor->isWriting());

        $another = $this->channel(static function (): void {
        });
        $another->send(new RtSyncCreatedSignalData(self::COLLECTION, 'another', ['value' => 2]));
        try {
            $another->moveUnsentTo($successor, static function (): void {
            });
            $this->fail('A successor with outgoing work must be refused before mutation');
        } catch (LogicException) {
            $this->assertTrue($another->isWriting());
            $this->assertTrue($successor->isWriting());
        }
    }

    public function testTransferFromInsideTheWriterDoesNotRestoreTheDonorsOldPositions(): void
    {
        foreach ([false, true] as $final) {
            $parts = [];
            $successor = $this->channel(static function (): void {
            }, static function (RtSyncStreamFrame $part) use (&$parts): void {
                $parts[] = $part;
            });
            $oldParts = [];
            $donor = null;
            $donor = new RtSyncStreamChannel(
                static function (RtSyncStreamFrame $part) use (&$donor, $successor, &$oldParts): void {
                    $oldParts[] = $part;
                    $donor->moveUnsentTo($successor, static function (): void {
                    });
                },
                static fn (): null => null,
                static function (): void {
                },
            );
            $donor->send($final
                ? new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['value' => 1])
                : $this->twoPartWrite('row-1'));
            $told = [];
            $donor->afterAnswered(static function () use (&$told): void {
                $told[] = 'answered';
            });
            while ($donor->pump(new RtWorkBudget())) {
            }
            $this->assertCount(1, $oldParts);
            $this->assertSame($final, $oldParts[0]->final);
            $this->assertTrue($donor->isIdle(), 'Returning from the writer cannot put old positions back');
            if ($final) {
                $this->assertSame([], $parts, 'A final part already written is not replayed');
                $this->assertFalse($successor->isWriting());
                $this->assertSame(['answered'], $told);
            } else {
                $this->assertSame([], $told, 'The partial write still needs its new answer');
                $this->assertCount(1, $this->deliver($successor, $parts));
                $this->assertSame(['answered'], $told);
            }
        }
    }

    /**
     * @param ?Closure(RtSyncStreamChannel): void $wake Host pass the channel is pumped on; null pumps at once
     * @param ?Closure(RtSyncStreamFrame): void $sent Observes the actual frames for delivery to another channel
     * @return RtSyncStreamChannel Sending end whose frames go to the wire log
     */
    private function channel(?Closure $wake = null, ?Closure $sent = null): RtSyncStreamChannel
    {
        return new RtSyncStreamChannel(
            function (RtSyncStreamFrame $frame) use ($sent): void {
                $part = "part {$frame->sequence}.{$frame->part}";
                $this->wire[] = $frame->final ? "final {$part}" : $part;
                if ($sent !== null) {
                    $sent($frame);
                }
            },
            static fn (): null => null,
            $wake,
        );
    }

    /**
     * @param RtSyncStreamChannel $sender Sending end under normal budget and credit
     * @param list<RtSyncStreamFrame> $parts Frames written, removed once read by the receiving end
     * @return list<RtSyncStreamOperation> Complete application history on the new receiving end
     */
    private function deliver(RtSyncStreamChannel $sender, array &$parts): array
    {
        $operations = [];
        $receiver = new RtSyncStreamChannel(
            static fn (RtSyncStreamFrame $answer) => $sender->receive($answer),
            static function (RtSyncStreamOperation $operation, Closure $settle) use (&$operations): null {
                $operations[] = $operation;
                $settle(true);

                return null;
            },
        );
        $passes = 0;
        do {
            $busy = $sender->pump(new RtWorkBudget(8, 2048));
            foreach (array_splice($parts, 0) as $part) {
                $this->assertLessThanOrEqual(RtSyncStreamFrame::MAX_PART_BYTES, strlen($part->data));
                $receiver->receive($part);
            }
            $this->assertLessThan(5000, ++$passes, 'The transferred stream must progress');
        } while ($busy || $parts !== [] || $sender->isWriting());
        $this->assertTrue($sender->isIdle());
        $this->assertTrue($receiver->isIdle());

        return $operations;
    }

    /**
     * @param RtSyncStreamChannel $channel Channel the host writes its frame through
     */
    private function signal(RtSyncStreamChannel $channel): void
    {
        $channel->writeInTurn(function (): void {
            $this->wire[] = self::SIGNAL;
        });
    }

    /**
     * @param string $stateId Row the write creates
     * @return RtSyncCreatedSignalData A create whose row does not fit one part
     */
    private function twoPartWrite(string $stateId): RtSyncCreatedSignalData
    {
        return new RtSyncCreatedSignalData(self::COLLECTION, $stateId, ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]);
    }
}
