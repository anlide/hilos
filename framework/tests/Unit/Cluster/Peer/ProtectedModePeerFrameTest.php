<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeDisableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeEnableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeReadyDTO;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Tests the protected-mode peer transport frames (HIL-267 slice 3).
 *
 * The initiator↔leader hand-off cannot use the agent-signal fabric — a worker-sent signal never
 * reaches the leader daemon — so enable/ready/disable ride the peer channel instead. These frames
 * are thin envelopes over the slice-1 payload DTOs; here we lock the wire shape and the transport
 * error on a malformed payload. Recognition by {@see \Hilos\Cluster\Peer\DTO\PeerDTO::fromWire} and
 * leader-side handling land with the orchestration slices.
 */
final class ProtectedModePeerFrameTest extends TestCase
{
    public function testEnableFrameRoundTripsThroughTheWire(): void
    {
        $frame = new PeerProtectedModeEnableDTO(new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            initiatorNodeId: 'node-a',
        ));

        $restored = PeerProtectedModeEnableDTO::fromJson($frame->toJson());

        $this->assertSame(PeerProtectedModeEnableDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame('restore', $restored->data->operation);
        $this->assertSame('accept-9', $restored->data->initiatorAcceptKey);
        $this->assertSame('backup', $restored->data->initiatorAgentType);
        $this->assertSame(0, $restored->data->initiatorAgentIndex);
        $this->assertSame('node-a', $restored->data->initiatorNodeId);
    }

    public function testEnableFrameKeepsNullAgentIndex(): void
    {
        $frame = new PeerProtectedModeEnableDTO(new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
            initiatorNodeId: 'node-a',
        ));

        $restored = PeerProtectedModeEnableDTO::fromArray($frame->toArray());

        $this->assertNull($restored->data->initiatorAgentIndex);
    }

    public function testEnableFrameRejectsNonObjectPayload(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerProtectedModeEnableDTO::fromArray([
            PeerProtectedModeEnableDTO::FIELD_PAYLOAD => 'not-an-object',
        ]);
    }

    public function testReadyFrameRoundTripsAsEmptyPayload(): void
    {
        $frame = new PeerProtectedModeReadyDTO();

        $restored = PeerProtectedModeReadyDTO::fromJson($frame->toJson());

        $this->assertSame(PeerProtectedModeReadyDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame([], $restored->data->toArray());
    }

    public function testDisableFrameRoundTripsAsEmptyPayload(): void
    {
        $frame = new PeerProtectedModeDisableDTO();

        $restored = PeerProtectedModeDisableDTO::fromJson($frame->toJson());

        $this->assertSame(PeerProtectedModeDisableDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame([], $restored->data->toArray());
    }

    public function testEnableFrameDispatchesThroughTheSharedWireParser(): void
    {
        $frame = new PeerProtectedModeEnableDTO(new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 2,
            initiatorNodeId: 'node-a',
        ));

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerProtectedModeEnableDTO::class, $parsed);
        $this->assertSame('restore', $parsed->data->operation);
        $this->assertSame(2, $parsed->data->initiatorAgentIndex);
        $this->assertSame('node-a', $parsed->data->initiatorNodeId);
    }

    public function testReadyFrameDispatchesThroughTheSharedWireParser(): void
    {
        $parsed = PeerDTO::fromWire((new PeerProtectedModeReadyDTO())->toJson());

        $this->assertInstanceOf(PeerProtectedModeReadyDTO::class, $parsed);
    }

    public function testDisableFrameDispatchesThroughTheSharedWireParser(): void
    {
        $parsed = PeerDTO::fromWire((new PeerProtectedModeDisableDTO())->toJson());

        $this->assertInstanceOf(PeerProtectedModeDisableDTO::class, $parsed);
    }
}
