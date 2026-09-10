<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\TruthSource\RtReplicaOriginMap;
use Hilos\Core\Router\DTO\SignalDTO;

/**
 * Outbound peer port the daemon announces its own RT writes through.
 *
 * The mirror of {@see RtSyncSink}: that one carries a replica in, this one carries a local
 * fact out. It hides the {@see PeerServer} behind the single send replication needs, for the
 * reason every mesh port in this framework exists — the announcing side stays logic a test
 * can drive with a fake instead of a listener and a live link.
 *
 * There is no addressed form and there will not be one: a fact about an RT collection is
 * either everybody's or nobody's, however many nodes hold a piece of the right to write it.
 */
interface RtSyncMesh
{
    /**
     * Announces one RT sync fact written on this node to every other node of the mesh.
     *
     * @param string $signalType RT sync signal type being announced
     * @param SignalDTO $signal RT sync signal the other nodes apply
     * @param bool $partialOwner Whether this node holds only part of the right over the collection
     */
    public function broadcastRtSync(string $signalType, SignalDTO $signal, bool $partialOwner = false): void;

    /**
     * Names the nodes this one can hand something to right now.
     *
     * Membership is the wrong answer here and the transport knows the right one: a node is a
     * member from the moment a peer mentions it, which on a mesh of three is well before there
     * is a link to it, and a frame sent then reaches nothing.
     *
     * @return list<string> Node ids behind a handshaked link, each named once
     */
    public function linkedNodeIds(): array;

    /**
     * Hands one RT collection this node owns, or the rows of it it owns, to a node that joined.
     *
     * Addressed, unlike the announcement above: a node that has just come up is the only one
     * behind on the collection, and the others hold a copy the deltas have kept current.
     *
     * @param string $nodeId Node that joined
     * @param string $collectionKey RT collection this node owns
     * @param array<string, array<string, mixed>> $rows Rows by state id, as this node holds them
     * @param list<string> $scopeKeys Rows this node speaks for; empty when it owns the collection
     */
    public function sendRtSnapshotToNode(
        string $nodeId,
        string $collectionKey,
        array $rows,
        array $scopeKeys = [],
    ): void;

    /**
     * Asks one node for the collections this one holds nothing of.
     *
     * The other direction of the hand-over above, and the reason there is one: the owner-driven
     * path cannot cover the window in which a collection is claimed by nobody, so a node that
     * comes back to a mesh whose worker fleet is being replaced is answered with silence by
     * neighbours that hold every row of it. Sent on a completed handshake, addressed to the peer
     * that handshaked.
     *
     * @param string $nodeId Node being asked
     * @param list<string> $collectionKeys RT collections this node holds nothing of, each named once
     */
    public function sendRtSnapshotQueryToNode(string $nodeId, array $collectionKeys): void;

    /**
     * Asks every linked node for the collections this one holds nothing of.
     *
     * The broadcast form, sent when this node's own reader interest changes — the other moment
     * at which the answer could have become a different one. It reaches every linked node rather
     * than the readers of each collection: a holder need not read what it holds, an owner that
     * writes a collection without reading it being the ordinary case, and the precise filter
     * would drop exactly the node worth asking.
     *
     * @param list<string> $collectionKeys RT collections this node holds nothing of, each named once
     */
    public function broadcastRtSnapshotQuery(array $collectionKeys): void;

    /**
     * Offers a node the rows of a collection it asked for, as written by one node.
     *
     * The answer to the two asks above, and the one send of this port whose caller need not own
     * what it sends: that right is opened by the request and by nothing else. One call per node
     * that wrote the rows, because the origin is what replica staleness is marked by. Rows this
     * node wrote itself name no origin and are signed by the transport, which is the only layer
     * that knows the local id — the same answer {@see RtReplicaOriginMap::nodeOfRow()} gives.
     *
     * @param string $nodeId Node that asked for the collection
     * @param ?string $originNodeId Node that wrote these rows, or null when this node wrote them
     * @param string $collectionKey RT collection the rows belong to
     * @param array<string, array<string, mixed>> $rows Rows by state id, as this node holds them
     */
    public function sendRtReplicaOfferToNode(
        string $nodeId,
        ?string $originNodeId,
        string $collectionKey,
        array $rows,
    ): void;
}
