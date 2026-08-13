<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Closure;
use Hilos\Backup\Anonymization\ArchiveSchemaReader;
use Hilos\Backup\Anonymization\ArchiveTableSchema;
use Hilos\Backup\Anonymization\CatalogRestoreAnonymizer;
use Hilos\Backup\Exception\BackupMetadataIncompleteException;
use Hilos\Backup\Exception\RestoreArchiveNotFoundException;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Process;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Environment\Exception\EnvException;
use Hilos\Fs\Exception\FilePermissionException;
use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use Random\RandomException;
use Throwable;

/**
 * BackupRestorer - the restore engine shared by the cold CLI path and the hot child.
 *
 * The mirror of {@see BackupCreator} for the read-back direction: it locates the
 * archive the creator published, re-checks its digest, unpacks it into a temp workdir
 * and replays each `db-<index>.sql` through the mysql client into the connection of
 * the same index. All blocking work happens here, off the daemon event loop - the
 * caller is either the `--cold` CLI process or the short-lived restore child the
 * monopoly backup agent spawns under protected mode.
 *
 * The order of steps is the safety argument: everything that can refuse (locate,
 * sidecar read, digest check, migration gate, anonymizer availability via the decision,
 * and the anonymizer's own verdict on the archive's schema) runs before the first import
 * touches a database. Import replaces tables as the dump
 * directs (mysqldump's default DROP TABLE IF EXISTS + CREATE); tables present in the
 * target but absent from the dump are left alone - reconciling those is HIL-436.
 *
 * The ENV decision is made by {@see RestoreEnvGuard} in the caller and arrives here
 * as a value: the engine acts on it (runs the anonymization seam or not) but does not
 * re-derive it, so both execution paths act on the same recorded verdict. The migration
 * gate is the opposite case and is re-run here rather than passed in: it reads only the
 * sidecar and the migration files, both of which this process has, and the engine is the
 * last thing standing before the data is overwritten - the same argument that makes it
 * re-check the digest.
 */
final class BackupRestorer
{
    /** Import client binary; the counterpart of the creator's mysqldump hardcode-with-seam. */
    private const string MYSQL_BIN = 'mysql';

    /** Archiver binary unpacking the gzip tarball. */
    private const string TAR_BIN = 'tar';

    /** Temp workdir prefix; distinct from the creator's `.tmp-` so failure sweeps never collide. */
    private const string WORKDIR_PREFIX = 'hilos-restore-';

