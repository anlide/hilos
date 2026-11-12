<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\SchemaMetadata;
use Hilos\Backup\FullRestore\StructureDefaults;
use Hilos\Backup\FullRestorePlan;
use Hilos\Backup\FullRestorePlanner;
use Hilos\Backup\FullRestoreSchemaSnapshot;
use Hilos\Constants\EnvConstants;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseMarker;
use Hilos\Database\DatabaseMarkerRow;
use Hilos\Database\Migration;
use Hilos\Database\MigrationSqlReader;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * Physical schema/privilege proof requires real MariaDB DDL, outside the rollback harness.
 * Each case owns and drops its schemas and account; preparation itself must leave them unchanged.
 */
final class FullRestorePlannerIntegrationTest extends FrameworkIntegrationTestCase
{
    private string $database;
    private string $external;
    private string $root;
    private string $restrictedUser;
    private DatabaseConnectionConfig $base;
    private array $previousMigration;

    /**
     * Dedicated restore endpoint isolated from concurrent DDL and global setting mutations.
     */
    protected function primaryDatabaseHost(): string
    {
        $restoreHost = getenv('HILOS_TEST_RESTORE_DB_HOST');
        $this->assertIsString($restoreHost, 'The restore fixture needs its dedicated restore server');
        $this->assertNotSame('', $restoreHost);
        $this->assertNotSame(Hilos::$env[EnvConstants::DB_HOST]->string(), $restoreHost);
        $this->assertNotSame(getenv('HILOS_TEST_CATALOG_DB_HOST'), $restoreHost);

        return $restoreHost;
    }

    /** Creates a real late table pair and history, plus an old full dump and the exact source track. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->base = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $this->previousMigration = [];
        foreach (['migrationListPath', 'migrationName', 'initialized'] as $property) {
            $this->previousMigration[$property] = new ReflectionProperty(Migration::class, $property)->getValue();
        }
        $suffix = bin2hex(random_bytes(6));
        $this->database = 'hilos-restore-proof-' . $suffix;
        $this->external = $this->database . '-external';
        $this->restrictedUser = 'restore-proof-' . $suffix;
        $this->root = sys_get_temp_dir() . '/' . $this->database;
        FsPath::ensureDirectory($this->root . '/Schema', 0700);
        Migration::setMigrationListPath($this->root);
        Migration::setMigrationName('Schema');
        $this->connect($this->base->database);
        Database::sql('CREATE DATABASE ' . ChangeLogDatabase::identifier($this->database));
        Database::sql('CREATE DATABASE ' . ChangeLogDatabase::identifier($this->external));
        $this->connect($this->database);
        Database::sql('CREATE TABLE migration (`index` INT PRIMARY KEY, failed TINYINT NOT NULL)');
        Database::sql('INSERT INTO migration VALUES(1,0),(2,0),(3,0)');
        $sql = [
            1 => 'SELECT 1',
            2 => 'CREATE TABLE parent (id INT PRIMARY KEY)',
            3 => 'CREATE TABLE child (id INT PRIMARY KEY, parent_id INT, '
                . 'CONSTRAINT fk_parent FOREIGN KEY(parent_id) REFERENCES parent(id) ON DELETE CASCADE)',
            4 => 'ALTER TABLE child ADD future_column INT',
        ];
        foreach ($sql as $number => $statement) {
            FsPath::write($this->root . '/Schema/' . sprintf('%03d', $number) . '_proof.sql', $statement . ";\n");
            if ($number === 2 || $number === 3) {
                Database::sql($statement);
            }
        }
        Database::sql('INSERT INTO parent VALUES(11)');
        Database::sql('INSERT INTO child VALUES(12,11)');
        Database::sql('CREATE TABLE independent (id INT)');
        Database::sql('INSERT INTO independent VALUES(13)');
        FsPath::write($this->root . '/db-0.sql', "CREATE TABLE migration (`index` INT PRIMARY KEY, failed TINYINT NOT NULL);\n"
            . "INSERT INTO migration VALUES(1,0);\n-- Dump completed on 2026-01-01 00:00:00\n");
    }

    /** Drops only owned physical resources and restores process-local migration settings. */
    protected function tearDown(): void
    {
        try {
            $this->connect($this->base->database);
            Database::sql('DROP DATABASE IF EXISTS ' . ChangeLogDatabase::identifier($this->external));
            Database::sql('DROP DATABASE IF EXISTS ' . ChangeLogDatabase::identifier($this->database));
            Database::sql('DROP USER IF EXISTS ?@?', [$this->restrictedUser, '%']);
            foreach (FsPath::entries($this->root . '/Schema') as $file) {
                FsPath::delete($this->root . '/Schema/' . $file);
            }
            FsPath::removeDirectory($this->root . '/Schema');
            foreach (FsPath::entries($this->root) as $file) {
                FsPath::delete($this->root . '/' . $file);
            }
            FsPath::removeDirectory($this->root);
        } finally {
            foreach ($this->previousMigration as $property => $value) {
                new ReflectionProperty(Migration::class, $property)->setValue(null, $value);
            }
            parent::tearDown();
        }
    }

