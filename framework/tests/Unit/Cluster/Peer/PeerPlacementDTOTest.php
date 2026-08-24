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
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
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

    /**
     * The view is the report turned around — leader to node instead of node to leader — so what
     * has to survive the wire is the one thing a report leaves implicit: which node each agent
     * is on (HIL-668). Grouping by node is what keeps the entries themselves unchanged.
     */
    public function testPlacementViewRoundTripsEveryNodesAgents(): void
    {
        $frame = new PeerPlacementViewDTO('leader', [
            'node-b' => [new PeerPlacedAgentEntry('chat', '1')],
            'node-c' => [new PeerPlacedAgentEntry('moderator', null), new PeerPlacedAgentEntry('render', '9')],
        ]);
        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerPlacementViewDTO::class, $parsed);
        $this->assertSame('leader', $parsed->leaderNodeId);
        $this->assertSame(['node-b', 'node-c'], array_keys($parsed->agents));
        $this->assertSame('chat', $parsed->agents['node-b'][0]->agentType);
        $this->assertNull($parsed->agents['node-c'][0]->agentIndex);
        $this->assertSame('9', $parsed->agents['node-c'][1]->agentIndex);
    }

    /**
     * A cluster running nothing is a real state, and its view is an empty map rather than a
     * missing one: the receiver has to end up holding nothing, not holding what it had.
     */
    public function testAPlacementViewOfNothingRoundTrips(): void
    {
        $parsed = PeerDTO::fromWire(new PeerPlacementViewDTO('leader', [])->toJson());

        $this->assertInstanceOf(PeerPlacementViewDTO::class, $parsed);
        $this->assertSame([], $parsed->agents);
    }

    /**
     * Refused whole rather than thinned, because this frame REPLACES the receiver's picture of
     * the cluster: an entry read as blank would index its agents under a node nothing can
     * reach, and the picture that was true is gone.
     */
    public function testAPlacementViewRejectsABlankNodeId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer placement view carries a malformed node entry');

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerPlacementViewDTO::MESSAGE_TYPE,
            PeerPlacementViewDTO::FIELD_LEADER_NODE_ID => 'leader',
            PeerPlacementViewDTO::FIELD_AGENTS => ['' => [['agentType' => 'chat', 'agentIndex' => '1']]],
        ]));
    }

    /**
     * A cluster whose nodes are named "1", "2", "3" is an ordinary cluster — CLUSTER_NODE_ID is
     * any non-blank string — and its view groups under int keys, because PHP has no string
     * array key that reads as a decimal integer. Refusing those would reject every view such a
     * cluster ever sends, silently, leaving its followers exactly as blind as this frame exists
     * to stop them being.
     */
    public function testAPlacementViewCarriesNodeIdsThatReadAsNumbers(): void
    {
        $frame = new PeerPlacementViewDTO('1', ['2' => [new PeerPlacedAgentEntry('chat', '7')]]);
        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerPlacementViewDTO::class, $parsed);
        $this->assertSame('1', $parsed->leaderNodeId);
        $this->assertSame('chat', $parsed->agents['2'][0]->agentType);
    }

    public function testAPlacementViewRejectsAMissingLeaderId(): void
    {
        $this->expectException(PeerTransportException::class);
        $this->expectExceptionMessage('Peer placement view is missing the leader node id');

        PeerDTO::fromWire(json_encode([
            PeerDTO::TYPE => PeerPlacementViewDTO::MESSAGE_TYPE,
            PeerPlacementViewDTO::FIELD_AGENTS => [],
        ]));
    }
}
