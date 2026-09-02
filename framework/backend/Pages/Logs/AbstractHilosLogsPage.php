<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogTotals;
use Hilos\Log\LogArchiveRetentionPolicy;
use Hilos\Log\LogBatchSummary;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogSettingsResolver;
use Hilos\Log\NodeLogIndex;
use Hilos\Pages\Logs\DTO\HilosLogsOverviewSignalData;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use JsonException;
use Hilos\Core\Page\PageRouteParams;

/**
 * Abstract Hilos admin page: logs overview (rotation batch metrics under the daemon log archive).
 *
 * Reads the CLUSTER's figures out of {@see ClusterLogIndexMirror} and keeps only what the overview
 * draws from them (HIL-756): the tiles' scalars, and a row per named node for the table beneath
 * them. It walks no directory itself: a walk in the page worker would block it
 * on file I/O and could only ever see the node it happens to run on, which is one node's logs shown as
 * though they were the whole installation. The caching around the projection stays — a ~100ms throttle
 * plus a payload fingerprint so an unchanged picture is not rebroadcast. Live updates are pushed while
 * at least one subscriber is connected ({@see self::onAgentTick()}), invoked from
 * {@see AbstractHilosLogsAgent}.
 *
 * Every connection that subscribes is also counted as a viewer of the SECTION, which is what makes the
 * aggregator send anything at all; without that the mirror would stay empty and the page would have
 * nothing to project.
 *
 * Projects register a concrete empty subclass in the page factory (wiring only).
 */
