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
use Hilos\Log\LogKeySummary;
use Hilos\Pages\Logs\DTO\HilosLogsOverviewSignalData;
use JsonException;
use Hilos\Core\Page\PageRouteParams;

/**
 * Abstract Hilos admin page: logs overview (rotation batch metrics under the daemon log archive).
 *
 * Reads the CLUSTER's figures out of {@see ClusterLogIndexMirror} and keeps only the overview scalars
 * projected from them (HIL-756). It walks no directory itself: a walk in the page worker would block it
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
     */
    private static function refreshOverview(): void
    {
        $index = ClusterLogIndexMirror::index();
        $totals = $index?->totals();
        if ($totals === null || $totals->nodeCount === 0) {
            self::setUnknownState();

            return;
        }
        if ($totals->unavailableNodeCount === $totals->nodeCount) {
            self::setUnavailableState();

            return;
        }

        self::$logsOverviewAvailable = true;
        self::$logsOverviewTotalRotationsAllTime = $totals->batchCount;
        self::$logsOverviewLastRotationAt = $totals->lastRotationAt === null
            ? null
            : new DateTimeImmutable()
                ->setTimestamp($totals->lastRotationAt)
                ->format(DateTimeInterface::ATOM);
        // The daemon's own streams (HIL-753) are a third class here and belong to neither tile.
        self::$logKeysPerAgent = self::countOf($totals, LogKeySummary::CLASS_AGENT);
        self::$totalWeightAgentKeysBytes = self::bytesOf($totals, LogKeySummary::CLASS_AGENT);
        self::$logKeysPerWorker = self::countOf($totals, LogKeySummary::CLASS_WORKER);
        self::$totalWeightWorkerKeysBytes = self::bytesOf($totals, LogKeySummary::CLASS_WORKER);
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
    }

    /**
     * Stable JSON fingerprint of overview scalars for change detection after a tick rescan.
     *
     * @return string JSON object string
     *
     * @throws JsonException From {@see json_encode()} with {@see JSON_THROW_ON_ERROR}
     */
    private static function overviewFingerprint(): string
    {
        return json_encode([
            'available' => self::$logsOverviewAvailable,
            'totalRotationsAllTime' => self::$logsOverviewTotalRotationsAllTime,
            'lastRotationAt' => self::$logsOverviewLastRotationAt,
            'logKeysPerAgent' => self::$logKeysPerAgent,
            'totalWeightAgentKeysBytes' => self::$totalWeightAgentKeysBytes,
            'logKeysPerWorker' => self::$logKeysPerWorker,
            'totalWeightWorkerKeysBytes' => self::$totalWeightWorkerKeysBytes,
        ], JSON_THROW_ON_ERROR);
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
