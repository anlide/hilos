<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
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

        PeerSignalDTO::fromArray([
            PeerSignalDTO::FIELD_AGENT_TYPE => 'chat_agent',
            PeerSignalDTO::FIELD_SIGNAL => $this->signal()->toArray(),
        ]);
    }

    public function testRejectsMissingInnerSignal(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerSignalDTO::fromArray([
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