    /** No DROP, initialization or data rewrite occurs; the ordered result has a real unchanged recheck. */
    public function testPrepareIsReadOnlyAndOrdersRealForeignKeys(): void
    {
        $this->selectCaller();
        $inputs = $this->inputs();
        $before = FullRestoreSchemaSnapshot::read($inputs->targets);
        $planner = FullRestorePlanner::prepare($inputs);
        $this->assertSame(['child', 'parent'], array_column($planner->cleanup, 'table'));
        $this->assertEquals($before, FullRestoreSchemaSnapshot::read($inputs->targets));
        $this->assertSame(11, (int)Database::sql('SELECT id FROM parent', index: DatabaseConnectionDefaults::PRIMARY_INDEX)->field('id'));
        $this->assertSame(12, (int)Database::sql('SELECT id FROM child', index: DatabaseConnectionDefaults::PRIMARY_INDEX)->field('id'));
        $this->assertSame(13, (int)Database::sql('SELECT id FROM independent', index: DatabaseConnectionDefaults::PRIMARY_INDEX)->field('id'));
        $planner->assertUnchanged();
        $this->assertSame(7, Database::getCurrentIndex());
    }

    /** Numeric archive markers are admitted and omitted while the target's exact marker remains pinned. */
    public function testNumericArchiveMarkerKeepsTheTargetsMillisecondIdentity(): void
    {
        $marker = $this->jsonMarker();
        FsPath::write($this->root . '/db-0.sql',
            "CREATE TABLE migration (`index` INT PRIMARY KEY, failed TINYINT NOT NULL);\n"
            . "INSERT INTO migration VALUES(1,0);\n"
            . "CREATE TABLE hilos_database_marker(id TINYINT UNSIGNED NOT NULL PRIMARY KEY, marker CHAR(32) NOT NULL, "
            . "written_by VARCHAR(255) NOT NULL, written_at BIGINT(20) NOT NULL);\n"
            . "INSERT INTO hilos_database_marker VALUES(1,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','archive-node',-1);\n"
            . "-- Dump completed on 2026-01-01 00:00:00\n");
        $inputs = $this->inputs();
        $this->assertEquals($marker, $inputs->marker);
        $planner = FullRestorePlanner::prepare($inputs);
        $planner->assertUnchanged();
        $this->assertEquals($marker, DatabaseMarker::current());
        $this->assertStringNotContainsString('hilos_database_marker', FsPath::read($inputs->targets[0]->sqlPath));
    }

