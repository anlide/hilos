<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

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
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestoreMigrationGuard;
use Hilos\Backup\RestoreNotifier;
use Hilos\Backup\RestorePhase;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Schema\TablesWithoutEntityProvider;
use Hilos\Database\Schema\Schema;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Hilos;
use Hilos\Notification\DeferredNotificationQueue;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Users\AdminAudience;

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

    /** Administrator of the restored database, as its audience names them. */
    public const int ADMIN_USER_ID = 41;

    /** @var list<string> Framework notification tables the announcement cases raise and drop. */
    private const array NOTIFICATION_TABLES = ['hilos_notification', 'hilos_setting'];

    private string $storeRoot = '';

    private ?EnvAccessor $previousEnv = null;

    private bool $fixtureMigrationListed = false;

    /** @var ?DbContext Database context to put back after an announcement case swapped it. */
    private ?DbContext $previousDb = null;

    private bool $notificationTablesRaised = false;

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
            null,
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
     * so it is deferred and the notifications library sends it when it next starts. What these
     * cases still pin is the half the restore owns - who is told, and what the letter says -
     * and that half is decided against the database the archive brought.
     *
     * @param int $userId Recipient user id
     * @return ?NotificationDraft The newest letter left for them, or null when they got none
     */
    private function queuedFor(int $userId): ?NotificationDraft
    {
        $mine = array_values(array_filter(
            DeferredNotificationQueue::drain(),
            static fn(NotificationDraft $draft): bool => $draft->userId === $userId,
        ));

        return $mine === [] ? null : $mine[count($mine) - 1];
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
        foreach (self::NOTIFICATION_TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }

    /**
     * Builds and publishes one fixture backup exactly as the create path lays it out.
     *
     * @param string $dumpSql Contents of the connection-0 dump inside the archive
     * @param ?int $migrationIndex Migration level recorded for connection 0
     * @param BackupScope $scope Scope the fixture claims to have been captured under
     * @return string Absolute path of the published archive
     */
    private function publishFixtureBackup(
        string $dumpSql,
        ?int $migrationIndex = 0,
        BackupScope $scope = BackupScope::FULL,
    ): string {
        $scopeDir = $this->storeRoot . '/' . $scope->value;
        $workDir = $this->storeRoot . '/build';
        mkdir($workDir, 0700);
        file_put_contents(
            $workDir . '/' . BackupCreator::SQL_FILE_PREFIX . '0' . BackupCreator::SQL_FILE_SUFFIX,
            $dumpSql,
        );

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
 * Project facade fixture whose catalog classifies a column only the migrated schema has.
 */
final class MigratedPiiRestoreTestHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = MigratedPiiRestoreTestCatalog::class;

    /**
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
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
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => FullPiiRestoreTestTables::class,
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
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => MigratedPiiRestoreTestTables::class,
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
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => TightenedPiiRestoreTestTables::class,
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
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => PartialPiiRestoreTestTables::class,
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
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
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
