<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHandshakeDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Cluster\Peer\PeerAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for peer handshake frame serialization and wire parsing (HIL-178), the markers
 * included (HIL-1206).
 */
final class PeerHandshakeDTOTest extends TestCase
{
    public function testHelloRoundTripsThroughTheWire(): void
    {
        $hello = new PeerHelloDTO(1, 'node-a', NodeRole::Master, ['gpu-local', 'ssd'], PeerTestMarkers::onWire());

        $parsed = PeerDTO::fromWire($hello->toJson());

        $this->assertInstanceOf(PeerHelloDTO::class, $parsed);
        $this->assertSame(PeerHelloDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertSame(1, $parsed->protocolVersion);
        $this->assertSame('node-a', $parsed->nodeId);
        $this->assertSame(NodeRole::Master, $parsed->role);
        $this->assertSame(['gpu-local', 'ssd'], $parsed->capabilities);
        $this->assertSame(PeerTestMarkers::onWire(), $parsed->markers);
    }

    public function testWelcomeRoundTripsThroughTheWire(): void
    {
        // A node that carries no marker says so with an empty set, and the empty set survives the wire.
        $welcome = new PeerWelcomeDTO(1, 'node-b', NodeRole::Slave, [], []);

        $parsed = PeerDTO::fromWire($welcome->toJson());

        $this->assertInstanceOf(PeerWelcomeDTO::class, $parsed);
        $this->assertSame(PeerWelcomeDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertSame('node-b', $parsed->nodeId);
        $this->assertSame(NodeRole::Slave, $parsed->role);
        $this->assertSame([], $parsed->capabilities);
        $this->assertSame([], $parsed->markers);
    }

    public function testFromWireRejectsUnknownType(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerDTO::fromWire('{"type":"peer_nope","nodeId":"x","role":"master"}');
    }

    public function testFromWireRejectsNonJson(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerDTO::fromWire('not json');
    }

    public function testFromArrayRejectsMissingNodeId(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerHelloDTO::fromArray([
            PeerHandshakeDTO::FIELD_PROTOCOL_VERSION => 1,
            PeerHandshakeDTO::FIELD_NODE_ROLE => 'master',
        ]);
    }

    public function testFromArrayRejectsInvalidRole(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerHelloDTO::fromArray([
            PeerHandshakeDTO::FIELD_PROTOCOL_VERSION => 1,
            PeerHandshakeDTO::FIELD_NODE_ID => 'node-a',
            PeerHandshakeDTO::FIELD_NODE_ROLE => 'overlord',
        ]);
    }

    /**
     * A required field that arrives as something other than a string is a broken
     * payload, not a value to coerce: 42 must not read back as the node id '42'.
     *
     * @param mixed $nodeId Non-string node id from the wire
     */
    #[DataProvider('nonStringNodeIds')]
    public function testFromArrayRejectsNonStringNodeId(mixed $nodeId): void
    {
        $this->expectException(PeerTransportException::class);

        PeerHelloDTO::fromArray([
            PeerHandshakeDTO::FIELD_PROTOCOL_VERSION => 1,
            PeerHandshakeDTO::FIELD_NODE_ID => $nodeId,
            PeerHandshakeDTO::FIELD_NODE_ROLE => 'master',
        ]);
    }

    /**
     * @return array<string, array{mixed}> Node ids no producer of this frame writes
     */
    public static function nonStringNodeIds(): array
    {
        return [
            'number' => [42],
            'array' => [['node-a']],
            'bool' => [true],
        ];
    }

    public function testFromArrayRejectsNonStringRole(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerHelloDTO::fromArray([
            PeerHandshakeDTO::FIELD_PROTOCOL_VERSION => 1,
            PeerHandshakeDTO::FIELD_NODE_ID => 'node-a',
            PeerHandshakeDTO::FIELD_NODE_ROLE => ['master'],
        ]);
    }

    /**
     * An address a node does not advertise is written as null and reads back as
     * absent; that is the only shape in which the field may be missing.
     */
    public function testFromArrayReadsAnUnadvertisedAddressAsAbsent(): void
    {
        $hello = PeerHelloDTO::fromArray([
            PeerHandshakeDTO::FIELD_PROTOCOL_VERSION => 1,
            PeerHandshakeDTO::FIELD_NODE_ID => 'node-a',
            PeerHandshakeDTO::FIELD_NODE_ROLE => 'master',
            PeerHandshakeDTO::FIELD_NODE_CAPABILITIES => [],
            PeerHandshakeDTO::FIELD_ADDRESS => null,
            PeerHandshakeDTO::FIELD_MARKERS => PeerTestMarkers::onWire(),
        ]);

        $this->assertNull($hello->address);
    }

    /**
     * A non-string address is not an absent one: read as absent, it left the link
     * dialling a node whose address the frame did carry, in a shape nobody read.
     */
    public function testFromArrayRejectsNonStringAddress(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerHelloDTO::fromArray([
            PeerHandshakeDTO::FIELD_PROTOCOL_VERSION => 1,
            PeerHandshakeDTO::FIELD_NODE_ID => 'node-a',
            PeerHandshakeDTO::FIELD_NODE_ROLE => 'master',
            PeerHandshakeDTO::FIELD_NODE_CAPABILITIES => [],
            PeerHandshakeDTO::FIELD_ADDRESS => ['10.0.0.1', 8095],
        ]);
    }

    public function testFromArrayRejectsAHandshakeWithoutItsCapabilities(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerHelloDTO::fromArray([
            PeerHandshakeDTO::FIELD_PROTOCOL_VERSION => 1,
            PeerHandshakeDTO::FIELD_NODE_ID => 'node-a',
            PeerHandshakeDTO::FIELD_NODE_ROLE => 'master',
        ]);
    }

    /**
     * The markers are required: a handshake of the previous protocol, which had no such field, is
     * a broken payload here rather than a node that carries no marker.
     */
    public function testFromArrayRejectsAHandshakeWithoutItsMarkers(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerHelloDTO::fromArray([
            PeerHandshakeDTO::FIELD_PROTOCOL_VERSION => 1,
            PeerHandshakeDTO::FIELD_NODE_ID => 'node-a',
            PeerHandshakeDTO::FIELD_NODE_ROLE => 'master',
            PeerHandshakeDTO::FIELD_NODE_CAPABILITIES => [],
        ]);
    }

    /**
     * @param array<mixed> $markers Marker set no producer of this frame writes
     */
    #[DataProvider('malformedMarkers')]
    public function testFromArrayRejectsAMalformedMarker(array $markers): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer handshake carries a malformed marker');

        PeerHelloDTO::fromArray([
            PeerHandshakeDTO::FIELD_PROTOCOL_VERSION => 1,
            PeerHandshakeDTO::FIELD_NODE_ID => 'node-a',
            PeerHandshakeDTO::FIELD_NODE_ROLE => 'master',
            PeerHandshakeDTO::FIELD_NODE_CAPABILITIES => [],
            PeerHandshakeDTO::FIELD_MARKERS => $markers,
        ]);
    }

    /**
     * @return array<string, array{array<mixed>}> Marker sets whose kind or value is not a non-empty string
     */
    public static function malformedMarkers(): array
    {
        return [
            'value not a string' => [['database' => 42]],
            'empty value' => [['database' => '']],
            'kind not a string' => [['0123456789abcdef0123456789abcdef']],
            'empty kind' => [['' => '0123456789abcdef0123456789abcdef']],
        ];
    }

    public function testHandshakeCarriesTheAdvertisedAddress(): void
    {
        $hello = new PeerHelloDTO(1, 'node-a', NodeRole::Master, [], PeerTestMarkers::onWire(), PeerAddress::fromString('10.0.0.1:8095'));

        $parsed = PeerDTO::fromWire($hello->toJson());

        $this->assertNotNull($parsed->address);
        $this->assertSame('10.0.0.1', $parsed->address->host);
        $this->assertSame(8095, $parsed->address->port);
    }

    public function testHandshakeWithoutAddressRoundTripsNull(): void
    {
        $hello = new PeerHelloDTO(1, 'node-a', NodeRole::Master, [], PeerTestMarkers::onWire());

        $parsed = PeerDTO::fromWire($hello->toJson());

        $this->assertNull($parsed->address);
    }

    public function testFromArrayNormalizesCapabilities(): void
    {
        $hello = PeerHelloDTO::fromArray([
            PeerHandshakeDTO::FIELD_PROTOCOL_VERSION => 1,
            PeerHandshakeDTO::FIELD_NODE_ID => 'node-a',
            PeerHandshakeDTO::FIELD_NODE_ROLE => 'master',
            PeerHandshakeDTO::FIELD_NODE_CAPABILITIES => ['gpu-local', '', 123, 'ssd'],
            PeerHandshakeDTO::FIELD_MARKERS => PeerTestMarkers::onWire(),
        ]);

        $this->assertSame(['gpu-local', 'ssd'], $hello->capabilities);
    }
}