    /** The real Chat UP110 only removes archive data during ordinary UP, with no preparatory DROP. */
    public function testChatModerationRemovalReplaysItsUnchangedMigration(): void
    {
        foreach (FsPath::entries($this->root . '/Schema') as $file) {
            FsPath::delete($this->root . '/Schema/' . $file);
        }
        $sql = FsPath::read(dirname(__DIR__, 3) . '/demo/chat/backend/Database/Migration/Schema/110_drop_chat_moderation.sql');
        FsPath::write($this->root . '/Schema/109_archive.sql', "SELECT 1;\n");
        FsPath::write($this->root . '/Schema/110_drop_chat_moderation.sql', $sql);
        Database::sql('DELETE FROM migration');
        Database::sql('INSERT INTO migration VALUES(109,0),(110,0)');
        Database::sql('CREATE TABLE hilos_setting (`key` VARCHAR(128) PRIMARY KEY)');
        Database::sql("INSERT INTO hilos_setting VALUES('kept')");
        $dump = "DROP TABLE IF EXISTS migration;\n"
            . "CREATE TABLE migration (`index` INT PRIMARY KEY, failed TINYINT NOT NULL);\n"
            . "INSERT INTO migration VALUES(109,0);\n"
            . "CREATE TABLE moderator_prompt_piece (id INT PRIMARY KEY);\n"
            . "INSERT INTO moderator_prompt_piece VALUES(7);\n"
            . "DROP TABLE IF EXISTS hilos_setting;\n"
            . "CREATE TABLE hilos_setting (`key` VARCHAR(128) PRIMARY KEY);\n"
            . "INSERT INTO hilos_setting VALUES('chat_moderation_model'),('chat_moderation_url'),"
            . "('chat_moderation_provider'),('chat_moderation_timeout_sec'),('kept');\n";
        FsPath::write($this->root . '/db-0.sql', $dump . "-- Dump completed on 2026-01-01 00:00:00\n");
        $inputs = $this->inputs();
        $before = FullRestoreSchemaSnapshot::read($inputs->targets);
        $planner = FullRestorePlanner::prepare($inputs);
        $this->assertSame([], $planner->cleanup);
        $this->assertEquals($before, FullRestoreSchemaSnapshot::read($inputs->targets));
        $planner->assertUnchanged();

        foreach (MigrationSqlReader::statements($dump) as $statement) {
            Database::sql($statement);
        }
        $this->assertSame(7, (int)Database::sql('SELECT id FROM moderator_prompt_piece')->field('id'));
        $this->assertCount(5, Database::sql('SELECT * FROM hilos_setting')->rows());
        Migration::reset();
        Migration::migrateUp();
        $this->assertSame([109, 110], Migration::appliedIndices());
        $this->assertSame([], Database::sql("SHOW TABLES LIKE 'moderator_prompt_piece'")->rows());
        $this->assertSame([['key' => 'kept']], Database::sql('SELECT * FROM hilos_setting')->rows());
    }

    /** Real JSON alias output must leave parent id/index/engine facts available during an unrelated archive DROP. */
    public function testArchiveJsonParentSupportsOrdinaryDropWithoutCleanup(): void
    {
        $dump = $this->jsonDump();
        $this->assertStringContainsString('CHECK (json_valid(`payload_json`))', $dump);
        FsPath::write($this->root . '/db-0.sql', $dump . "-- Dump completed on 2026-01-01 00:00:00\n");
        $inputs = $this->inputs();
        $before = FullRestoreSchemaSnapshot::read($inputs->targets);
        $marker = DatabaseMarker::current();
        $planner = FullRestorePlanner::prepare($inputs);
        $this->assertSame([], $planner->cleanup);
        $this->assertEquals($before, FullRestoreSchemaSnapshot::read($inputs->targets));
        $planner->assertUnchanged();
        $this->assertJsonTargetIntact();
        $this->assertEquals($marker, DatabaseMarker::current());
    }

    /**
     * @param array<string, string> $changes Replacements in the real SHOW CREATE that remove a necessary FK fact
     * @param string $reason Specific pre-write refusal
     */
    #[DataProvider('invalidArchivedJsonParents')]
    public function testUnprovenJsonParentRefusesBeforeChangingTheTarget(array $changes, string $reason): void
    {
        $dump = $this->jsonDump();
        foreach (array_keys($changes) as $before) {
            $this->assertStringContainsString($before, $dump);
        }
        FsPath::write($this->root . '/db-0.sql', strtr($dump, $changes) . "-- Dump completed on 2026-01-01 00:00:00\n");
        $inputs = $this->inputs();
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $marker = DatabaseMarker::current();
        try {
            FullRestorePlanner::prepare($inputs);
            $this->fail('The JSON constraint must not hide missing FK evidence');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString($reason, $failure->getMessage());
            $this->assertEquals($snapshot, FullRestoreSchemaSnapshot::read($inputs->targets));
            $this->assertJsonTargetIntact();
            $this->assertEquals($marker, DatabaseMarker::current());
        }
    }

    /** @return iterable<string, array{array<string, string>, string}> Full parent facts remain mandatory with a JSON CHECK */
    public static function invalidArchivedJsonParents(): iterable
    {
        yield 'parent column' => [['REFERENCES `hilos_analytics_payload_json` (`id`)'
            => 'REFERENCES `hilos_analytics_payload_json` (`missing`)'], 'parent columns'];
        yield 'parent type' => [['`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT'
            => '`id` int(11) unsigned NOT NULL AUTO_INCREMENT'], 'column types or collations'];
        yield 'parent index' => [["  PRIMARY KEY (`id`),\n" => ''], 'supporting index'];
        yield 'parent engine' => [['ENGINE=InnoDB AUTO_INCREMENT=' => 'ENGINE=MyISAM AUTO_INCREMENT='], 'parent columns'];
        yield 'parent collation' => [[
            '`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT' => '`id` varchar(20) COLLATE utf8mb4_bin NOT NULL',
            '`payload_json_id` bigint(20) unsigned' => '`payload_json_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
        ], 'column types or collations'];
        yield 'parent charset' => [[
            '`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT' => '`id` varchar(20) COLLATE utf8mb4_bin NOT NULL',
            '`payload_json_id` bigint(20) unsigned' => '`payload_json_id` varchar(20) CHARACTER SET ascii',
        ], 'column types or collations'];
    }

