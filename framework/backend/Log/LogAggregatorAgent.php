<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Hilos;
use Hilos\Log\DTO\NodeLogIndexSignalData;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotReadableException;
use Hilos\Runtime\State\Item\HilosClusterNode;

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
 * It is filled by one signal and nothing else ({@see HilosSignalConstants::LOGS_NODE_INDEX_REPORT},
 * HIL-755), which every node sends unasked on its own tick; the frames that carry projections to a
 * subscribed page are HIL-756. {@see onTick()} stays the base class's no-op because all the work
 * there is to do happens on a frame.
 *
 * Whether a node is still THERE is not something the frames can answer - the last one arrived
 * before the machine fell over - so {@see nodeViews()} reads cluster membership out of
 * {@see HilosClusterNode} at the moment it hands the picture over. The aggregator keeps no silence
 * clock of its own: "which nodes exist" belongs to the master's register, and a second answer to it
 * here would be a second truth.
 *
 * It also answers how often a node may report ({@see pushIntervalMs()}), because that number is one
 * setting for the whole cluster and this is the one thing in the cluster. The sender obeys the
 * WRITTEN setting directly instead (HIL-755), so this door stands without a consumer for now.
 */
final class LogAggregatorAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LOG_AGGREGATOR;

    /**
     * The one frame it takes: a node's whole log index, sent by the owner of that directory.
     *
     * No index field, because there is nothing to index by - the aggregator is one instance for
     * the whole cluster, and the placement policy names the node it runs on.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::LOGS_NODE_INDEX_REPORT => NodeLogIndexSignalData::class,
    ];

    /**
     * Cluster membership, which is what tells a node that fell over from one that is quiet.
     *
     * Declared on the class rather than registered from {@see onStart()} so the worker raises the
     * interest and waits for the copy BEFORE this instance exists: the first frame can arrive on
     * the tick after the start, and the answer it produces must not depend on that race.
     *
     * @var list<string>
     */
    public const array READS_RT = [HilosClusterNode::RT_COLLECTION];

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
     * Takes the one frame this agent owns and files it.
     *
     * The door is thin on purpose: the payload is already parsed and checked against the class
     * this agent declared, so all that is left is to unwrap the index and hand it to
     * {@see applyNodeIndex()}. Nothing is answered back - the report travels one way, and a node
     * that hears nothing simply sends the next one on its own schedule.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Signal source (unused)
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the agent is reached by a signal it does not own
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::LOGS_NODE_INDEX_REPORT:
                if ($data->data instanceof NodeLogIndexSignalData) {
                    $this->applyNodeIndex($data->data->toIndex());
                }

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
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
     * Every node that has reported, each with the cluster's word on whether it is still connected.
     *
     * Membership is read HERE, when the picture is handed over, and not when a frame lands: a node
     * falls over after sending its last frame, so a liveness recorded on arrival would always read
     * "alive". A node with no row at all counts as online - the frame really did come from it, and
     * the register is only a publication behind.
     *
     * A node the cluster no longer sees keeps its slot and its figures; what it measured is still
     * on a disk somewhere. Saying so is this view's whole job.
     *
     * @return list<ClusterLogNodeView> Slots in node order, each with its membership verdict
     * @throws RtCollectionNotFoundException When the cluster register is not mounted in this process
     * @throws RtCollectionNotReadableException When this process was not told it reads the register
     * @throws RtActionsStateCollectionNullException When the register's backing state is unavailable
     */
    public function nodeViews(): array
    {
        $views = [];
        foreach ($this->index->nodes() as $slot) {
            $row = Hilos::$rt?->hilosClusterNodes[$slot->nodeId ?? HilosClusterNode::STANDALONE_NODE_ID];
            $views[] = new ClusterLogNodeView($slot->nodeId, $slot, $row?->online ?? true);
        }

        return $views;
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