abstract class AbstractHilosLogsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS,
    ];

    /** @var array<string, true> WebSocket accept keys currently subscribed to this page */
    private static array $logsOverviewSubscribers = [];

    /**
     * Last wall time ({@see microtime()}) when a refresh ran; used for ~100ms throttle in
     * {@see self::onAgentTick()}.
     */
    private static ?float $lastIncrementalScanAt = null;

    /**
     * JSON fingerprint of the last overview payload; when unchanged after a tick rescan, broadcast is skipped.
     */
    private static string $lastOverviewFingerprint = '';

    /**
     * @var ?bool Whether the cluster's log stores could be read, null while no merged picture has arrived
     *
     * The third state is the transport's own and not a fault: an aggregator that is unplaced, moving
     * between nodes or simply not answering yet leaves the figures UNKNOWN, where false would report
     * every log store in the cluster as unreadable.
     */
    private static ?bool $logsOverviewAvailable = null;

    /** @var ?int Total rotation batch folders; null when metrics are unavailable. */
    private static ?int $logsOverviewTotalRotationsAllTime = null;

    /** @var ?string Latest rotation time (ISO 8601); null if none or unavailable. */
    private static ?string $logsOverviewLastRotationAt = null;

    /** @var ?int Distinct agent-*.log basenames (archive + live); null when unavailable */
    private static ?int $logKeysPerAgent = null;

    /** @var ?int Sum of agent log file sizes in bytes; null when unavailable */
    private static ?int $totalWeightAgentKeysBytes = null;

    /** @var ?int Distinct worker + worker-monopolistic basenames; null when unavailable */
    private static ?int $logKeysPerWorker = null;

    /** @var ?int Sum of worker log file sizes in bytes; null when unavailable */
    private static ?int $totalWeightWorkerKeysBytes = null;

    /**
     * @var ?int Bytes written across the cluster over the last day; null while no stream's day
     *     window has filled, which is not the same answer as nothing having been written
     */
    private static ?int $logsOverviewGrowthBytesPerDay = null;

    /** @var ?int Streams whose day window is not a day old yet; null when unavailable */
    private static ?int $logsOverviewKeysWithoutGrowthWindow = null;

    /** @var ?int Rotation batches past their retention across the whole cluster; null when unavailable */
    private static ?int $logsOverviewBatchesDueForTakeout = null;

    /**
     * @var list<array{nodeId: string, available: bool, lastRotationAt: ?string, liveBytes: ?int,
     *     archiveBytes: ?int, growthBytesPerDay: ?int, batchesDueForTakeout: ?int}> Rows of the
     *     per-node table, named nodes only; empty in a single-node installation
     */
    private static array $logsOverviewNodes = [];

    /**
     * Process-wide reader of the log settings, asked for the retention rule alone.
     *
     * Kept between refreshes for the reason the rotations table keeps its own
     * ({@see HilosLogRotationsTable}): the reader speaks up about settings it cannot make sense
     * of, and building a new one on every tick would repeat that line ten times a second.
     */
    private static ?LogSettingsResolver $settingsResolver = null;

    /**
     * Remove a connection from the subscriber set after {@see self::onUnsubscribe()} or when the connection
     * is already torn down (safety net; idempotent with {@see self::onUnsubscribe()}).
     *
     * Called from {@see AbstractHilosLogsAgent::onSignalConnectionClose()}, which is the path a
     * connection that went without a word arrives by - so the section's viewer count is dropped
     * here too, and not only on the orderly unsubscribe. Left counted, such a viewer would keep the
     * aggregator sending frames to a page nobody has open.
     *
     * @param string $acceptKey Target connection accept key
     */
    public static function removeSubscriber(string $acceptKey): void
    {
        unset(self::$logsOverviewSubscribers[$acceptKey]);
        ClusterLogIndexMirror::removeViewer($acceptKey);
    }

    /**
     * Worker tick hook for the Hilos logs agent: throttled incremental rescan (~100ms) and broadcast on change.
     *
     * @param PageAgentInterface $agent Hilos logs agent (for {@see PageAgentInterface::getAgentSignalSource()} when broadcasting)
     * @throws InvalidArgumentException When the overview signal cannot be named
     */
    public static function onAgentTick(PageAgentInterface $agent): void
    {
        if (self::$logsOverviewSubscribers === []) {
            return;
        }

        $now = microtime(true);
        if (self::$lastIncrementalScanAt !== null && ($now - self::$lastIncrementalScanAt) < 0.1) {
            return;
        }
        self::$lastIncrementalScanAt = $now;

        self::refreshOverview();

        $fp = self::overviewFingerprint();
        if ($fp === self::$lastOverviewFingerprint) {
            return;
        }

        self::$lastOverviewFingerprint = $fp;
        self::broadcastOverviewToSubscribers($agent);
    }

    /**
     * Drop subscriber state when the client leaves this page (explicit unsubscribe or synthetic unsubscribe on navigate).
     *
     * @param string $acceptKey WebSocket accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        $this->logAgentInfo("hilos_logs onUnsubscribe acceptKey={$acceptKey}");
        self::removeSubscriber($acceptKey);
    }

    /**
     * Send the current overview snapshot over the WebSocket, ahead of the page_response frame.
     *
     * The overview rides a signal of its own and must reach the client before the frame that
     * releases the page, because that frame means the subscription is answered in full.
     *
     * @param string $acceptKey Target connection accept key
     * @param PageRouteParams $params Route parameters (unused for this page)
     * @throws InvalidArgumentException When the overview signal cannot be named
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->logAgentInfo("hilos_logs onSubscribe acceptKey={$acceptKey}");
        self::refreshOverview();
        self::$lastOverviewFingerprint = self::overviewFingerprint();

        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS,
            $acceptKey,
            self::buildLogsOverviewSignalData(),
        );
    }

    /**
     * Register the connection for the live pushes of {@see self::onAgentTick()}, and count it as a
     * viewer of the section.
     *
     * Both run after the answer so a subscription that was refused leaves neither a subscriber nor
     * a viewer behind: {@see self::removeSubscriber()} only ever hears about a connection that
     * closed, and a viewer counted for a page nobody was given would keep the aggregator sending
     * frames for as long as that socket lived.
     *
     * @param string $acceptKey Target connection accept key
     * @param PageRouteParams $params Route parameters (unused for this page)
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        self::$logsOverviewSubscribers[$acceptKey] = true;
        ClusterLogIndexMirror::addViewer($acceptKey);
    }

    /**
     * Refresh the overview scalars from the cluster picture the mirror holds.
     *
     * Nothing is read from a disk and nothing is walked: the figures are the cluster's own
     * projection ({@see ClusterLogTotals}) of what every node reported, so this is a copy out of
     * memory, and the throttle in {@see self::onAgentTick()} now guards a fingerprint comparison
     * rather than an I/O cost.
     *
     * Two states are not figures at all and collapse to {@see self::setUnknownState()}: no picture
     * has arrived, and a picture in which no node has reported yet. The second is the same answer as
     * the first - nobody has told us anything - and reporting it as zeros would put a measurement on
     * the screen that no node ever took.
     *
     * The takeout verdict is the one figure not taken out of {@see ClusterLogTotals}, because it is
     * not a property of the picture but of the picture read against the retention rule in force -
     * and against the current instant, which is what makes a batch age into being due without
     * anything in the picture having moved.
     */
    private static function refreshOverview(): void
    {
        $index = ClusterLogIndexMirror::index();
        $totals = $index?->totals();
        if ($index === null || $totals === null || $totals->nodeCount === 0) {
            self::setUnknownState();

            return;
        }
        if ($totals->unavailableNodeCount === $totals->nodeCount) {
            self::setUnavailableState();

            return;
        }

        self::$logsOverviewAvailable = true;
        self::$logsOverviewTotalRotationsAllTime = $totals->batchCount;
        self::$logsOverviewLastRotationAt = self::rotationAt($totals->lastRotationAt);
        // The daemon's own streams (HIL-753) are a third class here and belong to neither tile.
        self::$logKeysPerAgent = self::countOf($totals, LogKeySummary::CLASS_AGENT);
        self::$totalWeightAgentKeysBytes = self::bytesOf($totals, LogKeySummary::CLASS_AGENT);
        self::$logKeysPerWorker = self::countOf($totals, LogKeySummary::CLASS_WORKER);
        self::$totalWeightWorkerKeysBytes = self::bytesOf($totals, LogKeySummary::CLASS_WORKER);
        self::$logsOverviewGrowthBytesPerDay = $totals->growthBytesPerDay;
        self::$logsOverviewKeysWithoutGrowthWindow = $totals->keysWithoutGrowthWindow;

        // Read once for the whole picture, because the rule is one for the whole cluster: what is
        // per node is the archive the rule is applied TO, not the rule itself.
        $policy = self::settingsResolver()->retentionPolicy();
        $now = time();
        $batchesDueForTakeout = 0;
        $nodes = [];
        foreach ($index->nodes() as $slot) {
            $due = self::batchesDueOf($slot->index, $policy, $now);
            // Summed over every slot, the nameless one included: a single-node installation draws
            // no table, and the banner above it still has to say the batches are waiting.
            $batchesDueForTakeout += $due;
            if ($slot->nodeId !== null) {
                $nodes[] = self::nodeRow($slot->nodeId, $slot->index, $due);
            }
        }
        self::$logsOverviewBatchesDueForTakeout = $batchesDueForTakeout;
        self::$logsOverviewNodes = $nodes;
    }

    /**
     * Lays out one named node's row: when it last rotated, what it is holding, and what is due.
     *
     * A node whose store could not be read carries null in every figure rather than zeros. The row
     * stays in the table saying "no data", because a node silently missing from it would read as a
     * node that never reported at all - and a zero there would be a measurement nobody took.
     *
     * @param string $nodeId Cluster node the row speaks for
     * @param NodeLogIndex $index That node's index, as it last reported it
     * @param int $batchesDue Batches of this node past their retention, judged by the caller
     * @return array{nodeId: string, available: bool, lastRotationAt: ?string, liveBytes: ?int,
     *     archiveBytes: ?int, growthBytesPerDay: ?int, batchesDueForTakeout: ?int} Row of the table
     */
    private static function nodeRow(string $nodeId, NodeLogIndex $index, int $batchesDue): array
    {
        if (!$index->available) {
            return [
                HilosLogsOverviewSignalData::nodeId => $nodeId,
                HilosLogsOverviewSignalData::available => false,
                HilosLogsOverviewSignalData::lastRotationAt => null,
                HilosLogsOverviewSignalData::liveBytes => null,
                HilosLogsOverviewSignalData::archiveBytes => null,
                HilosLogsOverviewSignalData::growthBytesPerDay => null,
                HilosLogsOverviewSignalData::batchesDueForTakeout => null,
            ];
        }

        $archiveBytes = self::archiveBytesOf($index);

        return [
            HilosLogsOverviewSignalData::nodeId => $nodeId,
            HilosLogsOverviewSignalData::available => true,
            HilosLogsOverviewSignalData::lastRotationAt => self::rotationAt(self::lastRotationOf($index)),
            HilosLogsOverviewSignalData::liveBytes => self::liveBytesOf($index, $archiveBytes),
            HilosLogsOverviewSignalData::archiveBytes => $archiveBytes,
            HilosLogsOverviewSignalData::growthBytesPerDay => self::growthOf($index),
            HilosLogsOverviewSignalData::batchesDueForTakeout => $batchesDue,
        ];
    }

    /**
     * How many of one node's batches are past their retention now.
     *
     * Judged over that node's archive alone and never over the cluster's: archives do not travel,
     * so a batch ages out where it lies, and the cluster figure is the sum of these verdicts
     * rather than a verdict over a pile nobody holds.
     *
     * @param NodeLogIndex $index That node's index, as it last reported it
     * @param LogArchiveRetentionPolicy $policy The rule in force, one for the whole cluster
     * @param int $now Instant the batch ages are measured against, in Unix seconds
     * @return int Batches of this node awaiting takeout
     */
    private static function batchesDueOf(NodeLogIndex $index, LogArchiveRetentionPolicy $policy, int $now): int
    {
        $timestamps = array_map(static fn(LogBatchSummary $batch): int => $batch->timestamp, $index->batches);

        return count($policy->selectEvictionCandidates($timestamps, $now));
    }

    /**
     * When one node last rotated.
     *
     * @param NodeLogIndex $index That node's index, as it last reported it
     * @return ?int Unix timestamp of its newest batch, null when it holds none
     */
    private static function lastRotationOf(NodeLogIndex $index): ?int
    {
        $last = null;
        foreach ($index->batches as $batch) {
            if ($last === null || $batch->timestamp > $last) {
                $last = $batch->timestamp;
            }
        }

        return $last;
    }

    /**
     * What one node's archive weighs.
     *
     * Every class of stream, the daemon's own included: this is what the archive costs, which is
     * the same sum the rotations history charges a batch with ({@see HilosLogRotationsTable}).
     *
     * @param NodeLogIndex $index That node's index, as it last reported it
     * @return int Summed size in bytes of every batch it holds
     */
    private static function archiveBytesOf(NodeLogIndex $index): int
    {
        $bytes = 0;
        foreach ($index->batches as $batch) {
            $bytes += $batch->agentBytes + $batch->workerBytes + $batch->workerMonopolisticBytes + $batch->daemonBytes;
        }

        return $bytes;
    }

    /**
     * What one node's live files weigh.
     *
     * Nothing measures the live weight on its own: a {@see LogKeySummary} carries the live file AND
     * every batch occurrence of that stream in one number, so the live weight is that sum with the
     * archive taken back out. Both sides count all four stream classes, which is why the
     * subtraction meets.
     *
     * @param NodeLogIndex $index That node's index, as it last reported it
     * @param int $archiveBytes What its archive weighs, already summed
     * @return int Summed size in bytes of its live files
     */
    private static function liveBytesOf(NodeLogIndex $index, int $archiveBytes): int
    {
        $bytes = 0;
        foreach ($index->keys as $key) {
            $bytes += $key->totalBytes;
        }

        return $bytes - $archiveBytes;
    }

    /**
     * How much one node wrote over the last day.
     *
     * The rule {@see ClusterLogTotals} applies to the cluster, applied to one node: a stream whose
     * day window has not filled contributes nothing rather than a zero, and a node where none of
     * them has filled answers null - it has not been measured long enough, which is not the same
     * as not growing.
     *
     * @param NodeLogIndex $index That node's index, as it last reported it
     * @return ?int Bytes written over the last day, null while no window of its has filled
     */
    private static function growthOf(NodeLogIndex $index): ?int
    {
        $growth = null;
        foreach ($index->growthBytesPerDay as $bytes) {
            if ($bytes === null) {
                continue;
            }

            $growth = ($growth ?? 0) + $bytes;
        }

        return $growth;
    }

    /**
     * A rotation instant in the form the screen reads it in.
     *
     * @param ?int $timestamp Unix timestamp of the rotation, null when there was none
     * @return ?string ISO 8601 datetime, null when there was no rotation
     */
    private static function rotationAt(?int $timestamp): ?string
    {
        return $timestamp === null
            ? null
            : new DateTimeImmutable()
                ->setTimestamp($timestamp)
                ->format(DateTimeInterface::ATOM);
    }

    /**
     * The process-wide settings reader the retention policy is asked for.
     *
     * @return LogSettingsResolver Reader over the settings, with the environment beneath them
     */
    private static function settingsResolver(): LogSettingsResolver
    {
        // The complaint the resolver raises is not taken here: the rotation agent on each node
        // already writes that line, and a second copy from the page worker says nothing new.
        return self::$settingsResolver ??= new LogSettingsResolver();
    }

    /**
     * How many streams of one class the cluster holds.
     *
     * A class no node reported is absent from the map rather than present as a zero, and here it IS
     * zero: the picture has been taken, and it found none.
     *
     * @param ClusterLogTotals $totals Cluster summary to read
     * @param string $class Stream class, one of the {@see LogKeySummary} class values
     * @return int Streams of that class across the cluster
     */
    private static function countOf(ClusterLogTotals $totals, string $class): int
    {
        return $totals->streamCountByClass[$class] ?? 0;
    }

    /**
     * What the streams of one class weigh across the cluster.
     *
     * @param ClusterLogTotals $totals Cluster summary to read
     * @param string $class Stream class, one of the {@see LogKeySummary} class values
     * @return int Summed size in bytes of that class
     */
    private static function bytesOf(ClusterLogTotals $totals, string $class): int
    {
        return $totals->bytesByClass[$class] ?? 0;
    }

    /**
     * Reset overview scalars to the state that says no merged picture has arrived.
     *
     * Distinct from {@see self::setUnavailableState()} in exactly one field, and that field is the
     * whole difference between "we have not heard" and "we heard, and nothing can be read".
     */
    private static function setUnknownState(): void
    {
        self::setUnavailableState();
        self::$logsOverviewAvailable = null;
    }

    /**
     * Reset overview scalars when the log store cannot be read or env is missing.
     *
     * The per-node table empties with them. Both states this leads to are states of the whole
     * picture - nobody has reported, or nobody could be read - so there is no node left that
     * answers for itself, and a table of rows all saying "no data" would repeat once per row what
     * the screen already says once.
     */
    private static function setUnavailableState(): void
    {
        self::$logsOverviewAvailable = false;
        self::$logsOverviewTotalRotationsAllTime = null;
        self::$logsOverviewLastRotationAt = null;
        self::$logKeysPerAgent = null;
        self::$totalWeightAgentKeysBytes = null;
        self::$logKeysPerWorker = null;
        self::$totalWeightWorkerKeysBytes = null;
        self::$logsOverviewGrowthBytesPerDay = null;
        self::$logsOverviewKeysWithoutGrowthWindow = null;
        self::$logsOverviewBatchesDueForTakeout = null;
        self::$logsOverviewNodes = [];
    }

    /**
     * Stable JSON fingerprint of the overview payload for change detection after a tick rescan.
     *
     * It covers EVERY field the payload carries, the per-node rows included, and that is the whole
     * point of it: a field left out of here is a field whose change never wakes a broadcast, so
     * the picture moves while the screen holds the old one and goes on looking alive. Nothing
     * fails in that case - which is why the fingerprint is built from the payload itself.
     *
     * @return string JSON object string
     *
     * @throws JsonException From {@see json_encode()} with {@see JSON_THROW_ON_ERROR}
     */
    private static function overviewFingerprint(): string
    {
        return json_encode(self::buildLogsOverviewSignalData()->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Build the WebSocket payload from cached static overview fields.
     */
    private static function buildLogsOverviewSignalData(): HilosLogsOverviewSignalData
    {
        return new HilosLogsOverviewSignalData(
            available: self::$logsOverviewAvailable,
            totalRotationsAllTime: self::$logsOverviewTotalRotationsAllTime,
            lastRotationAt: self::$logsOverviewLastRotationAt,
            logKeysPerAgent: self::$logKeysPerAgent,
            totalWeightAgentKeysBytes: self::$totalWeightAgentKeysBytes,
            logKeysPerWorker: self::$logKeysPerWorker,
            totalWeightWorkerKeysBytes: self::$totalWeightWorkerKeysBytes,
            growthBytesPerDay: self::$logsOverviewGrowthBytesPerDay,
            keysWithoutGrowthWindow: self::$logsOverviewKeysWithoutGrowthWindow,
            batchesDueForTakeout: self::$logsOverviewBatchesDueForTakeout,
            nodes: self::$logsOverviewNodes,
        );
    }

    /**
     * Push the current overview DTO to every tracked subscriber (after a fingerprint change on tick).
     *
     * @param PageAgentInterface $agent Hilos logs agent used for signal routing
     */
    private static function broadcastOverviewToSubscribers(PageAgentInterface $agent): void
    {
        $dto = self::buildLogsOverviewSignalData();
        foreach (array_keys(self::$logsOverviewSubscribers) as $acceptKey) {
            self::queueOverviewToUser($agent, $acceptKey, $dto);
        }
    }

    /**
     * Queue a user-targeted WebSocket signal with the logs overview DTO.
     *
     * @param PageAgentInterface $agent Logs agent providing {@see PageAgentInterface::getAgentSignalSource()}
     * @param string $acceptKey Connection accept key
     * @param HilosLogsOverviewSignalData $data Payload
     */
    private static function queueOverviewToUser(
        PageAgentInterface $agent,
        string $acceptKey,
        HilosLogsOverviewSignalData $data,
    ): void {
        Hilos::$sr->queueSignal(
            signalSource: $agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS),
            signalData: new WebSocketSignalData(data: $data, targetAcceptKey: $acceptKey),
        );
    }
}