    /**
     * @param string $source Declared late table constraint
     * @param string $live Actual MariaDB constraint
     */
    #[DataProvider('changedJsonChecks')]
    public function testChangedJsonCheckCannotAuthorizeCleanup(string $source, string $live): void
    {
        $prefix = 'CREATE TABLE later(id INT PRIMARY KEY, payload_json LONGTEXT NOT NULL';
        FsPath::write($this->root . '/Schema/004_proof.sql', $prefix . $source . ");\n");
        Database::sql($prefix . $live . ')');
        Database::sql("INSERT INTO later VALUES(14,'[]')");
        Database::sql('INSERT INTO migration VALUES(4,0)');
        $marker = $this->jsonMarker();
        $inputs = $this->inputs();
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        try {
            FullRestorePlanner::prepare($inputs);
            $this->fail('A changed CHECK cannot establish cleanup provenance');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertEquals($snapshot, FullRestoreSchemaSnapshot::read($inputs->targets));
            $this->assertSame([['id' => '14', 'payload_json' => '[]']], Database::sql('SELECT * FROM later')->rows());
            $this->assertSame(13, (int)Database::sql('SELECT id FROM independent')->field('id'));
            $this->assertSame([1, 2, 3, 4], Migration::appliedIndices());
            $this->assertEquals($marker, DatabaseMarker::current());
        }
    }

    /** @return iterable<string, array{string, string}> Real missing, additional and changed constraints */
    public static function changedJsonChecks(): iterable
    {
        yield 'missing live check' => [' CHECK (json_valid(payload_json))', ''];
        yield 'additional live check' => ['', ' CHECK (json_valid(payload_json))'];
        yield 'changed expression' => [' CHECK (json_valid(payload_json))', ' CHECK (json_valid(payload_json) OR 1)'];
    }

    /** A trigger on retained rows belongs to those rows, even with an otherwise valid cleanup plan. */
    public function testDropOfRetainedTriggerRefusesWithoutChangingTheCatalog(): void
    {
        Database::sql('CREATE TRIGGER retained_trigger BEFORE DELETE ON independent FOR EACH ROW SET @seen=1');
        FsPath::write($this->root . '/Schema/004_proof.sql', "DROP TRIGGER IF EXISTS retained_trigger;\n");
        $inputs = $this->inputs();
        $before = FullRestoreSchemaSnapshot::read($inputs->targets);
        try {
            FullRestorePlanner::prepare($inputs);
            $this->fail('A retained table owns its trigger');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString('unproven target-only', $failure->getMessage());
            $this->assertEquals($before, FullRestoreSchemaSnapshot::read($inputs->targets));
            $this->assertSame(13, (int)Database::sql('SELECT id FROM independent')->field('id'));
        }
    }

    /** A CREATE naming only its charset still needs that charset's effective default collation. */
    public function testSnapshotIncludesTheDefaultCollationOfEveryCharacterSet(): void
    {
        $inputs = $this->inputs();
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $expected = [];
        foreach (Database::sql('SELECT CHARACTER_SET_NAME, DEFAULT_COLLATE_NAME FROM information_schema.CHARACTER_SETS')->rows() as $row) {
            $expected[$row['CHARACTER_SET_NAME']] = $row['DEFAULT_COLLATE_NAME'];
        }
        $this->assertEquals($expected, $snapshot->schemas[0]->structureDefaults->collations);
    }

    /** Explicit non-default collations and MariaDB's full UCA aliases must have catalog-proven charsets. */
    public function testSnapshotIncludesCollationCharacterSetEvidence(): void
    {
        $inputs = $this->inputs();
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $map = $snapshot->schemas[0]->structureDefaults->charsetByCollation;
        $this->assertSame('latin1', $map['latin1_bin']);
        $this->assertSame('ascii', $map['ascii_bin']);
        $this->assertSame('utf8mb4', $map['utf8mb4_bin']);
        $this->assertSame('utf8mb4', $map['utf8mb4_uca1400_ai_ci']);
        $this->assertArrayNotHasKey('uca1400_ai_ci', $map);
        $snapshot->assertUnchanged($inputs->targets);
    }

