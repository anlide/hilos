<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\Consensus\ConsensusMesh;
use Hilos\Cluster\Peer\DTO\PeerDTO;

/**
 * Outbound port the placement coordinator uses to reach nodes and read their
 * advertised capabilities.
 *
 * This is the seam between the pure placement logic and the peer transport, mirroring
 * {@see ConsensusMesh}: the coordinator never touches sockets
 * or the registry directly, it asks the mesh for a node's capabilities and hands it
 * frames to deliver. Unlike the consensus mesh — which only reaches the master set —
 * placement targets any node (a data-plane slave hosts placed agents), so delivery is
 * addressed by node id. The peer server implements it over its live links and the
 * membership registry; a test supplies a fake so the coordinator runs in isolation.
 */
interface PlacementMesh
{
    /**
     * Delivers a placement frame to one node by id, if a link to it exists.
     *
     * @param string $nodeId Target node id
     * @param PeerDTO $frame Placement frame to send
     * @return bool True when a link carried the frame, false when the node is unlinked
     */
    public function sendToNode(string $nodeId, PeerDTO $frame): bool;

    /**
     * Delivers a placement frame to every handshaked peer.
     *
     * Used for the rebuild query a fresh leader broadcasts to re-learn which node hosts
     * which placed agent.
     *
     * @param PeerDTO $frame Placement frame to broadcast
     */
    public function broadcastToNodes(PeerDTO $frame): void;

    /**
     * Returns the advertised capability tags of an online node, or null when the node
     * is unknown or offline.
     *
     * Backs the capability hard-constraint: the leader reads the target node's
     * advertised capabilities from the membership registry and refuses a placement whose
     * required tags are not all present.
     *
     * @param string $nodeId Node id to look up
     * @return ?list<string> Advertised capability tags, or null when unknown or offline
     */
    public function nodeCapabilities(string $nodeId): ?array;

    /**
     * Returns the ids of every currently-online node, including the local node.
     *
     * Backs failover node-selection: the leader scans the online set for a capable host to
     * re-place an orphaned agent onto. Ordering is stable so the pick is deterministic; the
     * soft-preference policy among capable nodes is HIL-182.
     *
     * @return list<string> Online node ids
     */
    public function onlineNodeIds(): array;
}
