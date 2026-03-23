<?php

declare(strict_types=1);

namespace Hilos\Pages\Logs;

use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\LogRotationConstants;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Pages\Logs\DTO\HilosLogsOverviewSignalData;
use Hilos\Utils\Env;
use Hilos\Utils\Exception\MissingEnvironmentVariableException;
use Hilos\Utils\Helpers\FileSystemHelper;

/**
 * Abstract Hilos admin page: logs overview (rotation batch metrics under the daemon log archive).
 *
 * Scans `{@link LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME}` under `dirname(DAEMON_LOG_FILE)` for
 * subdirectories named with {@link LogRotationConstants::TIMESTAMP_FORMAT}, matching the layout produced
 * by DockerManager::rotateLogs() (same timestamp folder naming).
 *
 * Metrics are computed once per PHP process and stored in private static scalars; each subscription
 * builds a fresh {@see HilosLogsOverviewSignalData} from those values (DTO is transport-only).
 *
 * Projects register a concrete empty subclass in the page factory (wiring only).
 */
abstract class AbstractHilosLogsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LOGS;

    /** @var bool Whether scalar overview fields have been computed in this process. */
    private static bool $logsOverviewMetricsInitialized = false;

    /** @var bool Whether archive metrics could be read (false if env missing, scandir failed, etc.). */
    private static bool $logsOverviewAvailable = false;

    /** @var ?int Total rotation batch folders; null when metrics are unavailable. */
    private static ?int $logsOverviewTotalRotationsAllTime = null;

    /** @var ?string Latest rotation time (ISO 8601); null if none or unavailable. */
    private static ?string $logsOverviewLastRotationAt = null;

    /**
     * Send logs overview payload to the subscribing WebSocket connection.
     *
     * @param string $acceptKey Target connection accept key
     * @param array<string, string> $params Route parameters (unused for this page)
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS,
            $acceptKey,
            self::createLogsOverviewSignalData(),
        );
    }

    /**
     * Build WebSocket subscription payload from cached scalars (new DTO instance on each call).
     *
     * @return HilosLogsOverviewSignalData Payload for {@see HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS}
     */
    private static function createLogsOverviewSignalData(): HilosLogsOverviewSignalData
    {
        self::computeLogsOverviewMetrics();

        return new HilosLogsOverviewSignalData(
            available: self::$logsOverviewAvailable,
            totalRotationsAllTime: self::$logsOverviewTotalRotationsAllTime,
            lastRotationAt: self::$logsOverviewLastRotationAt,
        );
    }

    /**
     * Scan the log archive directory and set static overview fields (runs once per process).
     *
     * TODO: Invalidate or refresh if manual changes under the archive tree must be visible without
     * restarting the PHP worker (e.g. admin-triggered rescan or TTL).
     */
    private static function computeLogsOverviewMetrics(): void
    {
        if (self::$logsOverviewMetricsInitialized) {
            return;
        }

        self::$logsOverviewMetricsInitialized = true;

        try {
            $daemonLogFile = Env::get(EnvConstants::DAEMON_LOG_FILE);
        } catch (MissingEnvironmentVariableException) {
            self::$logsOverviewAvailable = false;
            self::$logsOverviewTotalRotationsAllTime = null;
            self::$logsOverviewLastRotationAt = null;
            return;
        }

        $logRoot = dirname($daemonLogFile);
        $archivePath = $logRoot . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME;

        if (!is_dir($archivePath)) {
            self::$logsOverviewAvailable = true;
            self::$logsOverviewTotalRotationsAllTime = 0;
            self::$logsOverviewLastRotationAt = null;
            return;
        }

        $entries = FileSystemHelper::scandirOrFalse($archivePath);
        if ($entries === false) {
            self::$logsOverviewAvailable = false;
            self::$logsOverviewTotalRotationsAllTime = null;
            self::$logsOverviewLastRotationAt = null;
            return;
        }

        $rotationNames = [];
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
            $rotationNames[] = $name;
        }

        sort($rotationNames);
        $count = count($rotationNames);
        $lastRotationAt = null;
        if ($count > 0) {
            $maxName = $rotationNames[$count - 1];
            $dt = DateTimeImmutable::createFromFormat(LogRotationConstants::TIMESTAMP_FORMAT, $maxName);
            if ($dt !== false) {
                $lastRotationAt = $dt->format(DateTimeInterface::ATOM);
            }
        }

        self::$logsOverviewAvailable = true;
        self::$logsOverviewTotalRotationsAllTime = $count;
        self::$logsOverviewLastRotationAt = $lastRotationAt;
    }
}
