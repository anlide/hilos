<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSyncDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Tests the RT replication peer frame (HIL-586).
 *
 * The frame round-trips the origin node and the RT sync signal itself over the wire and is
 * recognised by the peer-frame parser. What it refuses is as much of the contract as what it
 * carries: the frame is applied on the far side by a node that has no way to check the sender,
 * so a payload naming a signal type that is not an RT sync one is a transport error here rather
 * than an apply of somebody else's sync arm there.
 */
final class PeerRtSyncDTOTest extends TestCase
{
    public function testRoundTripsThroughTheWire(): void
    {
        $frame = new PeerRtSyncDTO(
            originNodeId: 'node-A',
            signalType: SignalTypeConstants::RT_SYNC_CREATED,
            signal: $this->signal(),
        );

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerRtSyncDTO::class, $parsed);
        $this->assertSame(PeerRtSyncDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertSame('node-A', $parsed->originNodeId);
        $this->assertSame(SignalTypeConstants::RT_SYNC_CREATED, $parsed->signalType);
        $this->assertSame(SignalTypeConstants::RT_SYNC_CREATED, $parsed->signal->signalType->getType());

        $data = $parsed->signal->data;
        $this->assertInstanceOf(RtSyncCreatedSignalData::class, $data);
        $this->assertSame('rooms', $data->collectionKey);
        $this->assertSame('7', $data->stateId);
        $this->assertSame(['name' => 'Ada'], $data->row);
    }

    public function testRejectsMissingOriginNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer RT sync is missing the origin node id');

        PeerRtSyncDTO::fromArray([
            PeerRtSyncDTO::FIELD_SIGNAL_TYPE => SignalTypeConstants::RT_SYNC_CREATED,
            PeerRtSyncDTO::FIELD_SIGNAL => $this->signal()->toArray(),
        ]);
    }

    public function testRejectsASignalTypeThatIsNotAnRtSyncOne(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer RT sync carries a signal type that is not an RT sync one');

        PeerRtSyncDTO::fromArray([
            PeerRtSyncDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerRtSyncDTO::FIELD_SIGNAL_TYPE => SignalTypeConstants::DB_SYNC_CREATED,
            PeerRtSyncDTO::FIELD_SIGNAL => $this->signal()->toArray(),
        ]);
    }

    public function testRejectsMissingInnerSignal(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer RT sync is missing the inner signal payload');

        PeerRtSyncDTO::fromArray([
            PeerRtSyncDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerRtSyncDTO::FIELD_SIGNAL_TYPE => SignalTypeConstants::RT_SYNC_CREATED,
        ]);
    }

    /**
     * Builds the RT sync fact the frame carries.
     *
     * @return SignalDTO Signal announcing one created runtime row
     */
    private function signal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::RT),
            new SignalType(SignalTypeConstants::RT_SYNC_CREATED),
            new SignalName(SignalConstants::RT_SYNC_CREATED),
            new RtSyncCreatedSignalData('rooms', '7', ['name' => 'Ada']),
        );
    }
}
