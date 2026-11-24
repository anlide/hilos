<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the signal-forward peer frame (HIL-180).
 *
 * The frame round-trips the resolved target and the inner application signal over the wire
 * and is recognised by the peer-frame parser; a malformed frame is rejected as a transport
 * error rather than propagating out of the daemon loop.
 */
final class PeerSignalDTOTest extends TestCase
{
    public function testRoundTripsThroughTheWire(): void
    {
        $frame = new PeerSignalDTO(
            originNodeId: 'node-A',
            targetNodeId: 'node-B',
            agentType: 'chat_agent',
            agentIndex: '42',
            signal: $this->signal(),
        );

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerSignalDTO::class, $parsed);
        $this->assertSame(PeerSignalDTO::MESSAGE_TYPE, $parsed->getType());
        $this->assertSame('node-A', $parsed->originNodeId);
        $this->assertSame('node-B', $parsed->targetNodeId);
        $this->assertSame('chat_agent', $parsed->agentType);
        $this->assertSame('42', $parsed->agentIndex);
        $this->assertSame(SignalSource::AGENT, $parsed->signal->signalSource->getSource());
        $this->assertSame('chat_message', $parsed->signal->signalName->getName());
        $this->assertSame(['text' => 'hi'], $parsed->signal->data->toArray());
    }

    public function testAForwardedWebSocketActionPreservesNumericTypes(): void
    {
        $action = new WebSocketActionSignalDTO(
            acceptKey: 'browser-key',
            action: 'project_action',
            data: ['integer' => 1, 'wholeFloat' => -1.0, 'fractionalFloat' => 1.25],
            requestId: 'request-1',
        );
        $frame = new PeerSignalDTO('node-A', 'node-B', 'project_agent', '42', new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::ACTION),
            new SignalName($action->action),
            $action,
        ));

        $parsed = PeerDTO::fromWire($frame->toJson());

        self::assertInstanceOf(PeerSignalDTO::class, $parsed);
        self::assertInstanceOf(WebSocketActionSignalDTO::class, $parsed->signal->data);
        self::assertSame($frame->toArray(), $parsed->toArray());
    }

    public function testPlacementRecoveryRelayIsOptionalAndRoundTripsOnlyWhenTrue(): void
    {
        $frame = new PeerSignalDTO('node-A', 'node-B', 'chat_agent', '42', $this->signal());
        $wire = $frame->toArray();
        $this->assertArrayNotHasKey('placementRecoveryRelay', $wire);
        $this->assertArrayNotHasKey('placementRecoveryRelay', PeerSignalDTO::fromArray($wire + ['placementRecoveryRelay' => false])->toArray());

        $relay = PeerSignalDTO::fromArray($wire + ['placementRecoveryRelay' => true]);

        $this->assertSame($wire + ['placementRecoveryRelay' => true], PeerDTO::fromWire($relay->toJson())->toArray());
    }

    #[DataProvider('invalidPlacementRecoveryRelays')]
    public function testRejectsNonBooleanPlacementRecoveryRelay(mixed $value): void
    {
        $frame = new PeerSignalDTO('node-A', 'node-B', 'chat_agent', '42', $this->signal());
        $this->expectException(PeerTransportException::class);

        PeerSignalDTO::fromArray($frame->toArray() + ['placementRecoveryRelay' => $value]);
    }

    public static function invalidPlacementRecoveryRelays(): iterable
    {
        yield 'explicit null' => [null];
        yield 'integer' => [1];
        yield 'string' => ['true'];
        yield 'array' => [[]];
    }

    public function testPreservesNullAgentIndex(): void
    {
        $frame = new PeerSignalDTO('node-A', 'node-B', 'singleton_agent', null, $this->signal());

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerSignalDTO::class, $parsed);
        $this->assertNull($parsed->agentIndex);
    }

    public function testRejectsMissingTargetNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer signal is missing the target node id');

        PeerSignalDTO::fromArray([
            PeerSignalDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerSignalDTO::FIELD_AGENT_TYPE => 'chat_agent',
            PeerSignalDTO::FIELD_SIGNAL => $this->signal()->toArray(),
        ]);
    }

    public function testRejectsMissingOriginNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer signal is missing the origin node id');

        PeerSignalDTO::fromArray([
            PeerSignalDTO::FIELD_TARGET_NODE_ID => 'node-B',
            PeerSignalDTO::FIELD_AGENT_TYPE => 'chat_agent',
            PeerSignalDTO::FIELD_SIGNAL => $this->signal()->toArray(),
        ]);
    }

    public function testRejectsMissingInnerSignal(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer signal is missing the inner signal payload');

        PeerSignalDTO::fromArray([
            PeerSignalDTO::FIELD_ORIGIN_NODE_ID => 'node-A',
            PeerSignalDTO::FIELD_TARGET_NODE_ID => 'node-B',
            PeerSignalDTO::FIELD_AGENT_TYPE => 'chat_agent',
        ]);
    }

    /**
     * Builds a minimal application signal to carry inside the frame.
     */
    private function signal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType('agent_signal'),
            new SignalName('chat_message'),
            new SignalData(['text' => 'hi']),
        );
    }
}
