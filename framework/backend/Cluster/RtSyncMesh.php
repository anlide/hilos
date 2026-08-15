<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\Core\Router\DTO\SignalDTO;

/**
 * Outbound peer port the daemon announces its own RT writes through.
 *
 * The mirror of {@see RtSyncSink}: that one carries a replica in, this one carries a local
 * fact out. It hides the {@see PeerServer} behind the single send replication needs, for the
 * reason every mesh port in this framework exists — the announcing side stays logic a test
 * can drive with a fake instead of a listener and a live link.
 *
 * There is no addressed form and there will not be one: an RT collection has exactly one
 * truth source in the cluster, so a fact is either everybody's or nobody's.
 */
interface RtSyncMesh
{
    /**
     * Announces one RT sync fact written on this node to every other node of the mesh.
     *
     * @param string $signalType RT sync signal type being announced
     * @param SignalDTO $signal RT sync signal the other nodes apply
     */
    public function broadcastRtSync(string $signalType, SignalDTO $signal): void;

    /**
     * Hands one whole RT collection this node owns to a node that just joined.
     *
     * Addressed, unlike the announcement above: a node that has just come up is the only one
     * behind on the collection, and the others hold a copy the deltas have kept current.
     *
     * @param string $nodeId Node that joined
     * @param string $collectionKey RT collection this node owns
     * @param array<string, array<string, mixed>> $rows Rows by state id, as this node holds them
     */
    public function sendRtSnapshotToNode(string $nodeId, string $collectionKey, array $rows): void;
}
