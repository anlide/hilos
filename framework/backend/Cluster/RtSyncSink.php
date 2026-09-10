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
     * @param bool $partialOwner Whether the origin holds only part of the right over the collection
     * @throws HilosException When a collection refuses to be re-read from the replaced database
     */
    public function applyRemoteRtSync(
        string $originNodeId,
        string $signalType,
        SignalDTO $signal,
        bool $partialOwner = false,
    ): void;

    /**
     * Replaces this node's copy of one RT collection, or of the rows named, with the owner's.
     *
     * Replacement rather than merge: the owner's copy is the whole truth about what it sent, so
     * a row the snapshot does not carry is a row that no longer exists. The scope says what "what
     * it sent" covers — the collection when it is empty, and only the named rows otherwise, with
     * everything outside them left as this node holds it.
     *
     * @param string $originNodeId Id of the node that owns the collection
     * @param string $collectionKey RT collection being replaced
     * @param array<string, array<string, mixed>> $rows Rows by state id, as the owner holds them
     * @param list<string> $scopeKeys Rows the snapshot speaks for; empty for the whole collection
     * @throws HilosException Whatever the applied write of the snapshot raises
     */
    public function applyRemoteRtSnapshot(
        string $originNodeId,
        string $collectionKey,
        array $rows,
        array $scopeKeys = [],
    ): void;

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

    /**
     * Asks a node the mesh has just linked to for the collections this one holds nothing of.
     *
     * The pair of {@see handOverRtSnapshots()}, called off the same completed handshake and for
     * the same reason — the link is the first moment a frame can reach the other node. It runs
     * after the hand-over rather than before it, because the answer is filtered by the reader
     * map the interest frame ahead of it fills in; asked first, the request would arrive at a
     * holder that has never heard this node reads anything.
     *
     * Which collections are missing is a question only the daemon can answer, so the transport
     * carries no list: it knows nothing of what this node holds.
     *
     * @param string $nodeId Node this one can now reach
     */
    public function askRtSnapshotsFromNode(string $nodeId): void;

    /**
     * Answers another node's request with the rows this one holds of the collections it named.
     *
     * The one path on which this node hands RT state to a peer without owning it, and it is the
     * request that opens the right: the asker holds nothing, so nothing of its can be overwritten
     * by what comes back. A collection this node holds no rows of is passed over in silence.
     *
     * @param string $nodeId Node that asked
     * @param list<string> $collectionKeys RT collections it asked about
     */
    public function answerRtSnapshotQuery(string $nodeId, array $collectionKeys): void;

    /**
     * Tops this node's copy of one RT collection up with rows it does not hold.
     *
     * The other way round from {@see applyRemoteRtSnapshot()}: nothing is replaced and nothing
     * is removed, because the sender owns none of what it sends and speaks for no scope. A row
     * already held, or claimed by an agent of this node, is passed over — the offer is a copy of
     * how things were on the holder, and anything already here is at least as current.
     *
     * That is also why the whole frame is never refused. A snapshot overlapping this node's own
     * claim is the symptom of a split truth source; an offer overlapping it is ordinary, since a
     * holder keeps replicas of rows this node writes.
     *
     * @param string $originNodeId Id of the node that wrote these rows
     * @param string $collectionKey RT collection being topped up
     * @param array<string, array<string, mixed>> $rows Rows by state id, as the holder keeps them
     * @throws HilosException Whatever the applied write of the missing rows raises
     */
    public function applyRemoteRtReplicaOffer(string $originNodeId, string $collectionKey, array $rows): void;

    /**
     * Tells the runtime that nothing more will arrive from a node until it links again.
     *
     * The replicas this node holds of that node's rows stop being kept up to date at this
     * moment, and go on being served — so the one thing that must not happen is that they stay
     * indistinguishable from rows that are current (HIL-711). The cue is the link closing and
     * not the node leaving the roster: membership is gossip, and a third node's word can put a
     * peer back online while nothing this node sends or receives reaches it.
     *
     * @param string $nodeId Node that can no longer be reached
     * @param float $at Microtime of this node's clock when the link closed
     */
    public function noteNodeUnreachable(string $nodeId, float $at): void;

    /**
     * Tells the runtime that a node is reachable again, so its replicas are current once more.
     *
     * Called off the completed handshake, beside {@see handOverRtSnapshots()} and for the same
     * reason: the link is what carries deltas, so it is the link coming back — not the roster
     * saying the node is a member — that makes the copy trustworthy again.
     *
     * @param string $nodeId Node this one can reach again
     */
    public function noteNodeReachable(string $nodeId): void;
}
