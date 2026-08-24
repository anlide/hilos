<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Connections\ClusterClientLocation;
use Hilos\Core\Router\Destination\RemoteClientDestination;
use Hilos\Core\Router\Destination\WebSocketDestination;

/**
 * Read-only connection-lookup seam the signal router consults to learn which node a browser
 * is attached to.
 *
 * The twin of {@see WorkerPlacement} on the other end of the wire: that one answers "where
 * does this agent run", this one answers "where does this browser hang". The router asks it
 * for a resolved accept key and — only when the key belongs to another node — turns the local
 * {@see WebSocketDestination} into a {@see RemoteClientDestination} the daemon forwards over
 * the peer channel. The router never owns or mutates the index; the truth of who is attached
 * where is kept by {@see ClusterClientLocation}, which each node fills from its own sockets
 * and from what its peers announce. A test supplies a fake so the routing post-pass can be
 * exercised without a live cluster.
 *
 * The local short-circuit lives behind this contract: a key attached to this node (or one
 * nobody has announced) returns null, so the router keeps delivering it locally exactly as
 * before. Off-cluster nothing registers a lookup and the post-pass is inert.
 */
interface ClientLocation
{
    /**
     * Returns the id of the node holding a browser connection when it is a node other than
     * this one; null when the connection is local or unknown to the mesh.
     *
     * Local and unknown answer alike on purpose. An accept key is minted by the node that
     * accepted the socket and belongs to exactly one node for its whole life, so a key this
     * node cannot place is a key that either is its own or is gone — and both are served by
     * the local path, which drops what it cannot find. Guessing a node for it would be an
     * address invented rather than known.
     *
     * @param string $acceptKey Browser connection to look up
     * @return ?string Holding node id when remote, or null for local / unknown
     */
    public function nodeFor(string $acceptKey): ?string;
}
