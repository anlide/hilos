<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Core\Router\DTO\SignalDTO;

/**
 * Local port between the peer transport and this node's browser connections.
 *
 * The client-side counterpart of {@see AgentSignalSink}: that seam ends at an agent, this one
 * ends at a socket. It exists for the same two reasons — the transport must not reach into
 * the daemon, and a test supplies a fake so the transport runs without one.
 *
 * It carries one cue the other way, {@see handOverConnections()}, because only the transport
 * knows when a node became reachable and only the daemon knows which sockets it holds.
 */
interface ClientSignalSink
{
    /**
     * Writes one signal forwarded from another node to the browser it is addressed to.
     *
     * What arrives is already resolved — the sending node did the routing — so this end only
     * encodes and writes, exactly as it does for a signal resolved here. Nothing is re-routed
     * and nothing is passed on.
     *
     * @param string $acceptKey Accept key of the connection to deliver to
     * @param SignalDTO $signal Signal to write to that connection
     */
    public function deliverSignalToClient(string $acceptKey, SignalDTO $signal): void;

    /**
     * Fans one signal forwarded from another node out to the browsers this node holds.
     *
     * The unaddressed counterpart of {@see deliverSignalToClient()}: nothing was resolved on
     * the sending side, because nothing could be — the subscription registry that answers who
     * receives a fan-out is node-local. This end therefore does the resolving, against its own
     * registry and its own connections, and writes to whoever comes out. It never passes the
     * job on: the sending node already told everyone.
     *
     * @param string $originNodeId Id of the node the fan-out started on (trace only)
     * @param SignalDTO $signal Signal to expand against this node's subscriptions
     */
    public function deliverFanoutToClients(string $originNodeId, SignalDTO $signal): void;

    /**
     * Hands the whole set of browser connections this node holds to a node the mesh has just
     * linked to.
     *
     * Called once per completed handshake, from the side that completed it, for the same
     * reason {@see RtSyncSink::handOverRtSnapshots()} is: membership is the wrong cue, since a
     * node is a member before this one can reach it, and the handshake that finally opens the
     * link changes no membership and would never ask again.
     *
     * @param string $nodeId Node this one can now reach
     */
    public function handOverConnections(string $nodeId): void;
}
