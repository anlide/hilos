<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Peer\DTO\PeerDbSyncDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\HilosException;

/**
 * Local port between the peer transport and this node's database-backed collections.
 *
 * Mostly the receiving half of cross-node DB replication: when a peer broadcasts a
 * {@see PeerDbSyncDTO}, the transport passes it through this seam to the daemon master, which
 * applies it to the rows this node holds and fans it out to its own workers. The seam exists
 * for the same reason {@see RtSyncSink} does — the transport must not reach into the daemon,
 * and a test supplies a fake so the transport runs without one.
 *
 * It carries one cue the other way, {@see reReadAfterLink()}, because only the transport knows
 * when a link was established and only the daemon knows what to re-read.
 *
 * Replication is one-way and one hop: what arrives here is applied and never announced on.
 */
interface DbSyncSink
{
    /**
     * Applies one DB sync fact written on another node to the rows this node holds.
     *
     * @param string $originNodeId Id of the node the write happened on
     * @param string $signalType DB sync signal type the frame carried
     * @param SignalDTO $signal DB sync signal to apply and fan out locally
     * @throws HilosException When a collection refuses the fact it is handed
     */
    public function applyRemoteDbSync(string $originNodeId, string $signalType, SignalDTO $signal): void;

    /**
     * Stops believing this node's own copies of database rows, on a link being established.
     *
     * A frame is lost whenever a link is down — that is what best-effort delivery means — and a
     * node cannot tell what it missed while it could not hear. Rather than number the frames and
     * ask for the gap, it distrusts every row it holds: a lazy collection forgets its rows and
     * fetches them from the database on the next read, an eager one re-reads at once. The
     * database is the source either way, so the answer is right whatever was missed.
     *
     * Called once per completed handshake, from the side that completed it, for the reason
     * {@see RtSyncSink::handOverRtSnapshots()} is: membership says a node exists, a handshake
     * says frames can flow, and only the second one marks the end of the deaf window.
     *
     * The price is named and accepted: a burst of queries after every reconnect, including a
     * pointless one when the peer is a brand new node that missed nothing. Telling a newcomer
     * from a returner would mean trusting membership, which is itself restored asynchronously.
     *
     * Raises nothing, and that is part of the contract rather than an implementation detail: the
     * transport calls this from a handshake handler on the master loop, where an escaping
     * exception would end the daemon's run loop. An implementation that cannot re-read reports
     * it and returns.
     *
     * @param string $nodeId Node this one has just linked to
     */
    public function reReadAfterLink(string $nodeId): void;
}
