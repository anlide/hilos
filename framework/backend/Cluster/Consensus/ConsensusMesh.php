<?php

declare(strict_types=1);

namespace Hilos\Cluster\Consensus;

use Hilos\Cluster\Peer\DTO\PeerDTO;

/**
 * Outbound port the coordinator uses to reach the master peers and read liveness.
 *
 * This is the seam between the pure consensus state machine and the peer
 * transport: the coordinator never touches sockets or the registry directly, it
 * asks the mesh who is online and hands it frames to deliver. The peer server
 * implements it over its live links and the membership registry; a test supplies a
 * fake so the state machine can be driven in isolation with an injected clock.
 */
interface ConsensusMesh
{
    /**
     * Returns the node ids of master-role peers currently online, including self.
     *
     * The coordinator intersects this with the static master set to size the
     * quorum, so a partition that drops links naturally shrinks one side below
     * majority without any extra signalling.
     *
     * @return list<string> Online master node ids, including the local node
     */
    public function onlineMasterIds(): array;

    /**
     * Delivers a consensus frame to every connected master peer.
     *
     * @param PeerDTO $frame Consensus frame to broadcast
     */
    public function broadcastToMasters(PeerDTO $frame): void;

    /**
     * Delivers a consensus frame to one master peer by node id, if it is linked.
     *
     * @param string $nodeId Target master node id
     * @param PeerDTO $frame Consensus frame to send
     */
    public function sendToMaster(string $nodeId, PeerDTO $frame): void;
}
