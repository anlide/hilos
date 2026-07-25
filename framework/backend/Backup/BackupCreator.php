<?php

declare(strict_types=1);

namespace Hilos\Backup;

use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Backup\Exception\BackupDumpFailedException;
use Hilos\Backup\Exception\BackupException;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Process;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Database\Migration;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\BackupHistory;
use Throwable;

/**
 * BackupCreator - the backup create engine that runs inside the short-lived child.
 *
 * The monopoly {@see Agent\BackupAgent} supervisor spawns a `backup:run` CLI child
 * (HIL-270 supervisor step) that delegates here. All blocking work — one mysqldump
 * per configured connection, then the archive and sidecar write — happens here, off
 * the daemon event loop. The run is all-or-nothing: any failing step aborts the whole
 * backup, cleans the partial temp, and throws; no partial archive is ever published.
 * On success it publishes `<root>/<scope>/<id>-<env>-<scope>.tar.gz` and its
 * `.json` sidecar atomically (temp path in the same directory, then rename), so the
 * read path never scans a half-written archive.
 *
 * The dumper is hardcoded to mysqldump inline (the project targets MySQL only); the
 * per-connection dump is isolated in {@see dumpConnection()} as the seam a future
 * per-driver dumper abstraction would replace (deferred until a non-MySQL connection
 * appears).
 *
 * The engine also carries the failure bookkeeping the supervisor delegates to when a run
 * ends badly ({@see recordFailure()}): a child killed on timeout cannot clean up or write
 * its own sidecar, so the supervisor reuses this class's storage-layout knowledge to sweep
 * the partial temp and publish an error sidecar in its place.
 */
final class BackupCreator
{
    /** Dumper binary; inline hardcode is the documented seam for a future dumper abstraction. */
    private const string MYSQLDUMP_BIN = 'mysqldump';

    /** Archiver binary producing the gzip tarball. */
    private const string TAR_BIN = 'tar';

    /** Poll interval while blocking on a child dump/archive process, microseconds. */
    private const int POLL_INTERVAL_US = 50_000;

    /** Allowed backup id characters; the id is a filesystem base name, so no separators. */
    private const string ID_PATTERN = '/^[A-Za-z0-9._-]+$/';

    /** Clock format the supervisor mints a backup id from; read back to date the backup. */
    private const string ID_TIME_FORMAT = 'Y-m-d_H-i-s';

    private const string SQL_FILE_PREFIX = 'db-';
    private const string SQL_FILE_SUFFIX = '.sql';
    private const string METADATA_FILENAME = 'metadata.json';
    private const string ARCHIVE_EXTENSION = '.tar.gz';
    private const string SIDECAR_EXTENSION = '.json';

    /** Recorded when schema-seed runs but the reference registry declares no tables for any connection. */
    private const string WARNING_EMPTY_REFERENCE_REGISTRY =
        'schema-seed found no reference tables: captured schema only, seed data is empty';

