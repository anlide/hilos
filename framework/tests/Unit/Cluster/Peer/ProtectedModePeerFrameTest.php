<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeCircleDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeDisableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeEnableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeLiftDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModePassDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeAdmitDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeProgressDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiesceDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiescedDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeRefreezeDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeSettledDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeVerifyDTO;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\VerifierCircleSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Tests the protected-mode peer transport frames (HIL-267 slices 3 and 4).
 *
 * The initiator↔leader hand-off cannot use the agent-signal fabric — a worker-sent signal never
 * reaches the leader daemon — so enable/ready/disable ride the peer channel instead, and their
 * cluster-wide mirror (quiesce/quiesced/settled/lift) rides it too as the leader freezes its followers.
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
            initiatorSessionTokenHash: null,
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
            initiatorSessionTokenHash: null,
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

    public function testInitiatorFramesRoundTripWithIdentityAndNullableIndex(): void
    {
        foreach ([0, null] as $index) {
            $frames = [
                new PeerProtectedModeDisableDTO('backup', $index),
                new PeerProtectedModeVerifyDTO('backup', $index),
                new PeerProtectedModeProgressDTO('backup', $index),
                new PeerProtectedModeRefreezeDTO('backup', $index),
                new PeerProtectedModePassDTO('backup', $index, 'pass-hash'),
                new PeerProtectedModeCircleDTO('backup', $index, new VerifierCircleSnapshot(1, ['session-hash'])),
            ];

            foreach ($frames as $frame) {
                $restored = PeerDTO::fromWire($frame->toJson());

                $this->assertSame($frame::class, $restored::class);
                $this->assertSame('backup', $restored->initiatorAgentType);
                $this->assertSame($index, $restored->initiatorAgentIndex);
                $this->assertArrayHasKey('initiatorAgentIndex', $restored->toArray());
            }
        }
    }

    public function testInitiatorFrameWithoutIdentityIsRejected(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerProtectedModeDisableDTO::fromArray([PeerDTO::TYPE => PeerProtectedModeDisableDTO::MESSAGE_TYPE]);
    }

    public function testEnableFrameDispatchesThroughTheSharedWireParser(): void
    {
        $frame = new PeerProtectedModeEnableDTO(new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
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
        $parsed = PeerDTO::fromWire(new PeerProtectedModeDisableDTO('backup', 0)->toJson());

        $this->assertInstanceOf(PeerProtectedModeDisableDTO::class, $parsed);
    }

    public function testQuiesceFrameRoundTripsThroughTheWire(): void
    {
        $frame = new PeerProtectedModeQuiesceDTO(new ProtectedModeQuiesceData(
            operation: 'restore',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 3,
            initiatorNodeId: 'node-a',
            initiatorSessionTokenHash: 'operator-hash',
        ));

        $restored = PeerProtectedModeQuiesceDTO::fromJson($frame->toJson());

        $this->assertSame(PeerProtectedModeQuiesceDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame('restore', $restored->data->operation);
        $this->assertSame('backup', $restored->data->initiatorAgentType);
        $this->assertSame(3, $restored->data->initiatorAgentIndex);
        $this->assertSame('node-a', $restored->data->initiatorNodeId);
        $this->assertSame('operator-hash', $restored->data->initiatorSessionTokenHash);
    }

    public function testQuiesceFrameKeepsNullAgentIndex(): void
    {
        $frame = new PeerProtectedModeQuiesceDTO(new ProtectedModeQuiesceData(
            operation: 'restore',
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
            initiatorNodeId: 'node-a',
            initiatorSessionTokenHash: null,
        ));

        $restored = PeerProtectedModeQuiesceDTO::fromArray($frame->toArray());

        $this->assertNull($restored->data->initiatorAgentIndex);
        $this->assertNull($restored->data->initiatorSessionTokenHash);
    }

    public function testAdmissionFrameRoundTripsAndDispatchesThroughTheWireParser(): void
    {
        $frame = new PeerProtectedModeAdmitDTO('pass-hash', 'session-hash');

        $restored = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerProtectedModeAdmitDTO::class, $restored);
        $this->assertSame('pass-hash', $restored->passHash);
        $this->assertSame('session-hash', $restored->sessionTokenHash);
    }

    public function testAdmissionFrameRejectsMissingOrEmptyHashes(): void
    {
        foreach ([
            [PeerProtectedModeAdmitDTO::TYPE => PeerProtectedModeAdmitDTO::MESSAGE_TYPE,
                PeerProtectedModeAdmitDTO::FIELD_PASS_HASH => 'pass-hash'],
            [PeerProtectedModeAdmitDTO::TYPE => PeerProtectedModeAdmitDTO::MESSAGE_TYPE,
                PeerProtectedModeAdmitDTO::FIELD_PASS_HASH => 'pass-hash',
                PeerProtectedModeAdmitDTO::FIELD_SESSION_TOKEN_HASH => ''],
        ] as $frame) {
            try {
                PeerProtectedModeAdmitDTO::fromArray($frame);
                $this->fail('Malformed admission frame was accepted.');
            } catch (PeerTransportException) {
                $this->addToAssertionCount(1);
            }
        }
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

    public function testSettledFrameRoundTripsAsEmptyPayload(): void
    {
        $restored = PeerProtectedModeSettledDTO::fromJson(new PeerProtectedModeSettledDTO()->toJson());

        $this->assertSame(PeerProtectedModeSettledDTO::MESSAGE_TYPE, $restored->getType());
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
            initiatorSessionTokenHash: null,
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

    public function testSettledFrameDispatchesThroughTheSharedWireParser(): void
    {
        $parsed = PeerDTO::fromWire(new PeerProtectedModeSettledDTO()->toJson());

        $this->assertInstanceOf(PeerProtectedModeSettledDTO::class, $parsed);
    }

    public function testLiftFrameDispatchesThroughTheSharedWireParser(): void
    {
        $parsed = PeerDTO::fromWire(new PeerProtectedModeLiftDTO()->toJson());

        $this->assertInstanceOf(PeerProtectedModeLiftDTO::class, $parsed);
    }

    public function testCircleFrameRoundTripsThroughTheWire(): void
    {
        $frame = new PeerProtectedModeCircleDTO('backup', 0, new VerifierCircleSnapshot(3, ['hash-a', 'hash-b']));

        $restored = PeerProtectedModeCircleDTO::fromJson($frame->toJson());

        $this->assertSame(PeerProtectedModeCircleDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame(3, $restored->snapshot->namedCount);
        $this->assertSame(['hash-a', 'hash-b'], $restored->snapshot->sessionTokenHashes);
    }

    public function testCircleFrameDispatchesThroughTheSharedWireParser(): void
    {
        // Named but nobody online: the count alone still travels, so a node can tell "nobody was
        // named" from "nobody named was online".
        $frame = new PeerProtectedModeCircleDTO('backup', null, new VerifierCircleSnapshot(1, []));

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerProtectedModeCircleDTO::class, $parsed);
        $this->assertSame(1, $parsed->snapshot->namedCount);
        $this->assertSame([], $parsed->snapshot->sessionTokenHashes);
    }

    public function testCircleFrameRejectsANonIntegerCount(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerProtectedModeCircleDTO::fromArray([
            PeerProtectedModeCircleDTO::FIELD_INITIATOR_AGENT_TYPE => 'backup',
            PeerProtectedModeCircleDTO::FIELD_INITIATOR_AGENT_INDEX => null,
            PeerProtectedModeCircleDTO::FIELD_NAMED_COUNT => '1',
            PeerProtectedModeCircleDTO::FIELD_SESSION_TOKEN_HASHES => [],
        ]);
    }

    public function testCircleFrameRejectsAHashListThatIsNotAList(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerProtectedModeCircleDTO::fromArray([
            PeerProtectedModeCircleDTO::FIELD_INITIATOR_AGENT_TYPE => 'backup',
            PeerProtectedModeCircleDTO::FIELD_INITIATOR_AGENT_INDEX => null,
            PeerProtectedModeCircleDTO::FIELD_NAMED_COUNT => 1,
            PeerProtectedModeCircleDTO::FIELD_SESSION_TOKEN_HASHES => 'hash-a',
        ]);
    }

    public function testCircleFrameRefusesTheWholeListOverOneMalformedHash(): void
    {
        // Thinning the list would lock out a person the photograph named.
        $this->expectException(PeerTransportException::class);

        PeerProtectedModeCircleDTO::fromArray([
            PeerProtectedModeCircleDTO::FIELD_INITIATOR_AGENT_TYPE => 'backup',
            PeerProtectedModeCircleDTO::FIELD_INITIATOR_AGENT_INDEX => null,
            PeerProtectedModeCircleDTO::FIELD_NAMED_COUNT => 2,
            PeerProtectedModeCircleDTO::FIELD_SESSION_TOKEN_HASHES => ['hash-a', 7],
        ]);
    }
}
