<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Cluster\Peer\PeerProtocol;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the consensus frames and the protocol bump (HIL-339).
 */
final class PeerConsensusDTOTest extends TestCase
{
    public function testProtocolVersionBumpedForConsensusFrames(): void
    {
        // The consensus frames extend the peer channel, so the version moved to 2.
        $this->assertSame(2, PeerProtocol::VERSION);
    }

    public function testRequestVoteRoundTripsThroughTheWire(): void
    {
        $frame = new PeerRequestVoteDTO(7, 'node-a');

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerRequestVoteDTO::class, $parsed);
        $this->assertSame(7, $parsed->term);
        $this->assertSame('node-a', $parsed->candidateId);
    }

    public function testVoteReplyRoundTripsThroughTheWire(): void
    {
        $frame = new PeerVoteReplyDTO(7, true, 'node-b');

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerVoteReplyDTO::class, $parsed);
        $this->assertSame(7, $parsed->term);
        $this->assertTrue($parsed->voteGranted);
        $this->assertSame('node-b', $parsed->voterId);
    }

    public function testVoteReplyPreservesADeniedVote(): void
    {
        $parsed = PeerDTO::fromWire((new PeerVoteReplyDTO(3, false, 'node-c'))->toJson());

        $this->assertInstanceOf(PeerVoteReplyDTO::class, $parsed);
        $this->assertFalse($parsed->voteGranted);
    }

    public function testHeartbeatRoundTripsThroughTheWire(): void
    {
        $frame = new PeerHeartbeatDTO(9, 'node-a');

        $parsed = PeerDTO::fromWire($frame->toJson());

        $this->assertInstanceOf(PeerHeartbeatDTO::class, $parsed);
        $this->assertSame(9, $parsed->term);
        $this->assertSame('node-a', $parsed->leaderId);
    }

    public function testRequestVoteRejectsMissingCandidateId(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerDTO::fromWire('{"type":"peer_request_vote","term":1}');
    }

    public function testVoteReplyRejectsMissingVoterId(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerDTO::fromWire('{"type":"peer_vote_reply","term":1,"voteGranted":true}');
    }

    public function testHeartbeatRejectsMissingLeaderId(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerDTO::fromWire('{"type":"peer_heartbeat","term":1}');
    }
}
