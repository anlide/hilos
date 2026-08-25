<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Core\Daemon\DaemonManager;

/**
 * Local port the command channel reads this node's RT replication state through.
 *
 * What a scenario can otherwise see of cross-node RT is nothing at all: the copy a node holds
 * lives in the master's memory, and `demo/cluster` runs headless with no browser to render it.
 * So the inspect command asks here, and the answer says what this node owns, how completely,
 * which rows it holds, and how the frames from other nodes were judged — including the count of
 * frames refused as a two-owner split, which a scenario asserts is zero.
 *
 * A port of its own rather than a fourth method on {@see RtSyncSink}: that one is the seam
 * BETWEEN THE TRANSPORT AND THE RUNTIME, crossed by a peer frame arriving or a hand-over going
 * out, while inspection is neither — it is the command channel asking the daemon a question and
 * never touches a link. {@see DaemonManager} implements both, as it implements every daemon-side
 * port, and registers them separately for the same reason.
 */
interface RtReplicaInspector
{
    /**
     * Reports what this node holds of every replicated RT collection, and how it judged frames.
     *
     * @return array<string, mixed> Inspection payload: collections by key, plus the two counters
     */
    public function inspectRtReplicas(): array;
}
