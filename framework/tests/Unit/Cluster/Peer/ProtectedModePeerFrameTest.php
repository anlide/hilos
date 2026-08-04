<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeDisableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeEnableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeLiftDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiesceDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiescedDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeReadyDTO;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use PHPUnit\Framework\TestCase;

/**
 * Tests the protected-mode peer transport frames (HIL-267 slices 3 and 4).
 *
 * The initiator↔leader hand-off cannot use the agent-signal fabric — a worker-sent signal never
 * reaches the leader daemon — so enable/ready/disable ride the peer channel instead, and their
 * cluster-wide mirror (quiesce/quiesced/lift) rides it too as the leader freezes its followers.
 * These frames are thin envelopes over the domain payload DTOs; here we lock the wire shape and the
 * transport error on a malformed payload. Leader-side handling lands with the orchestration slices.
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

    public function testDisableFrameRoundTripsCarryingOnlyItsType(): void
    {
        // Unlike the worker->daemon disable frame, this one names no initiator agent: the leader
        // authorizes the release by the node id of the link it arrived on.
        $frame = new PeerProtectedModeDisableDTO();

        $restored = PeerProtectedModeDisableDTO::fromJson($frame->toJson());

        $this->assertSame(PeerProtectedModeDisableDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame([PeerProtectedModeDisableDTO::TYPE => PeerProtectedModeDisableDTO::MESSAGE_TYPE], $restored->toArray());
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
        $parsed = PeerDTO::fromWire(new PeerProtectedModeReadyDTO()->toJson());

        $this->assertInstanceOf(PeerProtectedModeReadyDTO::class, $parsed);
    }

    public function testDisableFrameDispatchesThroughTheSharedWireParser(): void
    {
        $parsed = PeerDTO::fromWire(new PeerProtectedModeDisableDTO()->toJson());

        $this->assertInstanceOf(PeerProtectedModeDisableDTO::class, $parsed);
    }

    public function testQuiesceFrameRoundTripsThroughTheWire(): void
    {
        $frame = new PeerProtectedModeQuiesceDTO(new ProtectedModeQuiesceData(
            operation: 'restore',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 3,
            initiatorNodeId: 'node-a',
        ));

        $restored = PeerProtectedModeQuiesceDTO::fromJson($frame->toJson());

        $this->assertSame(PeerProtectedModeQuiesceDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame('restore', $restored->data->operation);
        $this->assertSame('backup', $restored->data->initiatorAgentType);
        $this->assertSame(3, $restored->data->initiatorAgentIndex);
        $this->assertSame('node-a', $restored->data->initiatorNodeId);
    }

    public function testQuiesceFrameKeepsNullAgentIndex(): void
    {
        $frame = new PeerProtectedModeQuiesceDTO(new ProtectedModeQuiesceData(
            operation: 'restore',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
            initiatorNodeId: 'node-a',
        ));

        $restored = PeerProtectedModeQuiesceDTO::fromArray($frame->toArray());

        $this->assertNull($restored->data->initiatorAgentIndex);
    }

    public function testQuiesceFrameRejectsNonObjectPayload(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerProtectedModeQuiesceDTO::fromArray([
            PeerProtectedModeQuiesceDTO::FIELD_PAYLOAD => 'not-an-object',
        ]);
    }

    public function testQuiescedFrameRoundTripsAsEmptyPayload(): void
    {
        $restored = PeerProtectedModeQuiescedDTO::fromJson(new PeerProtectedModeQuiescedDTO()->toJson());

        $this->assertSame(PeerProtectedModeQuiescedDTO::MESSAGE_TYPE, $restored->getType());
    }

    public function testLiftFrameRoundTripsAsEmptyPayload(): void
    {
        $restored = PeerProtectedModeLiftDTO::fromJson(new PeerProtectedModeLiftDTO()->toJson());

        $this->assertSame(PeerProtectedModeLiftDTO::MESSAGE_TYPE, $restored->getType());
    }

    public function testQuiesceFrameDispatchesThroughTheSharedWireParser(): void
    {
        $frame = new PeerProtectedModeQuiesceDTO(new ProtectedModeQuiesceData(
            operation: 'restore',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 1,
            initiatorNodeId: 'node-a',
        ));

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerProtectedModeQuiesceDTO::class, $parsed);
        $this->assertSame('restore', $parsed->data->operation);
        $this->assertSame(1, $parsed->data->initiatorAgentIndex);
        $this->assertSame('node-a', $parsed->data->initiatorNodeId);
    }

    public function testQuiescedFrameDispatchesThroughTheSharedWireParser(): void
    {
        $parsed = PeerDTO::fromWire(new PeerProtectedModeQuiescedDTO()->toJson());

        $this->assertInstanceOf(PeerProtectedModeQuiescedDTO::class, $parsed);
    }

    public function testLiftFrameDispatchesThroughTheSharedWireParser(): void
    {
        $parsed = PeerDTO::fromWire(new PeerProtectedModeLiftDTO()->toJson());

        $this->assertInstanceOf(PeerProtectedModeLiftDTO::class, $parsed);
    }
}
