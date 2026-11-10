<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\AdminViewModeLatchFile;
use Hilos\AdminViewMode\AdminViewModeLatchTable;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\ArchiveMigrationMarker;
use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupNotificationType;
use Hilos\Backup\BackupRestorer;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\BackupVerifier;
use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestorePlan;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestoreMigrationGuard;
use Hilos\Backup\RestoreNotifier;
use Hilos\Backup\RestorePhase;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseMarker;
use Hilos\Database\DatabaseMarkerRow;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Migration;
use Hilos\Database\MigrationClaim;
use Hilos\Database\MigrationSqlReader;
use Hilos\Database\Schema\TablesWithoutEntityProvider;
use Hilos\Database\Schema\Schema;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Log\LogLineReader;
use Hilos\Notification\DeferredNotificationQueue;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\RestoredDeliveries;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Users\AdminAudience;
use Hilos\Utils\LogLevel;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * Integration coverage for the restore engine against the live test database.
 *
 * Hand-written mysqldump fixtures carry completed SQL streams and explicit migration rows
 * for migration scenarios, in the creator's archive layout with a real sidecar digest.
 */
final class BackupRestorerIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Probe table the fixtures restore; dropped on teardown. Read by the catalog fixtures below. */
    public const string PROBE_TABLE = 'hilos_fw_restore_probe';

    /** Token-shaped table the anonymization fixtures purge; dropped on teardown. */
    public const string TOKEN_TABLE = 'hilos_fw_restore_token';

    /** Late tables deliberately absent from the old FULL archive. */
    private const string LATE_TABLE = 'hilos_fw_restore_late';
    private const string CHILD_TABLE = 'hilos_fw_restore_child';
    private const string INDEPENDENT_TABLE = 'hilos_fw_restore_independent';

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

    /** Live history rows that a safe preparation refusal must preserve, including failed history. */
    private const int TARGET_APPLIED_INDEX = 9002;
    private const int TARGET_FAILED_INDEX = 9003;

    /**
     * Column the archive-level migration adds, and which a schema archive taken at that level
     * therefore already carries. Replaying that migration over the archive is what fails.
     */
    private const string EARLY_COLUMN = 'early';

    /**
     * Column the fixture migration adds; the proof that migrations ran after the import.
     * Read by the catalog fixtures below, one of which classifies it.
     */
    public const string MIGRATED_COLUMN = 'note';

    /** Value the fixture migration backfills {@see MIGRATED_COLUMN} with, so the pass has work. */
    private const string MIGRATED_VALUE = 'from the migration';

    /**
     * Column the PII archive carries as nullable; one case migrates it to NOT NULL under a
     * registry that declared it for `null`. Read by the catalog fixtures below.
     */
    public const string NULLABLE_COLUMN = 'nickname';

    /** Holder of the rollout claim a fixture archive was taken under; a process of the backup's time. */
    private const string ARCHIVED_CLAIM_HOLDER = 'backup-time-node:4242';

    /** Marker of the database a fixture archive was taken from; a restore must not bring it. */
    private const string ARCHIVED_MARKER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** Marker the target database carries before a restore. */
    private const string TARGET_MARKER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Administrator of the restored database, as its audience names them. */
    public const int ADMIN_USER_ID = 41;

    /** @var list<string> Framework notification tables the announcement cases raise and drop. */
    private const array NOTIFICATION_TABLES = ['hilos_user', 'hilos_notification', 'hilos_setting'];

    private string $storeRoot = '';

    private ?EnvAccessor $previousEnv = null;

    private bool $fixtureMigrationListed = false;

    /** @var ?DbContext Database context to put back after an announcement case swapped it. */
    private ?DbContext $previousDb = null;

    private bool $notificationTablesRaised = false;

    /** Whether a restore fixture raised the delivery journal table. */
    private bool $deliveryTableRaised = false;

    /** Previous operation-log address; every case writes its evidence in its own fixture store. */
    private string|false $previousLogFile = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeRoot = sys_get_temp_dir() . '/hilos-restore-it-' . getmypid();
        $this->removeTree($this->storeRoot);
        // Every scope gets its directory: the store is laid out by scope, and the schema cases
        // publish beside the full ones rather than into a store of their own.
        foreach (BackupScope::cases() as $scope) {
            mkdir($this->storeRoot . '/' . $scope->value, 0700, true);
        }

        // The engine reads BACKUP_DIR off the env facade; a stub-backed accessor lets the
        // test point it at the fixture store (the BackupCreatorTest precedent).
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv('BACKUP_DIR=' . $this->storeRoot);
        $this->previousLogFile = getenv('DAEMON_LOG_FILE');
        putenv('DAEMON_LOG_FILE=' . $this->storeRoot . '/daemon.log');

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
        putenv($this->previousLogFile === false ? 'DAEMON_LOG_FILE' : 'DAEMON_LOG_FILE=' . $this->previousLogFile);
        $this->removeTree($this->storeRoot);
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::sql('DROP TABLE IF EXISTS `' . self::CHILD_TABLE . '`');
        Database::sql('DROP TABLE IF EXISTS `' . self::LATE_TABLE . '`');
        Database::sql('DROP TABLE IF EXISTS `' . self::INDEPENDENT_TABLE . '`');
        Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`');
        Database::sql('DROP TABLE IF EXISTS `' . self::TOKEN_TABLE . '`');
        if ($this->deliveryTableRaised) {
            Database::sql('DROP TABLE IF EXISTS `' . EntityNotificationDelivery::_table . '`');
        }
        if ($this->notificationTablesRaised) {
            Hilos::$db = $this->previousDb;
            self::runNotificationStubs(down: true);
            Schema::reset();
            $this->notificationTablesRaised = false;
        }
        // The anonymization cases capture a project facade; later cases must find the base.
        Hilos::initBrowser();
        Hilos::resetBrowser();
        if ($this->fixtureMigrationListed) {
            // The migration table is shared with every other suite against this database, so
            // the fixture's row leaves with the fixture.
            Migration::initialize();
            Database::sqlRun(
                'DELETE FROM `migration` WHERE `index` IN (?, ?)',
                [self::ARCHIVE_MIGRATION_INDEX, self::CODE_MIGRATION_INDEX],
            );
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

    /** Old FULL data returns, while proven late tables are recreated without target rows, on every run. */
    public function testOldFullRecreatesLateTablesWithoutInheritingTheirRows(): void
    {
        $this->prepareLateTables();
        $marker = DatabaseMarker::current();
        $this->configureSecondTarget($this->databaseName());
        $first = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::sql('DELETE FROM `' . self::PROBE_TABLE . '`');
            Database::sql('INSERT INTO `' . self::PROBE_TABLE . "` VALUES (7, 'target')");
            Database::sql('INSERT INTO `' . self::LATE_TABLE . '` VALUES (11)');
            Database::sql('INSERT INTO `' . self::CHILD_TABLE . '` VALUES (12,11)');
            Database::useConnection(2);

            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

            $this->assertSame(2, Database::getCurrentIndex());
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $this->assertSame([['1', 'archive']], $this->probeRows());
            $this->assertSame([], Database::sql('SELECT * FROM `' . self::LATE_TABLE . '`')->rows());
            $this->assertSame([], Database::sql('SELECT * FROM `' . self::CHILD_TABLE . '`')->rows());
            $this->assertSame([['id' => '13']], Database::sql('SELECT * FROM `' . self::INDEPENDENT_TABLE . '`')->rows());
            $this->assertSame([self::ARCHIVE_MIGRATION_INDEX, self::CODE_MIGRATION_INDEX], Migration::appliedIndices());
            $this->assertEquals($marker, DatabaseMarker::current());
            $state = [$this->probeRows(), Migration::appliedIndices(), DatabaseMarker::current()];
            if ($first !== null) {
                $this->assertEquals($first, $state);
            }
            $first = $state;
        }
        $log = (string)file_get_contents($this->storeRoot . '/daemon.log');
        $this->assertStringContainsString('"phase":"importing","connections":[0],"starting":"drop ' . self::CHILD_TABLE . '"', $log);
        $this->assertLessThan(strpos($log, '"starting":"drop ' . self::LATE_TABLE . '"'),
            strpos($log, '"starting":"drop ' . self::CHILD_TABLE . '"'));
        DatabaseMarker::clear();
    }

    /** Real BOOLEAN defaults and COLLATE-only declarations must authorize the same cleanup as SHOW CREATE. */
    public function testFullRecreatesBooleanAndCollateOnlyTablesWithoutTheirRows(): void
    {
        $this->prepareLateTables();
        Database::sql('DROP TABLE `' . self::CHILD_TABLE . '`');
        Database::sql('DROP TABLE `' . self::LATE_TABLE . '`');
        $sql = 'CREATE TABLE `' . self::LATE_TABLE . '` (id INT PRIMARY KEY, confirmed BOOLEAN NOT NULL DEFAULT FALSE,'
            . ' enabled BOOL DEFAULT TRUE, label VARCHAR(10), code VARCHAR(10) COLLATE ascii_bin) COLLATE=latin1_bin';
        file_put_contents($this->storeRoot . '/migrations/' . self::MIGRATION_TRACK . '/' . self::CODE_MIGRATION_INDEX . '_up.sql', $sql . ";\n");
        Database::sql($sql);
        Database::sql('INSERT INTO `' . self::LATE_TABLE . "`(id,label,code) VALUES(11,'old','old')");
        $physical = Database::sql('SHOW CREATE TABLE `' . self::LATE_TABLE . '`')->field('Create Table');
        $this->assertStringContainsString('`confirmed` tinyint(1) NOT NULL DEFAULT 0', $physical);
        $this->assertStringContainsString('`enabled` tinyint(1) DEFAULT 1', $physical);
        $this->assertStringContainsString('DEFAULT CHARSET=latin1 COLLATE=latin1_bin', $physical);
        $this->assertStringContainsString('CHARACTER SET ascii COLLATE ascii_bin', $physical);
        $marker = DatabaseMarker::current();

        new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

        $this->assertSame([], Database::sql('SELECT * FROM `' . self::LATE_TABLE . '`')->rows());
        $this->assertSame($physical, Database::sql('SHOW CREATE TABLE `' . self::LATE_TABLE . '`')->field('Create Table'));
        $this->assertSame([['1', 'archive']], $this->probeRows());
        $this->assertSame([self::ARCHIVE_MIGRATION_INDEX, self::CODE_MIGRATION_INDEX], Migration::appliedIndices());
        $this->assertEquals($marker, DatabaseMarker::current());
        DatabaseMarker::clear();
    }

    /** @param string $change A real material difference from the successful source CREATE */
    #[DataProvider('booleanAndCollationChanges')]
    public function testBooleanOrCollationMismatchRefusesBeforeRestoreWrites(string $change): void
    {
        $this->prepareLateTables();
        Database::sql('ALTER TABLE `' . self::LATE_TABLE . '` ADD confirmed BOOLEAN NOT NULL DEFAULT FALSE,'
            . ' ADD label VARCHAR(10) COLLATE latin1_bin');
        file_put_contents($this->storeRoot . '/migrations/' . self::MIGRATION_TRACK . '/' . self::CODE_MIGRATION_INDEX . '_up.sql',
            'CREATE TABLE `' . self::LATE_TABLE . '` (id INT PRIMARY KEY,'
            . ' confirmed BOOLEAN NOT NULL DEFAULT FALSE, label VARCHAR(10) COLLATE latin1_bin);' . "\n"
            . ' CREATE TABLE `' . self::CHILD_TABLE . '` (id INT PRIMARY KEY, parent_id INT, CONSTRAINT restore_late_fk'
            . ' FOREIGN KEY(parent_id) REFERENCES `' . self::LATE_TABLE . '`(id) ON DELETE CASCADE);' . "\n");
        Database::sql('ALTER TABLE `' . self::LATE_TABLE . '` ' . $change);
        $this->assertLatePreparationRefuses('structure');
    }

    /** @return iterable<string, array{string}> Literal, nullability, type and collation mismatches */
    public static function booleanAndCollationChanges(): iterable
    {
        yield 'boolean default' => ['MODIFY confirmed BOOLEAN NOT NULL DEFAULT TRUE'];
        yield 'boolean nullable' => ['MODIFY confirmed BOOLEAN DEFAULT FALSE'];
        yield 'boolean type' => ['MODIFY confirmed SMALLINT NOT NULL DEFAULT FALSE'];
        yield 'column collation' => ['MODIFY label VARCHAR(10) COLLATE latin1_general_ci'];
        yield 'table collation' => ['DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci'];
    }

    /** A real creator archive rolls back both schemas, with only the primary track recreating late objects. */
    public function testFullRestoresTheJournalThroughThePrimaryTrack(): void
    {
        $this->prepareLateTables();
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(index: ChangeLogDatabase::CONNECTION_INDEX, host: $config->host, user: $config->user,
            password: $config->password, database: ChangeLogDatabase::configuredName(), port: $config->port, charset: $config->charset);
        Database::connect(ChangeLogDatabase::CONNECTION_INDEX);
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $journalTables = ['hilos_change_log_field', 'hilos_change_log_value', 'hilos_change_log_change',
            'hilos_change_log', 'hilos_change_log_receipt', 'hilos_change_log_table'];
        $previousDb = Hilos::$db;
        $triggerPath = new ReflectionProperty(JournalTriggerFiles::class, 'path')->getValue();
        mkdir($this->storeRoot . '/triggers');
        JournalTriggerFiles::setPath($this->storeRoot . '/triggers');
        Hilos::$db = null;
        try {
            $stub = (string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_change_log.sql');
            foreach (MigrationSqlReader::statements($stub) as $statement) {
                Database::sql($statement);
            }
            Database::sql('INSERT INTO ' . $journal . ".hilos_change_log_table (id, name) VALUES (1, 'archive')");
            Database::sql('DROP TABLE `' . self::CHILD_TABLE . '`');
            Database::sql('DROP TABLE `' . self::LATE_TABLE . '`');
            Database::sql('DELETE FROM migration WHERE `index` = ?', [self::CODE_MIGRATION_INDEX]);
            Database::sql('UPDATE `' . self::PROBE_TABLE . "` SET label='archive'");
            DatabaseMarker::put(new DatabaseMarkerRow(self::ARCHIVED_MARKER, 'archive-node', 1786190399456));
            $metadata = new BackupCreator()->create('journal-old-full', BackupScope::FULL);
            $this->assertNotNull($metadata->sha256);
            $this->assertSame([0, 1], array_column($metadata->connections, 'index'));

            file_put_contents($this->storeRoot . '/migrations/' . self::MIGRATION_TRACK . '/' . self::CODE_MIGRATION_INDEX . '_up.sql',
                'CREATE TABLE {{change_log_database}}.`journal_late` (id INT PRIMARY KEY);' . "\n", FILE_APPEND);
            Migration::migrateUp();
            Database::sql('INSERT INTO ' . $journal . '.journal_late VALUES (15)');
            Database::sql('INSERT INTO `' . self::LATE_TABLE . '` VALUES (11)');
            Database::sql('INSERT INTO `' . self::CHILD_TABLE . '` VALUES (12,11)');
            Database::sql('UPDATE ' . $journal . ".hilos_change_log_table SET name='target'");
            $marker = new DatabaseMarkerRow(self::TARGET_MARKER, 'target-node', 1788256800123);
            DatabaseMarker::put($marker);
            $phases = [];
            new BackupRestorer()->restore('journal-old-full', BackupScope::FULL, RestoreEnvDecision::ALLOW, null,
                static function (RestorePhase $phase) use (&$phases): void {
                    $phases[] = $phase;
                });

            $this->assertSame(RestorePhase::INSTALLING_TRIGGERS, $phases[array_key_last($phases)]);
            $this->assertSame([['7', 'archive']], $this->probeRows());
            $this->assertSame([], Database::sql('SELECT * FROM `' . self::LATE_TABLE . '`')->rows());
            $this->assertSame([], Database::sql('SELECT * FROM `' . self::CHILD_TABLE . '`')->rows());
            $this->assertSame([], Database::sql('SELECT * FROM ' . $journal . '.journal_late')->rows());
            $this->assertSame('archive', Database::sql('SELECT name FROM ' . $journal . '.hilos_change_log_table WHERE id=1')->field('name'));
            $this->assertSame([self::ARCHIVE_MIGRATION_INDEX, self::CODE_MIGRATION_INDEX], Migration::appliedIndices());
            $this->assertEquals($marker, DatabaseMarker::current());
            $this->assertSame([], Database::sql('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?'
                . ' AND TABLE_NAME IN (?, ?, ?, ?)', [ChangeLogDatabase::configuredName(), 'migration', MigrationClaim::TABLE,
                    AdminViewModeLatchTable::TABLE, DatabaseMarker::TABLE])->rows());
            $log = (string)file_get_contents($this->storeRoot . '/daemon.log');
            $this->assertStringContainsString('"connections":[1],"starting":"drop journal_late"', $log);
            $this->assertStringContainsString('"connections":[0,1],"starting":"install journal triggers"', $log);
            $this->assertSame(1, substr_count($log, '"starting":"migrate"'));
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::sql('DROP TABLE IF EXISTS ' . $journal . '.journal_late');
            foreach ($journalTables as $table) {
                Database::sql('DROP TABLE IF EXISTS ' . $journal . '.`' . $table . '`');
            }
            DatabaseMarker::clear();
            Hilos::$db = $previousDb;
            new ReflectionProperty(JournalTriggerFiles::class, 'path')->setValue(null, $triggerPath);
        }
    }

    /** Cleanup cannot remove a successful live INSERT's source before that source is recreated. */
    public function testDmlBeforeCreateRefusesBeforeAnyRestoreWrite(): void
    {
        $this->prepareLateTables();
        $path = $this->storeRoot . '/migrations/' . self::MIGRATION_TRACK . '/' . self::CODE_MIGRATION_INDEX . '_up.sql';
        $source = FsPath::read($path);
        FsPath::write($path, 'INSERT INTO `' . self::PROBE_TABLE . "`(id,label) SELECT id, 'copied' FROM `"
            . self::LATE_TABLE . "`;\n" . $source);
        Database::sql('INSERT INTO `' . self::LATE_TABLE . '` VALUES(11)');
        $this->assertLatePreparationRefuses('absent table');
        $this->assertSame([['id' => '11']], Database::sql('SELECT * FROM `' . self::LATE_TABLE . '`')->rows());
    }

    /** @param string $verb Mutation of an unproven retained table */
    #[DataProvider('retainedDataVerbs')]
    public function testRetainedDmlRefusesAndPreservesItsTrigger(string $verb): void
    {
        $this->prepareLateTables();
        $path = $this->storeRoot . '/migrations/' . self::MIGRATION_TRACK . '/' . self::CODE_MIGRATION_INDEX . '_up.sql';
        FsPath::write($path, ($verb === 'UPDATE' ? 'UPDATE `' . self::INDEPENDENT_TABLE . '` SET id=14'
            : 'DELETE FROM `' . self::INDEPENDENT_TABLE . '`') . " WHERE id=13;\n" . FsPath::read($path));
        Database::sql('CREATE TRIGGER restore_retained_trigger BEFORE DELETE ON `' . self::INDEPENDENT_TABLE . '` FOR EACH ROW SET @seen=1');
        try {
            $this->assertLatePreparationRefuses('unproven target-only');
            $this->assertSame([['id' => '13']], Database::sql('SELECT * FROM `' . self::INDEPENDENT_TABLE . '`')->rows());
            $this->assertSame('restore_retained_trigger', Database::sql('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS'
                . ' WHERE TRIGGER_SCHEMA=? AND TRIGGER_NAME=?', [$this->databaseName(), 'restore_retained_trigger'])->field('TRIGGER_NAME'));
        } finally {
            Database::sql('DROP TRIGGER IF EXISTS restore_retained_trigger');
        }
    }

    /** @return iterable<string, array{string}> Both writes previously admitted as OTHER */
    public static function retainedDataVerbs(): iterable
    {
        yield 'delete' => ['DELETE'];
        yield 'update' => ['UPDATE'];
    }

    /**
     * An UP alias is refused during preparation; the target is never handed to the migration DDL.
     *
     * @param bool $hasMarker Whether the target initially carries a marker
     */
    #[DataProvider('markerStates')]
    public function testLegacyPhysicalAliasInFutureUpRefusesBeforeAnyRestoreWrite(bool $hasMarker): void
    {
        $this->prepareLateTables();
        if (!$hasMarker) {
            DatabaseMarker::clear();
        }
        FsPath::write($this->storeRoot . '/migrations/' . self::MIGRATION_TRACK . '/'
            . self::CODE_MIGRATION_INDEX . '_up.sql', 'DROP TABLE IF EXISTS `#mysql50#' . self::INDEPENDENT_TABLE . '`;' . "\n");
        $history = Database::sql('SELECT * FROM migration ORDER BY `index`')->rows();
        $marker = DatabaseMarker::current();

        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
            $this->fail('An aliased target-only DROP must refuse during preparation');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertSame('Full restore planner, connection 0: Opaque UP operation in '
                . self::CODE_MIGRATION_INDEX . '_up.sql', $failure->getMessage());
            $this->assertSame([['7', 'untouched']], $this->probeRows());
            $this->assertSame([['id' => '13']], Database::sql('SELECT * FROM `' . self::INDEPENDENT_TABLE . '`')->rows());
            $this->assertSame($history, Database::sql('SELECT * FROM migration ORDER BY `index`')->rows());
            $this->assertEquals($marker, DatabaseMarker::current());
        } finally {
            DatabaseMarker::clear();
        }
    }

    /**
     * The fixture records live success without ever executing the destructive future call itself.
     *
     * @param bool $cleanup Whether independent late tables also require cleanup
     * @param bool $bootstrap Whether the function targets the database's bootstrap marker
     */
    #[DataProvider('indirectFutureWrites')]
    public function testFutureFunctionCannotWritePreservedRows(bool $cleanup, bool $bootstrap): void
    {
        $this->prepareLateTables();
        $path = $this->storeRoot . '/migrations/' . self::MIGRATION_TRACK . '/' . self::CODE_MIGRATION_INDEX . '_up.sql';
        $source = "SELECT restore_touch_kept();\n";
        if ($cleanup) {
            $source = FsPath::read($path) . $source;
        } else {
            Database::sql('DROP TABLE `' . self::CHILD_TABLE . '`');
            Database::sql('DROP TABLE `' . self::LATE_TABLE . '`');
        }
        FsPath::write($path, $source);
        $table = $bootstrap ? DatabaseMarker::TABLE : self::INDEPENDENT_TABLE;
        Database::sql('CREATE FUNCTION restore_touch_kept() RETURNS INT NO SQL BEGIN DELETE FROM `' . $table . '`; RETURN 1; END');
        try {
            $this->assertLatePreparationRefuses('restore_touch_kept');
            $this->assertSame([['id' => '13']], Database::sql('SELECT * FROM `' . self::INDEPENDENT_TABLE . '`')->rows());
            if ($cleanup) {
                $this->assertCount(1, Database::sql("SHOW TABLES LIKE '" . self::LATE_TABLE . "'")->rows());
                $this->assertCount(1, Database::sql("SHOW TABLES LIKE '" . self::CHILD_TABLE . "'")->rows());
            }
        } finally {
            Database::sql('DROP FUNCTION IF EXISTS restore_touch_kept');
        }
    }

    /** @return iterable<string, array{bool, bool}> Retained rows and marker, independent of cleanup */
    public static function indirectFutureWrites(): iterable
    {
        yield 'retained without cleanup' => [false, false];
        yield 'retained with cleanup' => [true, false];
        yield 'bootstrap without cleanup' => [false, true];
        yield 'bootstrap with cleanup' => [true, true];
    }

    /** Safe scalar SQL still executes against archive data while retained rows and marker survive. */
    public function testBuiltinFutureSqlPreservesIndependentRowsWithoutCleanup(): void
    {
        $this->prepareLateTables();
        Database::sql('DROP TABLE `' . self::CHILD_TABLE . '`');
        Database::sql('DROP TABLE `' . self::LATE_TABLE . '`');
        FsPath::write($this->storeRoot . '/migrations/' . self::MIGRATION_TRACK . '/' . self::CODE_MIGRATION_INDEX . '_up.sql',
            "SELECT COALESCE(ABS(-1),0), 'restore_touch_kept()';\n"
            . 'INSERT INTO `' . self::PROBE_TABLE . "`(id,label) VALUES(ABS(-2),CONCAT('built','in'));\n");
        $marker = DatabaseMarker::current();
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
            $this->assertSame([['1', 'archive'], ['2', 'builtin']], $this->probeRows());
            $this->assertSame([['id' => '13']], Database::sql('SELECT * FROM `' . self::INDEPENDENT_TABLE . '`')->rows());
            $this->assertSame([self::ARCHIVE_MIGRATION_INDEX, self::CODE_MIGRATION_INDEX], Migration::appliedIndices());
            $this->assertEquals($marker, DatabaseMarker::current());
        } finally {
            DatabaseMarker::clear();
        }
    }

    /** Even the absence of a routine is rechecked before import when cleanup is empty. */
    public function testFutureFunctionCatalogDriftRefusesWithoutCleanup(): void
    {
        $this->prepareLateTables();
        Database::sql('DROP TABLE `' . self::CHILD_TABLE . '`');
        Database::sql('DROP TABLE `' . self::LATE_TABLE . '`');
        FsPath::write($this->storeRoot . '/migrations/' . self::MIGRATION_TRACK . '/' . self::CODE_MIGRATION_INDEX . '_up.sql',
            "SELECT restore_touch_kept();\n");
        $history = Database::sql('SELECT * FROM migration ORDER BY `index`')->rows();
        $marker = DatabaseMarker::current();
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW, null,
                static function (RestorePhase $phase): void {
                    if ($phase === RestorePhase::IMPORTING) {
                        Database::sql('CREATE FUNCTION restore_touch_kept() RETURNS INT NO SQL BEGIN DELETE FROM `'
                            . self::INDEPENDENT_TABLE . '`; RETURN 1; END');
                    }
                });
            $this->fail('Changed routine catalog must refuse before import');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString('changed', $failure->getMessage());
            $this->assertSame([['7', 'untouched']], $this->probeRows());
            $this->assertSame([['id' => '13']], Database::sql('SELECT * FROM `' . self::INDEPENDENT_TABLE . '`')->rows());
            $this->assertSame($history, Database::sql('SELECT * FROM migration ORDER BY `index`')->rows());
            $this->assertEquals($marker, DatabaseMarker::current());
        } finally {
            Database::sql('DROP FUNCTION IF EXISTS restore_touch_kept');
            DatabaseMarker::clear();
        }
    }

    /** A late table gets only the data its real UP derives from the newly imported archive. */
    public function testDmlAfterCreateUsesArchiveRowsWithoutInheritingTargetRows(): void
    {
        $this->prepareLateTables();
        $path = $this->storeRoot . '/migrations/' . self::MIGRATION_TRACK . '/' . self::CODE_MIGRATION_INDEX . '_up.sql';
        FsPath::append($path, 'INSERT INTO `' . self::LATE_TABLE . '`(id) SELECT p.id FROM `' . $this->databaseName()
            . '`.`' . self::PROBE_TABLE . "` p;\n");
        Database::sql('INSERT INTO `' . self::LATE_TABLE . '` VALUES(11)');
        $marker = DatabaseMarker::current();
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
            $this->assertSame([['id' => '1']], Database::sql('SELECT * FROM `' . self::LATE_TABLE . '`')->rows());
            $this->assertSame([['1', 'archive']], $this->probeRows());
            $this->assertSame([self::ARCHIVE_MIGRATION_INDEX, self::CODE_MIGRATION_INDEX], Migration::appliedIndices());
            $this->assertEquals($marker, DatabaseMarker::current());
        } finally {
            DatabaseMarker::clear();
        }
    }

    /** A matching name and CREATE file do not prove that this target ever applied that CREATE. */
    public function testUnprovenLateTableRefusesBeforeAnyRestoreWrite(): void
    {
        $this->prepareLateTables();
        Database::sql('DELETE FROM migration WHERE `index` = ?', [self::CODE_MIGRATION_INDEX]);
        $this->assertLatePreparationRefuses('unproven target-only rows');
    }

    /** Incoming CASCADE from a preserved schema must refuse, rather than deleting that schema's rows. */
    public function testExternalForeignKeyRefusesBeforeAnyRestoreWrite(): void
    {
        $this->prepareLateTables();
        $external = '`' . $this->databaseName() . '-change-log`.' . '`' . self::CHILD_TABLE . '`';
        Database::sql('CREATE TABLE ' . $external . ' (id INT PRIMARY KEY, parent_id INT, CONSTRAINT restore_external_fk'
            . ' FOREIGN KEY(parent_id) REFERENCES `' . $this->databaseName() . '`.`' . self::LATE_TABLE . '`(id) ON DELETE CASCADE)');
        Database::sql('INSERT INTO `' . self::LATE_TABLE . '` VALUES (11)');
        Database::sql('INSERT INTO ' . $external . ' VALUES (14,11)');
        try {
            $this->assertLatePreparationRefuses('restore_external_fk');
            $this->assertSame([['id' => '14', 'parent_id' => '11']], Database::sql('SELECT * FROM ' . $external)->rows());
        } finally {
            Database::sql('DROP TABLE ' . $external);
        }
    }

    /** @return iterable<string, array{string}> Proof dimensions changed at the connected apply boundary */
    public static function lateProofChanges(): iterable
    {
        foreach (['schema', 'history', 'track', 'dependency'] as $change) {
            yield $change => [$change];
        }
    }

    /** @param string $change Deterministic change after planner preparation, before its final recheck */
    #[DataProvider('lateProofChanges')]
    public function testChangedPlannerProofRefusesAtTheRestoreBoundary(string $change): void
    {
        $this->prepareLateTables();
        $expectedHistory = Migration::appliedIndices();
        $marker = DatabaseMarker::current();
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW, null,
                function (RestorePhase $phase) use ($change, &$expectedHistory): void {
                    if ($phase !== RestorePhase::IMPORTING) {
                        return;
                    }
                    match ($change) {
                        'schema' => Database::sql('ALTER TABLE `' . self::LATE_TABLE . '` ADD changed INT'),
                        'history' => Database::sql('DELETE FROM migration WHERE `index` = ?', [self::CODE_MIGRATION_INDEX]),
                        'track' => file_put_contents($this->storeRoot . '/migrations/' . self::MIGRATION_TRACK . '/'
                            . self::CODE_MIGRATION_INDEX . '_up.sql', "SELECT 1;\n"),
                        'dependency' => Database::sql('CREATE VIEW `' . self::INDEPENDENT_TABLE . '_view` AS SELECT id FROM `'
                            . self::LATE_TABLE . '`'),
                    };
                    $expectedHistory = Migration::appliedIndices();
                });
            $this->fail('Changed planner evidence must refuse before cleanup');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString('changed', $failure->getMessage());
            $this->assertSame([['7', 'untouched']], $this->probeRows());
            $this->assertSame($expectedHistory, Migration::appliedIndices());
            $this->assertEquals($marker, DatabaseMarker::current());
            $this->assertSame([['id' => '13']], Database::sql('SELECT * FROM `' . self::INDEPENDENT_TABLE . '`')->rows());
        } finally {
            Database::sql('DROP VIEW IF EXISTS `' . self::INDEPENDENT_TABLE . '_view`');
            DatabaseMarker::clear();
        }
    }

    /** @param bool $hasMarker Whether the target had a marker before cleanup */
    #[DataProvider('markerStates')]
    public function testImportFailureAfterCleanupRemainsPartialAndKeepsTheMarker(bool $hasMarker): void
    {
        $this->prepareLateTables(duplicateRow: true);
        if (!$hasMarker) {
            DatabaseMarker::clear();
        }
        $marker = DatabaseMarker::current();
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
            $this->fail('Duplicate data must fail after the proven DROP operations');
        } catch (RestoreFailedException $failure) {
            $this->assertTrue($failure->databaseTouched());
            $this->assertStringStartsWith('phase=importing, connections=0, step=import:', $failure->getMessage());
            $this->assertSame([['1', 'archive']], $this->probeRows());
            $this->assertSame([], Database::sql('SHOW TABLES LIKE ?', [self::LATE_TABLE])->rows());
            $this->assertSame([], Database::sql('SHOW TABLES LIKE ?', [self::CHILD_TABLE])->rows());
            $this->assertEquals($marker, DatabaseMarker::current());
        } finally {
            DatabaseMarker::clear();
        }
    }

    /**
     * The row half of the admin view mode latch against a restore from an archive taken before the
     * latch existed (HIL-1249): the dump does not carry its table, and a table outside the dump is
     * left as it was, so the live latch survives. The file half lives in the log directory and a
     * restore touches no such thing.
     *
     * @throws DatabaseException When the latch row cannot be written, read or removed
     */
    public function testALiveAdminViewModeLatchSurvivesARestoreOfAnArchiveWithoutItsTable(): void
    {
        Migration::initialize();
        Database::sql('DELETE FROM `' . AdminViewModeLatchTable::TABLE . '`');
        AdminViewModeLatchTable::close('prod', 'latch-node', 1700000000123);
        $this->publishFixtureBackup($this->probeDumpSql([['1', 'alpha']]));

        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

            $this->assertSame(
                [
                    AdminViewModeLatchFile::KEY_ENVIRONMENT => 'prod',
                    AdminViewModeLatchFile::KEY_NODE => 'latch-node',
                    AdminViewModeLatchFile::KEY_CLOSED_AT => 1700000000123,
                ],
                AdminViewModeLatchTable::read(),
            );
        } finally {
            Database::sql('DELETE FROM `' . AdminViewModeLatchTable::TABLE . '`');
        }
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
        // Both INSERTs are admitted literal dump data. The second fails in mysql after the
        // table was replaced and the first row committed, rather than failing dump admission.
        $this->publishFixtureBackup($this->probeDumpSql([['1', 'alpha']])
            . 'INSERT INTO `' . self::PROBE_TABLE . "` VALUES (1, 'duplicate');\n");
        $workDir = sys_get_temp_dir() . '/hilos-restore-' . getmypid() . '-' . self::BACKUP_ID;

        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
            $this->fail('A failing import must surface as RestoreFailedException');
        } catch (RestoreFailedException $failure) {
            $this->assertStringContainsString('import into connection 0', $failure->getMessage());
            $this->assertTrue($failure->databaseTouched());
            $this->assertStringStartsWith('phase=importing, connections=0, step=import:', $failure->getMessage());
        }

        $this->assertSame([['1', 'alpha']], $this->probeRows());
        $this->assertDirectoryDoesNotExist($workDir, 'The temp workdir must be swept even on failure');
    }

    /** Even an error from the first write-capable SQL cannot promise that replacement never began. */
    public function testFailureOfTheFirstImportStatementStillReportsPartialReplacement(): void
    {
        $this->publishFixtureBackup('CREATE TABLE `' . self::TOKEN_TABLE . "` (id INT NOT NULL DEFAULT NULL);\n");
        $this->raiseUntouchedProbe();
        Migration::initialize();
        $history = Migration::appliedIndices();
        $marker = new DatabaseMarkerRow(self::TARGET_MARKER, 'target-node', 1788256800123);
        DatabaseMarker::put($marker);
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
            $this->fail('Invalid SQL default must fail in the first mysql statement');
        } catch (RestoreFailedException $failure) {
            $this->assertTrue($failure->databaseTouched());
            $this->assertStringStartsWith('phase=importing, connections=0, step=import:', $failure->getMessage());
            $this->assertStringContainsString('Invalid default value', $failure->getMessage());
            $this->assertSame([['7', 'untouched']], $this->probeRows());
            $this->assertSame($history, Migration::appliedIndices());
            $this->assertEquals($marker, DatabaseMarker::current());
        } finally {
            DatabaseMarker::clear();
        }
    }

    /** A metadata session hiding a physical change must refuse with the old target intact. */
    public function testTruncatedPhysicalDeclarationRefusesBeforeAnyRestoreWrite(): void
    {
        $this->prepareLateTables();
        Database::sql('ALTER TABLE `' . self::LATE_TABLE . '` MODIFY id INT NOT NULL AUTO_INCREMENT');
        Database::sql('INSERT INTO `' . self::LATE_TABLE . '` VALUES(11)');
        $originalMode = Database::sql('SELECT @@SESSION.sql_mode AS mode')->field('mode');
        try {
            Database::sql("SET SESSION sql_mode = 'NO_FIELD_OPTIONS'");
            $this->assertLatePreparationRefuses('SHOW CREATE');
            $this->assertSame(11, (int)Database::sql('SELECT id FROM `' . self::LATE_TABLE . '`')->field('id'));
        } finally {
            Database::sql('SET SESSION sql_mode = ?', [$originalMode]);
        }
    }

    /** Actual future FULL history cannot bypass the code gate through a null sidecar or an empty cleanup. */
    public function testActualFutureArchiveRefusesBeforeAnyRestoreWrite(): void
    {
        $this->listFixtureMigration();
        $futureLevel = self::CODE_MIGRATION_INDEX + 1;
        $dump = $this->probeDumpSql([['1', 'archive']]) . $this->markerDumpSql(self::ARCHIVED_MARKER)
            . "DROP TABLE IF EXISTS migration;\nCREATE TABLE migration (`index` INT PRIMARY KEY, failed TINYINT NOT NULL);\n"
            . "INSERT INTO migration VALUES ({$futureLevel},0);\n";
        $this->publishFixtureBackup($dump, migrationIndex: null);
        $this->assertPreparationKeepsPrimary('archive at migration ' . $futureLevel . ', code expects ' . self::CODE_MIGRATION_INDEX);
    }

    /** A missing later input refuses with the first target's sentinel, history and marker intact. */
    public function testMissingSecondDumpRefusesBeforeTheFirstTargetIsWritten(): void
    {
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $this->configureSecondTarget($config->database . '-change-log');
        $this->publishFixtureBackup(
            $this->probeDumpSql([['1', 'archive']]),
            connections: [new BackupConnectionMeta(0, 'source-primary', 0), new BackupConnectionMeta(2, 'source-second', 0)],
        );
        $this->assertPreparationKeepsPrimary('connection 2', restoreIndex: 2);
    }

    /** Availability of later connections is checked while the primary still holds its old data. */
    public function testUnavailableSecondTargetRefusesBeforeTheFirstTargetIsWritten(): void
    {
        $this->configureSecondTarget($this->databaseName(), port: 1);
        $this->publishFixtureBackup(
            $this->probeDumpSql([['1', 'archive']]),
            connections: [new BackupConnectionMeta(0, 'source-primary', 0), new BackupConnectionMeta(2, 'source-second', 0)],
            extraDumps: [2 => "-- Dump completed on 2026-08-08 12:00:00\n"],
        );
        $this->assertPreparationKeepsPrimary('connection 2');
    }

    /** Different indices do not permit two imports into one physical schema. */
    public function testDuplicatePhysicalTargetRefusesBeforeAnyImport(): void
    {
        $this->configureSecondTarget($this->databaseName());
        $this->publishFixtureBackup(
            $this->probeDumpSql([['1', 'archive']]),
            connections: [new BackupConnectionMeta(0, 'source', 0), new BackupConnectionMeta(2, 'another-source', 0)],
        );
        $this->assertPreparationKeepsPrimary('Duplicate physical target');
    }

    /** Repeated sidecar indices must never cause the same schema to be imported twice. */
    public function testDuplicateConnectionIndexRefusesBeforeAnyImport(): void
    {
        $this->publishFixtureBackup(
            $this->probeDumpSql([['1', 'archive']]),
            connections: [new BackupConnectionMeta(0, 'source', 0), new BackupConnectionMeta(0, 'source', 0)],
        );
        $this->assertPreparationKeepsPrimary('Duplicate connection index');
    }

    /** An unsupported marker mutation after ordinary table data is refused before primary is imported. */
    public function testUnrecognizedMarkerWriteRefusesBeforeAnyImport(): void
    {
        $this->publishFixtureBackup($this->probeDumpSql([['1', 'archive']])
            . 'UPDATE `' . DatabaseMarker::TABLE . "` SET marker='" . self::ARCHIVED_MARKER . "';\n");
        $this->assertPreparationKeepsPrimary('Unsupported full dump statement family');
    }

    /**
     * Admission refusals preserve target data, complete history and the original marker through the public restore engine.
     *
     * @param string $sql Unsafe fragment appended to a complete archive
     * @param string $reason Expected admission refusal
     */
    #[DataProvider('executionDependentDumps')]
    public function testExecutionDependentFullDumpCannotWriteTheTarget(string $sql, string $reason): void
    {
        $this->publishFixtureBackup($this->probeDumpSql([['1', 'archive']]) . $sql, migrationIndex: null);
        $this->assertPreparationKeepsPrimary($reason);
    }

    /** @return iterable<string, array{string, string}> Unsafe dump fragments and the required admission refusal */
    public static function executionDependentDumps(): iterable
    {
        yield 'conditional migration history' => ['CREATE TABLE migration (`index` INT, failed TINYINT);'
            . ' INSERT INTO migration VALUES (1,0); /*!999999 INSERT INTO migration VALUES (2,0) */;', 'executable comment'];
        yield 'conditional object' => ['/*!999999 CREATE TABLE later(id INT) */;', 'executable comment'];
        yield 'inherited quote mode' => [<<<'SQL'
CREATE TABLE notes(text LONGTEXT);
INSERT INTO notes VALUES ('x\'); DROP TABLE hilos_database_marker; -- ');
SQL, 'SQL mode'];
        yield 'mixed marker setup' => [<<<'SQL'
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO', @saved_cs_client=@@character_set_client;
CREATE TABLE hilos_database_marker(id TINYINT UNSIGNED, marker CHAR(32), written_by VARCHAR(255), written_at DATETIME);
SET character_set_client=@saved_cs_client;
SQL, 'mixed'];
        yield 'legacy marker alias' => ['DROP TABLE IF EXISTS `#mysql50#hilos_database_marker`;'
            . ' CREATE TABLE `#mysql50#hilos_database_marker`(id INT);', 'legacy'];
    }

    /**
     * The mysql child inherits both quote-affecting modes while PHP's already-open preparation session does not.
     *
     * @param bool $setsMode Whether the retained dump explicitly establishes safe quoting
     */
    #[DataProvider('dumpModeSetups')]
    public function testMysqlQuoteModeIsEstablishedByTheExecutedDump(bool $setsMode): void
    {
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(index: DatabaseConnectionDefaults::PRIMARY_INDEX, host: $config->host, user: 'root',
            password: Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(), database: $config->database,
            port: $config->port, charset: $config->charset, socket: $config->socket);
        Database::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $originalMode = Database::sql('SELECT @@GLOBAL.sql_mode AS sql_mode')->row()['sql_mode'];
        try {
            Database::sql('SET GLOBAL sql_mode = ?', ['NO_BACKSLASH_ESCAPES,ANSI_QUOTES']);
            if (!$setsMode) {
                $this->publishFixtureBackup(<<<'SQL'
CREATE TABLE notes(text LONGTEXT);
INSERT INTO notes VALUES ('x\'); DROP TABLE hilos_database_marker; -- ');
SQL);
                $this->assertPreparationKeepsPrimary('SQL mode');
                return;
            }
            Migration::initialize();
            $marker = new DatabaseMarkerRow(self::TARGET_MARKER, 'target-node', 1788256800123);
            DatabaseMarker::put($marker);
            $this->publishFixtureBackup("SET SQL_MODE='';\n" . $this->markerDumpSql(self::ARCHIVED_MARKER)
                . $this->probeDumpSql([['1', 'backslash\\\\end "quoted"']]));

            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

            $this->assertSame([['1', 'backslash\\end "quoted"']], $this->probeRows());
            $this->assertEquals($marker, DatabaseMarker::current());
        } finally {
            Database::sql('SET GLOBAL sql_mode = ?', [$originalMode]);
            DatabaseMarker::clear();
            Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::configure(index: DatabaseConnectionDefaults::PRIMARY_INDEX, host: $config->host, user: $config->user,
                password: $config->password, database: $config->database, port: $config->port,
                charset: $config->charset, socket: $config->socket);
            Database::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    /** @return iterable<string, array{bool}> Retained safe setup and the unsafe inherited-mode legacy stream */
    public static function dumpModeSetups(): iterable
    {
        yield 'explicit safe setup' => [true];
        yield 'no mode setup' => [false];
    }

    /** A partial later SQL file must be admitted before any earlier destination is overwritten. */
    public function testIncompleteSecondDumpRefusesBeforeTheFirstTargetIsWritten(): void
    {
        $this->configureSecondTarget($this->databaseName() . '-change-log');
        $this->publishFixtureBackup(
            $this->probeDumpSql([['1', 'archive']]),
            connections: [new BackupConnectionMeta(0, 'source-primary', 0), new BackupConnectionMeta(2, 'source-second', 0)],
            extraDumps: [2 => 'CREATE TABLE `truncated_dump` (`id` int);'],
        );
        $this->assertPreparationKeepsPrimary('Incomplete full dump');
    }

    /** Identical schema names on two real servers remain distinct, with every import using its checked binding. */
    public function testEveryPreparedTargetIsImportedIntoItsConfiguredSchema(): void
    {
        Database::configure(
            index: 2,
            host: (string)getenv('HILOS_TEST_REMOTE_DB_HOST'),
            user: (string)getenv('HILOS_TEST_REMOTE_DB_USERNAME'),
            password: (string)getenv('HILOS_TEST_REMOTE_DB_PASSWORD'),
            database: $this->databaseName(),
            port: (int)getenv('HILOS_TEST_REMOTE_DB_PORT'),
            charset: DatabaseConnectionDefaults::CHARSET,
        );
        $this->publishFixtureBackup(
            $this->probeDumpSql([['1', 'primary']]),
            connections: [new BackupConnectionMeta(2, 'source-second', 0), new BackupConnectionMeta(0, 'source-primary', 0)],
            extraDumps: [2 => $this->probeDumpSql([['2', 'second']]) . "-- Dump completed on 2026-08-08 12:00:00\n"],
        );
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

            $this->assertSame([['1', 'primary']], $this->probeRows());
            Database::useConnection(2);
            $this->assertSame([['2', 'second']], $this->probeRows());
            $this->assertStringContainsString('"connections":[2],"starting":"import"',
                (string)file_get_contents($this->storeRoot . '/daemon.log'));
        } finally {
            Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`', index: 2);
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    /** The archived database name describes the source and never selects a mysql destination. */
    public function testSourceSchemaNameDoesNotSelectTheImportTarget(): void
    {
        $this->configureSecondTarget($this->databaseName());
        $this->publishFixtureBackup(
            $this->probeDumpSql([['1', 'archive']]),
            connections: [new BackupConnectionMeta(0, 'source_schema_that_does_not_exist', 0)],
        );

        Database::useConnection(2);
        new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

        $this->assertSame(2, Database::getCurrentIndex());
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $this->assertSame([['1', 'archive']], $this->probeRows());
    }

    /** @param bool $legacy Whether the archive predates signed-millisecond marker storage */
    #[DataProvider('markerArchiveFormats')]
    public function testFullRestorePreservesExactTargetMillisecondsAndOmitsForeignMarker(bool $legacy): void
    {
        Migration::initialize();
        $marker = new DatabaseMarkerRow(self::TARGET_MARKER, 'target-node', 2208988800456);
        DatabaseMarker::put($marker);
        $block = $this->markerDumpSql(self::ARCHIVED_MARKER);
        if (!$legacy) {
            $block = str_replace(['`written_at` datetime', "'2026-08-08 11:59:59'"],
                ['`written_at` BIGINT', '-1'], $block);
        }
        $this->publishFixtureBackup($block . $this->probeDumpSql([['1', 'archive']]));
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
            $this->assertEquals($marker, DatabaseMarker::current());
            $this->assertSame([['1', 'archive']], $this->probeRows());
        } finally {
            DatabaseMarker::clear();
        }
    }

    /** @return list<array{bool}> Both historical and current marker blocks */
    public static function markerArchiveFormats(): array
    {
        return [[true], [false]];
    }

    /** Required operation evidence is written even with ordinary logging disabled. */
    public function testOperationLogRecordsTargetsAndMarkerAtErrorOnlyLevel(): void
    {
        Migration::initialize();
        $marker = new DatabaseMarkerRow(self::TARGET_MARKER, 'target-node', 1788256800123);
        DatabaseMarker::put($marker);
        $this->publishFixtureBackup($this->markerDumpSql(self::ARCHIVED_MARKER) . $this->probeDumpSql([['1', 'archive']]));
        $previousLevel = Logger::writeLevel();
        try {
            Logger::setWriteLevel(LogLevel::Error);
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

            $lines = file($this->storeRoot . '/daemon.log', FILE_IGNORE_NEW_LINES);
            $this->assertIsArray($lines);
            $this->assertNotNull(LogLineReader::stampText($lines[0]));
            $this->assertNotNull(LogLineReader::stampMilliseconds($lines[0]));
            $this->assertStringStartsWith('Restore: ', LogLineReader::entryText($lines[0]));
            $this->assertStringContainsString('"archive":', $lines[0]);
            $this->assertStringContainsString('"index":0', $lines[0]);
            $this->assertStringContainsString('"database":"' . $this->databaseName() . '"', $lines[0]);
            $this->assertStringContainsString('"host":', $lines[0]);
            $this->assertStringContainsString('"server":', $lines[0]);
            $this->assertStringContainsString('"primaryMarker":"' . self::TARGET_MARKER . '"', $lines[0]);
            $this->assertStringContainsString('"phase":"importing","connections":[0],"starting":"import"', $lines[1]);
            $this->assertStringContainsString('"phase":"migrating","connections":[0],"starting":"migrate"', $lines[2]);
            $this->assertStringNotContainsString('password', implode("\n", $lines));
            $this->assertStringNotContainsString('INSERT', implode("\n", $lines));
            $this->assertStringNotContainsString(self::ARCHIVED_MARKER, implode("\n", $lines));
        } finally {
            Logger::setWriteLevel($previousLevel);
            DatabaseMarker::clear();
        }
    }

    /** A log that cannot accept the pre-replacement record is a safe refusal. */
    public function testUnwritableOperationLogRefusesBeforeAnyImport(): void
    {
        putenv('DAEMON_LOG_FILE=' . $this->storeRoot);
        $this->publishFixtureBackup($this->probeDumpSql([['1', 'archive']]));
        $this->assertPreparationKeepsPrimary('Cannot write the restore operation log');
    }

    /** Preparing a plan reads history and stages primary without initializing or changing the target. */
    public function testFullPlanPreparationDoesNotWriteTheTarget(): void
    {
        $this->raiseUntouchedProbe();
        Migration::initialize();
        $history = Migration::appliedIndices();
        $marker = DatabaseMarker::current();
        $workDir = $this->storeRoot . '/plan-input';
        mkdir($workDir);
        file_put_contents($workDir . '/db-0.sql', $this->probeDumpSql([['1', 'archive']])
            . "CREATE TABLE `migration` (`index` int, `failed` tinyint);\n"
            . "INSERT INTO `migration` VALUES (7,0);\n-- Dump completed on 2026-08-08 12:00:00\n");
        $this->configureSecondTarget($this->databaseName());
        Database::useConnection(2);

        $plan = FullRestorePlan::prepare([new BackupConnectionMeta(0, 'source-only', 7)], $workDir);
        $plan->assertUnchanged();

        $this->assertSame(2, Database::getCurrentIndex());
        $this->assertSame(0, $plan->targets[0]->connection->index);
        $this->assertSame($this->databaseName(), $plan->targets[0]->config->database);
        $this->assertSame(7, $plan->targets[0]->dump->migrationHistory->rows[0]->index);
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $this->assertSame([['7', 'untouched']], $this->probeRows());
        $this->assertSame($history, Migration::appliedIndices());
        $this->assertEquals($marker, DatabaseMarker::current());
    }

    /** @return iterable<string, array{bool}> Target marker presence */
    public static function markerStates(): iterable
    {
        yield 'existing marker' => [true];
        yield 'absent marker' => [false];
    }

    /**
     * The archive's marker never reaches a target, even when SQL fails after committed writes.
     *
     * @param bool $hasMarker Whether the target initially carries a marker
     */
    #[DataProvider('markerStates')]
    public function testSqlFailureCannotInstallArchivedMarker(bool $hasMarker): void
    {
        Migration::initialize();
        DatabaseMarker::clear();
        $marker = $hasMarker ? new DatabaseMarkerRow(self::TARGET_MARKER, 'target-node', 1788256800123) : null;
        if ($marker !== null) {
            DatabaseMarker::put($marker);
        }
        $this->publishFixtureBackup($this->markerDumpSql(self::ARCHIVED_MARKER)
            . $this->probeDumpSql([['1', 'archive']])
            . 'INSERT INTO `' . self::PROBE_TABLE . "` VALUES (1, 'duplicate');\n");

        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
            $this->fail('Duplicate data must fail in mysql after replacement started');
        } catch (RestoreFailedException $failure) {
            $this->assertTrue($failure->databaseTouched());
            $this->assertStringStartsWith('phase=importing, connections=0, step=import:', $failure->getMessage());
        } finally {
            $this->assertEquals($marker, DatabaseMarker::current());
            DatabaseMarker::clear();
        }
        $this->assertSame([['1', 'archive']], $this->probeRows());
        $log = (string)file_get_contents($this->storeRoot . '/daemon.log');
        $this->assertStringContainsString('"starting":"import"', $log);
        $this->assertStringNotContainsString(self::ARCHIVED_MARKER, $log);
    }

    /**
     * A process lost after import but before migrations cannot leave the archive's marker behind.
     *
     * @param bool $hasMarker Whether the target initially carries a marker
     */
    #[DataProvider('markerStates')]
    public function testMarkerIsAlreadyPreservedBeforeTheMigrateStep(bool $hasMarker): void
    {
        Migration::initialize();
        DatabaseMarker::clear();
        $marker = $hasMarker ? new DatabaseMarkerRow(self::TARGET_MARKER, 'target-node', 1788256800123) : null;
        if ($marker !== null) {
            DatabaseMarker::put($marker);
        }
        $this->publishFixtureBackup($this->markerDumpSql(self::ARCHIVED_MARKER) . $this->probeDumpSql([['1', 'archive']]));

        try {
            new BackupRestorer()->restore(
                self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW, null,
                function (RestorePhase $phase) use ($marker): void {
                    if ($phase === RestorePhase::MIGRATING) {
                        $this->assertEquals($marker, DatabaseMarker::current());
                        throw new RestoreFailedException('Interrupted after the import');
                    }
                },
            );
            $this->fail('The interruption must surface');
        } catch (RestoreFailedException $failure) {
            $this->assertTrue($failure->databaseTouched());
            $this->assertStringContainsString('Interrupted after the import', $failure->getMessage());
        } finally {
            $this->assertEquals($marker, DatabaseMarker::current());
            DatabaseMarker::clear();
        }
    }

    /** @return iterable<string, array{string}> Inputs and bindings that can drift after admission */
    public static function preparedChanges(): iterable
    {
        foreach (['source', 'staged', 'config', 'indices', 'marker', 'rollout', 'sidecar'] as $change) {
            yield $change => [$change];
        }
    }

    /**
     * Visible drift at the last pre-import phase refuses while the primary sentinel is intact.
     *
     * @param string $change Evidence changed after preparation
     */
    #[DataProvider('preparedChanges')]
    public function testPreparedChangesRefuseBeforeWriting(string $change): void
    {
        $archive = $this->publishFixtureBackup($this->probeDumpSql([['1', 'archive']]));
        $this->raiseUntouchedProbe();
        Migration::initialize();
        DatabaseMarker::clear();
        $history = Migration::appliedIndices();
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        try {
            new BackupRestorer()->restore(
                self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW, null,
                function (RestorePhase $phase) use ($change, $archive, $config): void {
                    if ($phase !== RestorePhase::IMPORTING) {
                        return;
                    }
                    $source = sys_get_temp_dir() . '/hilos-restore-' . getmypid() . '-' . self::BACKUP_ID . '/db-0.sql';
                    switch ($change) {
                        case 'source':
                        case 'staged':
                            file_put_contents($change === 'staged' ? $source . '.staged' : $source, "\n-- changed\n", FILE_APPEND);
                            break;
                        case 'config':
                            Database::configure(index: 0, host: $config->host, user: $config->user, password: $config->password,
                                database: $config->database, port: 1, charset: $config->charset);
                            break;
                        case 'indices':
                            $this->configureSecondTarget($config->database);
                            break;
                        case 'marker':
                            DatabaseMarker::put(new DatabaseMarkerRow(self::TARGET_MARKER, 'other-node', 1788256800123));
                            break;
                        case 'rollout':
                            Database::sql('INSERT INTO `' . MigrationClaim::TABLE . '` VALUES (?, ?, ?)',
                                [MigrationClaim::CLAIM_ID, 'foreign-process', 1788256800123]);
                            break;
                        case 'sidecar':
                            $path = substr($archive, 0, -strlen(BackupCreator::ARCHIVE_EXTENSION)) . BackupCreator::SIDECAR_EXTENSION;
                            $metadata = json_decode((string)file_get_contents($path), true);
                            $metadata[BackupMetadata::keep] = true;
                            file_put_contents($path, json_encode($metadata));
                            break;
                    }
                },
            );
            $this->fail('Changed preparation evidence must refuse the import');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString('connections=0', $failure->getMessage());
            if ($change === 'rollout') {
                $this->assertStringContainsString('A schema rollout is held by foreign-process', $failure->getMessage());
            }
        } finally {
            if ($change === 'config') {
                Database::configure(index: 0, host: $config->host, user: $config->user, password: $config->password,
                    database: $config->database, port: $config->port, charset: $config->charset, socket: $config->socket);
            }
            MigrationClaim::clear();
            DatabaseMarker::clear();
        }
        $this->assertSame([['7', 'untouched']], $this->probeRows());
        $this->assertSame($history, Migration::appliedIndices());
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
            null,
            static function (RestorePhase $phase) use (&$phases): void {
                $phases[] = $phase;
            },
        );

        $this->assertContains(RestorePhase::MIGRATING, $phases);
        $this->assertNotContains(RestorePhase::INSTALLING_TRIGGERS, $phases);
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

    public function testARolloutClaimTheArchiveCarriedIsClearedBeforeMigrating(): void
    {
        $this->listFixtureMigration();
        // A FULL dump taken while a process held the claim carries that row. Left in place it
        // would keep the migrate step below waiting for a process of the backup's time forever.
        $this->publishFixtureBackup(
            $this->probeDumpSql([['1', 'alpha']]) . $this->claimDumpSql(self::ARCHIVED_CLAIM_HOLDER),
            self::ARCHIVE_MIGRATION_INDEX,
        );

        new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

        $this->assertSame(self::CODE_MIGRATION_INDEX, Migration::getCurrentIndex());
        $this->assertNull(MigrationClaim::current(), 'The claim table must be left empty');
    }

    /**
     * The database marker names the database, not its content (HIL-1206): an archive taken from
     * another database carries that database's name, and the target keeps its own.
     *
     * @throws DatabaseException When the marker row cannot be written, read or removed
     */
    public function testTheTargetKeepsItsOwnDatabaseMarkerOverTheArchivedOne(): void
    {
        Migration::initialize();
        DatabaseMarker::put(new DatabaseMarkerRow(self::TARGET_MARKER, 'target-node', 1788256800123));
        $this->publishFixtureBackup($this->probeDumpSql([['1', 'alpha']]) . $this->markerDumpSql(self::ARCHIVED_MARKER));

        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

            $kept = DatabaseMarker::current();
            $this->assertSame(self::TARGET_MARKER, $kept?->marker);
            $this->assertSame('target-node', $kept?->writtenBy);
        } finally {
            DatabaseMarker::clear();
        }
    }

    /**
     * A target without a marker stays without one: its first node writes its own, rather than
     * taking the name of the database the archive came from.
     *
     * @throws DatabaseException When the marker row cannot be read or removed
     */
    public function testATargetWithoutADatabaseMarkerIsLeftWithoutOne(): void
    {
        Migration::initialize();
        DatabaseMarker::clear();
        $this->publishFixtureBackup($this->probeDumpSql([['1', 'alpha']]) . $this->markerDumpSql(self::ARCHIVED_MARKER));

        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

            $this->assertNull(DatabaseMarker::current(), 'The archived marker must not be left behind');
        } finally {
            DatabaseMarker::clear();
        }
    }

    public function testRestoreSettlesPendingDeliveriesButLeavesSentRowsAlone(): void
    {
        $this->publishFixtureBackup($this->deliveryDumpSql());
        $this->deliveryTableRaised = true;

        new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

        $rows = $this->deliveryRows();
        $this->assertSame(DeliveryStatus::FAILED, $rows[0][EntityNotificationDelivery::status]);
        $this->assertSame(RestoredDeliveries::UNKNOWN_FATE, $rows[0][EntityNotificationDelivery::last_error]);
        $this->assertSame(2, (int)$rows[0][EntityNotificationDelivery::attempts]);
        $this->assertNull($rows[0][EntityNotificationDelivery::delivered_at]);
        $this->assertNotSame('1786190340123', $rows[0][EntityNotificationDelivery::updated_at]);
        $this->assertSame(DeliveryStatus::SENT, $rows[1][EntityNotificationDelivery::status]);
        $this->assertSame(1, (int)$rows[1][EntityNotificationDelivery::attempts]);
        $this->assertNull($rows[1][EntityNotificationDelivery::last_error]);
        $this->assertSame('1786190220123', $rows[1][EntityNotificationDelivery::delivered_at]);
    }

    public function testRestoreWritesThePendingReasonAfterAnonymization(): void
    {
        $this->publishFixtureBackup($this->deliveryDumpSql());
        $this->deliveryTableRaised = true;
        $previousDb = Hilos::$db;
        $db = new DeliveryPiiRestoreTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        try {
            PiiRestoreTestHilos::initBrowser();
            new BackupRestorer()->restore(
                self::BACKUP_ID,
                BackupScope::FULL,
                RestoreEnvDecision::REQUIRE_ANONYMIZATION,
            );

            $rows = $this->deliveryRows();
            $this->assertSame(DeliveryStatus::FAILED, $rows[0][EntityNotificationDelivery::status]);
            $this->assertSame(RestoredDeliveries::UNKNOWN_FATE, $rows[0][EntityNotificationDelivery::last_error]);
        } finally {
            Hilos::$db = $previousDb;
        }
    }

    public function testSchemaOnlyRestoreDoesNotSettlePendingDeliveries(): void
    {
        // Rows in a schema-only fixture reveal whether the settle step ran despite its scope.
        $this->publishFixtureBackup(
            $this->deliveryDumpSql() . ArchiveMigrationMarker::statement(0),
            0,
            BackupScope::SCHEMA_ONLY,
        );
        $this->deliveryTableRaised = true;

        new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::SCHEMA_ONLY, RestoreEnvDecision::ALLOW);

        $rows = $this->deliveryRows();
        $this->assertSame(DeliveryStatus::PENDING, $rows[0][EntityNotificationDelivery::status]);
        $this->assertSame('possibly sent', $rows[0][EntityNotificationDelivery::last_error]);
        $this->assertSame(2, (int)$rows[0][EntityNotificationDelivery::attempts]);
    }

    public function testASchemaArchiveIsLeftAtTheLevelItsMarkerDeclares(): void
    {
        // A schema archive imports an empty `migration` table, so without the marker's level
        // being recorded the restore would read level 0 and replay everything. Here that would
        // not merely be wasteful, it would fail: the archive-level migration adds a column the
        // imported schema already carries, so a green run IS the proof that it was skipped.
        $this->listSchemaArchiveMigrations();
        $this->publishFixtureBackup(
            $this->schemaDumpSql(self::ARCHIVE_MIGRATION_INDEX),
            self::ARCHIVE_MIGRATION_INDEX,
            BackupScope::SCHEMA_ONLY,
        );
        Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`');

        new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::SCHEMA_ONLY, RestoreEnvDecision::ALLOW);

        $this->assertSame(self::CODE_MIGRATION_INDEX, Migration::getCurrentIndex());
        $columns = $this->probeColumns();
        $this->assertContains(self::EARLY_COLUMN, $columns, 'The archive brought this column itself');
        $this->assertContains(self::MIGRATED_COLUMN, $columns, 'Only what is above the marker is applied');
    }

    public function testASchemaArchiveDeclaringNoLevelRefusesBeforeTheFirstImport(): void
    {
        // An archive written before the marker existed. It is restorable, but not silently: the
        // level cannot be guessed, and guessing it wrong replays history over a finished schema.
        $this->listSchemaArchiveMigrations();
        $this->publishFixtureBackup(
            $this->schemaDumpSql(null),
            self::ARCHIVE_MIGRATION_INDEX,
            BackupScope::SCHEMA_ONLY,
        );
        $this->raiseUntouchedProbe();

        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::SCHEMA_ONLY, RestoreEnvDecision::ALLOW);
            $this->fail('A schema archive with no migration level must refuse to restore');
        } catch (RestoreFailedException $refusal) {
            $this->assertStringContainsString('records no migration level', $refusal->getMessage());
            $this->assertStringContainsString('--' . BackupConstants::MIGRATION_INDEX_OPTION, $refusal->getMessage());
            $this->assertFalse($refusal->databaseTouched(), 'The refusal must come before the first import');
        }

        $this->assertSame([['7', 'untouched']], $this->probeRows(), 'Nothing may be imported over the refusal');
    }

    public function testTheOperatorsMigrationIndexClosesThatRefusal(): void
    {
        $this->listSchemaArchiveMigrations();
        $this->publishFixtureBackup(
            $this->schemaDumpSql(null),
            self::ARCHIVE_MIGRATION_INDEX,
            BackupScope::SCHEMA_ONLY,
        );
        Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`');

        new BackupRestorer()->restore(
            self::BACKUP_ID,
            BackupScope::SCHEMA_ONLY,
            RestoreEnvDecision::ALLOW,
            self::ARCHIVE_MIGRATION_INDEX,
        );

        $this->assertSame(self::CODE_MIGRATION_INDEX, Migration::getCurrentIndex());
        $this->assertContains(self::MIGRATED_COLUMN, $this->probeColumns());
    }

    public function testADumpContradictingItsSidecarRefusesTheRestore(): void
    {
        // Both numbers are written in one run from one reading, so a disagreement is not two
        // versions of the truth - one of the two files was swapped, copied or corrupted.
        $this->listSchemaArchiveMigrations();
        $this->publishFixtureBackup(
            $this->schemaDumpSql(self::ARCHIVE_MIGRATION_INDEX),
            self::ARCHIVE_MIGRATION_INDEX - 1,
            BackupScope::SCHEMA_ONLY,
        );
        $this->raiseUntouchedProbe();

        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::SCHEMA_ONLY, RestoreEnvDecision::ALLOW);
            $this->fail('A dump disagreeing with its sidecar must refuse to restore');
        } catch (RestoreFailedException $refusal) {
            $this->assertStringContainsString('contradicts its sidecar', $refusal->getMessage());
            $this->assertFalse($refusal->databaseTouched());
        }

        $this->assertSame([['7', 'untouched']], $this->probeRows());
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

    public function testASchemaOnlyRestoreLeavesThePassUnrun(): void
    {
        // The skip nothing was watching (P-080): a schema-only archive carries no rows, so the
        // engine must not build an anonymizer or run a pass over it. Proven with a dump that
        // does carry rows - a shape the create path never produces, and the only way to tell a
        // skipped pass from one that ran over an empty database and found nothing.
        $this->publishFixtureBackup(
            $this->piiDumpSql() . ArchiveMigrationMarker::statement(0),
            0,
            BackupScope::SCHEMA_ONLY,
        );
        PiiRestoreTestHilos::initBrowser();

        new BackupRestorer()->restore(
            self::BACKUP_ID,
            BackupScope::SCHEMA_ONLY,
            RestoreEnvDecision::REQUIRE_ANONYMIZATION,
        );

        $this->assertSame(
            [
                ['1', 'Alice', 'alice@real.example'],
                ['2', 'Bob', 'bob@real.example'],
            ],
            $this->piiRows(),
            'A schema-only restore must arrive with the pass unrun',
        );
        $this->assertSame([['1'], ['2']], $this->tokenRows(), 'And with the purge unrun as well');
    }

    public function testAColumnOnlyTheMigratedSchemaCarriesIsAnonymized(): void
    {
        // The case the archive-shaped gate got wrong: the registry describes the code's
        // schema, the archive predates the column, and judging the registry against the dump
        // refused a restore that had nothing wrong with it. What the pass writes into is the
        // schema the migrations leave behind, so that is what is judged.
        $this->listFixtureMigration();
        $this->publishFixtureBackup($this->piiDumpSql(), self::ARCHIVE_MIGRATION_INDEX);
        MigratedPiiRestoreTestHilos::initBrowser();

        new BackupRestorer()->restore(
            self::BACKUP_ID,
            BackupScope::FULL,
            RestoreEnvDecision::REQUIRE_ANONYMIZATION,
        );

        $this->assertSame(
            [['1', '[redacted]'], ['2', '[redacted]']],
            $this->probeNotes(),
            'A column the archive never carried must still be rewritten once the code has it',
        );
    }

    public function testASchemaTightenedByAMigrationRefusesBeforeTheFirstRowIsRewritten(): void
    {
        // The other half of judging the live schema: the archive says the column takes NULL,
        // the migration that runs after the import says it does not, and only the second one
        // describes what the pass would write into.
        $this->listFixtureMigration(
            'ALTER TABLE `' . self::PROBE_TABLE . '` MODIFY `' . self::NULLABLE_COLUMN
            . "` VARCHAR(32) NOT NULL DEFAULT ''",
        );
        $this->publishFixtureBackup($this->piiDumpSql(), self::ARCHIVE_MIGRATION_INDEX);
        TightenedPiiRestoreTestHilos::initBrowser();

        try {
            new BackupRestorer()->restore(
                self::BACKUP_ID,
                BackupScope::FULL,
                RestoreEnvDecision::REQUIRE_ANONYMIZATION,
            );
            $this->fail('A column the migrations made NOT NULL must not be told to hold NULL');
        } catch (RestoreFailedException $refusal) {
            $this->assertStringContainsString(self::NULLABLE_COLUMN, $refusal->getMessage());
            $this->assertTrue(
                $refusal->databaseTouched(),
                'The refusal comes after the import, and an operator must be told the data is there',
            );
            $this->assertStringContainsString('NOT anonymized', $refusal->getMessage());
        }

        // Read the column this case's registry DOES declare: `label` is not one of its rows,
        // so a probe of it would look untouched whether the pass ran or not.
        $this->assertSame(
            [
                ['1', 'Alice', 'alice@real.example'],
                ['2', 'Bob', 'bob@real.example'],
            ],
            $this->piiRows(),
            'The refusal must land before the first UPDATE, with every connection still unwritten',
        );
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

    public function testAFinishedRestoreIsAnnouncedInTheDatabaseItRestored(): void
    {
        $this->publishFixtureBackup($this->probeDumpSql([['1', 'alpha']]));

        new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);

        // Raised after the replay, because that is where the table comes from: the announcement is
        // written into the database the archive brought, not into the one it replaced.
        $this->raiseNotificationTables();

        new RestoreNotifier()->notifyOutcome(
            self::BACKUP_ID,
            BackupScope::FULL,
            success: true,
            failureDetail: null,
            startedAt: '2026-08-08 12:00:00',
            durationSeconds: 12,
            rehydrateComplete: true,
            initiatorIdentities: [],
        );

        $draft = $this->queuedFor(self::ADMIN_USER_ID);
        $this->assertNotNull($draft, 'The administrator of the restored database is told the restore happened');
        $this->assertSame(BackupNotificationType::RESTORE_SUCCEEDED, $draft->type);
        $this->assertSame(NotificationSeverity::SUCCESS, $draft->severity);
        $this->assertStringContainsString(self::BACKUP_ID, (string)$draft->body);
        $this->assertNotNull($draft->data);
        $this->assertSame('succeeded', $draft->data['outcome']);
    }

    public function testAFailedRestoreIsAnnouncedWithItsOneLineReason(): void
    {
        $this->publishFixtureBackup($this->probeDumpSql([['1', 'alpha']]));

        new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
        $this->raiseNotificationTables();

        new RestoreNotifier()->notifyOutcome(
            self::BACKUP_ID,
            BackupScope::FULL,
            success: false,
            failureDetail: "import failed on db-0\nmysql: syntax error near line 4",
            startedAt: '2026-08-08 12:00:00',
            durationSeconds: 12,
            rehydrateComplete: false,
            initiatorIdentities: [],
        );

        $draft = $this->queuedFor(self::ADMIN_USER_ID);
        $this->assertNotNull($draft);
        $this->assertSame(BackupNotificationType::RESTORE_FAILED, $draft->type);
        $this->assertSame(NotificationSeverity::ERROR, $draft->severity);
        $this->assertSame(
            'import failed on db-0 Details are on the backups page.',
            $draft->body,
            'The second line of the reason stays in the log rather than going out by mail',
        );
    }

    /**
     * Raises the notification tables of the restored database and mounts a context over them.
     *
     * The tables are raised for the CONTEXT rather than for the announcement: the letter itself
     * is queued and written later, by the library that owns those tables (HIL-771), but a
     * framework context will not mount over a database whose framework tables the archive
     * replaced - and mounting one is what makes the audience be read from the restored database
     * rather than from the installation's own.
     *
     * @throws DatabaseException When a stub statement fails
     */
    private function raiseNotificationTables(): void
    {
        self::runNotificationStubs(down: true);
        self::runNotificationStubs(down: false);
        $this->notificationTablesRaised = true;
        // The activation gate reads the cached schema map, and these tables were raised after it
        // was built; without the re-read the collection would refuse a table that is right there.
        Schema::reset();
        Schema::initialize();

        $this->previousDb = Hilos::$db;
        $db = new RestoreAnnouncementTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        RestoreAnnouncementTestHilos::initBrowser();
    }

    /**
     * Reads back the letter a finished restore left for one recipient.
     *
     * Off the queue rather than out of `hilos_notification`, because that is where a restore
     * puts it (HIL-771): the announcement is written with the node frozen or the daemon down,
     * so it is deferred, and the backup agent hands it to the notifications library once the
     * library can be reached (HIL-846). What these cases still pin is the half the restore owns -
     * who is told, and what the letter says - and that half is decided against the database the
     * archive brought.
     *
     * @param int $userId Recipient user id
     * @return ?NotificationDraft The newest letter left for them, or null when they got none
     */
    private function queuedFor(int $userId): ?NotificationDraft
    {
        $mine = array_values(array_filter(
            self::takeLetters(),
            static fn(NotificationDraft $draft): bool => $draft->userId === $userId,
        ));

        return $mine === [] ? null : $mine[count($mine) - 1];
    }

    /**
     * Takes the letter batch waiting in the queue and closes it, as the holder does on the library's receipt.
     *
     * @return list<NotificationDraft> Drafts of the batch, empty when nothing waits
     */
    private static function takeLetters(): array
    {
        $batch = DeferredNotificationQueue::take();
        if ($batch === null) {
            return [];
        }

        DeferredNotificationQueue::release($batch->batch);

        return $batch->drafts;
    }

    /**
     * Runs one direction of the stub file of every notification table these cases need.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws DatabaseException When a stub statement fails
     */
    private static function runNotificationStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::NOTIFICATION_TABLES) : self::NOTIFICATION_TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }

    /**
     * @param string $database Target schema on the same stand server
     * @param ?int $port Override for the unavailable-connection scenario
     */
    private function configureSecondTarget(string $database, ?int $port = null): void
    {
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(index: 2, host: $config->host, user: $config->user, password: $config->password,
            database: $database, port: $port ?? $config->port, charset: $config->charset, socket: $config->socket);
    }

    /** @param bool $duplicateRow Whether mysql should fail after the archive's first committed row */
    private function prepareLateTables(bool $duplicateRow = false): void
    {
        // DROP proof needs the real server-wide metadata that the stand's application account cannot see.
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(index: DatabaseConnectionDefaults::PRIMARY_INDEX, host: $config->host, user: 'root',
            password: Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(), database: $config->database,
            port: $config->port, charset: $config->charset, socket: $config->socket);
        Database::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $lateSql = 'CREATE TABLE IF NOT EXISTS `' . self::LATE_TABLE . "` (id INT PRIMARY KEY);\n"
            . 'CREATE TABLE `' . self::CHILD_TABLE . '` (id INT PRIMARY KEY, parent_id INT, CONSTRAINT restore_late_fk'
            . ' FOREIGN KEY(parent_id) REFERENCES `' . self::LATE_TABLE . '`(id) ON DELETE CASCADE)';
        $this->listFixtureMigration($lateSql);
        $dump = $this->markerDumpSql(self::ARCHIVED_MARKER) . $this->probeDumpSql([['1', 'archive']]);
        if ($duplicateRow) {
            $dump .= 'INSERT INTO `' . self::PROBE_TABLE . "` VALUES (1, 'duplicate');\n";
        }
        $this->publishFixtureBackup($dump, self::ARCHIVE_MIGRATION_INDEX);
        $this->raiseUntouchedProbe();
        Migration::initialize();
        Database::sql('DELETE FROM migration');
        Database::sql('INSERT INTO migration (`index`, failed) VALUES (?,0),(?,0)',
            [self::ARCHIVE_MIGRATION_INDEX, self::CODE_MIGRATION_INDEX]);
        Database::sql('CREATE TABLE IF NOT EXISTS `' . self::LATE_TABLE . '` (id INT PRIMARY KEY)');
        Database::sql('CREATE TABLE `' . self::CHILD_TABLE . '` (id INT PRIMARY KEY, parent_id INT, CONSTRAINT restore_late_fk'
            . ' FOREIGN KEY(parent_id) REFERENCES `' . self::LATE_TABLE . '`(id) ON DELETE CASCADE)');
        Database::sql('CREATE TABLE `' . self::INDEPENDENT_TABLE . '` (id INT PRIMARY KEY)');
        Database::sql('INSERT INTO `' . self::INDEPENDENT_TABLE . '` VALUES (13)');
        DatabaseMarker::put(new DatabaseMarkerRow(self::TARGET_MARKER, 'target-node', 1788256800123));
    }

    /** @param string $reason Exact proof that should refuse this restore */
    private function assertLatePreparationRefuses(string $reason): void
    {
        $history = Database::sql('SELECT * FROM migration ORDER BY `index`')->rows();
        $marker = DatabaseMarker::current();
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
            $this->fail('Unproven cleanup must refuse before any restore write');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString($reason, $failure->getMessage());
            $this->assertSame([['7', 'untouched']], $this->probeRows());
            $this->assertSame($history, Database::sql('SELECT * FROM migration ORDER BY `index`')->rows());
            $this->assertEquals($marker, DatabaseMarker::current());
        } finally {
            DatabaseMarker::clear();
        }
    }

    /**
     * @param string $reason Expected refusal detail
     * @param int $restoreIndex Caller connection to preserve through the failed restore
     */
    private function assertPreparationKeepsPrimary(string $reason, int $restoreIndex = DatabaseConnectionDefaults::PRIMARY_INDEX): void
    {
        $this->raiseUntouchedProbe();
        Migration::initialize();
        Database::sql('INSERT INTO `migration` (`index`, `failed`) VALUES (?,0),(?,1)',
            [self::TARGET_APPLIED_INDEX, self::TARGET_FAILED_INDEX]);
        $history = Database::sql('SELECT `index`, `failed` FROM `migration` ORDER BY `index`')->rows();
        $marker = new DatabaseMarkerRow(self::TARGET_MARKER, 'target-node', 1788256800123);
        DatabaseMarker::put($marker);
        Database::useConnection($restoreIndex);
        try {
            new BackupRestorer()->restore(self::BACKUP_ID, BackupScope::FULL, RestoreEnvDecision::ALLOW);
            $this->fail('Preparation must refuse before the primary import');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString($reason, $failure->getMessage());
            $this->assertSame($restoreIndex, Database::getCurrentIndex());
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            $this->assertSame([['7', 'untouched']], $this->probeRows());
            $this->assertSame($history, Database::sql('SELECT `index`, `failed` FROM `migration` ORDER BY `index`')->rows());
            $this->assertEquals($marker, DatabaseMarker::current());
        } finally {
            DatabaseMarker::clear();
            Database::sql('DELETE FROM `migration` WHERE `index` IN (?, ?)',
                [self::TARGET_APPLIED_INDEX, self::TARGET_FAILED_INDEX], index: DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    /**
     * Builds and publishes one fixture backup exactly as the create path lays it out.
     *
     * @param string $dumpSql Contents of the connection-0 dump inside the archive
     * @param ?int $migrationIndex Migration level recorded for connection 0
     * @param BackupScope $scope Scope the fixture claims to have been captured under
     * @param ?list<BackupConnectionMeta> $connections Explicit sidecar connections, defaulting to primary
     * @param array<int, string> $extraDumps Further extracted files by connection index
     * @return string Absolute path of the published archive
     */
    private function publishFixtureBackup(
        string $dumpSql,
        ?int $migrationIndex = 0,
        BackupScope $scope = BackupScope::FULL,
        ?array $connections = null,
        array $extraDumps = [],
    ): string {
        $scopeDir = $this->storeRoot . '/' . $scope->value;
        $workDir = $this->storeRoot . '/build';
        mkdir($workDir, 0700);
        if ($scope === BackupScope::FULL) {
            if ($migrationIndex !== null && $migrationIndex > 0) {
                $dumpSql .= "DROP TABLE IF EXISTS `migration`;\n"
                    . "CREATE TABLE `migration` (`index` int NOT NULL PRIMARY KEY, `failed` tinyint NOT NULL);\n"
                    . "INSERT INTO `migration` VALUES ({$migrationIndex},0);\n";
            }
            $dumpSql .= "-- Dump completed on 2026-08-08 12:00:00\n";
        }
        file_put_contents(
            $workDir . '/' . BackupCreator::SQL_FILE_PREFIX . '0' . BackupCreator::SQL_FILE_SUFFIX,
            $dumpSql,
        );
        foreach ($extraDumps as $index => $sql) {
            file_put_contents($workDir . '/' . BackupCreator::SQL_FILE_PREFIX . $index . BackupCreator::SQL_FILE_SUFFIX, $sql);
        }

        $base = BackupCreator::archiveBaseName(self::BACKUP_ID, 'test', $scope);
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
            scope: $scope,
            connections: $connections ?? [new BackupConnectionMeta(0, $this->databaseName(), $migrationIndex)],
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
     * A mysqldump-shaped dump of the rollout claim table holding one row.
     *
     * @param string $holder Holder the archived row names
     * @return string Dump SQL
     */
    private function claimDumpSql(string $holder): string
    {
        return 'DROP TABLE IF EXISTS `' . MigrationClaim::TABLE . "`;\n"
            . 'CREATE TABLE `' . MigrationClaim::TABLE . '` (`id` tinyint(3) UNSIGNED NOT NULL,'
            . " `holder` varchar(255) NOT NULL, `claimed_at` bigint NOT NULL, PRIMARY KEY (`id`));\n"
            . 'INSERT INTO `' . MigrationClaim::TABLE . '` VALUES (' . MigrationClaim::CLAIM_ID
            . ", '{$holder}', 1786190399000);\n";
    }

    /**
     * The database marker table as a FULL dump of another database carries it.
     *
     * @param string $marker Marker of the database the dump was taken from
     * @return string Dump SQL recreating the marker table with that row
     */
    private function markerDumpSql(string $marker): string
    {
        return 'DROP TABLE IF EXISTS `' . DatabaseMarker::TABLE . "`;\n"
            . 'CREATE TABLE `' . DatabaseMarker::TABLE . '` (`id` tinyint(3) UNSIGNED NOT NULL, `marker` char(32) NOT NULL,'
            . " `written_by` varchar(255) NOT NULL, `written_at` datetime NOT NULL, PRIMARY KEY (`id`));\n"
            . 'INSERT INTO `' . DatabaseMarker::TABLE . '` VALUES (' . DatabaseMarker::ROW_ID
            . ", '{$marker}', 'archive-node', '2026-08-08 11:59:59');\n";
    }

    /**
     * A dump of two journal rows, one whose fate is unknown and one already sent.
     *
     * @return string Dump SQL
     */
    private function deliveryDumpSql(): string
    {
        $stub = dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_notification_delivery.sql';

        return 'DROP TABLE IF EXISTS `' . EntityNotificationDelivery::_table . "`;\n"
            . (string)file_get_contents($stub)
            . 'INSERT INTO `' . EntityNotificationDelivery::_table . '` (`id`, `notification_id`, `channel`, `status`,'
            . ' `attempts`, `last_error`, `created_at`, `updated_at`, `delivered_at`) VALUES '
            . "(1, 101, 'email', 'pending', 2, 'possibly sent', 1786190280123, 1786190340123, NULL),"
            . " (2, 102, 'email', 'sent', 1, NULL, 1786190160123, 1786190220123, 1786190220123);\n";
    }

    /**
     * @return list<array<string, mixed>> Journal rows after import and settlement
     */
    private function deliveryRows(): array
    {
        return Database::sql(
            'SELECT * FROM `' . EntityNotificationDelivery::_table . '` ORDER BY `' . EntityNotificationDelivery::id . '`',
        )->rows();
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
            . '  `' . self::NULLABLE_COLUMN . "` varchar(32) DEFAULT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
            . 'INSERT INTO `' . self::PROBE_TABLE . "` VALUES "
            . "(1, 'Alice', 'alice@real.example', 'ally'), (2, 'Bob', 'bob@real.example', 'bobby');\n"
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
     * @return list<array{0: string, 1: string}> Probe rows with the column the migration adds
     */
    private function probeNotes(): array
    {
        Database::sql(
            'SELECT id, `' . self::MIGRATED_COLUMN . '` FROM `' . self::PROBE_TABLE . '` ORDER BY id',
        );
        $rows = [];
        while (($row = Database::row()) !== null) {
            $rows[] = [(string)$row['id'], (string)$row[self::MIGRATED_COLUMN]];
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
    private function listFixtureMigration(?string $statement = null): void
    {
        $trackDir = $this->storeRoot . '/migrations/' . self::MIGRATION_TRACK;
        mkdir($trackDir, 0700, true);
        // The column arrives filled rather than empty: a column of NULLs would be rewritten
        // to NULLs by any strategy (the pass leaves nothing as nothing), so a case about a
        // migrated column being anonymized would pass without the pass doing anything.
        file_put_contents(
            $trackDir . '/' . self::CODE_MIGRATION_INDEX . '_up.sql',
            ($statement ?? 'ALTER TABLE `' . self::PROBE_TABLE . '` ADD COLUMN `' . self::MIGRATED_COLUMN
                . "` VARCHAR(32) NOT NULL DEFAULT '" . self::MIGRATED_VALUE . "'") . ";\n",
        );
        $this->fixtureMigrationListed = true;
    }

    /**
     * Lists both migrations a schema-archive case needs: the one its schema already carries
     * and the one above it.
     *
     * The lower file adds {@see EARLY_COLUMN}, which the schema fixture already declares, so
     * replaying it fails on a duplicate column. That is deliberate: it is what makes "the level
     * was recorded, so only what is above it ran" an assertion rather than a hope.
     */
    private function listSchemaArchiveMigrations(): void
    {
        $this->listFixtureMigration();
        file_put_contents(
            $this->storeRoot . '/migrations/' . self::MIGRATION_TRACK . '/'
            . self::ARCHIVE_MIGRATION_INDEX . '_up.sql',
            'ALTER TABLE `' . self::PROBE_TABLE . '` ADD COLUMN `' . self::EARLY_COLUMN
            . "` VARCHAR(32) DEFAULT NULL;\n",
        );
    }

    /**
     * A schema-only dump of the probe table: structure, no rows, optionally stamped.
     *
     * @param ?int $markerLevel Level to stamp the dump with, or null for an unstamped archive
     * @return string Dump SQL
     */
    private function schemaDumpSql(?int $markerLevel): string
    {
        $sql = 'DROP TABLE IF EXISTS `' . self::PROBE_TABLE . "`;\n"
            . 'CREATE TABLE `' . self::PROBE_TABLE . "` (\n"
            . "  `id` int NOT NULL,\n"
            . "  `label` varchar(32) NOT NULL,\n"
            . '  `' . self::EARLY_COLUMN . "` varchar(32) DEFAULT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";

        return $markerLevel === null ? $sql : $sql . ArchiveMigrationMarker::statement($markerLevel);
    }

    /**
     * Raises the probe table with one row a refused restore must leave exactly as it is.
     *
     * @throws DatabaseException When the pre-state cannot be written
     */
    private function raiseUntouchedProbe(): void
    {
        Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`');
        Database::sql(
            'CREATE TABLE `' . self::PROBE_TABLE . '` (id INT PRIMARY KEY, label VARCHAR(32) NOT NULL)',
        );
        Database::sql('INSERT INTO `' . self::PROBE_TABLE . "` VALUES (7, 'untouched')");
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
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
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
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new PiiRestoreTestDbContext();
    }
}

/**
 * Project facade fixture whose catalog classifies a column only the migrated schema has.
 */
final class MigratedPiiRestoreTestHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = MigratedPiiRestoreTestCatalog::class;

    /**
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new PiiRestoreTestDbContext();
    }
}

/**
 * Project facade fixture whose catalog declares `null` on a column a migration tightens.
 */
final class TightenedPiiRestoreTestHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = TightenedPiiRestoreTestCatalog::class;

    /**
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new PiiRestoreTestDbContext();
    }
}

/**
 * No-op DB context so the facade fixtures are instantiable.
 */
final class PiiRestoreTestDbContext extends HilosDbContext
{
    /**
     * No-op DB configuration for the restore fixtures.
     */
    public function configure(): void
    {
    }
}

/** Mounts framework entities so their delivery verdicts enter the anonymization registry. */
final class DeliveryPiiRestoreTestDbContext extends HilosDbContext
{
}

/**
 * Tables outside the ORM covering both fixture tables: the probe rewritten column by
 * column, the token-shaped table emptied whole. The fixtures have no Entity class, which
 * is the case a provider of tables without one exists for.
 */
final class FullPiiRestoreTestTables implements TablesWithoutEntityProvider
{
    /**
     * @return list<string> Fixture table names
     */
    public static function tables(): array
    {
        return array_keys(self::pii());
    }

    /** @return array<string, list<string>> Declared tables with no physical moment columns */
    public static function moments(): array
    {
        return [BackupRestorerIntegrationTest::PROBE_TABLE => [], BackupRestorerIntegrationTest::TOKEN_TABLE => []];
    }

    /** @return array<string, array<string, string>> No project storage exceptions */
    public static function momentStorage(): array
    {
        return [];
    }

    /**
     * @return array<string, array<string, AnonymizationStrategy>|AnonymizationStrategy> Verdict per table
     */
    public static function pii(): array
    {
        return [
            BackupRestorerIntegrationTest::PROBE_TABLE => [
                'label' => AnonymizationStrategy::MASK,
                'email' => AnonymizationStrategy::FAKE_EMAIL,
            ],
            BackupRestorerIntegrationTest::TOKEN_TABLE => AnonymizationStrategy::PURGE,
        ];
    }

    /**
     * @return array<string, list<string>> Non-personal columns per table
     */
    public static function piiNotPersonal(): array
    {
        return [BackupRestorerIntegrationTest::PROBE_TABLE => ['id']];
    }
}

/**
 * Backup catalog naming the provider that covers both fixture tables.
 */
final class FullPiiRestoreTestCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<int, mixed>> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => [
                DatabaseConnectionDefaults::PRIMARY_INDEX => FullPiiRestoreTestTables::class,
            ],
        ];
    }
}

/**
 * Tables of a project whose verdict has run ahead of the archive: it classifies the
 * column a forward migration adds, which no dump written before that migration carries.
 */
final class MigratedPiiRestoreTestTables implements TablesWithoutEntityProvider
{
    /**
     * @return list<string> Fixture table names
     */
    public static function tables(): array
    {
        return array_keys(self::pii());
    }

    /** @return array<string, list<string>> Declared tables with no physical moment columns */
    public static function moments(): array
    {
        return [BackupRestorerIntegrationTest::PROBE_TABLE => [], BackupRestorerIntegrationTest::TOKEN_TABLE => []];
    }

    /** @return array<string, array<string, string>> No project storage exceptions */
    public static function momentStorage(): array
    {
        return [];
    }

    /**
     * @return array<string, array<string, AnonymizationStrategy>|AnonymizationStrategy> Verdict per table
     */
    public static function pii(): array
    {
        return [
            BackupRestorerIntegrationTest::PROBE_TABLE => [
                'label' => AnonymizationStrategy::MASK,
                'email' => AnonymizationStrategy::FAKE_EMAIL,
                BackupRestorerIntegrationTest::MIGRATED_COLUMN => AnonymizationStrategy::MASK,
            ],
            BackupRestorerIntegrationTest::TOKEN_TABLE => AnonymizationStrategy::PURGE,
        ];
    }

    /**
     * @return array<string, list<string>> Non-personal columns per table
     */
    public static function piiNotPersonal(): array
    {
        return [BackupRestorerIntegrationTest::PROBE_TABLE => ['id']];
    }
}

/**
 * Backup catalog naming the provider whose verdict ran ahead of the archive.
 */
final class MigratedPiiRestoreTestCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<int, mixed>> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => [
                DatabaseConnectionDefaults::PRIMARY_INDEX => MigratedPiiRestoreTestTables::class,
            ],
        ];
    }
}

