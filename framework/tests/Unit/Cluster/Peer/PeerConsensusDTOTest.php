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
        // The consensus frames extended the peer channel to version 2; later frame
        // additions (e.g. placement, HIL-179) move it further, so it is at least 2.
        $this->assertGreaterThanOrEqual(2, PeerProtocol::VERSION);
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
        $parsed = PeerDTO::fromWire(new PeerVoteReplyDTO(3, false, 'node-c')->toJson());

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

    public function testHeartbeatRejectsAFrameWithoutItsTerm(): void
    {
        // Term 0 is the term before any election: read into a missing field, it
        // asserts a leader of a term no node in the set is in.
        $this->expectException(PeerTransportException::class);

        PeerDTO::fromWire('{"type":"peer_heartbeat","leaderId":"node-a"}');
    }

    public function testRequestVoteRejectsAFrameWithoutItsTerm(): void
    {
        $this->expectException(PeerTransportException::class);

        PeerDTO::fromWire('{"type":"peer_request_vote","candidateId":"node-a"}');
    }

    public function testVoteReplyRejectsAFrameWithoutItsGrant(): void
    {
        // The grant is what the frame is for, and a missing one used to arrive as a
        // refusal: a vote that was given would be counted as one that was withheld.
        $this->expectException(PeerTransportException::class);

        PeerDTO::fromWire('{"type":"peer_vote_reply","term":1,"voterId":"node-a"}');
    }

    public function testVoteReplyKeepsADeniedVoteApartFromAMissingOne(): void
    {
        $reply = PeerDTO::fromWire('{"type":"peer_vote_reply","term":1,"voteGranted":false,"voterId":"node-a"}');

        $this->assertInstanceOf(PeerVoteReplyDTO::class, $reply);
        $this->assertFalse($reply->voteGranted);
    }
}
