<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use PHPUnit\Framework\TestCase;

/**
 * The frame a node sends the leader to ask that an addressed agent be placed (HIL-628).
 *
 * It is the only placement frame that travels UP, from a node to the leader, and it exists
 * because an instance agent is started by being addressed while only the leader may decide where
 * it runs. Every case here goes through {@see PeerDTO::fromWire()} rather than the DTO's own
 * parser, because the point of a frame is that the shared dispatch on the receiving node
 * recognizes it — a frame that serializes perfectly and is not registered there arrives as an
 * unknown type and drops the link.
 */
final class PeerPlacementRequestFrameTest extends TestCase
{
    public function testAPlacementRequestRoundTrips(): void
    {
        $parsed = PeerDTO::fromWire(new PeerPlacementRequestDTO('chat', '42')->toJson());

        $this->assertInstanceOf(PeerPlacementRequestDTO::class, $parsed);
        $this->assertSame('chat', $parsed->agentType);
        $this->assertSame('42', $parsed->agentIndex);
    }

    /**
     * The request names an agent and nothing else — no node id — because naming a target would
     * be the asking node picking the host, which is the leader's decision.
     */
    public function testThePayloadCarriesTheAgentAndNoNode(): void
    {
        $this->assertSame(
            [
                PeerPlacementRequestDTO::TYPE,
                PeerPlacementRequestDTO::FIELD_AGENT_TYPE,
                PeerPlacementRequestDTO::FIELD_AGENT_INDEX,
            ],
            array_keys(new PeerPlacementRequestDTO('chat', '42')->toArray()),
        );
    }

    /**
     * A singleton agent has no index, and the absent one has to survive the trip as null: read
     * back as an empty string it would name a second, different agent id.
     */
    public function testANullIndexSurvivesTheTrip(): void
    {
        $parsed = PeerDTO::fromWire(new PeerPlacementRequestDTO('moderator', null)->toJson());

        $this->assertInstanceOf(PeerPlacementRequestDTO::class, $parsed);
        $this->assertNull($parsed->agentIndex);
    }

    public function testABlankIndexReadsAsNoIndex(): void
    {
        $parsed = PeerPlacementRequestDTO::fromArray([
            PeerPlacementRequestDTO::TYPE => PeerPlacementRequestDTO::MESSAGE_TYPE,
            PeerPlacementRequestDTO::FIELD_AGENT_TYPE => 'chat',
            PeerPlacementRequestDTO::FIELD_AGENT_INDEX => '  ',
        ]);

        $this->assertNull($parsed->agentIndex);
    }

    /**
     * A request naming no agent is refused whole rather than thinned, exactly as its sibling
     * placement frames are: there is nothing to place, and a blank type would ask the policy to
     * rank nodes for an agent that does not exist.
     */
    public function testARequestWithoutAnAgentTypeIsRefused(): void
    {
        $this->expectException(PeerTransportException::class);
        PeerPlacementRequestDTO::fromArray([
            PeerPlacementRequestDTO::TYPE => PeerPlacementRequestDTO::MESSAGE_TYPE,
        ]);
    }
}
