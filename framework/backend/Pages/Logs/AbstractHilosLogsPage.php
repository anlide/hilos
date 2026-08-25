<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogStoreReader;
use Hilos\Log\LogStoreSnapshot;
use Hilos\Pages\Logs\DTO\HilosLogsOverviewSignalData;
use JsonException;
use Hilos\Core\Page\PageRouteParams;

/**
 * Abstract Hilos admin page: logs overview (rotation batch metrics under the daemon log archive).
 *
 * Delegates the log-store walk to the stateless {@see LogStoreReader} service (single source of truth,
 * shared with the drill-down pages) and keeps only the overview scalars derived from its snapshot. Each
 * refresh does a full walk; the page owns the caching around it — a ~100ms throttle plus a payload
 * fingerprint so unchanged snapshots are not rebroadcast. Live updates are pushed while at least one
 * subscriber is connected ({@see self::onAgentTick()}), invoked from {@see AbstractHilosLogsAgent}.
 *
 * Projects register a concrete empty subclass in the page factory (wiring only).
 */
abstract class AbstractHilosLogsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS;

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

    /** @var bool Whether archive metrics could be read (false if env missing, scandir failed, etc.). */
    private static bool $logsOverviewAvailable = false;

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
     * Called from {@see AbstractHilosLogsAgent::onSignalConnectionClose()}.
     *
     * @param string $acceptKey Target connection accept key
     */
    public static function removeSubscriber(string $acceptKey): void
    {
        unset(self::$logsOverviewSubscribers[$acceptKey]);
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
     * Register the connection for the live pushes of {@see self::onAgentTick()}.
     *
     * Runs after the answer so a subscription that was refused leaves no subscriber behind:
     * {@see self::removeSubscriber()} only ever hears about a connection that closed.
     *
     * @param string $acceptKey Target connection accept key
     * @param PageRouteParams $params Route parameters (unused for this page)
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        self::$logsOverviewSubscribers[$acceptKey] = true;
    }

    /**
     * Refresh the overview scalars from a fresh {@see LogStoreReader} walk.
     *
     * Every call does a full walk; the throttle and fingerprint in {@see self::onAgentTick()} keep the
     * cost bounded. Unreadable stores ({@see LogStoreSnapshot::$available} false) collapse to
     * {@see self::setUnavailableState()}. Logs wall duration with 0.001s precision via
     * {@see self::logAgentInfoForId()}.
     */
    private static function refreshOverview(): void
    {
        $t0 = microtime(true);
        try {
            $snapshot = LogStoreReader::fromEnv()->read();
            if (!$snapshot->available) {
                self::setUnavailableState();

                return;
            }

            self::$logsOverviewAvailable = true;

            $batches = $snapshot->batches();
            self::$logsOverviewTotalRotationsAllTime = count($batches);
            self::$logsOverviewLastRotationAt = $batches === []
                ? null
                : new DateTimeImmutable()
                    ->setTimestamp($batches[array_key_last($batches)]->timestamp)
                    ->format(DateTimeInterface::ATOM);

            $agentKeys = 0;
            $agentBytes = 0;
            $workerKeys = 0;
            $workerBytes = 0;
            foreach ($snapshot->keys() as $key) {
                if ($key->class === LogKeySummary::CLASS_AGENT) {
                    $agentKeys++;
                    $agentBytes += $key->totalBytes;
                } else {
                    $workerKeys++;
                    $workerBytes += $key->totalBytes;
                }
            }
            self::$logKeysPerAgent = $agentKeys;
            self::$totalWeightAgentKeysBytes = $agentBytes;
            self::$logKeysPerWorker = $workerKeys;
            self::$totalWeightWorkerKeysBytes = $workerBytes;
        } finally {
            $elapsed = microtime(true) - $t0;
            self::logAgentInfoForId(
                HilosAgentType::HILOS_LOGS,
                sprintf('hilos_logs refreshOverview duration_s=%.3f', $elapsed),
            );
        }
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
