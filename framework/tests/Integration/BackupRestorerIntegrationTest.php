<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupRestorer;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifier;
use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestoreMigrationGuard;
use Hilos\Backup\RestorePhase;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
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
    /** Probe table the fixtures restore; dropped on teardown. Read by the catalog fixtures below. */
    public const string PROBE_TABLE = 'hilos_fw_restore_probe';

    /** Token-shaped table the anonymization fixtures purge; dropped on teardown. */
    public const string TOKEN_TABLE = 'hilos_fw_restore_token';

    /** Fixture backup id; also the stem of the stored archive/sidecar names. */
    private const string BACKUP_ID = '2026-08-08_12-00-00';

    /** Migration track the fixture migration file is written under. */
    private const string MIGRATION_TRACK = 'main';

    /**
     * Index of the fixture migration. Deliberately far above anything a real schema will
     * reach: it is applied to the shared test database, so it must not collide with a real
     * migration row, and tearDown deletes exactly this one.
     */
    private const int CODE_MIGRATION_INDEX = 9001;

    /** Level the fixture archive records: one behind the code, the migrate-forward case. */
    private const int ARCHIVE_MIGRATION_INDEX = 9000;

    /** Column the fixture migration adds; the proof that migrations ran after the import. */
    private const string MIGRATED_COLUMN = 'note';

    private string $storeRoot = '';

    private ?EnvAccessor $previousEnv = null;

    private bool $fixtureMigrationListed = false;

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

        // Point the migration list at an empty fixture tree: the gate then reads no code
        // level, which is what the cases that are not about migrations want. The one case
        // that is fills the tree itself.
        Migration::setMigrationListPath($this->storeRoot . '/migrations');
        Migration::setMigrationName(self::MIGRATION_TRACK);
        $this->fixtureMigrationListed = false;
    }

    /**
     * @throws DatabaseException When the fixture migration row cannot be dropped
     */
    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        putenv('BACKUP_DIR');
        $this->removeTree($this->storeRoot);
        Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`');
        Database::sql('DROP TABLE IF EXISTS `' . self::TOKEN_TABLE . '`');
        // The anonymization cases capture a project facade; later cases must find the base.
        Hilos::initBrowser();
        Hilos::resetBrowser();
        if ($this->fixtureMigrationListed) {
            // The migration table is shared with every other suite against this database, so
            // the fixture's row leaves with the fixture.
            Migration::initialize();
            Database::sqlRun('DELETE FROM `migration` WHERE `index` = ?', [self::CODE_MIGRATION_INDEX]);
        }
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

    public function testAnArchiveBehindTheCodeIsMigratedForwardAfterTheImport(): void
    {
        $this->listFixtureMigration();
        $this->publishFixtureBackup($this->probeDumpSql([['1', 'alpha']]), self::ARCHIVE_MIGRATION_INDEX);
        Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`');

        $phases = [];
        new BackupRestorer()->restore(
            self::BACKUP_ID,
            BackupScope::FULL,
            RestoreEnvDecision::ALLOW,
            static function (RestorePhase $phase) use (&$phases): void {
                $phases[] = $phase;
            },
        );

        $this->assertContains(RestorePhase::MIGRATING, $phases);
        $this->assertSame(self::CODE_MIGRATION_INDEX, Migration::getCurrentIndex());
        $this->assertContains(
            self::MIGRATED_COLUMN,
            $this->probeColumns(),
            'The restored database must carry the schema the code expects, not the dump\'s',
        );
        $this->assertSame([['1', 'alpha']], $this->probeRows(), 'The imported rows must survive the migration');
    }

    public function testTheMigrateStepOpensALinkTheCallerNeverOpened(): void
    {
        $this->listFixtureMigration();
        $this->publishFixtureBackup($this->probeDumpSql([['1', 'alpha']]), self::ARCHIVE_MIGRATION_INDEX);
        // Everything before the migrate step reaches the database through the mysql client,
        // so a restore can arrive here with no PHP link open - which is the normal state of
        // every connection the calling command did not itself need.
        Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);

        new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

        $this->assertSame(self::CODE_MIGRATION_INDEX, Migration::getCurrentIndex());
    }

    public function testRefusedDecisionNeverTouchesStorage(): void
    {
        // No fixture is published on purpose: a refused restore must fail on the decision,
        // before it ever looks for the archive.
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('environment guard');

        new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::REFUSE);
    }

    public function testAnonymizationRewritesTheRestoredRowsAndEmptiesPurgedTables(): void
    {
        // The archive's own environment does not reach the engine; the ENV guard's verdict
        // does, and REQUIRE_ANONYMIZATION is what a production archive into a development
        // target produces.
        $this->publishFixtureBackup($this->piiDumpSql());
        PiiRestoreTestHilos::initBrowser();

        new BackupRestorer()->restore(
            self::BACKUP_ID,
            BackupScope::FULL,
            RestoreEnvDecision::REQUIRE_ANONYMIZATION,
        );

        $this->assertSame(
            [
                ['1', '[redacted]', 'user1@example.invalid'],
                ['2', '[redacted]', 'user2@example.invalid'],
            ],
            $this->piiRows(),
            'Every declared column must carry its replacement, derived from the primary key',
        );
        $this->assertSame([], $this->tokenRows(), 'A purged table must arrive empty');
    }

    public function testAnUnclassifiedTableRefusesTheRestoreBeforeTheFirstImport(): void
    {
        $this->publishFixtureBackup($this->piiDumpSql());
        // Pre-state the archive would replace: it must still be here when the gate refuses.
        Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`');
        Database::sql(
            'CREATE TABLE `' . self::PROBE_TABLE . '` (id INT PRIMARY KEY, label VARCHAR(32) NOT NULL)',
        );
        Database::sql("INSERT INTO `" . self::PROBE_TABLE . "` VALUES (9, 'untouched')");
        PartialPiiRestoreTestHilos::initBrowser();

        try {
            new BackupRestorer()->restore(
                self::BACKUP_ID,
                BackupScope::FULL,
                RestoreEnvDecision::REQUIRE_ANONYMIZATION,
            );
            $this->fail('A table no registry classifies must refuse the restore');
        } catch (AnonymizationConfigException $refusal) {
            $this->assertStringContainsString(self::TOKEN_TABLE, $refusal->getMessage());
        }

        $this->assertSame(
            [['9', 'untouched']],
            $this->probeRows(),
            'The coverage gate must fire before the first import',
        );
    }

    public function testAnInstallationThatDeclaredNothingIsRefusedOnItsOwnTables(): void
    {
        $this->publishFixtureBackup($this->piiDumpSql());

        // No project facade is captured, so the catalog declares nothing. The framework
        // classifies the tables it ships (HIL-585), so the merged registry is never empty
        // any more - and this is the case that must not be let through on that account:
        // the rows a project wrote are still nobody's, and every one of them is named.
        try {
            new BackupRestorer()->restore(
                self::BACKUP_ID,
                BackupScope::FULL,
                RestoreEnvDecision::REQUIRE_ANONYMIZATION,
            );
            $this->fail('An installation that classified no data must not be told it was anonymized');
        } catch (AnonymizationConfigException $refusal) {
            $this->assertStringContainsString(self::PROBE_TABLE, $refusal->getMessage());
            $this->assertStringContainsString(self::TOKEN_TABLE, $refusal->getMessage());
        }
    }

    /**
     * Builds and publishes one fixture backup exactly as the create path lays it out.
     *
     * @param string $dumpSql Contents of the connection-0 dump inside the archive
     * @param ?int $migrationIndex Migration level recorded for connection 0
     * @return string Absolute path of the published archive
     */
    private function publishFixtureBackup(string $dumpSql, ?int $migrationIndex = 0): string
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
            connections: [new BackupConnectionMeta(0, $this->databaseName(), $migrationIndex)],
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
     * A dump carrying personal data, laid out the way mysqldump writes a schema pass.
     *
     * The multi-line `CREATE TABLE` shape is load-bearing here and not decoration: the
     * coverage gate reads the archive text, so a fixture written on one line would declare
     * no tables and the gate would pass by finding nothing.
     *
     * @return string Dump SQL for the probe and token tables
     */
    private function piiDumpSql(): string
    {
        return 'DROP TABLE IF EXISTS `' . self::PROBE_TABLE . "`;\n"
            . 'CREATE TABLE `' . self::PROBE_TABLE . "` (\n"
            . "  `id` int NOT NULL,\n"
            . "  `label` varchar(32) NOT NULL,\n"
            . "  `email` varchar(255) NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
            . 'INSERT INTO `' . self::PROBE_TABLE . "` VALUES "
            . "(1, 'Alice', 'alice@real.example'), (2, 'Bob', 'bob@real.example');\n"
            . 'DROP TABLE IF EXISTS `' . self::TOKEN_TABLE . "`;\n"
            . 'CREATE TABLE `' . self::TOKEN_TABLE . "` (\n"
            . "  `id` int NOT NULL,\n"
            . "  `token` varchar(64) NOT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
            . 'INSERT INTO `' . self::TOKEN_TABLE . "` VALUES (1, 'secret-one'), (2, 'secret-two');\n";
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> Probe rows with their PII columns
     */
    private function piiRows(): array
    {
        Database::sql('SELECT id, label, email FROM `' . self::PROBE_TABLE . '` ORDER BY id');
        $rows = [];
        while (($row = Database::row()) !== null) {
            $rows[] = [(string)$row['id'], (string)$row['label'], (string)$row['email']];
        }

        return $rows;
    }

    /**
     * @return list<array{0: string}> Token table rows
     */
    private function tokenRows(): array
    {
        Database::sql('SELECT id FROM `' . self::TOKEN_TABLE . '` ORDER BY id');
        $rows = [];
        while (($row = Database::row()) !== null) {
            $rows[] = [(string)$row['id']];
        }

        return $rows;
    }

    /**
     * Writes the one migration file that gives this code a level above the archive's.
     *
     * Without it {@see RestoreMigrationGuard::codeMigrationIndex()} answers null, the gate
     * takes its "level not comparable" branch, and the test quietly stops being about
     * migrating forward at all.
     */
    private function listFixtureMigration(): void
    {
        $trackDir = $this->storeRoot . '/migrations/' . self::MIGRATION_TRACK;
        mkdir($trackDir, 0700, true);
        file_put_contents(
            $trackDir . '/' . self::CODE_MIGRATION_INDEX . '_up.sql',
            'ALTER TABLE `' . self::PROBE_TABLE . '` ADD COLUMN `' . self::MIGRATED_COLUMN . "` VARCHAR(32) NULL;\n",
        );
        $this->fixtureMigrationListed = true;
    }

    /**
     * @return list<string> Column names of the probe table
     */
    private function probeColumns(): array
    {
        Database::sql('SHOW COLUMNS FROM `' . self::PROBE_TABLE . '`');
        $columns = [];
        while (($row = Database::row()) !== null) {
            $columns[] = (string)$row['Field'];
        }

        return $columns;
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

/**
 * Project facade fixture whose catalog classifies every table of the PII archive.
 */
final class PiiRestoreTestHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = FullPiiRestoreTestCatalog::class;

    /**
     * Creates a no-op DB context for the abstract facade contract; the restore engine
     * talks to the live connection the integration base already opened.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new PiiRestoreTestDbContext();
    }
}

/**
 * Project facade fixture whose catalog leaves the token table unclassified.
 */
final class PartialPiiRestoreTestHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = PartialPiiRestoreTestCatalog::class;

    /**
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new PiiRestoreTestDbContext();
    }
}

/**
 * No-op DB context so the facade fixtures are instantiable.
 */
final class PiiRestoreTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for the restore fixtures.
     */
    public function configure(): void
    {
    }
}

/**
 * Catalog covering both fixture tables: the probe rewritten column by column, the
 * token-shaped table emptied whole. Both are declared by raw table name - they have no
 * Entity class, which is the case the raw-name key exists for.
 */
final class FullPiiRestoreTestCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<int, mixed>> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_PII => [
                0 => [
                    BackupRestorerIntegrationTest::PROBE_TABLE => [
                        'label' => AnonymizationStrategy::MASK,
                        'email' => AnonymizationStrategy::FAKE_EMAIL,
                    ],
                    BackupRestorerIntegrationTest::TOKEN_TABLE => AnonymizationStrategy::PURGE,
                ],
            ],
        ];
    }
}

/**
 * Catalog that forgot a table, the way a project does when a migration adds one.
 */
final class PartialPiiRestoreTestCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<int, mixed>> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_PII => [
                0 => [
                    BackupRestorerIntegrationTest::PROBE_TABLE => [
                        'label' => AnonymizationStrategy::MASK,
                        'email' => AnonymizationStrategy::FAKE_EMAIL,
                    ],
                ],
            ],
        ];
    }
}
