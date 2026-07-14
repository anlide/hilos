<?php

declare(strict_types=1);

namespace Hilos\Cluster;

/**
 * Seam that re-announces the local node's membership record to the peer mesh.
 *
 * The peer transport (which owns the peer links and the gossip fan-out) runs on
 * the daemon master, while a control-plane reload is triggered from the command
 * channel. This interface lets {@see ClusterContext} ask the transport to gossip
 * the freshly-reloaded local node without the context reaching into the
 * transport: the {@see Peer\PeerServer} registers itself as the announcer at
 * start, and a reload that changes the local record calls back through here.
 */
interface LocalNodeAnnouncer
{
    /**
     * Announces the local node's current registry record to every connected peer.
     */
    public function announceLocalNode(): void;
}
