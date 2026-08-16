<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Peer\DTO\PeerRtSyncDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\HilosException;

/**
 * Local port between the peer transport and the daemon's runtime state.
 *
 * Mostly the receiving half of cross-node RT replication: when a peer broadcasts a
 * {@see PeerRtSyncDTO}, the transport passes it through this seam to the daemon master, which
 * applies it to the read-only copy this node holds and fans it out to its own workers. The
 * seam exists for the same reason {@see AgentSignalSink} does — the transport must not reach
 * into the daemon, and a test supplies a fake so the transport runs without one.
 *
 * It carries one cue the other way, {@see handOverRtSnapshots()}, because only the transport
 * knows when a node became reachable and only the daemon knows what to send it.
 *
 * Replication is one-way and one hop: what arrives here is applied and never announced on.
 */
interface RtSyncSink
{
    /**
     * Applies one RT sync fact written on another node to the copy this node holds.
     *
     * @param string $originNodeId Id of the node the write happened on
     * @param string $signalType RT sync signal type the frame carried
     * @param SignalDTO $signal RT sync signal to apply and fan out locally
     * @throws HilosException When a collection refuses to be re-read from the replaced database
     */
    public function applyRemoteRtSync(string $originNodeId, string $signalType, SignalDTO $signal): void;

    /**
     * Replaces this node's copy of one RT collection with the one its owner handed over.
     *
     * Replacement rather than merge: the owner's copy is the whole truth about the collection,
     * so a row the snapshot does not carry is a row that no longer exists.
     *
     * @param string $originNodeId Id of the node that owns the collection
     * @param string $collectionKey RT collection being replaced
     * @param array<string, array<string, mixed>> $rows Rows by state id, as the owner holds them
     * @throws HilosException Whatever a subscriber to the collection's announcement raises
     */
    public function applyRemoteRtSnapshot(string $originNodeId, string $collectionKey, array $rows): void;

    /**
     * Hands every RT collection this node owns to a node the mesh has just linked to.
     *
     * Called once per completed handshake, from the side that completed it, because that is the
     * first moment a frame can actually reach the other node. Membership is the wrong cue: a
     * node is a member as soon as a peer mentions it, which on a mesh of three is well before
     * this node has any link to it - the hand-over would be sent into nothing, and the handshake
     * that follows changes no membership and would never ask again.
     *
     * @param string $nodeId Node this one can now reach
     */
    public function handOverRtSnapshots(string $nodeId): void;
}
