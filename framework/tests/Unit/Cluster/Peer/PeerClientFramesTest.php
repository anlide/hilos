<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerClientFanoutDTO;
use Hilos\Cluster\Peer\DTO\PeerClientSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerConnectionsDeltaDTO;
use Hilos\Cluster\Peer\DTO\PeerConnectionsSnapshotDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use PHPUnit\Framework\TestCase;

/**
 * The frames that carry a browser across the mesh (HIL-668).
 *
 * These are the wire of the connection index: a node says which sockets it holds, and every
 * other node believes it. What has to survive the trip is the accept-key list exactly — a key
 * lost is a browser the cluster can no longer answer, and a key invented is a signal addressed
 * into nothing. That is why the lists are read strictly and a malformed frame is refused whole
 * rather than thinned: a half-read snapshot REPLACES the receiver's picture of that node, so
 * quietly dropping one bad entry would delete a live connection with it.
 */
final class PeerClientFramesTest extends TestCase
{
    public function testAConnectionsSnapshotRoundTripsThroughTheWire(): void
    {
        $frame = new PeerConnectionsSnapshotDTO(nodeId: 'node-A', acceptKeys: ['ak-1', 'ak-2']);

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerConnectionsSnapshotDTO::class, $parsed);
        $this->assertSame(PeerConnectionsSnapshotDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertSame('node-A', $parsed->nodeId);
        $this->assertSame(['ak-1', 'ak-2'], $parsed->acceptKeys);
    }

