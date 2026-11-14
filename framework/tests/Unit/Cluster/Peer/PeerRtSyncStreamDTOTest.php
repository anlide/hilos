<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSyncStreamDTO;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\Stream\RtSyncStreamStatus;
use PHPUnit\Framework\TestCase;

/**
 * The peer frame of the ordinary RT stream crosses the wire whole, and a frame outside its
 * domain is refused before anything reads it.
 */
final class PeerRtSyncStreamDTOTest extends TestCase
{
    private const string STREAM_ID = '0123456789abcdef0123456789abcdef';

    public function testPartAndAnswerSurviveTheWire(): void
    {
        $part = new PeerRtSyncStreamDTO(RtSyncStreamFrame::part(self::STREAM_ID, 3, 1, true, "{\"row\":{}}\x00\xff"));
        $parsed = PeerDTO::fromWire($part->toJson());
        $this->assertInstanceOf(PeerRtSyncStreamDTO::class, $parsed);
        $this->assertSame(PeerRtSyncStreamDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertEquals($part->frame, $parsed->frame);

        $answer = new PeerRtSyncStreamDTO(RtSyncStreamFrame::ack(self::STREAM_ID, 3, 1, RtSyncStreamStatus::Applied));
        $parsedAnswer = PeerDTO::fromWire($answer->toJson());
        $this->assertInstanceOf(PeerRtSyncStreamDTO::class, $parsedAnswer);
        $this->assertEquals($answer->frame, $parsedAnswer->frame);
    }

    public function testBytesThatAreNotStrictBase64AreRefused(): void
    {
        $this->expectException(PeerTransportException::class);
        PeerRtSyncStreamDTO::fromArray([
            RtSyncStreamFrame::FIELD_KIND => RtSyncStreamFrame::KIND_PART,
            RtSyncStreamFrame::FIELD_STREAM_ID => self::STREAM_ID,
            RtSyncStreamFrame::FIELD_SEQUENCE => 1,
            RtSyncStreamFrame::FIELD_PART => 0,
            RtSyncStreamFrame::FIELD_FINAL => true,
            RtSyncStreamFrame::FIELD_DATA => 'e30',
        ]);
    }

    public function testAPartLargerThanOnePartMayCarryIsRefused(): void
    {
        $this->expectException(PeerTransportException::class);
        PeerRtSyncStreamDTO::fromArray([
            RtSyncStreamFrame::FIELD_KIND => RtSyncStreamFrame::KIND_PART,
            RtSyncStreamFrame::FIELD_STREAM_ID => self::STREAM_ID,
            RtSyncStreamFrame::FIELD_SEQUENCE => 1,
            RtSyncStreamFrame::FIELD_PART => 0,
            RtSyncStreamFrame::FIELD_FINAL => false,
            RtSyncStreamFrame::FIELD_DATA => base64_encode(str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES + 1)),
        ]);
    }

    public function testAnUnknownStatusOrAStreamIdOfAnotherShapeIsRefused(): void
    {
        foreach ([[self::STREAM_ID, 'done'], ['0123456789ABCDEF0123456789ABCDEF', 'next']] as [$streamId, $status]) {
            try {
                PeerRtSyncStreamDTO::fromArray([
                    RtSyncStreamFrame::FIELD_KIND => RtSyncStreamFrame::KIND_ACK,
                    RtSyncStreamFrame::FIELD_STREAM_ID => $streamId,
                    RtSyncStreamFrame::FIELD_SEQUENCE => 1,
                    RtSyncStreamFrame::FIELD_PART => 0,
                    RtSyncStreamFrame::FIELD_STATUS => $status,
                ]);
                $this->fail("The answer {$status} on {$streamId} is outside its domain");
            } catch (PeerTransportException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
