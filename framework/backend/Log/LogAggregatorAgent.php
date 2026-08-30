<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Hilos;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\DTO\LogsIndexWatchSignalData;
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
 * It is filled by one signal ({@see HilosSignalConstants::LOGS_NODE_INDEX_REPORT}, HIL-755), which
 * every node sends unasked on its own tick, and it hands the picture up by another
 * ({@see HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION}, HIL-756) to whoever claims to have
 * somebody watching. Nothing goes up while nobody does - a cluster with no administrator looking at
 * it costs one frame per node per interval and not a byte more.
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
     * The two frames it takes: a node's whole log index, and a claim of interest from above.
     *
     * No index field on either, because there is nothing to index by - the aggregator is one
     * instance for the whole cluster, and the placement policy names the node it runs on.
     */
    public const array AGENT_SIGNALS = [
        HilosSignalConstants::LOGS_NODE_INDEX_REPORT => NodeLogIndexSignalData::class,
        HilosSignalConstants::LOGS_INDEX_WATCH => LogsIndexWatchSignalData::class,
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

    /**
     * @var float Smallest gap between two portions, in seconds
     *
     * The coalescing window, and a constant rather than a setting: it protects the wire, which is
     * not something an administrator has a view about. Half a second is well under the interval a
     * node reports at (five seconds by default, HIL-754), so it never delays a frame that had
     * nothing to wait for - it only folds a burst of them into one.
     */
    private const float FANOUT_WINDOW_SECONDS = 0.5;

    /**
     * @var float Seconds of silence after which a subscriber is forgotten
     *
     * Three missed claims in a row at the subscriber's keepalive of thirty seconds. Long enough
     * that a busy tick or a lost frame does not cancel a live subscription, short enough that a
     * subscriber whose process died stops being sent to within a minute and a half.
     */
    private const float WATCH_LEASE_SECONDS = 90.0;

    /** Watcher record field: how many viewers that subscriber last claimed. */
    private const string WATCH_VIEWERS = 'viewers';

    /** Watcher record field: wall clock of that subscriber's last claim, which is what the lease is measured from. */
    private const string WATCH_RENEWED_AT = 'renewedAt';

    /** Watcher record field: revision this subscriber has already been written up to. */
    private const string WATCH_SENT_REVISION = 'sentRevision';

    /** @var ClusterLogIndex Slot per node, as each of them last reported */
    private ClusterLogIndex $index;

    /** @var LogSettingsResolver Reader of the push interval, kept so an unchanged fault is reported once */
    private LogSettingsResolver $resolver;

    /**
     * @var array<string, array{viewers: int, renewedAt: float, sentRevision: int}> Signal source → what it claimed and how far it has been written
     */
    private array $watchers = [];

    /** @var array<string, int> Slot key → the revision at which that node's slot last changed */
    private array $slotRevision = [];

    /**
     * @var int Bumped by every accepted node index, so a slot can be told from the one before it
     *
     * A counter and not a clock: {@see ClusterLogNodeSlot::$receivedAt} is whole seconds, and two
     * frames from one node inside the same second are a normal thing to happen - told apart by
     * arrival time, the second of them would never reach a screen.
     */
    private int $revision = 0;

    /** @var float Wall clock of the last portion sent to anybody, the window is measured from it */
    private float $lastFanoutAt = 0.0;

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
     * Nothing is sent from here either, however long a subscriber has been waiting: a node that
     * reports a hundred times in a second would otherwise be a hundred frames going up. What each
     * subscriber still owes is written down as a revision, and {@see fanOutIfDue()} pays it at the
     * pace of the coalescing window.
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

        $this->revision++;
        $this->slotRevision[ClusterLogIndex::slotKey($index->nodeId)] = $this->revision;
    }

    /**
     * Takes the two frames this agent owns and files each of them.
     *
     * Each door is thin on purpose: the payload is already parsed and checked against the class
     * this agent declared, so all that is left is to unwrap it. A node's report is answered with
     * nothing - it travels one way, and a node that hears back simply sends the next one on its own
     * schedule. A claim of interest is the one thing this agent ever answers, and only the first
     * non-zero one from a source, which is answered with the whole picture at once.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $source Signal source
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the agent is reached by a signal it does not own
     * @throws InvalidArgumentException When the answering frame cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::LOGS_NODE_INDEX_REPORT:
                if ($data->data instanceof NodeLogIndexSignalData) {
                    $this->applyNodeIndex($data->data->toIndex());
                }

                return;

            case HilosSignalConstants::LOGS_INDEX_WATCH:
                if ($data->data instanceof LogsIndexWatchSignalData) {
                    $this->applyWatch($source, $data->data->viewers, microtime(true));
                }

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Sends every subscriber the slots it has not been sent yet, no more often than the window.
     *
     * The clock is a parameter rather than a reading, the way {@see LogStoreAgent::pushIndexIfDue()}
     * takes its own: the tick is only this method's throttle, and when a portion is due should not
     * depend on when the loop got round to asking.
     *
     * Leases are collected first, so a round never writes to a subscriber that has already gone
     * quiet for good. The window is measured from the last frame that actually LEFT, not from the
     * last round: a round with nothing to say must not push the next real change half a second into
     * the future.
     *
     * @param float $now Wall clock of this tick
     * @throws InvalidArgumentException When a portion cannot be named
     */
    public function fanOutIfDue(float $now): void
    {
        $this->forgetExpiredWatchers($now);
        if ($now - $this->lastFanoutAt < self::FANOUT_WINDOW_SECONDS) {
            return;
        }

        foreach ($this->watchers as $source => $watcher) {
            $slots = $this->slotsChangedSince($watcher[self::WATCH_SENT_REVISION]);
            if ($slots === []) {
                continue;
            }

            $this->sendPortion($source, $slots, false, $now);
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
     * Pays the subscribers what the frames since the last round owe them.
     *
     * @throws InvalidArgumentException When a portion cannot be named
     */
    public function onTick(): void
    {
        $this->fanOutIfDue(microtime(true));
    }

    /**
     * Nothing owned to release: the picture is derived from frames and dies with the worker.
     */
    public function onStop(): void
    {
        // No-op.
    }

    /**
     * Opens, renews or cancels one subscriber's claim on the picture.
     *
     * There is no subscribe and unsubscribe pair to keep in step: the count does both jobs, and
     * zero is a cancellation that takes effect at once rather than at the end of a lease - a
     * subscriber whose last viewer just closed the tab must stop costing frames immediately.
     *
     * A claim that OPENS a subscription is answered on the spot with the whole picture, outside the
     * window. The window exists to fold a burst of changes into one frame, and there is no burst
     * here: making the first screen of a page wait half a second for something already in memory
     * would be the window charging for work it did not do.
     *
     * @param string $source Signal source of the subscriber
     * @param int $viewers Viewers it claims, zero to cancel
     * @param float $now Wall clock of this claim
     * @throws InvalidArgumentException When the answering frame cannot be named
     */
    private function applyWatch(string $source, int $viewers, float $now): void
    {
        if ($viewers === 0) {
            if (isset($this->watchers[$source])) {
                unset($this->watchers[$source]);
                $this->logAgentInfo("Log aggregator: {$source} stopped watching");
            }

            return;
        }

        if (isset($this->watchers[$source])) {
            $this->watchers[$source][self::WATCH_VIEWERS] = $viewers;
            $this->watchers[$source][self::WATCH_RENEWED_AT] = $now;

            return;
        }

        $this->watchers[$source] = [
            self::WATCH_VIEWERS => $viewers,
            self::WATCH_RENEWED_AT => $now,
            self::WATCH_SENT_REVISION => 0,
        ];
        $this->logAgentInfo("Log aggregator: {$source} started watching, {$viewers} viewer(s)");
        $this->sendPortion($source, $this->index->nodes(), true, $now);
    }

    /**
     * Drops every subscriber that has not renewed its claim within the lease.
     *
     * Silently, because it is not a fault: a subscriber renews on its own tick, so the only way to
     * miss three in a row is for the process holding it to be gone - and the frames it would have
     * received have nowhere to arrive anyway. Saying so at ERROR would fill the journal of every
     * ordinary restart.
     *
     * @param float $now Wall clock of this tick
     */
    private function forgetExpiredWatchers(float $now): void
    {
        foreach ($this->watchers as $source => $watcher) {
            if ($now - $watcher[self::WATCH_RENEWED_AT] >= self::WATCH_LEASE_SECONDS) {
                unset($this->watchers[$source]);
            }
        }
    }

    /**
     * The slots that have changed since a subscriber was last written to.
     *
     * @param int $revision Revision that subscriber has already been sent up to
     * @return list<ClusterLogNodeSlot> Slots newer than it, in node order
     */
    private function slotsChangedSince(int $revision): array
    {
        $slots = [];
        foreach ($this->index->nodes() as $slot) {
            if (($this->slotRevision[ClusterLogIndex::slotKey($slot->nodeId)] ?? 0) > $revision) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * Sends one subscriber a frame and writes down how far it has now been told.
     *
     * The mark is the current revision and not the newest slot in the frame, and the two are the
     * same number: every accepted index bumps the counter and stamps a slot with it, and this is
     * only ever called with everything the subscriber was owed. Nothing can arrive between building
     * the frame and marking it either - a frame is queued, not awaited, and one process handles the
     * arrival and the send.
     *
     * @param string $source Signal source of the subscriber
     * @param list<ClusterLogNodeSlot> $slots Slots to carry, each one whole
     * @param bool $snapshot Whether the frame replaces that subscriber's whole picture
     * @param float $now Wall clock the window is measured from after this
     * @throws InvalidArgumentException When the frame cannot be named
     */
    private function sendPortion(string $source, array $slots, bool $snapshot, float $now): void
    {
        $this->sendToAgent(
            HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION,
            ClusterLogIndexPortionSignalData::ofSlots($slots, $snapshot),
        );
        $this->watchers[$source][self::WATCH_SENT_REVISION] = $this->revision;
        $this->lastFanoutAt = $now;

        // DEBUG for the same reason the per-frame line below it is: this agent writes into the very
        // directory it measures, and a line per portion would outgrow what it reports on.
        $this->logAgentDebug(
            'Log aggregator: ' . ($snapshot ? 'snapshot' : 'portion') . " sent to {$source}, "
            . count($slots) . ' node(s)',
        );
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
