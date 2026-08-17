<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Exception\BackupDumpFailedException;
use Hilos\Backup\Exception\BackupException;
use Hilos\Backup\Ship\BackupShipperInterface;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Process;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Database\Migration;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\Exception\FilePermissionException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Runtime\View\Item\BackupHistory;
use Throwable;

/**
 * BackupCreator - the backup create engine that runs inside the short-lived child.
 *
 * The monopoly {@see BackupAgent} supervisor spawns a `backup:run` CLI child
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

    /**
     * Poll interval while blocking on a child dump/archive process, microseconds.
     * Public because it is the one child-process poll budget of the backup subsystem:
     * the restore engine blocks on its children under the same cadence.
     */
    public const int POLL_INTERVAL_US = 50_000;

    /**
     * Allowed backup id characters; the id is a filesystem base name, so no separators.
     * Public because the restore path validates the ids it is asked for against the
     * same contract this engine mints them under.
     */
    public const string ID_PATTERN = '/^[A-Za-z0-9._-]+$/';

    /** Clock format the supervisor mints a backup id from; read back to date the backup. */
    private const string ID_TIME_FORMAT = 'Y-m-d_H-i-s';

    // The storage layout vocabulary is public: the restore path ({@see BackupRestorer})
    // reads back exactly what this engine writes, and a private copy there would be a
    // second definition of the same on-disk contract, free to drift. The extensions
    // alias the scanner's pre-existing public pair rather than restating the literals:
    // one on-disk contract, one owner, however many readers.
    public const string SQL_FILE_PREFIX = 'db-';
    public const string SQL_FILE_SUFFIX = '.sql';
    public const string ARCHIVE_EXTENSION = BackupHistoryScanner::ARCHIVE_EXTENSION;
    public const string SIDECAR_EXTENSION = BackupHistoryScanner::SIDECAR_EXTENSION;

    private const string METADATA_FILENAME = 'metadata.json';

    /**
     * Prefix every unpublished artifact of this engine carries, work directories included.
     *
     * Public because it is read from outside the store as well: a mirror re-stating a scope
     * directory has to leave these behind ({@see BackupShipperInterface::mirrorCommand()}), and a
     * second spelling of the prefix over there is one waiting to drift away from this one.
     */
    public const string TEMP_PREFIX = '.tmp-';

    /** Temp-name discriminators for in-place sidecar rewrites; they keep concurrent rewrites apart. */
    private const string TEMP_KIND_KEEP = 'keep';
    private const string TEMP_KIND_VERIFY = 'verify';
    private const string TEMP_KIND_RESTORE = 'restore';
    private const string TEMP_KIND_SHIP = 'ship';

    /** Longest failure reason kept in an error sidecar; a killed dump's stderr can be a wall of text. */
    private const int FAILURE_REASON_LIMIT = 2000;

    /** Recorded when schema-seed runs but the reference registry declares no tables for any connection. */
    private const string WARNING_EMPTY_REFERENCE_REGISTRY =
        'schema-seed found no reference tables: captured schema only, seed data is empty';

    /** Recorded when the finished archive could not be hashed; the backup is published without a digest. */
    private const string WARNING_DIGEST_FAILED =
        'archive checksum could not be computed: the backup is stored without a sha256 digest';

    /** Recorded when there is not enough of the run's budget left to hash; the backup is published as is. */
    private const string WARNING_DIGEST_SKIPPED =
        'archive checksum skipped: not enough of the backup timeout was left to hash the archive';

    /**
     * Conservative floor for hashing throughput, bytes per second, used only to decide whether the
     * digest fits in what is left of the run's budget. Deliberately pessimistic (measured hardware
     * does 90-160 MB/s): over-estimating the cost costs a digest, under-estimating costs the backup.
     */
    private const int DIGEST_MIN_THROUGHPUT_BPS = 50 * 1024 * 1024;

    /** Seconds left untouched for the publish itself (fsync of a just-written archive is not free). */
    private const int DIGEST_BUDGET_MARGIN_SECONDS = 30;

    /**
     * Creates one backup: dumps every configured connection, archives, and publishes atomically.
     *
     * @param string $id Backup id and archive/sidecar base name (a filesystem-safe stem)
     * @param BackupScope $scope What to capture; also the storage subdirectory
     * @param ?Closure(BackupPhase): void $onPhase Observer told as each phase begins, in the form
     *     {@see BackupRestorer::restore()} uses; the child prints them for its supervisor, a caller
     *     with nobody to tell passes null
     * @return BackupMetadata The published sidecar metadata
     * @throws BackupException When the id or backup directory is invalid
     * @throws BackupDumpFailedException When a dump, archive, or publish step fails
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     */
    public function create(string $id, BackupScope $scope, ?Closure $onPhase = null): BackupMetadata
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
        $tmpStem = $scopeDir . '/' . self::TEMP_PREFIX . $base . '-' . getmypid();
        $workDir = $tmpStem . '.work';
        $tmpArchive = $tmpStem . self::ARCHIVE_EXTENSION;
        $tmpSidecar = $tmpStem . self::SIDECAR_EXTENSION;

        $startedAt = microtime(true);

        $references = BackupReferenceRegistry::fromCatalog();

        try {
            $this->ensureDirectory($workDir);

            if ($onPhase !== null) {
                $onPhase(BackupPhase::DUMPING);
            }
            $connections = $this->dumpAllConnections($scope, $workDir, $references);
            $warnings = $this->collectWarnings($scope, $references);

            // In-archive metadata copy (size is only known after the archive exists); its
            // dumpBytes stays 0, exactly as its sizeBytes does, so the in-archive copy is a
            // stable stub and the true numbers live only in the published sidecar.
            $inArchiveMeta = $this->buildMetadata($id, $scope, $connections, 0, 0, warnings: $warnings);
            $this->writeJson($workDir . '/' . self::METADATA_FILENAME, $inArchiveMeta->toArray());

            // The uncompressed dump peak (db-*.sql plus the in-archive metadata copy), measured
            // before the tar exists: the expanded dump and the growing archive coexist during
            // archiving, so this sum - not the archive size - is what the space guard sizes
            // future runs from (a multiplier on the archive size would systematically under-estimate).
            $dumpBytes = self::measureWorkDir($workDir);

            if ($onPhase !== null) {
                $onPhase(BackupPhase::ARCHIVING);
            }
            $this->archive($workDir, $tmpArchive);

            $sizeBytes = $this->fileSize($tmpArchive);

            // Hashed over the temp archive, before the sidecar is written: the digest then rides
            // the existing publish order (archive first, sidecar second), so there is no second
            // write pass and no window in which a published archive has no digest.
            //
            // But the hash is a full extra read of a possibly multi-gigabyte file, and it is spent
            // inside the same wall-clock budget the supervisor kills the child by. A run that used
            // to finish just inside that budget would now be killed mid-hash - and a killed child
            // loses the FINISHED archive, because the supervisor sweeps its temp files. So the
            // digest yields to the backup: no room, no digest, and the archive still gets published.
            if ($onPhase !== null) {
                $onPhase(BackupPhase::DIGESTING);
            }
            $sha256 = null;
            if (!$this->digestFitsBudget($sizeBytes, $startedAt)) {
                $warnings[] = self::WARNING_DIGEST_SKIPPED;
            } else {
                $sha256 = hash_file(BackupVerifier::DIGEST_ALGO, $tmpArchive);
                if ($sha256 === false) {
                    // A backup that exists without a digest beats no backup at all, so the failure
                    // is recorded as a warning rather than aborting an otherwise complete run.
                    $warnings[] = self::WARNING_DIGEST_FAILED;
                    $sha256 = null;
                }
            }

            $durationSeconds = (int)round(microtime(true) - $startedAt);
            $metadata = $this->buildMetadata(
                $id,
                $scope,
                $connections,
                $sizeBytes,
                $durationSeconds,
                warnings: $warnings,
                dumpBytes: $dumpBytes,
                sha256: $sha256,
            );

            $this->writeJson($tmpSidecar, $metadata->toArray());

            if ($onPhase !== null) {
                $onPhase(BackupPhase::PUBLISHING);
            }
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
     * Whether hashing the finished archive still fits in what is left of the run's budget.
     *
     * The supervisor kills the child by wall clock ({@see EnvConstants::BACKUP_TIMEOUT}) and then
     * sweeps its temp files, so overrunning does not cost a digest - it costs the whole backup.
     * The estimate is deliberately pessimistic and the answer is yes whenever the budget cannot be
     * read at all, which keeps the previous behaviour on an installation that does not declare it.
     *
     * @param int $sizeBytes Size of the archive to hash
     * @param float $startedAt Microtime the run started
     * @return bool True when the digest is worth attempting
     */
    private function digestFitsBudget(int $sizeBytes, float $startedAt): bool
    {
        try {
            $budgetSeconds = Hilos::$env->int(EnvConstants::BACKUP_TIMEOUT);
        } catch (EnvException) {
            return true;
        }
        if ($budgetSeconds <= 0) {
            return true;
        }

        $remaining = $budgetSeconds - (microtime(true) - $startedAt);
        $estimate = $sizeBytes / self::DIGEST_MIN_THROUGHPUT_BPS;

        return $remaining - self::DIGEST_BUDGET_MARGIN_SECONDS > $estimate;
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
     * @param ?string $failureReason Why the run failed; trimmed, capped, and empty-to-null before storage
     * @throws BackupException When the id or backup directory is invalid
     * @throws BackupDumpFailedException When the error sidecar cannot be written
     * @throws EnvException When a backup env value is missing or cannot be read as its type
     */
    public function recordFailure(string $id, BackupScope $scope, int $durationSeconds, ?string $failureReason): void
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

        $metadata = $this->buildMetadata(
            $id,
            $scope,
            [],
            0,
            $durationSeconds,
            BackupStatus::ERROR,
            failureReason: $this->capFailureReason($failureReason),
        );
        $tmpSidecar = $scopeDir . '/' . self::TEMP_PREFIX . 'err-' . $base . '-' . getmypid() . self::SIDECAR_EXTENSION;
        $this->writeJson($tmpSidecar, $metadata->toArray());
        $this->publish($tmpSidecar, $scopeDir . '/' . $base . self::SIDECAR_EXTENSION);
    }

    /**
     * Trims a raw failure reason, caps it, and collapses an empty result to null.
     *
     * The reason carries a killed child's stderr, which can be a wall of text, so the tail is
     * cut past the cap with an ellipsis (the useful part is at the front, per the
     * NotificationDeliveries::recordFailure precedent). A blank reason is stored as null, so a
     * reader tells "no detail" from an empty string.
     *
     * @param ?string $failureReason Raw failure reason
     * @return ?string Capped reason, or null when there is nothing to record
     */
    private function capFailureReason(?string $failureReason): ?string
    {
        $trimmed = trim((string)$failureReason);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > self::FAILURE_REASON_LIMIT) {
            return mb_substr($trimmed, 0, self::FAILURE_REASON_LIMIT - 1) . '…';
        }

        return $trimmed;
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
        $sidecarPath = $this->storedSidecarPath($row->getId(), $row->env, $row->scope, $root);

        $metadata = $this->readSidecar($sidecarPath);
        if ($metadata->keep === $keep) {
            return;
        }

        $this->republishSidecar($sidecarPath, self::TEMP_KIND_KEEP, $metadata->withKeep($keep));
    }

    /**
     * Records the outcome of a verification run in the stored sidecar, atomically (HIL-435).
     *
     * Verification leaves a trace so the admin list can show when an archive was last checked
     * and how it went. The rewrite reuses the same temp→fsync→rename idiom as
     * {@see setStoredKeep()}, over the sidecar that is the source of truth (files=truth); the
     * caller refreshes the runtime index afterwards. A concurrent keep-pin rewrite is
     * last-write-wins, which is accepted: both fields are re-read from the same file on the
     * next rescan.
     *
     * The caller names the backup with the sidecar it just read rather than an index row: the
     * verify CLI works straight off storage and has no runtime index to look one up in. The file
     * is re-read here all the same, so a keep pin written between the check and the stamp survives.
     *
     * @param BackupMetadata $checked Sidecar that was checked, naming the backup (its id, env, and scope)
     * @param string $root Backup storage root
     * @param BackupVerifyOutcome $outcome What the verification concluded (ok or mismatch)
     * @param string $verifiedAt ISO-8601 instant the verification ran
     * @throws BackupException When the scope, root, or stored sidecar is invalid or missing
     * @throws BackupDumpFailedException When the rewritten sidecar cannot be published
     */
    public function recordVerification(
        BackupMetadata $checked,
        string $root,
        BackupVerifyOutcome $outcome,
        string $verifiedAt,
    ): void {
        $sidecarPath = $this->storedSidecarPath($checked->id, $checked->env, $checked->scope->value, $root);
        $metadata = $this->readSidecar($sidecarPath);

        $this->republishSidecar(
            $sidecarPath,
            self::TEMP_KIND_VERIFY,
            $metadata->withVerification($verifiedAt, $outcome),
        );
    }

    /**
     * Records the outcome of a copy off this machine in the stored sidecar, atomically (HIL-431).
     *
     * Written the same way {@see recordVerification()} writes its stamp, over the file that is the
     * source of truth (files=truth); the caller updates the runtime index row beside it. The error
     * is capped by the same {@see capFailureReason()} the failure path uses, because it carries a
     * killed transfer's stderr and lands in the same bounded place.
     *
     * Only the LOCAL sidecar is stamped, and deliberately so: the copy leaves before the stamp
     * exists, so the sidecar sitting on the receiver never claims to have been shipped. What is
     * over there is a snapshot of the moment it was taken.
     *
     * @param BackupHistory $row Index row identifying the backup (its id, env, and scope)
     * @param string $root Backup storage root
     * @param ?string $shippedAt ISO-8601 instant of the last successful copy; null means never
     * @param ?BackupShipOutcome $outcome What the attempt concluded
     * @param ?string $error Why the attempt failed; null on success
     * @return ?string The error as it was actually stored, so the index half records the same
     *     text this file does rather than an uncapped copy of it
     * @throws BackupException When the scope, root, or stored sidecar is invalid or missing
     * @throws BackupDumpFailedException When the rewritten sidecar cannot be published
     */
    public function recordShipping(
        BackupHistory $row,
        string $root,
        ?string $shippedAt,
        ?BackupShipOutcome $outcome,
        ?string $error,
    ): ?string {
        $sidecarPath = $this->storedSidecarPath($row->getId(), $row->env, $row->scope, $root);
        $metadata = $this->readSidecar($sidecarPath);
        $stored = $this->capFailureReason($error);

        $this->republishSidecar(
            $sidecarPath,
            self::TEMP_KIND_SHIP,
            $metadata->withShipping($shippedAt, $outcome, $stored),
        );

        return $stored;
    }

    /**
     * Records that this archive was restored, in the stored sidecar, atomically.
     *
     * The archive is where a restore's history lives, because a restore has none of its own: its
     * runtime row holds the run in flight and is overwritten by the next one. Written the same way
     * {@see recordVerification()} writes its stamp, over the file that is the source of truth
     * (files=truth); the caller updates the runtime index row beside it.
     *
     * What it is read back for is the estimate of the next restore: a duration next to the size of
     * the archive it was spent on is a speed, and a speed is what carries over between archives of
     * different sizes ({@see BackupEstimator::restoreSeconds()}).
     *
     * @param string $id Backup id of the restored archive
     * @param string $env Application environment the archive was taken in
     * @param ?string $scope Scope value the archive was captured under
     * @param string $root Backup storage root
     * @param string $restoredAt ISO-8601 instant the restore finished
     * @param int $durationSeconds How long that restore took, in seconds
     * @throws BackupException When the scope, root, or stored sidecar is invalid or missing
     * @throws BackupDumpFailedException When the rewritten sidecar cannot be published
     */
    public function recordRestore(
        string $id,
        string $env,
        ?string $scope,
        string $root,
        string $restoredAt,
        int $durationSeconds,
    ): void {
        $sidecarPath = $this->storedSidecarPath($id, $env, $scope, $root);
        $metadata = $this->readSidecar($sidecarPath);

        $this->republishSidecar(
            $sidecarPath,
            self::TEMP_KIND_RESTORE,
            $metadata->withRestore($restoredAt, $durationSeconds),
        );
    }

    /**
     * Resolves the stored sidecar path of a backup named by its identity fields.
     *
     * Scalars rather than a row, so the agent path (which holds a runtime index row) and the
     * CLI path (which holds only the sidecar it read) resolve the same name the same way.
     *
     * @param string $id Backup id
     * @param string $env Application environment the backup was taken in
     * @param ?string $scope Backup scope value
     * @param string $root Backup storage root
     * @return string Absolute sidecar path
     * @throws BackupException When the scope or root is invalid
     */
    private function storedSidecarPath(string $id, string $env, ?string $scope, string $root): string
    {
        $backupScope = BackupScope::fromString($scope);
        if ($backupScope === null) {
            throw new BackupException("Invalid backup scope: {$scope}");
        }
        if ($root === '') {
            throw new BackupException('Backup directory (BACKUP_DIR) is not configured');
        }

        return $root . '/' . $backupScope->value . '/'
            . self::archiveBaseName($id, $env, $backupScope) . self::SIDECAR_EXTENSION;
    }

    /**
     * Rewrites a stored sidecar in place through the atomic publish idiom.
     *
     * The temp file is created beside the sidecar it replaces, because {@see publish()} renames
     * it and rename is only atomic within one filesystem.
     *
     * @param string $sidecarPath Sidecar being replaced
     * @param string $tempKind Temp-name discriminator naming the rewrite (keep pin, verification)
     * @param BackupMetadata $metadata Payload to publish over the sidecar
     * @throws BackupDumpFailedException When the rewritten sidecar cannot be written or published
     */
    private function republishSidecar(string $sidecarPath, string $tempKind, BackupMetadata $metadata): void
    {
        $tmpSidecar = dirname($sidecarPath) . '/' . self::TEMP_PREFIX . $tempKind . '-'
            . basename($sidecarPath, self::SIDECAR_EXTENSION) . '-' . getmypid() . self::SIDECAR_EXTENSION;
        $this->writeJson($tmpSidecar, $metadata->toArray());
        $this->publish($tmpSidecar, $sidecarPath);
    }

    /**
     * Reads and decodes one backup sidecar into its metadata.
     *
     * @param string $sidecarPath Absolute sidecar path
     * @return BackupMetadata Decoded sidecar metadata
     * @throws BackupException When the sidecar is missing, not valid JSON, or names no backup
     */
    private function readSidecar(string $sidecarPath): BackupMetadata
    {
        try {
            $raw = FsPath::read($sidecarPath);
        } catch (FsException $failure) {
            throw new BackupException("Backup sidecar not found: {$sidecarPath}", 0, $failure);
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
     * The migration level is read BEFORE the dump, not after it: reading it is what creates the
     * `migration` table ({@see Migration::initialize()}), so on a database that never migrated
     * the old order dumped a schema with no `migration` in it - and left the marker nothing to
     * attach to.
     *
     * @param BackupScope $scope Backup scope
     * @param string $workDir Temp working directory receiving the sql files
     * @param BackupReferenceRegistry $references Reference-table registry queried per connection
     * @return list<BackupConnectionMeta> Captured connection metadata
     * @throws BackupDumpFailedException When a connection cannot be dumped or stamped
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
            $migrationIndex = Migration::getCurrentIndex();

            $this->dumpConnection(
                $scope,
                $config,
                $sqlPath,
                $references->tablesForConnection($index),
                self::scopeMarkerIndex($scope, $migrationIndex),
            );

            $connections[] = new BackupConnectionMeta($index, $config->database, $migrationIndex);
        }

        return $connections;
    }

    /**
     * Maps a scope to the migration level its dump files are stamped with, or null for no stamp.
     *
     * The scope-level counterpart of {@see scopeDumpPasses()}, and public for the same reason: it
     * is the create side's whole decision about the marker, and pinning it takes no database.
     * {@see BackupScope::FULL} is not stamped - it dumps the rows of `migration` itself, and a
     * second copy of that number in the same file is the duplication this marker exists to avoid.
     *
     * @param BackupScope $scope Backup scope
     * @param int $migrationIndex Migration level the connection is being dumped at
     * @return ?int Level to stamp into the dump, or null when the scope carries its own
     */
    public static function scopeMarkerIndex(BackupScope $scope, int $migrationIndex): ?int
    {
        return match ($scope) {
            BackupScope::FULL => null,
            BackupScope::SCHEMA_ONLY, BackupScope::SCHEMA_SEED => $migrationIndex,
        };
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
     * The migration marker is appended after the passes and is not one of them
     * ({@see scopeDumpPasses()} stays a mysqldump vocabulary): it is a statement this engine
     * writes itself, into the dump file and never into the live database.
     *
     * @param BackupScope $scope Backup scope
     * @param DatabaseConnectionConfig $config Connection settings
     * @param string $sqlPath Output sql file for this connection
     * @param list<string> $referenceTables Reference tables this connection keeps under schema-seed
     * @param ?int $migrationIndex Migration level to stamp into the dump, or null to leave it unstamped
     * @throws BackupDumpFailedException When a mysqldump pass exits non-zero or the dump cannot be stamped
     */
    private function dumpConnection(
        BackupScope $scope,
        DatabaseConnectionConfig $config,
        string $sqlPath,
        array $referenceTables,
        ?int $migrationIndex,
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
            // warning-suppressed: teardown of the credentials file, no-op when it is already gone
            @unlink($iniPath);
        }

        if ($migrationIndex === null) {
            return;
        }

        // A dump that could not be stamped is a dump that must not be published: without the
        // marker a restore of it refuses, so publishing it would only defer the failure.
        try {
            FsPath::append($sqlPath, ArchiveMigrationMarker::statement($migrationIndex));
        } catch (FileWriteException $failure) {
            throw new BackupDumpFailedException(
                "Cannot stamp the migration marker into the dump: {$sqlPath}",
                0,
                $failure,
            );
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
     * @param ?string $failureReason Failure reason recorded in the sidecar (error records only)
     * @param int $dumpBytes Uncompressed dump volume recorded in the sidecar (0 for the in-archive
     *     copy and for error records, which capture nothing)
     * @param ?string $sha256 Archive digest recorded in the sidecar; null for the in-archive copy (it
     *     would hash itself), for error records, and for a run whose hashing failed
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
        ?string $failureReason = null,
        int $dumpBytes = 0,
        ?string $sha256 = null,
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
            $failureReason,
            $dumpBytes,
            $sha256,
        );
    }

    /**
     * Sums the byte size of every file directly in the backup work directory.
     *
     * This is the uncompressed dump peak {@see BackupSpaceGuard} sizes future runs
     * from: the per-connection `db-*.sql` files plus the in-archive `metadata.json` copy, measured
     * once the dumps are written and before the tar exists. Subdirectories and unreadable entries
     * are skipped, and a work directory that cannot be listed sums to 0 - "no measurement", not a
     * failure, so measurement never aborts an otherwise good backup.
     *
     * @param string $workDir Temp working directory holding the dump files
     * @return int Total uncompressed byte size of the dump files
     */
    public static function measureWorkDir(string $workDir): int
    {
        $total = 0;
        foreach (glob($workDir . '/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            // warning-suppressed: an unreadable dump file means "no measurement", the sum just does not grow
            $size = @filesize($path);
            if ($size !== false) {
                $total += $size;
            }
        }

        return $total;
    }

    /**
     * Reads a backup's start instant back out of its id.
     *
     * The id is minted from the clock when the run starts
     * ({@see BackupAgent::generateBackupId()}), so it already carries the
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
     * @throws BackupDumpFailedException When the temp file cannot be created, restricted or written
     */
    private function writeDefaultsIni(DatabaseConnectionConfig $config): string
    {
        try {
            $path = FsPath::createTempFile('hilos-backup-', 0600);
        } catch (FilePermissionException $failure) {
            throw new BackupDumpFailedException('Failed to restrict mysqldump defaults file', 0, $failure);
        } catch (FsException $failure) {
            throw new BackupDumpFailedException('Failed to create mysqldump defaults file', 0, $failure);
        }

        try {
            FsPath::write($path, self::renderDefaultsIni($config));
        } catch (FsException $failure) {
            // warning-suppressed: the half-written file is dropped best-effort, no-op when it resists
            @unlink($path);

            throw new BackupDumpFailedException('Failed to write mysqldump defaults file', 0, $failure);
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
        try {
            FsPath::write($path, $json);
        } catch (FsException $failure) {
            throw new BackupDumpFailedException("Failed to write {$path}", 0, $failure);
        }
    }

    /**
     * Publishes a finished file through the Fs seam, so readers never see a partial write.
     *
     * @param string $tmpPath Source temp file on the same filesystem as the target
     * @param string $finalPath Final path
     * @throws BackupDumpFailedException When the rename fails
     */
    private function publish(string $tmpPath, string $finalPath): void
    {
        try {
            FsPath::publish($tmpPath, $finalPath);
        } catch (FsException $failure) {
            throw new BackupDumpFailedException("Failed to publish {$finalPath}", 0, $failure);
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
        try {
            return FsPath::size($path);
        } catch (FsException $failure) {
            throw new BackupDumpFailedException("Failed to stat archive {$path}", 0, $failure);
        }
    }

    /**
     * Creates a directory (recursively) if missing.
     *
     * @param string $path Directory path
     * @throws BackupException When the directory cannot be created
     */
    private function ensureDirectory(string $path): void
    {
        try {
            FsPath::ensureDirectory($path, 0755);
        } catch (FsException $failure) {
            throw new BackupException("Failed to create directory {$path}", 0, $failure);
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
        // warning-suppressed: teardown of a temp artifact, no-op when the run never created it
        @unlink($tmpArchive);
        // warning-suppressed: teardown of a temp artifact, no-op when the run never created it
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
        foreach (glob($scopeDir . '/' . self::TEMP_PREFIX . $base . '-*') ?: [] as $path) {
            // warning-suppressed: sweeping leftovers of a dead child, an undeletable one stays behind
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
            // warning-suppressed: best-effort removal, an undeletable child leaves the tree in place
            is_dir($child) ? $this->removeDirectory($child) : @unlink($child);
        }
        // warning-suppressed: best-effort removal, a non-empty directory stays behind
        @rmdir($path);
    }

    /**
     * Double-quotes and escapes a value for a MySQL option file.
     *
     * Public because the escaping is one contract for every option file the subsystem
     * writes: the restore engine's `[client]` file must quote exactly as this dump-side
     * file does, or a credentials fix lands on one path only.
     *
     * @param string $value Raw value
     * @return string Quoted value
     */
    public static function iniQuote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
