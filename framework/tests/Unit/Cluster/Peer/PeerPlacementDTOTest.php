<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Placement\PlacementState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent-placement peer frames (HIL-179).
 *
 * Each frame round-trips through the shared {@see PeerDTO::fromWire()} dispatch so a
 * frame serialized on one node parses back to the same concrete DTO on another.
 */
final class PeerPlacementDTOTest extends TestCase
{
    public function testProtocolVersionBumpedForPlacementFrames(): void
    {
        // The placement frames extend the peer channel, so the version moved to at least 3.
        $this->assertGreaterThanOrEqual(3, PeerProtocol::VERSION);
    }

    public function testPlaceAgentFrameRoundTrips(): void
    {
        $frame = new PeerPlaceAgentDTO('chat', '42');
        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $parsed);
        $this->assertSame('chat', $parsed->agentType);
        $this->assertSame('42', $parsed->agentIndex);
    }

    public function testPlaceAgentFrameKeepsANullIndexForSingletons(): void
    {
        $parsed = PeerDTO::fromWire(new PeerPlaceAgentDTO('moderator', null)->toJson());

        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $parsed);
        $this->assertNull($parsed->agentIndex);
    }

    public function testPlaceAgentFrameRejectsAMissingType(): void
    {
        $this->expectException(PeerTransportException::class);
        PeerPlaceAgentDTO::fromArray([PeerPlaceAgentDTO::TYPE => PeerPlaceAgentDTO::MESSAGE_TYPE]);
    }

    public function testStopAgentFrameRoundTrips(): void
    {
        $parsed = PeerDTO::fromWire(new PeerStopAgentDTO('bot', 'b1')->toJson());

        $this->assertInstanceOf(PeerStopAgentDTO::class, $parsed);
        $this->assertSame('bot', $parsed->agentType);
        $this->assertSame('b1', $parsed->agentIndex);
    }

    public function testStartedStatusRoundTripsWithWorkerId(): void
    {
        $parsed = PeerDTO::fromWire(PeerAgentStatusDTO::started('chat', '7', -3)->toJson());

        $this->assertInstanceOf(PeerAgentStatusDTO::class, $parsed);
        $this->assertSame(PlacementState::Started, $parsed->state);
        $this->assertSame(-3, $parsed->workerId);
        $this->assertNull($parsed->error);
    }

    public function testFailedStatusRoundTripsWithReason(): void
    {
        $parsed = PeerDTO::fromWire(PeerAgentStatusDTO::failed('chat', null, 'no worker')->toJson());

        $this->assertInstanceOf(PeerAgentStatusDTO::class, $parsed);
        $this->assertSame(PlacementState::Failed, $parsed->state);
        $this->assertNull($parsed->workerId);
        $this->assertSame('no worker', $parsed->error);
    }

    public function testAgentStatusRejectsAnInvalidState(): void
    {
        $this->expectException(PeerTransportException::class);
        PeerAgentStatusDTO::fromArray([
            PeerAgentStatusDTO::TYPE => PeerAgentStatusDTO::MESSAGE_TYPE,
            PeerAgentStatusDTO::FIELD_AGENT_TYPE => 'chat',
            PeerAgentStatusDTO::FIELD_STATE => 'exploded',
        ]);
    }

    public function testPlacementReportRoundTripsEveryHostedAgent(): void
    {
        $frame = new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('chat', '1'),
            new PeerPlacedAgentEntry('moderator', null),
        ]);
        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerPlacementReportDTO::class, $parsed);
        $this->assertCount(2, $parsed->agents);
        $this->assertSame('chat', $parsed->agents[0]->agentType);
        $this->assertSame('1', $parsed->agents[0]->agentIndex);
        $this->assertNull($parsed->agents[1]->agentIndex);
    }

    public function testPlacementQueryRoundTrips(): void
    {
        $parsed = PeerDTO::fromWire(new PeerPlacementQueryDTO()->toJson());

        $this->assertInstanceOf(PeerPlacementQueryDTO::class, $parsed);
    }
}