/**
 * Tables of a project that declared `null` on a column the archive carries as nullable
 * and the code has since made NOT NULL.
 */
final class TightenedPiiRestoreTestTables implements TablesWithoutEntityProvider
{
    /**
     * @return list<string> Fixture table names
     */
    public static function tables(): array
    {
        return array_keys(self::pii());
    }

    /** @return array<string, list<string>> Declared tables with no physical moment columns */
    public static function moments(): array
    {
        return [BackupRestorerIntegrationTest::PROBE_TABLE => [], BackupRestorerIntegrationTest::TOKEN_TABLE => []];
    }

    /** @return array<string, array<string, string>> No project storage exceptions */
    public static function momentStorage(): array
    {
        return [];
    }

    /**
     * @return array<string, array<string, AnonymizationStrategy>|AnonymizationStrategy> Verdict per table
     */
    public static function pii(): array
    {
        return [
            BackupRestorerIntegrationTest::PROBE_TABLE => [
                'email' => AnonymizationStrategy::FAKE_EMAIL,
                BackupRestorerIntegrationTest::NULLABLE_COLUMN => AnonymizationStrategy::NULLIFY,
            ],
            BackupRestorerIntegrationTest::TOKEN_TABLE => AnonymizationStrategy::PURGE,
        ];
    }

