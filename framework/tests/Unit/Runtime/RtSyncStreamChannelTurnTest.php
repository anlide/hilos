<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Closure;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\Stream\RtSyncStreamStatus;
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

    public function testAFrameBehindAWriteAnsweredBeforeItsEndGoesOnceTheWriteIsGivenUp(): void
    {
        $channel = $this->channel();
        $channel->send($this->twoPartWrite('row-1'));
        $this->signal($channel);

        $channel->receive(RtSyncStreamFrame::ack($channel->streamId, 1, 0, RtSyncStreamStatus::Refused));
        $this->assertSame(['part 1.0', self::SIGNAL], $this->wire, 'A write given up does not hold the signal');
        $this->assertTrue($channel->isIdle());
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
