<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\Environment\Exception\EnvException;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;

/**
 * Outbound peer port the {@see ClusterProtectedMode} orchestration sends freeze frames through.
 *
 * It hides the {@see PeerServer} behind the sends the freeze needs and the two
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
     * @throws EnvException When the cluster-enabled flag value is invalid
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

    /**
     * Forwards this initiator node's request to open the verification window to the leader.
     *
     * @param string $leaderNodeId Node id of the current leader
     */
    public function sendVerify(string $leaderNodeId): void;

    /**
     * Broadcasts the verification window to every follower master.
     *
     * The phase has to reach every node, because a verifier may land on any of them and each node
     * decides admission against its own copy of the row.
     */
    public function broadcastVerify(): void;

    /**
     * Forwards this initiator node's progress mark to the leader.
     *
     * The one freeze frame with no broadcast beside it: the mark exists to be read by the
     * watchdog, and the watchdog runs on the leader alone.
     *
     * @param string $leaderNodeId Node id of the current leader
     */
    public function sendProgress(string $leaderNodeId): void;

    /**
     * Forwards this initiator node's minted pass to the leader.
     *
     * @param string $leaderNodeId Node id of the current leader
     * @param string $passHash SHA-256 of the minted pass
     */
    public function sendPass(string $leaderNodeId, string $passHash): void;

    /**
     * Broadcasts one minted pass to every follower master.
     *
     * @param string $passHash SHA-256 of the minted pass
     */
    public function broadcastPass(string $passHash): void;

    /**
     * Forwards this initiator node's request to close back out of the window to the leader.
     *
     * @param string $leaderNodeId Node id of the current leader
     */
    public function sendRefreeze(string $leaderNodeId): void;

    /**
     * Broadcasts the close-back to every follower master.
     */
    public function broadcastRefreeze(): void;
}
