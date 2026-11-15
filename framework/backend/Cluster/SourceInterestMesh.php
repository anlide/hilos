<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\RtCopyConfirmation;

/**
 * Outbound peer port a node tells the mesh what it reads through.
 *
 * A port of its own rather than a method on {@see RtSyncMesh}, because the two speak for
 * different sides of the same collection: that one announces a WRITE made here, this one
 * announces that this node would like to hear about writes made elsewhere. Nothing that
 * announces a write has any business knowing who is listening.
 *
 * It exists for the reason every mesh port in this framework does — the announcing side stays
 * logic a test can drive with a fake instead of a listener and a live link. The {@see PeerServer}
 * implements it, the daemon master calls it on the pass where the union of what its workers read
 * has moved ({@see AgentManagerDaemon::consumeChangedReaderInterest()}).
 *
 * There is no addressed form: every peer keeps the same map, so a node that becomes the owner of
 * a collection later already knows who was waiting for it.
 */
interface SourceInterestMesh
{
    /**
     * Tells every peer which collections this node reads, replacing what it said before.
     *
     * Replacement and not a delta, for the reason a worker reports to its master the same way: a
     * key the list stops naming is a key this node stopped reading, and a mesh merging the two
     * reports would go on sending frames for it until the node died.
     *
     * Both kinds travel in the one announcement, because both are read off the one map at the
     * one moment: two frames would have to be kept in agreement with each other, and they move
     * on the same events anyway.
     *
     * The announcement carries its number, and every node it is written to answers it behind the
     * snapshots it owes this node; the copy of a collection the announcement adds waits for those
     * answers ({@see RtCopyConfirmation}).
     *
     * @param list<string> $rtCollections RT collections the processes of this node read
     * @param list<string> $dbCollections DB collections the processes of this node read
     * @param int $announcement Number of the announcement
     * @return list<string> Nodes the announcement is written to
     */
    public function announceSourceInterest(array $rtCollections, array $dbCollections, int $announcement): array;

    /**
     * Tells every peer once more what this node announced last, under a new number: nothing it reads
     * changed, and a node it reaches answers the number behind whatever it owes this node by then - a
     * snapshot this node asked it for again before ({@see RtCopyConfirmation::reopen()}).
     *
     * @param int $announcement Number of the announcement
     * @return list<string> Nodes the announcement is written to
     */
    public function reannounceSourceInterest(int $announcement): array;
}