    /**
     * Creates one backup: dumps every configured connection, archives, and publishes atomically.
     *
     * @param string $id Backup id and archive/sidecar base name (a filesystem-safe stem)
     * @param BackupScope $scope What to capture; also the storage subdirectory
     * @return BackupMetadata The published sidecar metadata
     * @throws BackupException When the id or backup directory is invalid
     * @throws BackupDumpFailedException When a dump, archive, or publish step fails
     */
    public function create(string $id, BackupScope $scope): BackupMetadata
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new BackupException("Invalid backup id: {$id}");
        }

        $root = Hilos::$env->string(EnvConstants::BACKUP_DIR);
        if ($root === '') {
            throw new BackupException('Backup directory (BACKUP_DIR) is not configured');
        }

        $scopeDir = $root . '/' . $scope->value;
        $this->ensureDirectory($scopeDir);

        $base = self::archiveBaseName($id, Hilos::$env->string(EnvConstants::APP_ENV), $scope);
        $tmpStem = $scopeDir . '/.tmp-' . $base . '-' . getmypid();
        $workDir = $tmpStem . '.work';
        $tmpArchive = $tmpStem . self::ARCHIVE_EXTENSION;
        $tmpSidecar = $tmpStem . self::SIDECAR_EXTENSION;

        $startedAt = microtime(true);

        $references = BackupReferenceRegistry::fromCatalog();

        try {
            $this->ensureDirectory($workDir);

            $connections = $this->dumpAllConnections($scope, $workDir, $references);
            $warnings = $this->collectWarnings($scope, $references);

            // In-archive metadata copy (size is only known after the archive exists).
            $inArchiveMeta = $this->buildMetadata($id, $scope, $connections, 0, 0, warnings: $warnings);
            $this->writeJson($workDir . '/' . self::METADATA_FILENAME, $inArchiveMeta->toArray());

            $this->archive($workDir, $tmpArchive);

            $sizeBytes = $this->fileSize($tmpArchive);
            $durationSeconds = (int)round(microtime(true) - $startedAt);
            $metadata = $this->buildMetadata($id, $scope, $connections, $sizeBytes, $durationSeconds, warnings: $warnings);

            $this->writeJson($tmpSidecar, $metadata->toArray());

            // Publish archive before sidecar: the read path treats a sidecar without an
            // archive as an anomaly, never the reverse, so this order is never half-valid.
            $this->publish($tmpArchive, $scopeDir . '/' . $base . self::ARCHIVE_EXTENSION);
            $this->publish($tmpSidecar, $scopeDir . '/' . $base . self::SIDECAR_EXTENSION);

            $this->removeDirectory($workDir);

            return $metadata;
        } catch (BackupException $e) {
            $this->cleanupTemp($workDir, $tmpArchive, $tmpSidecar);
            throw $e;
        } catch (Throwable $e) {
            $this->cleanupTemp($workDir, $tmpArchive, $tmpSidecar);
            throw new BackupDumpFailedException('Backup create failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Records a failed run: sweeps any partial temp and publishes an error sidecar.
     *
     * Called by the supervisor, not the child. A child that fails gracefully already
     * cleaned its own temp in {@see create()}, but a child killed on timeout could not,
     * so this sweeps leftover temp artifacts by their id-scoped prefix. The published
     * error sidecar (status=error, no archive) lets the read path index the failed attempt
     * (files=truth), and its rotation is handled by HIL-272.
     *
     * @param string $id Backup id and sidecar base name (a filesystem-safe stem)
     * @param BackupScope $scope Scope of the failed run; also its storage subdirectory
     * @param int $durationSeconds Wall-clock time the run consumed before failing
     * @throws BackupException When the id or backup directory is invalid
     * @throws BackupDumpFailedException When the error sidecar cannot be written
     */
    public function recordFailure(string $id, BackupScope $scope, int $durationSeconds): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new BackupException("Invalid backup id: {$id}");
        }

        $root = Hilos::$env->string(EnvConstants::BACKUP_DIR);
        if ($root === '') {
            throw new BackupException('Backup directory (BACKUP_DIR) is not configured');
        }

        $scopeDir = $root . '/' . $scope->value;
        $this->ensureDirectory($scopeDir);

        $base = self::archiveBaseName($id, Hilos::$env->string(EnvConstants::APP_ENV), $scope);
        $this->sweepPartialTemp($scopeDir, $base);

        $metadata = $this->buildMetadata($id, $scope, [], 0, $durationSeconds, BackupStatus::ERROR);
        $tmpSidecar = $scopeDir . '/.tmp-err-' . $base . '-' . getmypid() . self::SIDECAR_EXTENSION;
        $this->writeJson($tmpSidecar, $metadata->toArray());
        $this->publish($tmpSidecar, $scopeDir . '/' . $base . self::SIDECAR_EXTENSION);
    }

    /**
     * Rewrites a stored backup's keep pin in place, atomically (HIL-333 toggle-keep).
     *
     * The sidecar is the source of truth for the keep flag (files=truth): this reads it,
     * rewrites only its keep field (every other field is preserved), and republishes it
     * over the same temp→fsync→rename idiom {@see create()} uses, so a reader never sees a
     * partial write. A no-change target is a no-op. The caller (the monopoly agent) re-mirrors
     * the runtime index from the rewritten sidecar afterwards.
     *
     * @param BackupHistory $row Index row identifying the backup (its id, env, and scope)
     * @param string $root Backup storage root
     * @param bool $keep Desired keep pin
     * @throws BackupException When the scope, root, or stored sidecar is invalid or missing
     * @throws BackupDumpFailedException When the rewritten sidecar cannot be published
     */
    public function setStoredKeep(BackupHistory $row, string $root, bool $keep): void
    {
        $scope = BackupScope::fromString($row->scope);
        if ($scope === null) {
            throw new BackupException("Invalid backup scope: {$row->scope}");
        }
        if ($root === '') {
            throw new BackupException('Backup directory (BACKUP_DIR) is not configured');
        }

        $scopeDir = $root . '/' . $scope->value;
        $base = self::archiveBaseName($row->getId(), $row->env, $scope);
        $sidecarPath = $scopeDir . '/' . $base . self::SIDECAR_EXTENSION;

        $metadata = $this->readSidecar($sidecarPath);
        if ($metadata->keep === $keep) {
            return;
        }

        $rewritten = new BackupMetadata(
            $metadata->id,
            $metadata->createdAt,
            $metadata->env,
            $metadata->scope,
            $metadata->connections,
            $metadata->sizeBytes,
            $metadata->durationSeconds,
            $keep,
            $metadata->status,
            $metadata->warnings,
        );

        $tmpSidecar = $scopeDir . '/.tmp-keep-' . $base . '-' . getmypid() . self::SIDECAR_EXTENSION;
        $this->writeJson($tmpSidecar, $rewritten->toArray());
        $this->publish($tmpSidecar, $sidecarPath);
    }

    /**
     * Reads and decodes one backup sidecar into its metadata.
     *
     * @param string $sidecarPath Absolute sidecar path
     * @return BackupMetadata Decoded sidecar metadata
     * @throws BackupException When the sidecar is missing or not valid JSON
     */
    private function readSidecar(string $sidecarPath): BackupMetadata
    {
        $raw = @file_get_contents($sidecarPath);
        if ($raw === false) {
            throw new BackupException("Backup sidecar not found: {$sidecarPath}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new BackupException("Backup sidecar is not valid JSON: {$sidecarPath}");
        }

        return BackupMetadata::fromArray($decoded);
    }

    /**
     * Builds the archive/sidecar base name `<id>-<env>-<scope>`.
     *
     * @param string $id Backup id
     * @param string $env Application environment
     * @param BackupScope $scope Backup scope
     * @return string Base name without extension
     */
    public static function archiveBaseName(string $id, string $env, BackupScope $scope): string
    {
        return $id . '-' . $env . '-' . $scope->value;
    }

    /**
     * Maps a scope to the ordered mysqldump passes for one connection.
     *
     * FULL is one pass with no restricting flags; SCHEMA_ONLY is one `--no-data` pass;
     * SCHEMA_SEED is a `--no-data` schema pass followed by a `--no-create-info` data
     * pass restricted to the reference tables (appended to the same file). The second
     * pass is omitted when no reference tables are given, so an unpopulated reference
     * registry makes SCHEMA_SEED equivalent to SCHEMA_ONLY.
     *
     * @param BackupScope $scope Backup scope
     * @param list<string> $referenceTables Seed/reference tables whose rows SCHEMA_SEED keeps
     * @return list<array{flags: list<string>, tables: list<string>, append: bool}> Ordered passes
     */
    public static function scopeDumpPasses(BackupScope $scope, array $referenceTables): array
    {
        return match ($scope) {
            BackupScope::FULL => [
                ['flags' => [], 'tables' => [], 'append' => false],
            ],
            BackupScope::SCHEMA_ONLY => [
                ['flags' => ['--no-data'], 'tables' => [], 'append' => false],
            ],
            BackupScope::SCHEMA_SEED => $referenceTables === []
                ? [['flags' => ['--no-data'], 'tables' => [], 'append' => false]]
                : [
                    ['flags' => ['--no-data'], 'tables' => [], 'append' => false],
                    ['flags' => ['--no-create-info'], 'tables' => $referenceTables, 'append' => true],
                ],
        };
    }

    /**
     * Renders a mysqldump defaults-extra-file so credentials never appear in argv.
     *
     * @param DatabaseConnectionConfig $config Connection settings
     * @return string INI text for `--defaults-extra-file`
     */
    public static function renderDefaultsIni(DatabaseConnectionConfig $config): string
    {
        $lines = [
            '[mysqldump]',
            'host = ' . self::iniQuote($config->host),
            'port = ' . $config->port,
            'user = ' . self::iniQuote($config->user),
            'password = ' . self::iniQuote($config->password),
        ];
        if ($config->socket !== null && $config->socket !== '') {
            $lines[] = 'socket = ' . self::iniQuote($config->socket);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Dumps every configured connection into `db-<index>.sql` files under the work dir.
     *
     * Each connection's schema-seed data pass is restricted to the reference tables the
     * registry declares for that connection index.
     *
     * @param BackupScope $scope Backup scope
     * @param string $workDir Temp working directory receiving the sql files
     * @param BackupReferenceRegistry $references Reference-table registry queried per connection
     * @return list<BackupConnectionMeta> Captured connection metadata
     * @throws BackupDumpFailedException When a connection cannot be dumped
     * @throws BackupException When a declared reference class cannot be resolved to a table
     */
    private function dumpAllConnections(BackupScope $scope, string $workDir, BackupReferenceRegistry $references): array
    {
        $connections = [];
        foreach (Database::getConfiguredIndices() as $index) {
            Database::useConnection($index);
            if (!Database::isConnected($index)) {
                Database::connect($index);
            }

            $config = Database::getConnectionConfig($index);
            $sqlPath = $workDir . '/' . self::SQL_FILE_PREFIX . $index . self::SQL_FILE_SUFFIX;

            $this->dumpConnection($scope, $config, $sqlPath, $references->tablesForConnection($index));

            $connections[] = new BackupConnectionMeta($index, $config->database, Migration::getCurrentIndex());
        }

        return $connections;
    }

    /**
     * Records the non-fatal warnings a run produces.
     *
     * The one warning today: a schema-seed run whose reference registry declares no tables
     * for any configured connection. The dump still succeeds as an effective schema-only
     * capture, but silently losing seed data is unacceptable, so it is surfaced in the
     * sidecar and the child's stderr rather than failing the run.
     *
     * @param BackupScope $scope Backup scope
     * @param BackupReferenceRegistry $references Reference-table registry
     * @return list<string> Warnings for the run
     * @throws BackupException When a declared reference class cannot be resolved to a table
     */
    private function collectWarnings(BackupScope $scope, BackupReferenceRegistry $references): array
    {
        if ($scope !== BackupScope::SCHEMA_SEED) {
            return [];
        }

        foreach (Database::getConfiguredIndices() as $index) {
            if ($references->tablesForConnection($index) !== []) {
                return [];
            }
        }

        return [self::WARNING_EMPTY_REFERENCE_REGISTRY];
    }

    /**
     * Runs the scope's mysqldump passes for one connection into a single sql file.
     *
     * This is the per-driver seam: a future dumper abstraction would replace the body
     * while the caller contract (scope + config + target file) stays the same.
     *
     * @param BackupScope $scope Backup scope
     * @param DatabaseConnectionConfig $config Connection settings
     * @param string $sqlPath Output sql file for this connection
     * @param list<string> $referenceTables Reference tables this connection keeps under schema-seed
     * @throws BackupDumpFailedException When a mysqldump pass exits non-zero
     */
    private function dumpConnection(
        BackupScope $scope,
        DatabaseConnectionConfig $config,
        string $sqlPath,
        array $referenceTables,
    ): void {
        $iniPath = $this->writeDefaultsIni($config);

        try {
            foreach (self::scopeDumpPasses($scope, $referenceTables) as $pass) {
                $params = array_merge(
                    ['--defaults-extra-file=' . $iniPath, '--single-transaction', '--no-tablespaces'],
                    $pass['flags'],
                    [$config->database],
                    $pass['tables'],
                );
                $this->runToFile(
                    self::MYSQLDUMP_BIN,
                    $params,
                    $sqlPath,
                    $pass['append'] ? Process::PIPE_APPEND : Process::PIPE_WRITE,
                );
            }
        } finally {
            @unlink($iniPath);
        }
    }

    /**
     * Archives the work directory contents into a gzip tarball.
     *
     * @param string $workDir Directory whose contents are archived
     * @param string $tarPath Output archive path
     * @throws BackupDumpFailedException When tar exits non-zero
     */
    private function archive(string $workDir, string $tarPath): void
    {
        // -C keeps the archive rooted at the backup contents, not the temp path.
        $this->runToFile(self::TAR_BIN, ['-czf', $tarPath, '-C', $workDir, '.'], null, Process::PIPE_WRITE);
    }

    /**
     * Runs a child process to completion, optionally redirecting stdout to a file.
     *
     * Blocking is intentional and safe: this runs in the short-lived backup child, not
     * the daemon event loop.
     *
     * @param string $bin Executable
     * @param list<string> $params argv passed verbatim (no shell)
     * @param ?string $outPath File to receive stdout, or null to discard via a pipe
     * @param string $outMode File open mode when $outPath is given
     * @throws BackupDumpFailedException When the process cannot run or exits non-zero
     */
    private function runToFile(string $bin, array $params, ?string $outPath, string $outMode): void
    {
        $stdOut = $outPath === null
            ? [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE]
            : [Process::DESCRIPTOR_FILE, $outPath, $outMode];

        try {
            $process = new Process(
                $bin,
                $params,
                null,
                [Process::DESCRIPTOR_PIPE, Process::PIPE_READ],
                $stdOut,
                [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE],
            );

            do {
                $process->tick();
                if ($process->getStatus()[Process::STATUS_RUNNING]) {
                    usleep(self::POLL_INTERVAL_US);
                }
            } while ($process->getStatus()[Process::STATUS_RUNNING]);

            $process->halt();
            $exitCode = $process->getExitCode();
            $stderr = $process->getStdErr();
        } catch (BackupDumpFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BackupDumpFailedException("Failed to run {$bin}: " . $e->getMessage(), 0, $e);
        }

        if ($exitCode !== 0) {
            throw new BackupDumpFailedException(
                sprintf('%s exited with code %s: %s', $bin, $exitCode ?? 'unknown', trim($stderr)),
            );
        }
    }

    /**
     * Assembles the sidecar metadata for a run.
     *
     * @param string $id Backup id
     * @param BackupScope $scope Backup scope
     * @param list<BackupConnectionMeta> $connections Captured connections
     * @param int $sizeBytes Archive size in bytes (0 for the in-archive copy)
     * @param int $durationSeconds Wall-clock capture duration
     * @param BackupStatus $status Terminal outcome recorded in the sidecar
     * @param list<string> $warnings Non-fatal warnings recorded in the sidecar
     * @return BackupMetadata Assembled metadata
     */
    private function buildMetadata(
        string $id,
        BackupScope $scope,
        array $connections,
        int $sizeBytes,
        int $durationSeconds,
        BackupStatus $status = BackupStatus::SUCCESS,
        array $warnings = [],
    ): BackupMetadata {
        return new BackupMetadata(
            $id,
            self::startedAtFromId($id),
            Hilos::$env->string(EnvConstants::APP_ENV),
            $scope,
            $connections,
            $sizeBytes,
            $durationSeconds,
            false,
            $status,
            $warnings,
        );
    }

    /**
     * Reads a backup's start instant back out of its id.
     *
     * The id is minted from the clock when the run starts
     * ({@see \Hilos\Backup\Agent\BackupAgent::generateBackupId()}), so it already carries the
     * only timestamp a backup should be dated by. Reading the clock here instead would date the
     * backup by the moment the metadata is built - after the dump - and a twenty-minute dump
     * would then disagree with its own id by twenty minutes, on the record and in the list.
     *
     * A malformed id (never produced by the supervisor) falls back to now, so a backup is
     * always dated by something.
     *
     * @param string $id Backup id in the `Y-m-d_H-i-s` stem form
     * @return string ISO-8601 start instant
     */
    public static function startedAtFromId(string $id): string
    {
        $started = DateTimeImmutable::createFromFormat(self::ID_TIME_FORMAT, $id);

        return ($started === false ? new DateTimeImmutable() : $started)->format(DateTimeInterface::ATOM);
    }

    /**
     * Writes a 0600 defaults-extra-file for mysqldump and returns its path.
     *
     * @param DatabaseConnectionConfig $config Connection settings
     * @return string Path to the temporary INI file
     * @throws BackupDumpFailedException When the temp file cannot be created or written
     */
    private function writeDefaultsIni(DatabaseConnectionConfig $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hilos-backup-');
        if ($path === false) {
            throw new BackupDumpFailedException('Failed to create mysqldump defaults file');
        }
        if (!@chmod($path, 0600) || @file_put_contents($path, self::renderDefaultsIni($config)) === false) {
            @unlink($path);
            throw new BackupDumpFailedException('Failed to write mysqldump defaults file');
        }

        return $path;
    }

    /**
     * Writes a JSON file (pretty-printed, unescaped) or fails.
     *
     * @param string $path Target file
     * @param array<string, mixed> $data Payload
     * @throws BackupDumpFailedException When encoding or writing fails
     */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new BackupDumpFailedException("Failed to encode JSON for {$path}");
        }
        if (@file_put_contents($path, $json) === false) {
            throw new BackupDumpFailedException("Failed to write {$path}");
        }
    }

    /**
     * Fsyncs a finished file then renames it into place, so readers never see a partial write.
     *
     * @param string $tmpPath Source temp file on the same filesystem as the target
     * @param string $finalPath Final path
     * @throws BackupDumpFailedException When fsync or rename fails
     */
    private function publish(string $tmpPath, string $finalPath): void
    {
        $handle = @fopen($tmpPath, 'r');
        if ($handle !== false) {
            @fflush($handle);
            @fsync($handle);
            @fclose($handle);
        }
        if (!@rename($tmpPath, $finalPath)) {
            throw new BackupDumpFailedException("Failed to publish {$finalPath}");
        }
    }

    /**
     * Returns a file size or fails.
     *
     * @param string $path File path
     * @return int Size in bytes
     * @throws BackupDumpFailedException When the size cannot be read
     */
    private function fileSize(string $path): int
    {
        $size = @filesize($path);
        if ($size === false) {
            throw new BackupDumpFailedException("Failed to stat archive {$path}");
        }

        return $size;
    }

    /**
     * Creates a directory (recursively) if missing.
     *
     * @param string $path Directory path
     * @throws BackupException When the directory cannot be created
     */
    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new BackupException("Failed to create directory {$path}");
        }
    }

    /**
     * Removes any temp artifacts left by a failed or finished run (best effort).
     *
     * @param string $workDir Temp working directory
     * @param string $tmpArchive Temp archive path
     * @param string $tmpSidecar Temp sidecar path
     */
    private function cleanupTemp(string $workDir, string $tmpArchive, string $tmpSidecar): void
    {
        $this->removeDirectory($workDir);
        @unlink($tmpArchive);
        @unlink($tmpSidecar);
    }

    /**
     * Removes any temp artifacts a killed child left behind for a base name (best effort).
     *
     * The child names its temp working dir and temp archive/sidecar `.tmp-<base>-<pid>*`;
     * globbing that id-scoped prefix reclaims them without needing the dead child's pid.
     *
     * @param string $scopeDir Scope directory holding the temp artifacts
     * @param string $base Archive/sidecar base name whose temp artifacts are swept
     */
    private function sweepPartialTemp(string $scopeDir, string $base): void
    {
        foreach (glob($scopeDir . '/.tmp-' . $base . '-*') ?: [] as $path) {
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
    }

    /**
     * Recursively removes a directory (best effort).
     *
     * @param string $path Directory path
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }

    /**
     * Double-quotes and escapes a value for a MySQL option file.
     *
     * @param string $value Raw value
     * @return string Quoted value
     */
    private static function iniQuote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
