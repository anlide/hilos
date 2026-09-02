<?php

declare(strict_types=1);

namespace Hilos\Log;

use DateTimeImmutable;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LogRotationConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Helpers\FileSystemHelper;

/**
 * Stateless read service enumerating the daemon log store (files, keys, workers, batches) (HIL-383).
 *
 * Generalizes the private scanners formerly inside the Hilos logs overview page into one reusable
 * API so the overview page and the by-key / by-worker / by-batch drill-down pages
 * (HIL-385/386/387) share a single source of truth. Bound to a log directory (from the daemon log
 * path via {@see fromEnv()}, or an explicit directory for tests), it holds no state: every
 * {@see read()} does a fresh walk of the log root plus each `archive/{timestamp}/` batch dir and
 * classifies files by the `worker-monopolistic-` / `worker-` / `agent-` prefix. Caching and
 * throttling stay with the caller.
 *
 * Unavailability (missing env, unreadable archive) is carried in the returned {@see LogStoreSnapshot}
 * rather than thrown.
 */
final class LogStoreReader
{
    /** Index key for the daemon's own streams, matched by exact basename rather than by prefix. */
    public const string CLASS_DAEMON = 'daemon';

    /** Index key for `agent-*.log` streams. */
    public const string CLASS_AGENT = 'agent';

    /** Index key for `worker-*.log` streams (regular workers only). */
    public const string CLASS_WORKER = 'worker';

    /** Index key for `worker-monopolistic-*.log` streams. */
    public const string CLASS_WORKER_MONOPOLISTIC = 'workerMonopolistic';

    /** Filename prefix of a monopolistic worker log; checked before {@see PREFIX_WORKER}. */
    private const string PREFIX_WORKER_MONOPOLISTIC = 'worker-monopolistic-';

    /** Filename prefix of a regular worker log. */
    private const string PREFIX_WORKER = 'worker-';

    /** Filename prefix of an agent log. */
    private const string PREFIX_AGENT = 'agent-';

    /**
     * @param ?string $logDirectory Log root holding the live `*.log` files and the archive subtree, or null when it could not be resolved
     * @param list<string> $daemonBasenames Exact basenames of the daemon's own logs, classified as {@see self::CLASS_DAEMON}
     */
    public function __construct(
        private readonly ?string $logDirectory,
        private readonly array $daemonBasenames = [],
    ) {
    }

    /**
     * Build a reader over the daemon log root (the directory of `DAEMON_LOG_FILE`).
     *
     * The same env values also name the daemon's own streams: they carry no classifying prefix, so
     * the reader recognizes them by exact basename (HIL-753). A missing env value yields a reader
     * whose {@see read()} returns an unavailable snapshot, mirroring the overview page's former
     * unavailable state rather than raising.
     *
     * @return self Reader bound to the configured log directory, or an unresolved reader
     */
    public static function fromEnv(): self
    {
        try {
            $daemonLogFile = Hilos::$env[EnvConstants::DAEMON_LOG_FILE];
        } catch (EnvException) {
            return new self(null);
        }

        return new self(dirname($daemonLogFile), self::daemonBasenamesFromEnv($daemonLogFile));
    }

    /**
     * Basenames of the daemon's own streams, which carry no classifying prefix of their own.
     *
     * Each env value names two files, not one: what the Logger writes, and the raw stream beside
     * it that carries whatever PHP prints past the Logger (HIL-480). Both belong to the same
     * process and get the same {@see self::CLASS_DAEMON} — a class of their own would split one
     * daemon's output in two on every screen that reads this index.
     *
     * Each env value is read on its own: a store that resolves through `DAEMON_LOG_FILE` alone
     * stays readable, and an env missing the error stream loses that one class rather than the
     * whole walk.
     *
     * @param string $daemonLogFile Value of `DAEMON_LOG_FILE`, already resolved by the caller
     *
     * @return list<string> Two basenames per daemon stream the env names
     */
    private static function daemonBasenamesFromEnv(string $daemonLogFile): array
    {
        $basenames = [
            basename($daemonLogFile),
            basename(DaemonRawStream::pathFor($daemonLogFile)),
        ];
        try {
            $errorLogFile = Hilos::$env[EnvConstants::DAEMON_ERROR_LOG_FILE];
        } catch (EnvException) {
            return $basenames;
        }

        $basenames[] = basename($errorLogFile);
        $basenames[] = basename(DaemonRawStream::pathFor($errorLogFile));

        return $basenames;
    }

    /**
     * The log root this reader walks, which is also the address an operator is told to copy from.
     *
     * @return ?string Absolute log root, or null when the environment could not name one
     */
    public function logDirectory(): ?string
    {
        return $this->logDirectory;
    }