    /**
     * Restores one stored backup into the configured connections.
     *
     * @param string $id Backup id as minted by the create path
     * @param BackupScope $scope Scope the archive was captured under; also its storage subdirectory
     * @param RestoreEnvDecision $decision Recorded ENV guard verdict this run acts on
     * @param ?Closure(RestorePhase): void $onPhase Observer told as each phase begins; the cold
     *     CLI prints them inline, the hot child runs unobserved (its supervisor reports coarsely)
     * @throws RestoreFailedException When any step refuses or fails; the raised failure says
     *     whether the database was already being replaced ({@see RestoreFailedException::databaseTouched()})
     * @throws EnvException When the backup storage env value cannot be read
     */
    public function restore(
        string $id,
        BackupScope $scope,
        RestoreEnvDecision $decision,
        ?Closure $onPhase = null,
    ): void {
        if ($decision === RestoreEnvDecision::REFUSE) {
            throw new RestoreFailedException('Restore refused by the environment guard');
        }
        if (preg_match(BackupCreator::ID_PATTERN, $id) !== 1) {
            throw new RestoreFailedException("Invalid backup id: {$id}");
        }

        $root = Hilos::$env->string(EnvConstants::BACKUP_DIR);
        if ($root === '') {
            throw new RestoreFailedException('Backup directory (BACKUP_DIR) is not configured');
        }

        $archivePath = self::locateArchive($root, $id, $scope);
        $metadata = self::readSidecarForArchive($archivePath);

        $migration = RestoreMigrationGuard::decide(
            $metadata->connections,
            RestoreMigrationGuard::codeMigrationIndex(),
        );
        if ($migration->decision === RestoreMigrationDecision::REFUSE) {
            throw new RestoreFailedException("Restore refused by the migration guard: {$migration->reason}");
        }

        // Anything that can refuse must run before the first destructive step, and an
        // unavailable anonymizer refuses: checked here, not after the imports, or a
        // REQUIRE_ANONYMIZATION run would commit raw production data before failing.
        $anonymizer = null;
        if ($decision === RestoreEnvDecision::REQUIRE_ANONYMIZATION) {
            if ($scope === BackupScope::SCHEMA_ONLY) {
                // Asked before the registry is: a schema-only archive carries no rows, so there
                // is nothing to anonymize and nothing an undeclared registry could fail to
                // anonymize. Refusing here would block a restore over a configuration gap that
                // this run cannot be harmed by.
                Logger::info('Restore: anonymization skipped, this archive carries schema only', [
                    'id' => $id,
                    'scope' => $scope->value,
                ]);
            } else {
                $anonymizer = $this->resolveAnonymizer();
                if ($anonymizer === null) {
                    throw new RestoreFailedException(
                        'Restore requires anonymization, but the project declares no PII registry '
                        . '(backup catalog key: ' . BackupConstants::CATALOG_PII . ')',
                    );
                }
            }
        }

        if ($onPhase !== null) {
            $onPhase(RestorePhase::VERIFYING);
        }
        try {
            $this->verifyArchive($archivePath, $metadata);
        } catch (RestoreFailedException $e) {
            throw RestoreFailedException::beforeDestructive($e->getMessage(), $e);
        }

        $workDir = sys_get_temp_dir() . '/' . self::WORKDIR_PREFIX . getmypid() . '-' . $id;
        try {
            if ($onPhase !== null) {
                $onPhase(RestorePhase::EXTRACTING);
            }
            try {
                $this->ensureWorkDir($workDir);
                $this->extract($archivePath, $workDir);
            } catch (RestoreFailedException $e) {
                throw RestoreFailedException::beforeDestructive($e->getMessage(), $e);
            }

            $connections = $metadata->connections;
            usort($connections, static fn (BackupConnectionMeta $a, BackupConnectionMeta $b): int
                => $a->index <=> $b->index);

            // The last refusal, and the only one that needs the archive open: the registry is
            // judged against the schema the dump declares, while the target database still
            // holds whatever it held. Every failure past this line leaves data behind.
            if ($anonymizer !== null) {
                $anonymizer->validateArchive($this->readArchiveSchemas($connections, $workDir));
            }

            // The destructive window opens here and does not close: from the first import on, a
            // failure leaves the database partially replaced, and everything raised inside says so
            // (HIL-436). Wrapped as a region rather than tagged step by step, because the boundary
            // is a property of where the run got to, not of which helper happened to throw.
            try {
                if ($onPhase !== null) {
                    $onPhase(RestorePhase::IMPORTING);
                }
                foreach ($connections as $connection) {
                    $this->importConnection($connection, $workDir);
                }

                // Before anonymization, not after: the anonymizer (HIL-275) works against the
                // schema the CODE knows, which is the schema these migrations produce.
                if ($onPhase !== null) {
                    $onPhase(RestorePhase::MIGRATING);
                }
                foreach ($connections as $connection) {
                    $this->migrateConnection($connection);
                }

                if ($anonymizer !== null) {
                    if ($onPhase !== null) {
                        $onPhase(RestorePhase::ANONYMIZING);
                    }
                    foreach ($connections as $connection) {
                        $anonymizer->anonymizeConnection(
                            $connection->index,
                            $this->targetDatabaseOf($connection->index),
                        );
                    }
                }
            } catch (RestoreFailedException $e) {
                throw RestoreFailedException::afterDestructive($e->getMessage(), $e);
            }
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /**
     * Locates the stored archive of a backup by its id and scope.
     *
     * The base name is `<id>-<env>-<scope>` and the archive's env is not an input here -
     * a restore names what it wants restored, not where it came from - so the env slot is
     * globbed. The glob alone cannot be trusted with the answer: ids may themselves carry
     * dashes, so `nightly-*` also matches a neighbour named `nightly-2`. Each candidate's
     * sidecar is therefore asked for its recorded id, and only exact holders count -
     * which also refuses an id typo here, before anything is frozen or spawned for it.
     * More than one exact holder (the same id stored from two environments) is refused
     * rather than guessed at; a candidate whose sidecar is missing or unreadable simply
     * does not count as a match.
     *
     * @param string $root Backup storage root
     * @param string $id Backup id
     * @param BackupScope $scope Scope the archive was captured under
     * @return string Absolute archive path
     * @throws RestoreArchiveNotFoundException When no stored backup carries this id and scope
     * @throws RestoreFailedException When more than one archive carries this exact id
     */
    public static function locateArchive(string $root, string $id, BackupScope $scope): string
    {
        $pattern = $root . '/' . $scope->value . '/'
            . $id . '-*-' . $scope->value . BackupCreator::ARCHIVE_EXTENSION;
        $matches = [];
        foreach (glob($pattern) ?: [] as $candidate) {
            try {
                $metadata = self::readSidecarForArchive($candidate);
            } catch (RestoreFailedException) {
                continue;
            }
            if ($metadata->id === $id) {
                $matches[] = $candidate;
            }
        }

        if ($matches === []) {
            throw new RestoreArchiveNotFoundException(
                "No stored backup [{$id}] under scope [{$scope->value}]",
            );
        }
        if (count($matches) > 1) {
            throw new RestoreFailedException(
                "Backup id [{$id}] is ambiguous under scope [{$scope->value}]: "
                . implode(', ', array_map(basename(...), $matches)),
            );
        }

        return $matches[0];
    }

    /**
     * Reads and decodes the sidecar belonging to a stored archive.
     *
     * The one owner of the archive→sidecar naming rule (same stem, sidecar extension);
     * shared by the engine and the CLI preflight so the two cannot read different
     * metadata for the same archive.
     *
     * @param string $archivePath Absolute archive path
     * @return BackupMetadata Decoded sidecar metadata
     * @throws RestoreFailedException When the sidecar is missing, not valid JSON, or names no backup
     */
    public static function readSidecarForArchive(string $archivePath): BackupMetadata
    {
        $sidecarPath = substr($archivePath, 0, -strlen(BackupCreator::ARCHIVE_EXTENSION))
            . BackupCreator::SIDECAR_EXTENSION;
        try {
            $raw = FsPath::read($sidecarPath);
        } catch (FsException $failure) {
            throw new RestoreFailedException("Backup sidecar not found: {$sidecarPath}", 0, $failure);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RestoreFailedException("Backup sidecar is not valid JSON: {$sidecarPath}");
        }

        try {
            return BackupMetadata::fromArray($decoded);
        } catch (BackupMetadataIncompleteException $failure) {
            throw new RestoreFailedException("Backup sidecar names no backup: {$sidecarPath}", 0, $failure);
        }
    }

    /**
     * Renders a mysql defaults-extra-file so credentials never appear in argv.
     *
     * The `[client]` counterpart of {@see BackupCreator::renderDefaultsIni()}: same
     * escaping, same fields, read by the import client instead of the dumper.
     *
     * @param DatabaseConnectionConfig $config Connection settings
     * @return string INI text for `--defaults-extra-file`
     */
    public static function renderClientIni(DatabaseConnectionConfig $config): string
    {
        $lines = [
            '[client]',
            'host = ' . BackupCreator::iniQuote($config->host),
            'port = ' . $config->port,
            'user = ' . BackupCreator::iniQuote($config->user),
            'password = ' . BackupCreator::iniQuote($config->password),
        ];
        if ($config->socket !== null && $config->socket !== '') {
            $lines[] = 'socket = ' . BackupCreator::iniQuote($config->socket);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Re-checks the archive digest right before the destructive steps.
     *
     * Defense in depth on top of the caller's own preflight check: the engine is the last
     * gate before data is overwritten, so it does not trust that a check happened earlier.
     * {@see BackupVerifyOutcome::NO_DIGEST} passes - a sidecar written before HIL-435
     * carries nothing to check, and refusing to ever restore the accumulated history over
     * that would be wrong.
     *
     * @param string $archivePath Absolute archive path
     * @param BackupMetadata $metadata Sidecar metadata carrying the recorded digest
     * @throws RestoreFailedException When the archive is missing, unreadable, or differs
     *     from its recorded digest
     */
    private function verifyArchive(string $archivePath, BackupMetadata $metadata): void
    {
        $result = new BackupVerifier()->verify($archivePath, $metadata);
        if ($result->outcome === BackupVerifyOutcome::OK || $result->outcome === BackupVerifyOutcome::NO_DIGEST) {
            return;
        }

        $detail = $result->reason ?? $result->outcome->value;
        throw new RestoreFailedException(
            "Archive failed verification before restore ({$detail}): {$archivePath}",
        );
    }

    /**
     * Unpacks the archive into the temp workdir.
     *
     * @param string $archivePath Absolute archive path
     * @param string $workDir Temp workdir receiving the dump files
     * @throws RestoreFailedException When tar exits non-zero
     */
    private function extract(string $archivePath, string $workDir): void
    {
        $this->runProcess(
            self::TAR_BIN,
            ['-xzf', $archivePath, '-C', $workDir],
            [Process::DESCRIPTOR_PIPE, Process::PIPE_READ],
        );
    }

    /**
     * Replays one connection's dump into that connection's configured database.
     *
     * The target database is the current configuration's, not the name recorded in the
     * archive: the same logical connection may carry different database names across
     * environments, and index is the identity the create path recorded.
     *
     * @param BackupConnectionMeta $connection Dumped connection being restored
     * @param string $workDir Temp workdir holding the extracted dump files
     * @throws RestoreFailedException When the dump file is missing, credentials cannot be
     *     written, or the import client exits non-zero
     */
    private function importConnection(BackupConnectionMeta $connection, string $workDir): void
    {
        $sqlPath = $workDir . '/'
            . BackupCreator::SQL_FILE_PREFIX . $connection->index . BackupCreator::SQL_FILE_SUFFIX;
        if (!is_file($sqlPath)) {
            throw new RestoreFailedException(
                "Archive carries no dump for connection {$connection->index}",
            );
        }

        Database::useConnection($connection->index);
        $config = Database::getConnectionConfig($connection->index);

        $iniPath = $this->writeClientIni($config);
        try {
            $this->runProcess(
                self::MYSQL_BIN,
                ['--defaults-extra-file=' . $iniPath, $config->database],
                [Process::DESCRIPTOR_FILE, $sqlPath, Process::PIPE_READ],
                "import into connection {$connection->index}",
            );
        } finally {
            // warning-suppressed: teardown of the credentials file, no-op when it is already gone
            @unlink($iniPath);
        }
    }

    /**
     * Brings one restored database up to the migration level the code expects.
     *
     * Unconditional, including when the levels already match: `migrateUp` applies nothing
     * then, and the alternative - deciding here whether to call it - would put a second
     * copy of the gate's arithmetic in the engine. The archive can only be at or below the
     * code level; anything ahead was refused before the first import.
     *
     * Opens the link when the process has not already, the way the create path does before
     * reading the same table: the import before this one talks to the database through the
     * mysql client, so a connection the caller never needed in PHP is still closed here.
     *
     * @param BackupConnectionMeta $connection Restored connection to migrate
     * @throws RestoreFailedException When the connection is not configured, cannot be opened,
     *     or a migration fails; the data is already imported at this point, so the run ends
     *     partially restored (HIL-436)
     */
    private function migrateConnection(BackupConnectionMeta $connection): void
    {
        try {
            Database::useConnection($connection->index);
            if (!Database::isConnected($connection->index)) {
                Database::connect($connection->index);
            }
            Migration::migrateUp();
        } catch (DatabaseException $failure) {
            throw new RestoreFailedException(
                "Failed to migrate connection {$connection->index} after the import: "
                . $failure->getMessage(),
                0,
                $failure,
            );
        }
    }

    /**
     * Names the database a connection index actually imported into.
     *
     * The archive records the database it was captured FROM, and the two names differ in
     * exactly the case anonymization exists for - a production dump restored into staging.
     * The seam is documented as taking the database the connection imported into, so it is
     * told the target's name rather than the archive's.
     *
     * @param int $index Connection index
     * @return string Configured database name
     * @throws RestoreFailedException When the connection index is not configured
     */
    private function targetDatabaseOf(int $index): string
    {
        try {
            return Database::getConnectionConfig($index)->database;
        } catch (DatabaseException $failure) {
            throw new RestoreFailedException("Connection {$index} is not configured", 0, $failure);
        }
    }

    /**
     * Reads the table schemas every connection's dump file declares.
     *
     * @param list<BackupConnectionMeta> $connections Connections the archive carries
     * @param string $workDir Temp workdir holding the extracted dump files
     * @return array<int, list<ArchiveTableSchema>> Declared tables per connection index
     * @throws RestoreFailedException When a connection's dump file is missing or unreadable
     */
    private function readArchiveSchemas(array $connections, string $workDir): array
    {
        $schemas = [];
        foreach ($connections as $connection) {
            $schemas[$connection->index] = ArchiveSchemaReader::read(
                $workDir . '/'
                . BackupCreator::SQL_FILE_PREFIX . $connection->index . BackupCreator::SQL_FILE_SUFFIX,
            );
        }

        return $schemas;
    }

    /**
     * Resolves the anonymizer implementation for this installation.
     *
     * The catalog is the resolution mechanism, the way it already is for the reference
     * registry and the schedule: the backup feature is activated by naming a catalog, and
     * a second, facade-level hook would be a second place to look for the same answer. An
     * installation whose catalog declares no PII registry resolves to none, and the
     * preflight in {@see restore()} refuses the run before anything destructive happens.
     *
     * @return ?RestoreAnonymizer Available anonymizer, or null when the catalog declares none
     * @throws RestoreFailedException When the declared registry is not a registry, or the
     *     platform's secure random source refuses to mint this run's salt
     */
    private function resolveAnonymizer(): ?RestoreAnonymizer
    {
        try {
            return CatalogRestoreAnonymizer::fromCatalog();
        } catch (RandomException $failure) {
            throw new RestoreFailedException(
                'Cannot mint the anonymization salt for this restore: ' . $failure->getMessage(),
                0,
                $failure,
            );
        }
    }

    /**
     * Writes a 0600 defaults-extra-file for the mysql client and returns its path.
     *
     * @param DatabaseConnectionConfig $config Connection settings
     * @return string Path to the temporary INI file
     * @throws RestoreFailedException When the temp file cannot be created, restricted or written
     */
    private function writeClientIni(DatabaseConnectionConfig $config): string
    {
        try {
            $path = FsPath::createTempFile(self::WORKDIR_PREFIX, 0600);
        } catch (FilePermissionException $failure) {
            throw new RestoreFailedException('Failed to restrict mysql defaults file', 0, $failure);
        } catch (FsException $failure) {
            throw new RestoreFailedException('Failed to create mysql defaults file', 0, $failure);
        }

        try {
            FsPath::write($path, self::renderClientIni($config));
        } catch (FsException $failure) {
            // warning-suppressed: the half-written file is dropped best-effort, no-op when it resists
            @unlink($path);

            throw new RestoreFailedException('Failed to write mysql defaults file', 0, $failure);
        }

        return $path;
    }

    /**
     * Runs a child process to completion with the given stdin descriptor.
     *
     * Blocking is intentional and safe: this runs in the cold CLI process or the
     * short-lived restore child, never the daemon event loop.
     *
     * @param string $bin Executable
     * @param list<string> $params argv passed verbatim (no shell)
     * @param array{0: string, 1: string, 2?: string} $stdIn stdin descriptor spec
     * @param ?string $step Step name for the failure message; the binary name when null
     * @throws RestoreFailedException When the process cannot run or exits non-zero
     */
    private function runProcess(string $bin, array $params, array $stdIn, ?string $step = null): void
    {
        try {
            $process = new Process(
                $bin,
                $params,
                null,
                $stdIn,
                [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE],
                [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE],
            );

            do {
                $process->tick();
                if ($process->getStatus()[Process::STATUS_RUNNING]) {
                    usleep(BackupCreator::POLL_INTERVAL_US);
                }
            } while ($process->getStatus()[Process::STATUS_RUNNING]);

            $process->halt();
            $exitCode = $process->getExitCode();
            $stderr = $process->getStdErr();
        } catch (Throwable $e) {
            throw new RestoreFailedException(
                'Failed to run ' . ($step ?? $bin) . ': ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if ($exitCode !== 0) {
            throw new RestoreFailedException(sprintf(
                '%s exited with code %s: %s',
                $step ?? $bin,
                $exitCode ?? 'unknown',
                trim($stderr),
            ));
        }
    }

    /**
     * Creates the temp workdir, private to the restoring process.
     *
     * @param string $workDir Temp workdir path
     * @throws RestoreFailedException When the directory cannot be created
     */
    private function ensureWorkDir(string $workDir): void
    {
        try {
            FsPath::ensureDirectory($workDir, 0700);
        } catch (FsException $failure) {
            throw new RestoreFailedException("Failed to create workdir {$workDir}", 0, $failure);
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

}