    /** The final read must compare the newly captured catalog evidence even when every table is unchanged. */
    public function testRecheckIncludesCollationCharacterSetEvidence(): void
    {
        $inputs = $this->inputs();
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $schema = $snapshot->schemas[0];
        $defaults = $schema->structureDefaults;
        $changedMap = $defaults->charsetByCollation;
        $changedMap['latin1_bin'] = 'ascii';
        $captured = new FullRestoreSchemaSnapshot([0 => new SchemaMetadata(
            $schema->server, $schema->objects, $schema->defaults, $schema->columns, $schema->indexes,
            new StructureDefaults($defaults->engine, $defaults->charset, $defaults->collation, $defaults->collations,
                $defaults->sqlMode, $defaults->rowFormat, $changedMap, $defaults->mariaDbJsonAlias), $schema->grants,
            completeTriggerVisibility: $schema->completeTriggerVisibility,
            triggers: $schema->triggers,
        )], $snapshot->servers);
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('changed');
        $captured->assertUnchanged($inputs->targets);
    }

    /** The real destination proves the alias, and changing only that capability invalidates the snapshot. */
    public function testRecheckIncludesMariaDbJsonAliasEvidence(): void
    {
        $inputs = $this->inputs();
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $schema = $snapshot->schemas[0];
        $defaults = $schema->structureDefaults;
        $this->assertTrue($defaults->mariaDbJsonAlias);
        $snapshot->assertUnchanged($inputs->targets);
        $captured = new FullRestoreSchemaSnapshot([0 => new SchemaMetadata(
            $schema->server, $schema->objects, $schema->defaults, $schema->columns, $schema->indexes,
            new StructureDefaults($defaults->engine, $defaults->charset, $defaults->collation, $defaults->collations,
                $defaults->sqlMode, $defaults->rowFormat, $defaults->charsetByCollation, mariaDbJsonAlias: false), $schema->grants,
            completeTriggerVisibility: $schema->completeTriggerVisibility,
            triggers: $schema->triggers,
        )], $snapshot->servers);
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('changed');
        $captured->assertUnchanged($inputs->targets);
    }

    /**
     * The real session reports enforced CHECK constraints, and changing only that report invalidates the snapshot.
     *
     * @param ?string $captured Prepared report that differs from the session's actual ON
     */
    #[DataProvider('changedCheckEnforcements')]
    public function testRecheckIncludesCheckConstraintEnforcement(?string $captured): void
    {
        $inputs = $this->inputs();
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $schema = $snapshot->schemas[0];
        $this->assertSame('ON', $schema->checkConstraintChecks);
        $snapshot->assertUnchanged($inputs->targets);
        $changed = new FullRestoreSchemaSnapshot([0 => new SchemaMetadata(
            $schema->server, $schema->objects, $schema->defaults, $schema->columns, $schema->indexes, $schema->structureDefaults,
            $schema->grants, $schema->completeTriggerVisibility, $schema->triggers, $captured,
        )], $snapshot->servers);
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('changed');
        $changed->assertUnchanged($inputs->targets);
    }

    /** @return iterable<string, array{?string}> Disabled and unknown enforcement reports */
    public static function changedCheckEnforcements(): iterable
    {
        yield 'OFF' => ['OFF'];
        yield 'no variable' => [null];
    }

    /**
     * @param string $column Ordinary text column declaration
     * @param string $options Explicit or inherited table character options
     */
    #[DataProvider('ordinaryCharacterDeclarations')]
    public function testCharacterDeclarationsProveCleanupAgainstRealShowCreate(string $column, string $options): void
    {
        $sql = 'CREATE TABLE later(id INT PRIMARY KEY, ' . $column . ') ' . $options;
        FsPath::write($this->root . '/Schema/004_proof.sql', $sql . ";\n");
        Database::sql($sql);
        Database::sql('INSERT INTO migration VALUES(4,0)');
        $planner = FullRestorePlanner::prepare($this->inputs());
        $this->assertContains('later', array_column($planner->cleanup, 'table'));
        $planner->assertUnchanged();
    }