    /**
     * Walk the log store once and return a classified snapshot.
     *
     * Each batch directory is asked for its takeout marker as it is walked (HIL-483). The marker
     * is read here rather than counted with the files because it is not one of them: the glob
     * below takes `*.log` alone, so the confirmation reaches the snapshot without reaching any
     * file count or byte weight.
     *
     * @return LogStoreSnapshot Live + archive index, or {@see LogStoreSnapshot::unavailable()} when
     *                          the log root is unresolved or a directory cannot be listed
     */
    public function read(): LogStoreSnapshot
    {
        if ($this->logDirectory === null) {
            return LogStoreSnapshot::unavailable();
        }

        $archivePath = $this->logDirectory . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME;
        $archiveBatches = [];
        $batchTakenAt = [];
        if (is_dir($archivePath)) {
            $entries = FileSystemHelper::scandirOrFalse($archivePath);
            if ($entries === false) {
                return LogStoreSnapshot::unavailable();
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
                $timestamp = self::timestampDirNameToUnix($name);
                if ($timestamp === null) {
                    continue;
                }
                $batch = $this->scanLogFilesInDirectory($full);
                if ($batch === null) {
                    return LogStoreSnapshot::unavailable();
                }
                $archiveBatches[$timestamp] = $batch;
                $batchTakenAt[$timestamp] = LogBatchTakeoutMarker::read($full);
            }
        }

        $liveFiles = $this->scanLogFilesInDirectory($this->logDirectory);
        if ($liveFiles === null) {
            return LogStoreSnapshot::unavailable();
        }

        return new LogStoreSnapshot(true, $archiveBatches, $liveFiles, $batchTakenAt);
    }

    /**
     * Walk the log root alone, skipping the archive subtree.
     *
     * The cheap half of the split walk (HIL-753): live files change every second while a batch
     * changes only when rotation or cleanup runs, so a caller sampling often takes this and reaches
     * for {@see read()} rarely. Feed the result to {@see LogStoreSnapshot::withLiveFiles()}.
     *
     * @return ?array{daemon: array<string, int>, agent: array<string, int>, worker: array<string, int>, workerMonopolistic: array<string, int>}
     *         Classified basename → size in bytes, or null when the log root is unresolved or cannot be listed
     */
    public function readLiveFiles(): ?array
    {
        if ($this->logDirectory === null) {
            return null;
        }

        return $this->scanLogFilesInDirectory($this->logDirectory);
    }

    /**
     * Parse a rotation folder name into a Unix timestamp.
     *
     * @param string $name Directory basename matching {@see LogRotationConstants::TIMESTAMP_FORMAT}
     *
     * @return ?int Unix timestamp, or null when the name does not parse
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
     * Scan one directory for `*.log` files and classify them.
     *
     * Order matters twice over. The daemon basenames are tested first, by exact match: they
     * carry no prefix of their own, are configurable, and a catch-all "any other `*.log` is the
     * daemon" rule would swallow every stray file in the directory. The prefixes follow, with
     * `worker-monopolistic-` before `worker-` before `agent-` so they do not overlap incorrectly.
     *
     * @param string $dir Absolute directory path (log root or one archive batch folder)
     *
     * @return ?array{daemon: array<string, int>, agent: array<string, int>, worker: array<string, int>, workerMonopolistic: array<string, int>}
     *         Basename → size in bytes (0 when the size could not be read), or null when the directory cannot be listed
     */
    private function scanLogFilesInDirectory(string $dir): ?array
    {
        $daemon = [];
        $agent = [];
        $worker = [];
        $workerMonopolistic = [];

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.log');
        if ($files === false) {
            return null;
        }

        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            // warning-suppressed: a log rotated away between the listing and the read counts as 0 bytes
            $size = @filesize($path);
            if ($size === false) {
                $size = 0;
            }
            if (in_array($name, $this->daemonBasenames, true)) {
                $daemon[$name] = $size;
            } elseif (str_starts_with($name, self::PREFIX_WORKER_MONOPOLISTIC)) {
                $workerMonopolistic[$name] = $size;
            } elseif (str_starts_with($name, self::PREFIX_WORKER)) {
                $worker[$name] = $size;
            } elseif (str_starts_with($name, self::PREFIX_AGENT)) {
                $agent[$name] = $size;
            }
        }

        return [
            self::CLASS_DAEMON => $daemon,
            self::CLASS_AGENT => $agent,
            self::CLASS_WORKER => $worker,
            self::CLASS_WORKER_MONOPOLISTIC => $workerMonopolistic,
        ];
    }
}
