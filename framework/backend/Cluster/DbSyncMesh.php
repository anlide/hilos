<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\Core\Router\DTO\SignalDTO;

/**
 * Outbound peer port the daemon announces its own DB writes through.
 *
 * The mirror of {@see DbSyncSink}: that one carries a fact in, this one carries a local fact
 * out. It hides the {@see PeerServer} behind the single send replication needs, for the reason
 * every mesh port in this framework exists — the announcing side stays logic a test can drive
 * with a fake instead of a listener and a live link.
 *
 * There is no addressed form and there will not be one: a row lives in the database every node
 * shares, so a change to it concerns whoever happens to be holding that row, and only they
 * know whether they are.
 *
 * There is also no snapshot form, unlike {@see RtSyncMesh}: a node that has just come up holds
 * no rows to be behind on, and the one place its rows come from is the database itself.
 */
interface DbSyncMesh
{
    /**
     * Announces one DB sync fact written on this node to every other node of the mesh.
     *
     * @param string $signalType DB sync signal type being announced
     * @param SignalDTO $signal DB sync signal the other nodes apply
     */
    public function broadcastDbSync(string $signalType, SignalDTO $signal): void;
}
