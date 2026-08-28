<?php

declare(strict_types=1);

namespace Hilos\TruthSource;

use Hilos\Cluster\RtSyncSink;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Runtime\RtStaleness;

/**
 * Which node each replicated RT row came from, as far as the daemon master knows.
 *
 * The mirror image of {@see RtNodeSourceMap}: that one answers "what do WE own", this one
 * answers "what arrived FROM WHOM". They are kept apart because they are filled from opposite
 * directions — ownership from the workers' reports about their own agents, origin from the
 * frames the peer transport delivers — and asked in opposite situations.
 *
 * What it exists for is a question a node cannot otherwise answer: when a link drops, which of
 * the rows this node holds have just stopped being kept up to date. A replica is written into
 * the same collection as everything else, so after the write it is indistinguishable from a row
 * this node produced itself; only the frame knew where it came from, and the frame is gone by
 * the time the link closes. Written down here, the answer survives it ({@see RtStaleness}).
 *
 * Keyed by row and not by collection, because with ownership by keys (HIL-589) one collection
 * has several remote owners at once: a fleet member writes the rows it named and its neighbours
 * write theirs. Losing one of them must freeze its rows and leave the others alone.
 *
 * Rows written locally are simply absent, and that is the whole of what "fresh" means here: this
 * node holds them itself, so no link can stop them being current.
 */
final class RtReplicaOriginMap
{
    /** @var array<string, array<string, string>> [collectionKey => [stateId => originNodeId]] */
    private array $byCollection = [];

    /**
     * Records which node the named rows of a collection arrived from.
     *
     * Additive per row rather than replacing the collection: the other rows of it may belong to
     * another node entirely, and a frame speaks only for what it carries. A row arriving from a
     * different node than last time simply moves — the newest frame is the one that says who
     * keeps it up to date now.
     *
     * @param string $originNodeId Node the rows were written on
     * @param string $collectionKey RT collection they belong to
     * @param list<string> $stateIds Rows that arrived
     */
    public function note(string $originNodeId, string $collectionKey, array $stateIds): void
    {
        foreach ($stateIds as $stateId) {
            $this->byCollection[$collectionKey][$stateId] = $originNodeId;
        }
    }

    /**
     * Drops what was known about one row, because it no longer exists here.
     *
     * @param string $collectionKey RT collection the row belonged to
     * @param string $stateId Row that is gone
     */
    public function forget(string $collectionKey, string $stateId): void
    {
        unset($this->byCollection[$collectionKey][$stateId]);
        if (($this->byCollection[$collectionKey] ?? []) === []) {
            unset($this->byCollection[$collectionKey]);
        }
    }

    /**
     * Every row this node holds a replica of that one node wrote.
     *
     * The question asked the moment a link to that node closes: those rows are the ones whose
     * source has just become unreachable ({@see DaemonManager::noteNodeUnreachable()}), and the
     * transport knows only the node id ({@see RtSyncSink}).
     *
     * @param string $nodeId Node to ask about
     * @return array<string, list<string>> Rows of that node, keyed by RT collection; collections
     *     it wrote nothing in are absent
     */
    public function rowsOfNode(string $nodeId): array
    {
        $rowsByCollection = [];
        foreach ($this->byCollection as $collectionKey => $originByRow) {
            // Cast back to string because a numeric state id - a fleet index, say - comes off
            // array_keys() as an int, and these ids travel on to a worker frame that declares
            // them strings.
            $rows = array_map(strval(...), array_keys($originByRow, $nodeId, true));
            if ($rows !== []) {
                $rowsByCollection[(string)$collectionKey] = $rows;
            }
        }

        return $rowsByCollection;
    }

    /**
     * Which node one row came from.
     *
     * @param string $collectionKey RT collection to look in
     * @param string $stateId Row to ask about
     * @return ?string Node that wrote it, or null when this node wrote it itself
     */
    public function nodeOfRow(string $collectionKey, string $stateId): ?string
    {
        return $this->byCollection[$collectionKey][$stateId] ?? null;
    }
}
