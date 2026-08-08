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
     */
    public function __construct(private readonly ?string $logDirectory)
    {
    }

    /**
     * Build a reader over the daemon log root (the directory of `DAEMON_LOG_FILE`).
     *
     * A missing env value yields a reader whose {@see read()} returns an unavailable snapshot,
     * mirroring the overview page's former unavailable state rather than raising.
     *
     * @return self Reader bound to the configured log directory, or an unresolved reader
     */
    public static function fromEnv(): self
    {
        return new self(self::tryResolveLogRoot());
    }

    /**
     * Walk the log store once and return a classified snapshot.
     *
     * @return LogStoreSnapshot Live + archive index, or {@see LogStoreSnapshot::unavailable()} when
     *                          the log root is unresolved or the archive cannot be listed
     */
    public function read(): LogStoreSnapshot
    {
        if ($this->logDirectory === null) {
            return LogStoreSnapshot::unavailable();
        }

        $archivePath = $this->logDirectory . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME;
        $archiveBatches = [];
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
                $archiveBatches[$timestamp] = self::scanLogFilesInDirectory($full);
            }
        }

        return new LogStoreSnapshot(true, $archiveBatches, self::scanLogFilesInDirectory($this->logDirectory));
    }

    /**
     * Resolve the daemon log directory from {@see EnvConstants::DAEMON_LOG_FILE}.
     *
     * @return ?string Absolute log root path, or null when the env value is missing
     */
    private static function tryResolveLogRoot(): ?string
    {
        try {
            return dirname(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]);
        } catch (EnvException) {
            return null;
        }
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
     * Scan one directory for `*.log` files and classify by filename prefix.
     *
     * Order matters: `worker-monopolistic-` is tested before `worker-` before `agent-` so the
     * prefixes do not overlap incorrectly.
     *
     * @param string $dir Absolute directory path (log root or one archive batch folder)
     *
     * @return array{agent: array<string, int>, worker: array<string, int>, workerMonopolistic: array<string, int>}
     *         Basename → size in bytes (0 when the size could not be read)
     */
    private static function scanLogFilesInDirectory(string $dir): array
    {
        $agent = [];
        $worker = [];
        $workerMonopolistic = [];

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.log');
        if ($files === false) {
            return [
                self::CLASS_AGENT => [],
                self::CLASS_WORKER => [],
                self::CLASS_WORKER_MONOPOLISTIC => [],
            ];
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
            if (str_starts_with($name, self::PREFIX_WORKER_MONOPOLISTIC)) {
                $workerMonopolistic[$name] = $size;
            } elseif (str_starts_with($name, self::PREFIX_WORKER)) {
                $worker[$name] = $size;
            } elseif (str_starts_with($name, self::PREFIX_AGENT)) {
                $agent[$name] = $size;
            }
        }

        return [
            self::CLASS_AGENT => $agent,
            self::CLASS_WORKER => $worker,
            self::CLASS_WORKER_MONOPOLISTIC => $workerMonopolistic,
        ];
    }
}
