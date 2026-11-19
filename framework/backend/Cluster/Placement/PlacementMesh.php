<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Closure;
use Hilos\Cluster\Consensus\ConsensusMesh;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\PeerLink;

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
     * Backs best-fit node-selection: the leader builds the candidate set from the online nodes
     * so the {@see PlacementPolicy} can rank them, both for the automatic placement entry and
     * for re-placing an orphaned agent onto a surviving node during failover.
     *
     * @return list<string> Online node ids
     */
    public function onlineNodeIds(): array;

    /**
     * $then once every active peer route answers the RT operations taken before its wait was registered,
     * or really closes; at once when nobody is awaited. Replacement transfers unfinished ordinary writes and
     * their wait boundaries. Fully sent old operations end their answer obligations without replay,
     * and canceled snapshots end theirs without claiming delivery. Each stream captures its operation
     * revision when its wait is registered; later writes do not extend that boundary.
     * Link liveness bounds it; no timer does. A ready callback's failure follows the other ready waits
     * and cleanup. This is a barrier on answers; the order of application across the two connections is
     * held by the receiving fence ({@see PeerLink::holdIncomingBehind()}).
     *
     * @param Closure(): void $then Told the RT writes are answered
     */
    public function afterRtWritesAnswered(Closure $then): void;
}