    /** @return iterable<string, array{string, string}> Collation-only, explicit and inherited server-resolved pairs */
    public static function ordinaryCharacterDeclarations(): iterable
    {
        yield 'table collation' => ['label VARCHAR(10)', 'COLLATE=latin1_bin'];
        yield 'column collation' => ['label VARCHAR(10) COLLATE ascii_bin', ''];
        yield 'column overrides table' => ['label VARCHAR(10) COLLATE utf8mb4_bin', 'COLLATE=latin1_bin'];
        yield 'charset default alias' => ['label VARCHAR(10)', 'CHARSET=utf8mb4'];
        yield 'explicit default alias' => ['label VARCHAR(10)', 'CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci'];
        yield 'explicit non-default alias' => ['label VARCHAR(10)', 'COLLATE=utf8mb4_uca1400_as_cs'];
        yield 'reordered table options' => ['label VARCHAR(10)', 'COLLATE=latin1_bin CHARSET=latin1'];
    }

    /**
     * @param string $mode Metadata session mode that hides a material property
     * @param string $liveSql Physically different table with the same truncated declaration
     * @param string $catalogSql Independent physical metadata observation
     * @param string $physicalValue Material value still visible in INFORMATION_SCHEMA
     * @param string $hidden Text SHOW CREATE must omit in this mode
     */
    #[DataProvider('hiddenPhysicalProperties')]
    public function testHiddenPhysicalPropertiesCannotProveCleanup(
        string $mode,
        string $liveSql,
        string $catalogSql,
        string $physicalValue,
        string $hidden,
    ): void {
        FsPath::write($this->root . '/Schema/004_proof.sql', "CREATE TABLE later(id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;\n");
        Database::sql($liveSql);
        Database::sql('INSERT INTO later VALUES(14)');
        Database::sql('INSERT INTO migration VALUES(4,0)');
        $originalMode = Database::sql('SELECT @@SESSION.sql_mode AS mode')->field('mode');
        try {
            Database::sql('SET SESSION sql_mode = ?', [$mode]);
            $this->assertSame($physicalValue, (string)Database::sql($catalogSql)->field('physical_value'));
            $inputs = $this->inputs();
            $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
            $this->assertStringNotContainsString($hidden, $snapshot->schemas[0]->objects['later']->createSql);
            try {
                FullRestorePlanner::prepare($inputs);
                $this->fail('A truncated SHOW CREATE must refuse before cleanup');
            } catch (RestoreFailedException $failure) {
                $this->assertStringContainsString('SHOW CREATE', $failure->getMessage());
                $this->assertFalse($failure->databaseTouched());
                $this->assertSame(14, (int)Database::sql('SELECT id FROM later')->field('id'));
                $this->assertSame($mode, Database::sql('SELECT @@SESSION.sql_mode AS mode')->field('mode'));
            }
        } finally {
            Database::sql('SET SESSION sql_mode = ?', [$originalMode]);
        }
    }

    /** @return iterable<string, array{string, string, string, string, string}> Real hidden column and table attributes */
    public static function hiddenPhysicalProperties(): iterable
    {
        yield 'auto increment' => ['NO_FIELD_OPTIONS', 'CREATE TABLE later(id INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB',
            "SELECT EXTRA AS physical_value FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()"
                . " AND TABLE_NAME='later' AND COLUMN_NAME='id'",
            'auto_increment', 'NOT NULL AUTO_INCREMENT'];
        yield 'table comment' => ['NO_TABLE_OPTIONS', "CREATE TABLE later(id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB COMMENT='manual change'",
            "SELECT TABLE_COMMENT AS physical_value FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='later'",
            'manual change', 'COMMENT'];
        yield 'key comment' => ['NO_KEY_OPTIONS', "CREATE TABLE later(id INT NOT NULL, PRIMARY KEY(id) COMMENT 'manual change') ENGINE=InnoDB",
            "SELECT INDEX_COMMENT AS physical_value FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='later'",
            'manual change', 'COMMENT'];
    }

    /** Same-server metadata includes a CASCADE dependency from a schema outside the FULL plan. */
    public function testExternalIncomingForeignKeyIsVisibleAndRefuses(): void
    {
        Database::sql('CREATE TABLE ' . ChangeLogDatabase::identifier($this->external) . '.outside_child (id INT, parent_id INT,'
            . ' CONSTRAINT outside_fk FOREIGN KEY(parent_id) REFERENCES ' . ChangeLogDatabase::identifier($this->database)
            . '.parent(id) ON DELETE CASCADE)');
        $this->selectCaller();
        $inputs = $this->inputs();
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $rows = array_values($snapshot->servers)[0]->foreignKeys;
        $this->assertNotEmpty(array_filter($rows,
            fn (array $row): bool => $row['TABLE_SCHEMA'] === $this->external && $row['DELETE_RULE'] === 'CASCADE'));
        // The caller is deliberately outside the prepared destination, on both success and refusal.
        try {
            FullRestorePlanner::prepare($inputs);
            $this->fail('An external dependency must refuse');
        } catch (RestoreFailedException $failure) {
            $this->assertStringContainsString('outside_fk', $failure->getMessage());
            $this->assertFalse($failure->databaseTouched());
            $this->assertSame(7, Database::getCurrentIndex());
        }
    }

