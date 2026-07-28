<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\LogRotationConstants;
use Hilos\Hilos;
use Hilos\Utils\Exception\LogRotationException;
use Hilos\Utils\Logger;

/**
 * Moves live daemon logs into the timestamped archive (HIL-379).
 *
 * The reusable rotation mechanics extracted from {@see \Hilos\Core\Daemon\DockerManager}: it
 * globs the `*.log` files under the log root, creates
 * `{@see LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME}/{@see LogRotationConstants::TIMESTAMP_FORMAT}/`,
 * and renames each live file there. Both the daemon bootstrap start path and the runtime
 * per-node {@see LogRotationAgent} call it, so rotation behaves identically whichever triggers
 * it. Bound to a log directory (from the daemon log path via {@see fromEnv()}, or an explicit
 * directory for tests) so it carries no global state.
 */
final class LogRotator
{
    /**
     * @param string $logDirectory Directory holding the live *.log files and the archive subtree
     */
    public function __construct(private readonly string $logDirectory)
    {
    }

    /**
     * Builds a rotator over the daemon log root (the directory of DAEMON_LOG_FILE).
     *
     * @return self Rotator bound to the configured log directory
     */
    public static function fromEnv(): self
    {
        return new self(dirname(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]));
    }

    /**
     * Summed size in bytes of the live *.log files, the input to the size trigger.
     *
     * Measures only the live files (not the archive), so it stays cheap enough for an onTick
     * check. Returns 0 when the directory is missing or holds no logs.
     *
     * @return int Total size in bytes of the live *.log files
     */
    public function liveLogBytes(): int
    {
        if (!is_dir($this->logDirectory)) {
            return 0;
        }

        $logFiles = glob($this->logDirectory . '/*.log');
        if ($logFiles === false || $logFiles === []) {
            return 0;
        }

        $total = 0;
        foreach ($logFiles as $logFile) {
            $size = filesize($logFile);
            if ($size !== false) {
                $total += $size;
            }
        }

        return $total;
    }

    /**
     * Rotates the live logs into a fresh timestamped archive batch.
     *
     * Creates `archive/{timestamp}/` under the log root and renames each live `*.log` file into
     * it. A no-op returning 0 when the directory is missing or holds no logs. Individual move
     * failures are logged and skipped; only directory-creation failures raise.
     *
     * @return int Number of log files moved into the archive
     * @throws LogRotationException If the archive or timestamp directory cannot be created
     */
    public function rotate(): int
    {
        if (!is_dir($this->logDirectory)) {
            return 0;
        }

        $logFiles = glob($this->logDirectory . '/*.log');
        if ($logFiles === false || $logFiles === []) {
            return 0;
        }

        $archiveDir = $this->logDirectory . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME;
        if (!is_dir($archiveDir)) {
            if (!mkdir($archiveDir, 0755, true)) {
                throw new LogRotationException("Cannot create archive directory: $archiveDir");
            }
        }

        $timestamp = date(LogRotationConstants::TIMESTAMP_FORMAT);
        $timestampDir = $archiveDir . DIRECTORY_SEPARATOR . $timestamp;
        if (!mkdir($timestampDir, 0755, true)) {
            throw new LogRotationException("Cannot create timestamp directory: $timestampDir");
        }

        $movedCount = 0;
        foreach ($logFiles as $logFile) {
            $targetPath = $timestampDir . DIRECTORY_SEPARATOR . basename($logFile);

            if (!rename($logFile, $targetPath)) {
                Logger::errorLog("Failed to move log file: $logFile to $targetPath");
                continue;
            }

            $movedCount++;
        }

        if ($movedCount > 0) {
            $archiveName = LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME;
            Logger::info("Log rotation: moved $movedCount log file(s) to {$archiveName}/$timestamp/");
        }

        return $movedCount;
    }
}