    /**
     * A node whose last client just left announces exactly this, and the receiver is meant to
     * end up holding nothing for it — which is why an empty list is a snapshot of nothing and
     * not a missing field.
     */
    public function testASnapshotOfNoConnectionsIsCarried(): void
    {
        $frame = new PeerConnectionsSnapshotDTO('node-A', []);

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerConnectionsSnapshotDTO::class, $parsed);
        $this->assertSame([], $parsed->acceptKeys);
    }

    public function testASnapshotRejectsAMissingNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer connections snapshot is missing the node id');

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerConnectionsSnapshotDTO::MESSAGE_TYPE,
            PeerConnectionsSnapshotDTO::FIELD_ACCEPT_KEYS => ['ak-1'],
        ]));
    }

    public function testASnapshotRejectsAMissingKeyList(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage("Peer connections snapshot is missing the 'acceptKeys' list");

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerConnectionsSnapshotDTO::MESSAGE_TYPE,
            PeerConnectionsSnapshotDTO::FIELD_NODE_ID => 'node-A',
        ]));
    }

    /**
     * A blank entry is not a connection: it is an address nothing can be delivered to, and one
     * more entry in a set the receiver is about to trust whole.
     */
    public function testASnapshotRejectsABlankAcceptKey(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer connections snapshot carries a malformed accept key');

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerConnectionsSnapshotDTO::MESSAGE_TYPE,
            PeerConnectionsSnapshotDTO::FIELD_NODE_ID => 'node-A',
            PeerConnectionsSnapshotDTO::FIELD_ACCEPT_KEYS => ['ak-1', ''],
        ]));
    }

    public function testAConnectionsDeltaRoundTripsThroughTheWire(): void
    {
        $frame = new PeerConnectionsDeltaDTO(nodeId: 'node-A', opened: ['ak-2'], closed: ['ak-1']);

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerConnectionsDeltaDTO::class, $parsed);
        $this->assertSame(PeerConnectionsDeltaDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertSame('node-A', $parsed->nodeId);
        $this->assertSame(['ak-2'], $parsed->opened);
        $this->assertSame(['ak-1'], $parsed->closed);
    }

    /**
     * The common shape by far — a tick in which only openings happened — and the one a lenient
     * reader would turn into "nothing closed" for a frame that actually lost its list.
     */
    public function testADeltaCarriesAnEmptySideAsAnEmptyList(): void
    {
        $frame = new PeerConnectionsDeltaDTO('node-A', ['ak-2'], []);

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerConnectionsDeltaDTO::class, $parsed);
        $this->assertSame([], $parsed->closed);
    }

    /**
     * A delta with a side missing is refused rather than read as "nothing closed": that reading
     * is exactly how a key stays pointing at a node that no longer holds it.
     */
    public function testADeltaRejectsAMissingCloseList(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage("Peer connections delta is missing the 'closed' list");

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerConnectionsDeltaDTO::MESSAGE_TYPE,
            PeerConnectionsDeltaDTO::FIELD_NODE_ID => 'node-A',
            PeerConnectionsDeltaDTO::FIELD_OPENED => ['ak-2'],
        ]));
    }

    public function testADeltaRejectsAMissingNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer connections delta is missing the node id');

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerConnectionsDeltaDTO::MESSAGE_TYPE,
            PeerConnectionsDeltaDTO::FIELD_OPENED => [],
            PeerConnectionsDeltaDTO::FIELD_CLOSED => [],
        ]));
    }

    /**
     * The application signal rides inside verbatim, through its own serializer: the receiving
     * node has to encode the very frame its own local path would have encoded, or a browser
     * would be able to tell which node its agent happened to run on.
     */
    public function testAClientSignalRoundTripsWithItsInnerSignalIntact(): void
    {
        $frame = new PeerClientSignalDTO('node-A', 'node-B', 'ak-1', $this->innerSignal());

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerClientSignalDTO::class, $parsed);
        $this->assertSame(PeerClientSignalDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertSame('node-A', $parsed->originNodeId);
        $this->assertSame('node-B', $parsed->targetNodeId);
        $this->assertSame('ak-1', $parsed->acceptKey);
        $this->assertSame('room_renamed', $parsed->signal->signalName->getName());
        $this->assertSame(['room' => 'Ada'], $parsed->signal->data->toArray());
    }

    /**
     * Without the accept key the frame names no browser, and the receiving node has nothing to
     * write to - a forward that could only end in a silent drop is refused at the wire instead.
     */
    public function testAClientSignalRejectsAMissingAcceptKey(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer client signal is missing the target accept key');

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerClientSignalDTO::MESSAGE_TYPE,
            PeerClientSignalDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerClientSignalDTO::FIELD_TARGET_NODE_ID => 'node-B',
            PeerClientSignalDTO::FIELD_SIGNAL => $this->innerSignal()->toArray(),
        ]));
    }

    public function testAClientSignalRejectsAMissingTargetNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer client signal is missing the target node id');

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerClientSignalDTO::MESSAGE_TYPE,
            PeerClientSignalDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerClientSignalDTO::FIELD_ACCEPT_KEY => 'ak-1',
            PeerClientSignalDTO::FIELD_SIGNAL => $this->innerSignal()->toArray(),
        ]));
    }

    public function testAClientSignalRejectsAMalformedInnerSignal(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer client signal is missing the inner signal payload');

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerClientSignalDTO::MESSAGE_TYPE,
            PeerClientSignalDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerClientSignalDTO::FIELD_TARGET_NODE_ID => 'node-B',
            PeerClientSignalDTO::FIELD_ACCEPT_KEY => 'ak-1',
        ]));
    }

    /**
     * The fan-out frame names no target at all, and that absence is the design: which browsers
     * it reaches is decided on the receiving node, against a subscription registry the sender
     * cannot see. Everything the receiver needs to decide with — the fan-out kind, the group,
     * the excluded key — is already inside the signal, so a field here would be a second copy
     * of it, free to disagree.
     */
    public function testAClientFanoutRoundTripsCarryingOnlyTheSignal(): void
    {
        $frame = new PeerClientFanoutDTO('node-A', $this->fanoutSignal());

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerClientFanoutDTO::class, $parsed);
        $this->assertSame(PeerClientFanoutDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertSame('node-A', $parsed->originNodeId);
        $this->assertSame('ws_group', $parsed->signal->signalType->getType());
        $this->assertSame('room_renamed', $parsed->signal->signalName->getName());
        $targeting = $parsed->signal->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $targeting);
        $this->assertSame('room-7', $targeting->targetGroup);
        $this->assertSame('ak-9', $targeting->excludeAcceptKey);
    }

    /**
     * The origin is what a dropped fan-out is logged with, and the only name in the frame: a
     * fan-out that cannot say where it came from is untraceable on a mesh where every node
     * received the same one.
     */
    public function testAClientFanoutRejectsAMissingOriginNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer client fanout is missing the origin node id');

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerClientFanoutDTO::MESSAGE_TYPE,
            PeerClientFanoutDTO::FIELD_SIGNAL => $this->fanoutSignal()->toArray(),
        ]));
    }

    public function testAClientFanoutRejectsAMissingInnerSignal(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer client fanout is missing the inner signal payload');

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerClientFanoutDTO::MESSAGE_TYPE,
            PeerClientFanoutDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
        ]));
    }

    /**
     * Builds the application signal the forward frames carry.
     *
     * @return SignalDTO One signal an agent would answer a browser with
     */
    private function innerSignal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType('ws_user'),
            new SignalName('room_renamed'),
            new SignalData(['room' => 'Ada']),
        );
    }

    /**
     * Builds the fan-out an agent raises for a group, targeting metadata and all.
     *
     * @return SignalDTO One group fan-out with the two fields the receiving node resolves by
     */
    private function fanoutSignal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType('ws_group'),
            new SignalName('room_renamed'),
            new WebSocketSignalData(
                data: new SignalData(['room' => 'Ada']),
                targetGroup: 'room-7',
                excludeAcceptKey: 'ak-9',
            ),
        );
    }
}
