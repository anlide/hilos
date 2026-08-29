<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;

/**
 * Cluster-wide owner of the merged log index across nodes (HIL-754).
 *
 * A concrete framework agent, the way {@see LogRotationAgent} and {@see LogStoreAgent} are:
 * merging what the nodes report looks the same in every project, so a project registers the pair
 * in Hilos::AGENTS under {@see HilosAgentType::HILOS_LOG_AGGREGATOR} and is done. One instance for
 * the whole cluster — the default agent scope — placed by policy rather than pinned to the leader,
 * so it survives a re-election instead of dying with the term.
 *
 * It is a separate agent from `hilos_logs`, the overview page's agent, on purpose: the page agent
 * is a surface a project implements, and where the picture COMES FROM must not be the same object
 * as what shows it. Nothing above this agent talks to the node-local {@see LogStoreAgent}s.
 *
 * It NEVER opens a log directory — not another node's, not the one on the node it happens to run
 * on. {@see applyNodeIndex()} is its only source of data, and a node's own index arrives the same
 * way whether that node is this one or not: one behavior, no "is this me" branch.
 *
 * The picture lives in this agent's memory and not in runtime state, for the reason
 * {@see NodeLogIndex} gives for its own half: the runtime is one truth per cluster and its
 * collection is shared, so a picture there would spill a full replica onto every node and
 * duplicate this agent besides.
 *
 * It arrives an empty receiver. The transport that fills it is HIL-755, and the frames that carry
 * projections to a subscribed page are HIL-756; {@see onTick()} stays the base class's no-op
 * because all the work there is to do happens on a frame.
 *
 * It also answers how often a node may report ({@see pushIntervalMs()}), because that number is one
 * setting for the whole cluster and this is the one thing in the cluster. Obeying it is the
 * sender's job, which is HIL-755 again.
 */
final class LogAggregatorAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOG_AGGREGATOR;

    /** @var ClusterLogIndex Slot per node, as each of them last reported */
    private ClusterLogIndex $index;

    /** @var LogSettingsResolver Reader of the push interval, kept so an unchanged fault is reported once */
    private LogSettingsResolver $resolver;

    /**
     * Opens with an empty picture and touches nothing else.
     *
     * No log reader, no path to a directory, no first walk: the aggregator knows only what the
     * nodes tell it. A restart or a move to another node costs nothing to repair either — every
     * node sends its index whole, so the next ordinary frame from each of them restores its slot,
     * and there is no "send everything again" protocol to build.
     *
     * The settings reader is built here and not per call, and it reads nothing yet: it is the
     * memory of what it last complained about, which is what keeps a value that stays wrong from
     * being reported again on every ask.
     */
    public function onStart(): void
    {
        $this->index = ClusterLogIndex::empty();
        $this->resolver = new LogSettingsResolver();
    }

    /**
     * Takes one node's index and puts it in that node's slot, replacing whatever was there.
     *
     * Nothing is recomputed and nothing is merged: the frame is the whole index of that node, so
     * the slot is exchanged for it. That also makes a lost frame self-healing, where stitching
     * deltas would leave the screen and the disks quietly disagreeing with no error anywhere.
     *
     * @param NodeLogIndex $index Index as the node that owns those files measured it
     */
    public function applyNodeIndex(NodeLogIndex $index): void
    {
        $subject = self::nodeSubject($index->nodeId);
        $firstFrame = $this->index->node($index->nodeId) === null;

        $this->index = $this->index->withNode(new ClusterLogNodeSlot($index->nodeId, $index, time()));

        // The aggregator writes into the very directory it measures, so it says one line when a
        // node joins the picture and keeps the per-frame chatter at DEBUG.
        if ($firstFrame) {
            $this->logAgentInfo("Log aggregator: {$subject} reported in");
        }
        $this->logAgentDebug(
            "Log aggregator: {$subject} index accepted, "
            . count($index->keys) . ' key(s), ' . count($index->batches) . ' batch(es)',
        );
    }

    /**
     * The merged picture of every node that has reported.
     *
     * @return ClusterLogIndex Current cluster index, empty until the first frame arrives
     */
    public function clusterIndex(): ClusterLogIndex
    {
        return $this->index;
    }

    /**
     * How often one node may send its index, in milliseconds.
     *
     * Read at the moment it is asked for and never cached: an administrator's edit is then obeyed
     * without restarting anything, the same way the rotation thresholds are. What the resolver had
     * to complain about goes to the journal here, and only when the outcome changed.
     *
     * @return int Milliseconds between two frames from one node
     */
    public function pushIntervalMs(): int
    {
        $interval = $this->resolver->pushIntervalMs();

        while (($complaint = $this->resolver->takeComplaint()) !== null) {
            $this->logAgentError($complaint);
        }

        return $interval;
    }

    /**
     * Nothing owned to release: the picture is derived from frames and dies with the worker.
     */
    public function onStop(): void
    {
        // No-op.
    }

    /**
     * Names a node the way a journal line wants it named.
     *
     * A single-node installation has no id to print ({@see NodeLogIndex::$nodeId} is null there),
     * so the whole subject of the sentence is built here rather than a stand-in id being minted.
     *
     * @param ?string $nodeId Node id, or null in a single-node installation
     * @return string Subject naming that node
     */
    private static function nodeSubject(?string $nodeId): string
    {
        return $nodeId === null ? 'the single node' : "node {$nodeId}";
    }
}
