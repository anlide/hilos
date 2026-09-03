<?php

declare(strict_types=1);

namespace Hilos\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DockerManager;
use Hilos\Constants\LogRotationConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Exception\LogRotationException;

/**
 * Moves live daemon logs into a timestamped batch in the staging directory (HIL-379, HIL-870).
 *
 * The reusable rotation mechanics extracted from {@see DockerManager}: it
 * globs the `*.log` files under the log root, creates
 * `{@see LogRotationConstants::LOG_STAGING_SUBDIR_NAME}/{@see LogRotationConstants::TIMESTAMP_FORMAT}/`,
 * and renames each live file there. Both the daemon bootstrap start path and the runtime log store
 * owner call it, so rotation behaves identically whichever triggers it. Bound to a log directory
 * (from the daemon log path via the two factories, or an explicit directory for tests) so it
 * carries no global state.
 *
 * Rotation is a rename and nothing else, which is why the two callers do not share one factory
 * (HIL-480): the daemon's raw stdout and stderr are open descriptors on their inodes while the
 * node runs, so renaming those two files would send every fatal and warning into a closed batch.
 * The start path has no such files to spare — it runs before `proc_open`, when the previous daemon
 * is dead — so it takes everything, and only the runtime path keeps the raw pair.
 *
 * Both factories land the batch in the STAGING subdirectory rather than in the archive (HIL-870).
 * That is what makes the rename a rename whatever the archive is: staging sits inside the log root,
 * so it is on the device of the live files by construction, and an archive on another device costs
 * this moment nothing. Carrying the batch on from staging into the archive belongs to
 * {@see LogBatchCarrier}, which runs outside the moment of rotation.
 */
final class LogRotator
{
    /** Mode the rotator creates its batch directories with; unrelated to the modes other subsystems pick. */
    private const int BATCH_DIR_PERMISSIONS = 0755;

    /**
     * @param string $logDirectory Directory holding the live *.log files, the staging subtree and the archive
     * @param list<string> $keptBasenames Basenames rotation leaves live instead of moving
     */
    public function __construct(
        private readonly string $logDirectory,
        private readonly array $keptBasenames = [],
    ) {
    }

    /**
     * Rotator for the daemon start path, which moves every live log it finds.
     *
     * Nothing is kept back: this runs before the daemon is started, so no descriptor is open on
     * any of these files and the raw pair is replaced along with its holder.
     *
     * Unlike the two policies beside it (HIL-682) this read is not contained: the log path has no
     * axis to switch off, and a node that cannot name its log directory has nothing to rotate. It
     * never fires in a live system either — DAEMON_LOG_FILE is required, so the master's startup
     * gate stops the daemon long before a worker holds this rotator.
     *
     * @return self Rotator bound to the configured log directory
     * @throws EnvException When the daemon log path is missing, outside the catalog, or not a string
     */
    public static function forStartup(): self
    {
        return new self(dirname(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]));
    }

    /**
     * Rotator for the running node, which leaves the daemon's raw stdout and stderr live.
     *
     * @return self Rotator bound to the configured log directory, keeping the raw pair
     * @throws EnvException When either daemon log path is missing, outside the catalog, or not a string
     */
    public static function forRuntime(): self
    {
        $daemonLogFile = Hilos::$env[EnvConstants::DAEMON_LOG_FILE];

        return new self(dirname($daemonLogFile), [
            basename(DaemonRawStream::pathFor($daemonLogFile)),
            basename(DaemonRawStream::pathFor(Hilos::$env[EnvConstants::DAEMON_ERROR_LOG_FILE])),
        ]);
    }

    /**
     * @return string Directory holding the live *.log files this rotator moves
     */
    public function logDirectory(): string
    {
        return $this->logDirectory;
    }

    /**
     * @return string Archive subtree under the log root, whether or not it exists yet
     */
    public function archiveDirectory(): string
    {
        return $this->logDirectory . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME;
    }

    /**
     * Staging subtree this rotator makes its batches in, on the device of the live logs (HIL-870).
     *
     * @return string Staging subtree under the log root, whether or not it exists yet
     */
    public function stagingDirectory(): string
    {
        return $this->logDirectory . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_STAGING_SUBDIR_NAME;
    }

    /**
     * Names this rotator leaves live, so a caller weighing the directory can leave them out too.
     *
     * @return list<string> Basenames rotation does not move
     */
    public function keptBasenames(): array
    {
        return $this->keptBasenames;
    }

    /**
     * Rotates the live logs into a fresh timestamped batch in the staging directory.
     *
     * Creates `staging/{timestamp}/` under the log root and renames each live `*.log` file into
     * it, apart from the kept basenames. The batch directory is created only once there is
     * something to put in it, so a run with nothing to move leaves no empty folder for the carrier
     * to walk. Individual move failures are collected and skipped; only directory-creation
     * failures raise.
     *
     * @return LogRotationReport What was moved, where, and what stayed behind
     * @throws LogRotationException If the staging or timestamp directory cannot be created
     */
    public function rotate(): LogRotationReport
    {
        if (!is_dir($this->logDirectory)) {
            return LogRotationReport::nothingToRotate();
        }

        $logFiles = glob($this->logDirectory . '/*.log');
        if ($logFiles === false) {
            return LogRotationReport::nothingToRotate();
        }

        $logFiles = array_values(array_filter(
            $logFiles,
            fn(string $logFile): bool => !in_array(basename($logFile), $this->keptBasenames, true),
        ));
        if ($logFiles === []) {
            return LogRotationReport::nothingToRotate();
        }

        $stagingDir = $this->stagingDirectory();
        if (!is_dir($stagingDir)) {
            if (!mkdir($stagingDir, self::BATCH_DIR_PERMISSIONS, true)) {
                throw new LogRotationException("Cannot create staging directory: $stagingDir");
            }
        }

        $timestamp = date(LogRotationConstants::TIMESTAMP_FORMAT);
        $timestampDir = $stagingDir . DIRECTORY_SEPARATOR . $timestamp;
        if (!mkdir($timestampDir, self::BATCH_DIR_PERMISSIONS, true)) {
            throw new LogRotationException("Cannot create timestamp directory: $timestampDir");
        }

        $movedCount = 0;
        $failedFiles = [];
        foreach ($logFiles as $logFile) {
            $targetPath = $timestampDir . DIRECTORY_SEPARATOR . basename($logFile);

            if (!rename($logFile, $targetPath)) {
                $failedFiles[] = $logFile;
                continue;
            }

            $movedCount++;
        }

        return new LogRotationReport($movedCount, $timestamp, $failedFiles);
    }
}
