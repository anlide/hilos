<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\LogRotationConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosLogsAgent;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Pages\Logs\DTO\HilosLogsOverviewSignalData;
use Hilos\Utils\Env;
use Hilos\Utils\Exception\MissingEnvironmentVariableException;
use Hilos\Utils\Helpers\FileSystemHelper;
use JsonException;
use Hilos\Core\Page\PageRouteParams;

/**
 * Abstract Hilos admin page: logs overview (rotation batch metrics under the daemon log archive).
 *
 * Scans `{@link LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME}` under `dirname(DAEMON_LOG_FILE)` for
 * subdirectories named with {@link LogRotationConstants::TIMESTAMP_FORMAT}, matching the layout produced
 * by DockerManager::rotateLogs() (same timestamp folder naming).
 *
 * Raw file weights are kept in private static structures (per-process). A full archive walk runs once per worker
 * process while {@see self::$logsOverviewMetricsInitialized} is false ({@see self::onSubscribe()}); further
 * subscribes and ticks use incremental rescans. Live updates are pushed while at least one subscriber is connected
 * ({@see self::onAgentTick()}), invoked from {@see AbstractHilosLogsAgent}.
 *
 * Projects register a concrete empty subclass in the page factory (wiring only).
 */
abstract class AbstractHilosLogsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS;

    /** @var array<string, true> WebSocket accept keys currently subscribed to this page */
    private static array $logsOverviewSubscribers = [];

    /** @var bool Whether scalar overview maps have been populated with a full archive + live scan in this worker. */
    private static bool $logsOverviewMetricsInitialized = false;

    /**
     * Last wall time ({@see microtime()}) when an incremental refresh ran; used for ~100ms throttle in
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
     * Per rotation batch: Unix timestamp of the folder → classified log basenames → file size in bytes.
     *
     * @var array<int, array{agent: array<string, int>, worker: array<string, int>, workerMonopolistic: array<string, int>}>
     */
    private static array $archiveLogWeightsByTimestamp = [];

    /**
     * Live `*.log` files in the daemon log root (not under archive), same classification shape as archive batches.
     *
     * @var array{agent: array<string, int>, worker: array<string, int>, workerMonopolistic: array<string, int>}
     */
    private static array $currentLiveLogWeights = [
        'agent' => [],
        'worker' => [],
        'workerMonopolistic' => [],
    ];

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

        self::refreshOverviewIncremental();

        $fp = self::overviewFingerprint();
        if ($fp === self::$lastOverviewFingerprint) {
            return;
        }

        self::$lastOverviewFingerprint = $fp;
        self::broadcastOverviewToSubscribers($agent);
    }

    /**
     * Register subscriber and send the current overview snapshot over the WebSocket.
     *
     * Full archive scan ({@see self::refreshOverviewFull()}) runs while {@see self::$logsOverviewMetricsInitialized}
     * is false (first subscribe in this worker process). Additional subscribers get {@see self::refreshOverviewIncremental()}
     * so the whole archive tree is not rescanned on every tab.
     *
     * @param string $acceptKey Target connection accept key
     * @param PageRouteParams $params Route parameters (unused for this page)
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->logAgentInfo("hilos_logs onSubscribe acceptKey={$acceptKey}");
        self::$logsOverviewSubscribers[$acceptKey] = true;
        if (!self::$logsOverviewMetricsInitialized) {
            self::refreshOverviewFull();
            self::$logsOverviewMetricsInitialized = true;
        } else {
            self::refreshOverviewIncremental();
        }
        self::$lastOverviewFingerprint = self::overviewFingerprint();

        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS,
            $acceptKey,
            self::buildLogsOverviewSignalData(),
        );
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
     * Full rescan of archive batches and live log directory; used on {@see self::onSubscribe()}.
     *
     * Logs wall duration with 0.001s precision via {@see self::logAgentInfoForId()}.
     */
    private static function refreshOverviewFull(): void
    {
        $t0 = microtime(true);
        try {
            $logRoot = self::tryResolveLogRoot();
            if ($logRoot === null) {
                self::setUnavailableState();

                return;
            }

            $archivePath = $logRoot . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME;
            self::$archiveLogWeightsByTimestamp = [];

            if (!is_dir($archivePath)) {
                self::$logsOverviewAvailable = true;
            } else {
                $entries = FileSystemHelper::scandirOrFalse($archivePath);
                if ($entries === false) {
                    self::setUnavailableState();

                    return;
                }
                foreach ($entries as $name) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }
                    $full = $archivePath . DIRECTORY_SEPARATOR . $name;
                    if (!is_dir($full)) {
                        continue;
                    }
                    if (preg_match(LogRotationConstants::TIMESTAMP_DIR_NAME_PATTERN, $name) !== 1) {
                        continue;
                    }
                    $ts = self::timestampDirNameToUnix($name);
                    if ($ts === null) {
                        continue;
                    }
                    self::$archiveLogWeightsByTimestamp[$ts] = self::scanLogFilesInDirectory($full);
                }
                self::$logsOverviewAvailable = true;
            }

            self::$currentLiveLogWeights = self::scanLogFilesInDirectory($logRoot);
            self::updateRotationScalarsFromArchive();
            self::computeKeyAggregates();
        } finally {
            $elapsed = microtime(true) - $t0;
            self::logAgentInfoForId(
                HilosAgentType::HILOS_LOGS,
                sprintf('hilos_logs refreshOverviewFull duration_s=%.3f', $elapsed),
            );
        }
    }

    /**
     * Incremental rescan: always refresh live files; add/remove archive batch entries when folders appear or disappear.
     *
     * Logs wall duration with 0.001s precision via {@see self::logAgentInfoForId()}.
     */
    private static function refreshOverviewIncremental(): void
    {
        $t0 = microtime(true);
        try {
            $logRoot = self::tryResolveLogRoot();
            if ($logRoot === null) {
                self::setUnavailableState();

                return;
            }

            $archivePath = $logRoot . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME;

            if (!is_dir($archivePath)) {
                self::$archiveLogWeightsByTimestamp = [];
                self::$logsOverviewAvailable = true;
                self::$currentLiveLogWeights = self::scanLogFilesInDirectory($logRoot);
                self::updateRotationScalarsFromArchive();
                self::computeKeyAggregates();

                return;
            }

            $entries = FileSystemHelper::scandirOrFalse($archivePath);
            if ($entries === false) {
                self::setUnavailableState();

                return;
            }

            $seenTimestamps = [];
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $full = $archivePath . DIRECTORY_SEPARATOR . $name;
                if (!is_dir($full)) {
                    continue;
                }
                if (preg_match(LogRotationConstants::TIMESTAMP_DIR_NAME_PATTERN, $name) !== 1) {
                    continue;
                }
                $ts = self::timestampDirNameToUnix($name);
                if ($ts === null) {
                    continue;
                }
                $seenTimestamps[$ts] = true;
                if (!isset(self::$archiveLogWeightsByTimestamp[$ts])) {
                    self::$archiveLogWeightsByTimestamp[$ts] = self::scanLogFilesInDirectory($full);
                }
            }

            foreach (array_keys(self::$archiveLogWeightsByTimestamp) as $ts) {
                if (!isset($seenTimestamps[$ts])) {
                    unset(self::$archiveLogWeightsByTimestamp[$ts]);
                }
            }

            self::$logsOverviewAvailable = true;
            self::$currentLiveLogWeights = self::scanLogFilesInDirectory($logRoot);
            self::updateRotationScalarsFromArchive();
            self::computeKeyAggregates();
        } finally {
            $elapsed = microtime(true) - $t0;
            self::logAgentInfoForId(
                HilosAgentType::HILOS_LOGS,
                sprintf('hilos_logs refreshOverviewIncremental duration_s=%.3f', $elapsed),
            );
        }
    }

    /**
     * Resolve the daemon log directory from {@see EnvConstants::DAEMON_LOG_FILE}.
     *
     * @return ?string Absolute log root path, or null if env is missing
     */
    private static function tryResolveLogRoot(): ?string
    {
        try {
            $daemonLogFile = Env::get(EnvConstants::DAEMON_LOG_FILE);
        } catch (MissingEnvironmentVariableException) {
            return null;
        }

        return dirname($daemonLogFile);
    }

    /**
     * Reset overview scalars and maps when paths cannot be read or env is missing.
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
        self::$archiveLogWeightsByTimestamp = [];
        self::$currentLiveLogWeights = [
            'agent' => [],
            'worker' => [],
            'workerMonopolistic' => [],
        ];
    }

    /**
     * Parse a rotation folder name into a Unix timestamp.
     *
     * @param string $name Directory basename matching {@link LogRotationConstants::TIMESTAMP_FORMAT}
     *
     * @return ?int Unix timestamp, or null if the name does not parse
     */
    private static function timestampDirNameToUnix(string $name): ?int
    {
        $dt = DateTimeImmutable::createFromFormat(LogRotationConstants::TIMESTAMP_FORMAT, $name);
        if ($dt === false) {
            return null;
        }

        return $dt->getTimestamp();
    }

    /**
     * Scan one directory for `*.log` files and classify by filename prefix.
     *
     * Order: `worker-monopolistic-` before `worker-` before `agent-` so prefixes do not overlap incorrectly.
     *
     * @param string $dir Absolute directory path (log root or one archive batch folder)
     *
     * @return array{agent: array<string, int>, worker: array<string, int>, workerMonopolistic: array<string, int>}
     *         Basename → size in bytes (0 if stat failed)
     */
    private static function scanLogFilesInDirectory(string $dir): array
    {
        $agent = [];
        $worker = [];
        $workerMonopolistic = [];

        $pattern = $dir . DIRECTORY_SEPARATOR . '*.log';
        $files = glob($pattern);
        if ($files === false) {
            return [
                'agent' => [],
                'worker' => [],
                'workerMonopolistic' => [],
            ];
        }

        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            $size = @filesize($path);
            if ($size === false) {
                $size = 0;
            }
            if (str_starts_with($name, 'worker-monopolistic-')) {
                $workerMonopolistic[$name] = $size;
            } elseif (str_starts_with($name, 'worker-')) {
                $worker[$name] = $size;
            } elseif (str_starts_with($name, 'agent-')) {
                $agent[$name] = $size;
            }
        }

        return [
            'agent' => $agent,
            'worker' => $worker,
            'workerMonopolistic' => $workerMonopolistic,
        ];
    }

    /**
     * Set rotation count and last rotation ISO time from {@see self::$archiveLogWeightsByTimestamp} keys.
     */
    private static function updateRotationScalarsFromArchive(): void
    {
        $keys = array_keys(self::$archiveLogWeightsByTimestamp);
        sort($keys);
        $count = count($keys);
        self::$logsOverviewTotalRotationsAllTime = $count;

        if ($count === 0) {
            self::$logsOverviewLastRotationAt = null;

            return;
        }

        $maxTs = $keys[$count - 1];
        $dt = (new DateTimeImmutable())->setTimestamp($maxTs);
        self::$logsOverviewLastRotationAt = $dt->format(DateTimeInterface::ATOM);
    }

    /**
     * Compute distinct key counts and total byte weights for agent vs worker groups from archive + live maps.
     */
    private static function computeKeyAggregates(): void
    {
        if (!self::$logsOverviewAvailable) {
            self::$logKeysPerAgent = null;
            self::$totalWeightAgentKeysBytes = null;
            self::$logKeysPerWorker = null;
            self::$totalWeightWorkerKeysBytes = null;

            return;
        }

        $agentUnique = [];
        $agentTotal = 0;
        foreach (self::$archiveLogWeightsByTimestamp as $maps) {
            foreach ($maps['agent'] as $name => $sz) {
                $agentUnique[$name] = true;
                $agentTotal += $sz;
            }
        }
        foreach (self::$currentLiveLogWeights['agent'] as $name => $sz) {
            $agentUnique[$name] = true;
            $agentTotal += $sz;
        }
        self::$logKeysPerAgent = count($agentUnique);
        self::$totalWeightAgentKeysBytes = $agentTotal;

        $workerUnique = [];
        $workerTotal = 0;
        foreach (self::$archiveLogWeightsByTimestamp as $maps) {
            foreach ($maps['worker'] as $name => $sz) {
                $workerUnique[$name] = true;
                $workerTotal += $sz;
            }
            foreach ($maps['workerMonopolistic'] as $name => $sz) {
                $workerUnique[$name] = true;
                $workerTotal += $sz;
            }
        }
        foreach (self::$currentLiveLogWeights['worker'] as $name => $sz) {
            $workerUnique[$name] = true;
            $workerTotal += $sz;
        }
        foreach (self::$currentLiveLogWeights['workerMonopolistic'] as $name => $sz) {
            $workerUnique[$name] = true;
            $workerTotal += $sz;
        }
        self::$logKeysPerWorker = count($workerUnique);
        self::$totalWeightWorkerKeysBytes = $workerTotal;
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
