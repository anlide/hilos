<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupRestorer;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifier;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;

/**
 * Integration coverage for the restore engine against the live test database.
 *
 * The archive fixtures are hand-written (a real dump needs mysqldump and a run of the
 * create engine; the restore contract is only "replay `db-<index>.sql` files the
 * creator's layout carries"), stored and named exactly as {@see BackupCreator}
 * publishes them, with a real sha256 in the sidecar so the digest gate is exercised
 * for real.
 */
final class BackupRestorerIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Probe table the fixtures restore; dropped on teardown. */
    private const string PROBE_TABLE = 'hilos_fw_restore_probe';

    /** Fixture backup id; also the stem of the stored archive/sidecar names. */
    private const string BACKUP_ID = '2026-08-08_12-00-00';

    private string $storeRoot = '';

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeRoot = sys_get_temp_dir() . '/hilos-restore-it-' . getmypid();
        $this->removeTree($this->storeRoot);
        mkdir($this->storeRoot . '/' . BackupScope::FULL->value, 0700, true);

        // The engine reads BACKUP_DIR off the env facade; a stub-backed accessor lets the
        // test point it at the fixture store (the BackupCreatorTest precedent).
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv('BACKUP_DIR=' . $this->storeRoot);
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        putenv('BACKUP_DIR');
        $this->removeTree($this->storeRoot);
        Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`');
        parent::tearDown();
    }

    public function testRestoreReplaysTheProbeTableFromTheArchive(): void
    {
        $this->publishFixtureBackup($this->probeDumpSql([['1', 'alpha'], ['2', 'beta']]));
        // Pre-state the archive must replace: same table, different rows.
        Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`');
        Database::sql(
            'CREATE TABLE `' . self::PROBE_TABLE . '` (id INT PRIMARY KEY, label VARCHAR(32) NOT NULL)',
        );
        Database::sql("INSERT INTO `" . self::PROBE_TABLE . "` VALUES (9, 'stale')");

        new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

        $this->assertSame([['1', 'alpha'], ['2', 'beta']], $this->probeRows());
    }

    public function testCorruptedArchiveIsRefusedBeforeAnythingDestructive(): void
    {
        $archivePath = $this->publishFixtureBackup($this->probeDumpSql([['1', 'alpha']]));
        $this->flipByte($archivePath);
        Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`');
        Database::sql(
            'CREATE TABLE `' . self::PROBE_TABLE . '` (id INT PRIMARY KEY, label VARCHAR(32) NOT NULL)',
        );
        Database::sql("INSERT INTO `" . self::PROBE_TABLE . "` VALUES (7, 'untouched')");

        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
            $this->fail('A corrupted archive must refuse to restore');
        } catch (RestoreFailedException $refusal) {
            $this->assertStringContainsString('verification', $refusal->getMessage());
        }

        $this->assertSame([['7', 'untouched']], $this->probeRows(), 'The digest gate must fire before any import');
    }

    public function testFailedImportThrowsAndCleansItsTempWorkdir(): void
    {
        $this->publishFixtureBackup("THIS IS NOT SQL;\n");
        $workDir = sys_get_temp_dir() . '/hilos-restore-' . getmypid() . '-' . self::BACKUP_ID;

        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
            $this->fail('A failing import must surface as RestoreFailedException');
        } catch (RestoreFailedException $failure) {
            $this->assertStringContainsString('import into connection 0', $failure->getMessage());
        }

        $this->assertDirectoryDoesNotExist($workDir, 'The temp workdir must be swept even on failure');
    }

    public function testRefusedDecisionNeverTouchesStorage(): void
    {
        // No fixture is published on purpose: a refused restore must fail on the decision,
        // before it ever looks for the archive.
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('environment guard');

        new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::REFUSE);
    }

    /**
     * Builds and publishes one fixture backup exactly as the create path lays it out.
     *
     * @param string $dumpSql Contents of the connection-0 dump inside the archive
     * @return string Absolute path of the published archive
     */
    private function publishFixtureBackup(string $dumpSql): string
    {
        $scopeDir = $this->storeRoot . '/' . BackupScope::FULL->value;
        $workDir = $this->storeRoot . '/build';
        mkdir($workDir, 0700);
        file_put_contents(
            $workDir . '/' . BackupCreator::SQL_FILE_PREFIX . '0' . BackupCreator::SQL_FILE_SUFFIX,
            $dumpSql,
        );

        $base = BackupCreator::archiveBaseName(self::BACKUP_ID, 'test', BackupScope::FULL);
        $archivePath = $scopeDir . '/' . $base . BackupCreator::ARCHIVE_EXTENSION;
        exec(
            'tar -czf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($workDir) . ' .',
            $output,
            $exitCode,
        );
        $this->assertSame(0, $exitCode, 'Fixture archive build must succeed');

        $metadata = new BackupMetadata(
            id: self::BACKUP_ID,
            createdAt: '2026-08-08T12:00:00+00:00',
            env: 'test',
            scope: BackupScope::FULL,
            connections: [new BackupConnectionMeta(0, $this->databaseName(), 0)],
            sizeBytes: (int)filesize($archivePath),
            durationSeconds: 1,
            keep: false,
            status: BackupStatus::SUCCESS,
            sha256: (string)hash_file(BackupVerifier::DIGEST_ALGO, $archivePath),
        );
        file_put_contents(
            $scopeDir . '/' . $base . BackupCreator::SIDECAR_EXTENSION,
            json_encode($metadata->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
        $this->removeTree($workDir);

        return $archivePath;
    }

    /**
     * A mysqldump-shaped dump of the probe table: drop, create, insert the given rows.
     *
     * @param list<array{0: string, 1: string}> $rows id/label pairs the dump carries
     * @return string Dump SQL
     */
    private function probeDumpSql(array $rows): string
    {
        $inserts = implode(', ', array_map(
            static fn (array $row): string => "({$row[0]}, '{$row[1]}')",
            $rows,
        ));

        return 'DROP TABLE IF EXISTS `' . self::PROBE_TABLE . "`;\n"
            . 'CREATE TABLE `' . self::PROBE_TABLE . "` (id INT PRIMARY KEY, label VARCHAR(32) NOT NULL);\n"
            . 'INSERT INTO `' . self::PROBE_TABLE . "` VALUES {$inserts};\n";
    }

    /**
     * @return list<array{0: string, 1: string}> Probe table rows ordered by id
     */
    private function probeRows(): array
    {
        Database::sql('SELECT id, label FROM `' . self::PROBE_TABLE . '` ORDER BY id');
        $rows = [];
        while (($row = Database::row()) !== null) {
            $rows[] = [(string)$row['id'], (string)$row['label']];
        }

        return $rows;
    }

    /**
     * @return string Database name of the primary test connection
     */
    private function databaseName(): string
    {
        return (string)getenv(EnvConstants::DB_DATABASE->name);
    }

    /**
     * Flips one byte in the middle of a file, keeping its size (a pure digest mismatch).
     *
     * @param string $path File to corrupt
     */
    private function flipByte(string $path): void
    {
        $contents = (string)file_get_contents($path);
        $offset = intdiv(strlen($contents), 2);
        $contents[$offset] = chr(ord($contents[$offset]) ^ 0xFF);
        file_put_contents($path, $contents);
    }

    /**
     * Recursively removes a fixture tree (best effort).
     *
     * @param string $path Directory path
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