    /**
     * @return array<string, list<string>> Non-personal columns per table
     */
    public static function piiNotPersonal(): array
    {
        return [BackupRestorerIntegrationTest::PROBE_TABLE => ['id', 'label']];
    }
}

/**
 * Backup catalog naming the provider that declared `null` on a tightened column.
 */
final class TightenedPiiRestoreTestCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<int, mixed>> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => [
                DatabaseConnectionDefaults::PRIMARY_INDEX => TightenedPiiRestoreTestTables::class,
            ],
        ];
    }
}

/**
 * Tables of a project that forgot one, the way a project does when a migration adds it.
 */
final class PartialPiiRestoreTestTables implements TablesWithoutEntityProvider
{
    /**
     * @return list<string> Fixture table names
     */
    public static function tables(): array
    {
        return array_keys(self::pii());
    }

    /** @return array<string, list<string>> Declared tables with no physical moment columns */
    public static function moments(): array
    {
        return [BackupRestorerIntegrationTest::PROBE_TABLE => []];
    }

    /** @return array<string, array<string, string>> No project storage exceptions */
    public static function momentStorage(): array
    {
        return [];
    }

    /**
     * @return array<string, array<string, AnonymizationStrategy>|AnonymizationStrategy> Verdict per table
     */
    public static function pii(): array
    {
        return [
            BackupRestorerIntegrationTest::PROBE_TABLE => [
                'label' => AnonymizationStrategy::MASK,
                'email' => AnonymizationStrategy::FAKE_EMAIL,
            ],
        ];
    }

    /**
     * @return array<string, list<string>> Non-personal columns per table
     */
    public static function piiNotPersonal(): array
    {
        return [BackupRestorerIntegrationTest::PROBE_TABLE => ['id']];
    }
}

/**
 * Backup catalog naming the provider that forgot a table.
 */
final class PartialPiiRestoreTestCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<int, mixed>> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => [
                DatabaseConnectionDefaults::PRIMARY_INDEX => PartialPiiRestoreTestTables::class,
            ],
        ];
    }
}

/**
 * Project facade fixture naming the administrator of the restored database.
 */
final class RestoreAnnouncementTestHilos extends Hilos
{
    protected const string ADMIN_AUDIENCE = RestoreAnnouncementTestAudience::class;

    /**
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new RestoreAnnouncementTestDbContext();
    }
}

/**
 * The audience the restored database answers with.
 */
final class RestoreAnnouncementTestAudience extends AdminAudience
{
    /**
     * @return list<int> Fixture admin user ids
     */
    protected static function userIds(): array
    {
        return [BackupRestorerIntegrationTest::ADMIN_USER_ID];
    }
}

/**
 * A framework database context with nothing but the framework's own collections.
 */
final class RestoreAnnouncementTestDbContext extends HilosDbContext
{
}
