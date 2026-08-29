<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Cluster\NodeIdentity;

/**
 * Immutable picture of every node's log store at once (HIL-754).
 *
 * One slot per node, holding the index that node last sent, and the projections drawn from all of
 * them together. It is what {@see LogAggregatorAgent} holds and hands out; the transport that
 * fills it is HIL-755 and the frames that carry it to a page are HIL-756.
 *
 * A node is here because it reported, not because it is a member of the cluster: cluster
 * membership is the master's register and an agent in a worker cannot reach it, so "which nodes
 * are there" is answered by the frames themselves.
 *
 * Streams are counted per (key, node) pair and never folded by name. The same `worker-0.log` on
 * two nodes is two different files, rotated and evicted apart, and folding them would understate
 * both the count and the weight.
 */
final class ClusterLogIndex
{
    /**
     * Slot key of the installation that has no node id at all.
     *
     * The empty string, and it is the one key no real node can take: {@see NodeIdentity::fromEnv()}
     * refuses an empty CLUSTER_NODE_ID as a configuration error rather than defaulting it, so a
     * single-node installation cannot collide with anybody. It is named here rather than written
     * where it is used because there it would read as a missing value, which is the opposite of
     * what it is — a slot addressed by it holds a node's real index.
     */
    private const string SINGLE_NODE_KEY = '';

    /** @var array<string, ClusterLogNodeSlot> Node id → its slot, keyed by {@see self::SINGLE_NODE_KEY} in a single-node installation */
    private readonly array $nodes;

    /**
     * @param array<string, ClusterLogNodeSlot> $nodes Slots keyed by {@see self::slotKey()}
     */
    private function __construct(array $nodes)
    {
        $this->nodes = $nodes;
    }

    /**
     * The picture an aggregator starts with, and the one it returns to after a restart or a move.
     *
     * Empty is not the same as zero, and the difference matters on the overview: a cluster nobody
     * has reported for yet has no figures at all, where zeros would claim there is nothing to
     * report. Nothing has to be re-requested to leave this state — every node sends its index
     * whole, so the next ordinary frame from each of them rebuilds the picture.
     *
     * @return self Picture holding no node
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * The picture with one node's slot put in place of whatever that node had before.
     *
     * The slot is replaced whole rather than merged, and no timestamp is compared: a node's frames
     * travel one link of the mesh, which does not reorder them, while dropping a frame as "older"
     * would stick forever the first time a node's clock is wound back.
     *
     * @param ClusterLogNodeSlot $slot Slot to hold for that node
     * @return self Copy carrying the new slot
     */
    public function withNode(ClusterLogNodeSlot $slot): self
    {
        $nodes = $this->nodes;
        $nodes[self::slotKey($slot->nodeId)] = $slot;
        // Sorted by node, so the per-node table reads the same however the frames happened to
        // arrive; insertion order would make it the order the nodes first spoke in.
        ksort($nodes);

        return new self($nodes);
    }

    /**
     * Every node that has reported, ordered by node id.
     *
     * @return list<ClusterLogNodeSlot> Slots, one per node
     */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /**
     * One node's slot.
     *
     * @param ?string $nodeId Node to look up, null for the single-node installation
     * @return ?ClusterLogNodeSlot Its slot, or null when that node has never reported
     */
    public function node(?string $nodeId): ?ClusterLogNodeSlot
    {
        return $this->nodes[self::slotKey($nodeId)] ?? null;
    }

    /**
     * The overview tiles: everything the nodes have, added up, with the unknown counted separately.
     *
     * @return ClusterLogTotals Summary across every reported node
     */
    public function totals(): ClusterLogTotals
    {
        $unavailableNodeCount = 0;
        $lastRotationAt = null;
        $batchCount = 0;
        $streamCountByClass = [];
        $bytesByClass = [];
        $growthBytesPerDay = null;
        $keysWithoutGrowthWindow = 0;

        foreach ($this->nodes as $slot) {
            $index = $slot->index;
            if (!$index->available) {
                $unavailableNodeCount++;
            }

            $batchCount += count($index->batches);
            foreach ($index->batches as $batch) {
                if ($lastRotationAt === null || $batch->timestamp > $lastRotationAt) {
                    $lastRotationAt = $batch->timestamp;
                }
            }

            foreach ($index->keys as $key) {
                $streamCountByClass[$key->class] = ($streamCountByClass[$key->class] ?? 0) + 1;
                $bytesByClass[$key->class] = ($bytesByClass[$key->class] ?? 0) + $key->totalBytes;
            }

            foreach ($index->growthBytesPerDay as $bytes) {
                if ($bytes === null) {
                    $keysWithoutGrowthWindow++;

                    continue;
                }
                $growthBytesPerDay = ($growthBytesPerDay ?? 0) + $bytes;
            }
        }

        ksort($streamCountByClass);
        ksort($bytesByClass);

        return new ClusterLogTotals(
            nodeCount: count($this->nodes),
            unavailableNodeCount: $unavailableNodeCount,
            lastRotationAt: $lastRotationAt,
            batchCount: $batchCount,
            streamCountByClass: $streamCountByClass,
            bytesByClass: $bytesByClass,
            growthBytesPerDay: $growthBytesPerDay,
            keysWithoutGrowthWindow: $keysWithoutGrowthWindow,
        );
    }

    /**
     * The array key one node's slot lives under.
     *
     * @param ?string $nodeId Node id, or null in a single-node installation
     * @return string Slot key, {@see self::SINGLE_NODE_KEY} when there is no node id
     */
    private static function slotKey(?string $nodeId): string
    {
        return $nodeId ?? self::SINGLE_NODE_KEY;
    }
}
