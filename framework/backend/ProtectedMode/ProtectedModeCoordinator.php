<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;

/**
 * Node-local handler for the protected-mode frames the peer transport delivers.
 *
 * The initiator↔leader hand-off rides the peer channel, not the agent-signal fabric — a
 * worker-sent agent signal only ever lands on another worker, never on the leader daemon. The
 * {@see PeerServer} unwraps each arriving envelope and calls the method for
 * its kind here, so this seam receives the domain payload and never the wire frame: enable carries
 * the {@see ProtectedModeEnableSignalData} contract fields, while ready and disable are bare
 * signals (the frame itself is the whole message) and carry only the originating node id.
 *
 * The initiator↔leader half (enable/ready/disable) is mirrored by the cluster-wide half the leader
 * drives against its followers: quiesce carries the {@see ProtectedModeQuiesceData} freeze
 * descriptor, quiesced is the follower's bare readiness report, and lift is the bare release.
 *
 * The transport slice wires the routing to this interface; the leader orchestration slice supplies
 * the implementation and registers it with {@see PeerServer::registerProtectedMode()}.
 * Until then the seam stays null and the frames route to a no-op, exactly like the placement seam
 * before a worker executor is registered.
 */
interface ProtectedModeCoordinator
{
    /**
     * Handles an initiator's request to freeze the cluster for a destructive operation.
     *
     * Arrives on the leader; the leader records the carried fields and drives the two-phase freeze.
     *
     * @param string $fromNodeId Node id of the initiator that sent the request
     * @param ProtectedModeEnableSignalData $data Initiator identity and the operation the freeze protects
     */
    public function onEnable(string $fromNodeId, ProtectedModeEnableSignalData $data): void;

    /**
     * Handles the leader's confirmation that every node has quiesced.
     *
     * Arrives on the initiator; the destructive operation may now proceed.
     *
     * @param string $fromNodeId Node id of the leader that confirmed the freeze
     */
    public function onReady(string $fromNodeId): void;

    /**
     * Handles an initiator's request to lift the freeze once its operation has finished.
     *
     * Arrives on the leader; the leader drives the cluster-wide release.
     *
     * @param string $fromNodeId Node id of the initiator that released the freeze
     */
    public function onDisable(string $fromNodeId): void;

    /**
     * Handles the leader's order to freeze this node for a destructive operation.
     *
     * Arrives on a follower; the follower quiesces its own agents (leaving the initiator agent
     * named in the descriptor running), writes the freeze locally, and reports back quiesced.
     *
     * @param string $fromNodeId Node id of the leader that ordered the freeze
     * @param ProtectedModeQuiesceData $data Operation and initiator identity the freeze protects
     */
    public function onQuiesce(string $fromNodeId, ProtectedModeQuiesceData $data): void;

    /**
     * Handles a follower's confirmation that it has quiesced.
     *
     * Arrives on the leader; the leader activates the mode once every follower has reported.
     *
     * @param string $fromNodeId Node id of the follower that quiesced
     */
    public function onQuiesced(string $fromNodeId): void;

    /**
     * Handles the leader's order to lift the freeze on this node.
     *
     * Arrives on a follower; the follower clears its local freeze and resumes normal operation.
     *
     * @param string $fromNodeId Node id of the leader that lifted the freeze
     */
    public function onLift(string $fromNodeId): void;
}
