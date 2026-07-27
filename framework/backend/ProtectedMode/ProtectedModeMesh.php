<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;

/**
 * Outbound peer port the {@see ClusterProtectedMode} orchestration sends freeze frames through.
 *
 * It hides the {@see \Hilos\Cluster\Peer\PeerServer} behind the four sends the two-phase freeze
 * needs and the one roster read the leader waits on, so the coordinator stays pure logic and is
 * unit-testable with a fake. The leader broadcasts quiesce and lift to its followers and signals
 * ready to the initiator; a follower reports quiesced back to the leader. The concrete port is
 * wired at daemon start by the leader-orchestration slice.
 */
interface ProtectedModeMesh
{
    /**
     * @return array<string> Online master node ids other than self — the followers the leader
     *                       broadcasts quiesce to and awaits a quiesced report from
     */
    public function followerMasterNodeIds(): array;

    /**
     * Broadcasts the freeze order to every follower master.
     *
     * @param ProtectedModeQuiesceData $data Operation and initiator identity the freeze protects
     */
    public function broadcastQuiesce(ProtectedModeQuiesceData $data): void;

    /**
     * Signals the initiator that every node has quiesced and its operation may proceed.
     *
     * @param string $initiatorNodeId Node id that hosts the initiator agent
     */
    public function sendReady(string $initiatorNodeId): void;

    /**
     * Broadcasts the release order to every follower master.
     */
    public function broadcastLift(): void;

    /**
     * Reports this follower's quiesced state back to the leader that ordered the freeze.
     *
     * @param string $leaderNodeId Node id of the leader that ordered the freeze
     */
    public function sendQuiesced(string $leaderNodeId): void;
}