    /** Real filtered INFORMATION_SCHEMA remains insufficient even though the account sees all target tables. */
    public function testRestrictedMetadataVisibilityRefusesBeforeAnyWrite(): void
    {
        Database::sql('CREATE USER ?@? IDENTIFIED BY ?', [$this->restrictedUser, '%', 'fixture-secret']);
        Database::sql('GRANT SELECT, SHOW VIEW ON ' . ChangeLogDatabase::identifier($this->database) . '.* TO ?@?', [$this->restrictedUser, '%']);
        $this->connect($this->database, $this->restrictedUser, 'fixture-secret');
        $inputs = $this->inputs();
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $this->assertFalse(array_values($snapshot->servers)[0]->completeVisibility);
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('visibility');
        FullRestorePlanner::prepare($inputs);
    }

    /** Real view/trigger/routine declarations in another schema are dependencies, not anonymous catalog rows. */
    #[DataProvider('dependentObjects')]
    public function testDependentServiceObjectsRefuse(string $kind): void
    {
        $external = ChangeLogDatabase::identifier($this->external);
        $parent = ChangeLogDatabase::identifier($this->database) . '.parent';
        if ($kind === 'view') {
            Database::sql('CREATE VIEW ' . $external . '.dependent_object AS SELECT id FROM ' . $parent);
        } elseif ($kind === 'routine') {
            Database::sql('CREATE FUNCTION ' . $external . '.dependent_object() RETURNS INT READS SQL DATA RETURN (SELECT id FROM '
                . $parent . ' LIMIT 1)');
        } else {
            Database::sql('CREATE TABLE ' . $external . '.trigger_source (id INT)');
            Database::sql('CREATE TRIGGER ' . $external . '.dependent_object BEFORE INSERT ON ' . $external
                . '.trigger_source FOR EACH ROW SET NEW.id = (SELECT id FROM ' . $parent . ' LIMIT 1)');
        }
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('dependent_object');
        FullRestorePlanner::prepare($this->inputs());
    }

    /** @return iterable<array{string}> Automatically removed or statically dependent service objects */
    public static function dependentObjects(): iterable
    {
        yield ['view'];
        yield ['trigger'];
        yield ['routine'];
    }

    /** Each visible change of proof must invalidate the prepared result before import. */
    #[DataProvider('changedProofs')]
    public function testRecheckDetectsRelevantChanges(string $change): void
    {
        $planner = FullRestorePlanner::prepare($this->inputs());
        match ($change) {
            'schema' => Database::sql('ALTER TABLE child ADD changed INT'),
            'history' => Database::sql('UPDATE migration SET failed=1 WHERE `index`=2'),
            'track' => FsPath::write($this->root . '/Schema/004_proof.sql', "ALTER TABLE child ADD changed INT;\n"),
            'dependency' => Database::sql('CREATE VIEW ' . ChangeLogDatabase::identifier($this->external) . '.dependent AS SELECT id FROM '
                . ChangeLogDatabase::identifier($this->database) . '.parent'),
            'session mode' => Database::sql("SET SESSION sql_mode = 'NO_FIELD_OPTIONS'"),
            'external catalog' => Database::sql(
                'CREATE VIEW ' . ChangeLogDatabase::identifier($this->external) . '.external_drift AS SELECT 1 AS id'
            ),
            'session CHECK enforcement' => Database::sql('SET SESSION check_constraint_checks = OFF'),
        };
        $this->expectException(RestoreFailedException::class);
        $planner->assertUnchanged();
    }

    /** @return iterable<array{string}> Independent evidence dimensions */
    public static function changedProofs(): iterable
    {
        yield ['schema'];
        yield ['history'];
        yield ['track'];
        yield ['dependency'];
        yield ['session mode'];
        yield ['external catalog'];
        yield ['session CHECK enforcement'];
    }

