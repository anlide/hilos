<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;

/**
 * Outbound peer port the {@see ClusterProtectedMode} orchestration sends freeze frames through.
 *
 * It hides the {@see \Hilos\Cluster\Peer\PeerServer} behind the sends the freeze needs and the two
 * roster reads the coordinator relies on, so the coordinator stays pure logic and is unit-testable
 * with a fake. An initiator that does not lead forwards enable and disable to the current leader
 * (addressed via {@see leaderNodeId()}); the leader broadcasts quiesce and lift to its followers
 * and signals ready to the initiator; a follower reports quiesced back to the leader. The concrete
 * port is wired at daemon start by the leader-orchestration slice.
 */
interface ProtectedModeMesh
{
    /**
     * @return array<string> Online master node ids other than self — the followers the leader
     *                       broadcasts quiesce to and awaits a quiesced report from
     */
    public function followerMasterNodeIds(): array;

    /**
     * @return ?string Node id of the current leader an initiator addresses its request to, or null
     *                 when leadership is unknown
     */
    public function leaderNodeId(): ?string;

    /**
     * Forwards this initiator node's freeze request to the leader.
     *
     * @param string $leaderNodeId Node id of the current leader
     * @param ProtectedModeEnableSignalData $data Initiator identity and the operation the freeze protects
     */
    public function sendEnable(string $leaderNodeId, ProtectedModeEnableSignalData $data): void;

    /**
     * Forwards this initiator node's release request to the leader.
     *
     * @param string $leaderNodeId Node id of the current leader
     */
    public function sendDisable(string $leaderNodeId): void;

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