    /**
     * @return FullRestorePlan Complete source admission before physical planning
     */
    private function inputs(): FullRestorePlan
    {
        return FullRestorePlan::prepare([new BackupConnectionMeta(DatabaseConnectionDefaults::PRIMARY_INDEX, 'source-name', null)], $this->root);
    }

    /** @return string Real MariaDB JSON parent/BTREE child output alongside an unrelated historical table */
    private function jsonDump(): string
    {
        foreach (FsPath::entries($this->root . '/Schema') as $file) {
            FsPath::delete($this->root . '/Schema/' . $file);
        }
        FsPath::write($this->root . '/Schema/109_archive.sql', "SELECT 1;\n");
        FsPath::write($this->root . '/Schema/110_retire.sql', "DROP TABLE moderator_prompt_piece;\n");
        Database::sql('DELETE FROM migration');
        Database::sql('INSERT INTO migration VALUES(109,0),(110,0)');
        $analytics = FsPath::read(dirname(__DIR__, 3) . '/demo/chat/backend/Database/Migration/Schema/015_create_hilos_analytics.sql');
        $parent = array_find(MigrationSqlReader::statements($analytics),
            static fn (string $sql): bool => str_starts_with($sql, 'CREATE TABLE `hilos_analytics_payload_json`'));
        $this->assertNotNull($parent, 'The fixture uses the unchanged production JSON parent');
        Database::sql($parent);
        Database::sql('CREATE TABLE json_child(id BIGINT UNSIGNED PRIMARY KEY, payload_json_id BIGINT UNSIGNED, '
            . 'KEY idx_payload(payload_json_id), CONSTRAINT fk_payload FOREIGN KEY(payload_json_id) '
            . 'REFERENCES hilos_analytics_payload_json(id) ON DELETE SET NULL) ENGINE=InnoDB');
        Database::sql("INSERT INTO hilos_analytics_payload_json VALUES(21,UNHEX(?),'[]',1)", [str_repeat('a', 40)]);
        Database::sql('INSERT INTO json_child VALUES(22,21)');
        $this->jsonMarker();
        return "CREATE TABLE migration (`index` INT PRIMARY KEY, failed TINYINT NOT NULL);\n"
            . "INSERT INTO migration VALUES(109,0);\n"
            . Database::sql('SHOW CREATE TABLE hilos_analytics_payload_json')->field('Create Table') . ";\n"
            . Database::sql('SHOW CREATE TABLE json_child')->field('Create Table') . ";\n"
            . "CREATE TABLE moderator_prompt_piece(id INT PRIMARY KEY);\n";
    }

    /** Read-only preparation preserves JSON/FK data, the independent sentinel and exact live history. */
    private function assertJsonTargetIntact(): void
    {
        $this->assertSame([['id' => '21', 'payload_json' => '[]']],
            Database::sql('SELECT id, payload_json FROM hilos_analytics_payload_json')->rows());
        $this->assertSame([['id' => '22', 'payload_json_id' => '21']], Database::sql('SELECT * FROM json_child')->rows());
        $this->assertSame(13, (int)Database::sql('SELECT id FROM independent')->field('id'));
        $this->assertSame([109, 110], Migration::appliedIndices());
    }

    /** @return DatabaseMarkerRow Deterministic target marker, prepared outside any rollback scope */
    private function jsonMarker(): DatabaseMarkerRow
    {
        Database::sql('CREATE TABLE ' . DatabaseMarker::TABLE . ' (id TINYINT UNSIGNED PRIMARY KEY, '
            . 'marker CHAR(32) NOT NULL, written_by VARCHAR(128) NOT NULL, written_at BIGINT NOT NULL)');
        $marker = new DatabaseMarkerRow('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'restore-json-proof', 1767225600123);
        DatabaseMarker::put($marker);
        return $marker;
    }

    /**
     * @param string $database Owned physical schema
     * @param string $user Account used for real visibility checks
     * @param ?string $password Explicit fixture secret, or the stand root password
     */
    private function connect(string $database, string $user = 'root', ?string $password = null): void
    {
        $index = DatabaseConnectionDefaults::PRIMARY_INDEX;
        Database::close($index);
        Database::configure($index, $this->base->host, $user, $password ?? Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
            $database, $this->base->port, $this->base->charset);
        Database::connect($index);
    }

    /** Selects a real unrelated connection so a missing finally block cannot pass unnoticed. */
    private function selectCaller(): void
    {
        Database::configure(7, $this->base->host, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
            $this->external, $this->base->port, $this->base->charset);
        Database::connect(7);
        Database::useConnection(7);
    }
}
