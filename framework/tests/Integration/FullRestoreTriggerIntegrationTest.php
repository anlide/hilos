<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestorePlan;
use Hilos\Backup\FullRestorePlanner;
use Hilos\Backup\FullRestoreSchemaSnapshot;
use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseMarker;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Real trigger/grant DDL needs owned schemas and accounts, outside the rollback harness. */
final class FullRestoreTriggerIntegrationTest extends FrameworkIntegrationTestCase
{
    private const int CONTROL_INDEX = 7;
    private const int SECOND_INDEX = 2;
    private const string PASSWORD = 'trigger-fixture-secret';

    private DatabaseConnectionConfig $base;
    private string $catalogHost;
    private array $databases;
    private array $users;
    private array $previousMigration;
    private string $root;

    /** Owns a server-wide catalog and two destinations, each visible only to its own account. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->base = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $catalogHost = getenv('HILOS_TEST_CATALOG_DB_HOST');
        $this->assertIsString($catalogHost, 'The trigger fixture needs its dedicated catalog server');
        $this->assertNotSame('', $catalogHost);
        $this->assertNotSame($this->base->host, $catalogHost);
        $this->catalogHost = $catalogHost;
        $suffix = bin2hex(random_bytes(6));
        $this->databases = [0 => 'hilos-trigger_' . $suffix . '%`', self::SECOND_INDEX => 'hilos-trigger-' . $suffix];
        $this->users = [0 => 'trigger-' . $suffix . '-a', self::SECOND_INDEX => 'trigger-' . $suffix . '-b'];
        $this->root = sys_get_temp_dir() . '/hilos-trigger-' . $suffix;
        FsPath::ensureDirectory($this->root . '/Schema', 0700);
        $this->previousMigration = [];
        foreach (['migrationListPath', 'migrationName', 'initialized'] as $property) {
            $this->previousMigration[$property] = new ReflectionProperty(Migration::class, $property)->getValue();
        }
        Migration::setMigrationListPath($this->root);
        Migration::setMigrationName('Schema');
        FsPath::write($this->root . '/Schema/001_archive.sql', "SELECT 1;\n");
        FsPath::write($this->root . '/Schema/002_retire.sql', "DROP TRIGGER IF EXISTS retired_trigger;\nDROP TABLE carried;\n");
        $this->connect(self::CONTROL_INDEX, 'information_schema', 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string());
        foreach ($this->databases as $index => $database) {
            Database::sql('CREATE DATABASE ' . self::identifier($database));
            Database::sql('CREATE USER ?@? IDENTIFIED BY ?', [$this->users[$index], '%', self::PASSWORD]);
            $prefix = self::identifier($database) . '.';
            Database::sql('CREATE TABLE ' . $prefix . 'migration (`index` INT PRIMARY KEY, failed TINYINT NOT NULL)');
            Database::sql('INSERT INTO ' . $prefix . 'migration VALUES(1,0),(2,0)');
            Database::sql('CREATE TABLE ' . $prefix . 'carried(id INT)');
            Database::sql('INSERT INTO ' . $prefix . 'carried VALUES(11)');
            Database::sql('CREATE TABLE ' . $prefix . 'retained(id INT)');
            Database::sql('INSERT INTO ' . $prefix . 'retained VALUES(13)');
            FsPath::write($this->root . '/db-' . $index . '.sql',
                "CREATE TABLE migration (`index` INT PRIMARY KEY, failed TINYINT NOT NULL);\n"
                . "INSERT INTO migration VALUES(1,0);\nCREATE TABLE carried(id INT);\n"
                . "INSERT INTO carried VALUES(7);\n-- Dump completed on 2026-01-01 00:00:00\n");
            $this->grant($index, 'SELECT, SHOW VIEW, TRIGGER');
        }
        Database::sql('CREATE TABLE ' . self::identifier($this->databases[0]) . '.' . DatabaseMarker::TABLE
            . '(id INT PRIMARY KEY, marker VARCHAR(64), written_by VARCHAR(64), written_at BIGINT)');
        Database::sql('INSERT INTO ' . self::identifier($this->databases[0]) . '.' . DatabaseMarker::TABLE
            . " VALUES(1,'original-marker','fixture',1767225600123)");
        $this->connect(0, $this->databases[0], $this->users[0], self::PASSWORD);
        Database::useConnection(self::CONTROL_INDEX);
    }

    /** Drops owned DDL resources and restores invocation-local migration configuration on every outcome. */
    protected function tearDown(): void
    {
        try {
            Database::useConnection(self::CONTROL_INDEX);
            foreach ($this->databases as $index => $database) {
                Database::sql('DROP DATABASE IF EXISTS ' . self::identifier($database));
                Database::sql('DROP USER IF EXISTS ?@?', [$this->users[$index], '%']);
            }
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

    /**
     * @param bool $present Whether an archive-owned trigger still exists
     * @param string $privileges Sufficient direct schema grant, without global privileges
     */
    #[DataProvider('admittedTriggers')]
    public function testRestrictedSchemaGrantAdmitsExactConditionalDrop(bool $present, string $privileges): void
    {
        if ($present) {
            $this->createTrigger(0, 'carried');
        }
        $this->grant(0, $privileges);
        $planner = FullRestorePlanner::prepare($this->inputs());
        $this->assertSame([], $planner->cleanup);
        $this->assertFalse(array_values($planner->schema->servers)[0]->completeVisibility);
        $this->assertTrue($planner->schema->schemas[0]->completeTriggerVisibility);
        $this->assertCount($present ? 1 : 0, $planner->schema->schemas[0]->triggers);
        $planner->assertUnchanged();
        $this->assertIntact();
    }

    /** @return iterable<string, array{bool, string}> Absent/present triggers and both sufficient schema grants */
    public static function admittedTriggers(): iterable
    {
        yield 'absent trigger' => [false, 'SELECT, SHOW VIEW, TRIGGER'];
        yield 'archive owner' => [true, 'SELECT, SHOW VIEW, TRIGGER'];
        yield 'schema all' => [true, 'ALL PRIVILEGES'];
    }

    /** @param bool $partial Whether table-only TRIGGER exposes part of the catalog */
    #[DataProvider('incompleteCatalogs')]
    public function testMissingSchemaTriggerGrantNeverProvesAbsence(bool $partial): void
    {
        $this->createTrigger(0, 'retained');
        Database::sql('REVOKE TRIGGER ON ' . $this->grantSchema(0) . '.* FROM ?@?', [$this->users[0], '%']);
        if ($partial) {
            Database::sql('GRANT TRIGGER ON ' . self::identifier($this->databases[0]) . '.retained TO ?@?', [$this->users[0], '%']);
        }
        $this->connect(0, $this->databases[0], $this->users[0], self::PASSWORD);
        Database::useConnection(self::CONTROL_INDEX);
        $inputs = $this->inputs();
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $this->assertFalse($snapshot->schemas[0]->completeTriggerVisibility);
        $this->assertCount($partial ? 1 : 0, $snapshot->schemas[0]->triggers);
        try {
            FullRestorePlanner::prepare($inputs);
            $this->fail('A filtered catalog cannot prove trigger ownership or absence');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString('trigger ownership visibility', $failure->getMessage());
            $this->assertEquals($snapshot, FullRestoreSchemaSnapshot::read($inputs->targets));
            $this->assertIntact();
        }
    }

    /** @param bool $partial Whether an unrelated table-only grant exposes one trigger but hides the marker's */
    #[DataProvider('incompleteCatalogs')]
    public function testBootstrapUpdateRequiresCompleteSchemaTriggerVisibility(bool $partial): void
    {
        $part = new FrameworkMigrationCatalog()->part('20261109000848893922_HIL-1918_bootstrap_moments', 'database_marker');
        $origin = FrameworkMigrationOrigin::forBodies(FrameworkMigrationOrigin::CHANGE, [$part->unit], [$part], $part->upSql, $part->downSql);
        FsPath::write($this->root . '/Schema/002_retire.sql', $origin->header() . "\n" . $part->upSql);
        FsPath::write($this->root . '/Schema/002_retire_down.sql', $origin->header() . "\n" . $part->downSql);
        $this->createTrigger(0, DatabaseMarker::TABLE);
        Database::sql('REVOKE TRIGGER ON ' . $this->grantSchema(0) . '.* FROM ?@?', [$this->users[0], '%']);
        if ($partial) {
            Database::sql('GRANT TRIGGER ON ' . self::identifier($this->databases[0]) . '.retained TO ?@?', [$this->users[0], '%']);
            Database::sql('CREATE TRIGGER ' . self::identifier($this->databases[0]) . '.visible_trigger BEFORE INSERT ON '
                . self::identifier($this->databases[0]) . '.retained FOR EACH ROW SET NEW.id=NEW.id');
        }
        $this->connect(0, $this->databases[0], $this->users[0], self::PASSWORD);
        Database::useConnection(self::CONTROL_INDEX);
        $inputs = $this->inputs();
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $this->assertFalse($snapshot->schemas[0]->completeTriggerVisibility);
        $this->assertCount($partial ? 1 : 0, $snapshot->schemas[0]->triggers);
        try {
            FullRestorePlanner::prepare($inputs);
            $this->fail('A hidden retained bootstrap trigger cannot be proven absent');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString('bootstrap trigger visibility', $failure->getMessage());
            $this->assertEquals($snapshot, FullRestoreSchemaSnapshot::read($inputs->targets));
            $this->assertIntact();
        }
    }

    /** @return iterable<string, array{bool}> Empty and partially visible catalogs */
    public static function incompleteCatalogs(): iterable
    {
        yield 'empty' => [false];
        yield 'table-only' => [true];
    }

    /** Global TRIGGER alone proves the schema's trigger set without proving server-wide dependencies. */
    public function testGlobalTriggerPrivilegeWorksWithoutOtherGlobalGrants(): void
    {
        $this->createTrigger(0, 'carried');
        Database::sql('REVOKE TRIGGER ON ' . $this->grantSchema(0) . '.* FROM ?@?', [$this->users[0], '%']);
        Database::sql('GRANT TRIGGER ON *.* TO ?@?', [$this->users[0], '%']);
        $this->connect(0, $this->databases[0], $this->users[0], self::PASSWORD);
        Database::useConnection(self::CONTROL_INDEX);
        $planner = FullRestorePlanner::prepare($this->inputs());
        $this->assertSame([], $planner->cleanup);
        $this->assertTrue($planner->schema->schemas[0]->completeTriggerVisibility);
        $this->assertFalse(array_values($planner->schema->servers)[0]->completeVisibility);
        $planner->assertUnchanged();
        $this->assertIntact();
    }

    /** Global TRIGGER evidence still detects another schema's drift on the fixture's own server. */
    public function testGlobalTriggerRecheckDetectsOtherSchemaDrift(): void
    {
        Database::sql('REVOKE TRIGGER ON ' . $this->grantSchema(0) . '.* FROM ?@?', [$this->users[0], '%']);
        Database::sql('GRANT TRIGGER ON *.* TO ?@?', [$this->users[0], '%']);
        $this->connect(0, $this->databases[0], $this->users[0], self::PASSWORD);
        Database::useConnection(self::CONTROL_INDEX);
        $inputs = $this->inputs();
        $planner = FullRestorePlanner::prepare($inputs);
        $this->assertSame([], array_values($planner->schema->servers)[0]->triggers);
        $planner->assertUnchanged();
        $this->createTrigger(self::SECOND_INDEX, 'retained');
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $this->assertEquals($planner->schema->schemas, $snapshot->schemas);
        $this->assertCount(1, array_values($snapshot->servers)[0]->triggers);
        try {
            $planner->assertUnchanged();
            $this->fail('Server-wide trigger evidence must include drift outside the destination schema');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString('changed', $failure->getMessage());
            $this->assertIntact();
        }
    }

    /** @param string $owner Archived or retained owner hidden from the first target's account */
    #[DataProvider('secondOwners')]
    public function testSecondCredentialUsesItsOwnTriggerCatalog(string $owner): void
    {
        $this->createTrigger(self::SECOND_INDEX, $owner);
        $this->connect(self::SECOND_INDEX, $this->databases[self::SECOND_INDEX], $this->users[self::SECOND_INDEX], self::PASSWORD);
        Database::useConnection(self::CONTROL_INDEX);
        $inputs = $this->inputs([0, self::SECOND_INDEX]);
        $snapshot = FullRestoreSchemaSnapshot::read($inputs->targets);
        $this->assertSame($snapshot->schemas[0]->server, $snapshot->schemas[self::SECOND_INDEX]->server);
        $this->assertSame([], array_values($snapshot->servers)[0]->triggers);
        $this->assertTrue($snapshot->schemas[self::SECOND_INDEX]->completeTriggerVisibility);
        $this->assertSame($owner, $snapshot->schemas[self::SECOND_INDEX]->triggers[0]['EVENT_OBJECT_TABLE']);
        try {
            $planner = FullRestorePlanner::prepare($inputs);
            $this->assertSame('carried', $owner, 'The second account must not borrow proven absence from the first');
            $this->assertSame([], $planner->cleanup);
            $planner->assertUnchanged();
        } catch (RestoreFailedException $failure) {
            $this->assertSame('retained', $owner);
            $this->assertStringContainsString('unproven target-only', $failure->getMessage());
            $this->assertFalse($failure->databaseTouched());
        }
        $this->assertIntact();
    }

    /** @return iterable<string, array{string}> Allowed and forbidden owners of the second account's trigger */
    public static function secondOwners(): iterable
    {
        yield 'archive' => ['carried'];
        yield 'retained' => ['retained'];
    }

    /** @param string $change Relevant change immediately before the first write, with no cleanup */
    #[DataProvider('triggerDrifts')]
    public function testFinalRecheckDetectsTriggerAndGrantDrift(string $change): void
    {
        if ($change === 'owner') {
            $this->createTrigger(0, 'carried');
        }
        $planner = FullRestorePlanner::prepare($this->inputs());
        $this->assertSame([], $planner->cleanup);
        if ($change === 'grant') {
            Database::sql('REVOKE TRIGGER ON ' . $this->grantSchema(0) . '.* FROM ?@?', [$this->users[0], '%']);
        } else {
            if ($change === 'owner') {
                Database::sql('DROP TRIGGER ' . self::identifier($this->databases[0]) . '.retired_trigger');
            }
            $this->createTrigger(0, 'retained');
        }
        try {
            $planner->assertUnchanged();
            $this->fail('Schema-local trigger proof must be part of the final unchanged boundary');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString('changed', $failure->getMessage());
            $this->assertIntact();
        }
    }

    /** @return iterable<string, array{string}> Trigger names, owners and direct privileges are all immutable evidence */
    public static function triggerDrifts(): iterable
    {
        yield 'added name' => ['added'];
        yield 'changed owner' => ['owner'];
        yield 'lost grant' => ['grant'];
    }

    /**
     * @param list<int> $indices Prepared destinations
     * @return FullRestorePlan Real staged dumps and target bindings
     */
    private function inputs(array $indices = [0]): FullRestorePlan
    {
        return FullRestorePlan::prepare(array_map(static fn (int $index): BackupConnectionMeta =>
            new BackupConnectionMeta($index, 'source-name', null), $indices), $this->root);
    }

    /** Every safe refusal preserves target rows, history, marker and the caller's selected connection. */
    private function assertIntact(): void
    {
        $this->assertSame(self::CONTROL_INDEX, Database::getCurrentIndex());
        $this->assertSame([['id' => '11']], Database::sql('SELECT * FROM carried', index: 0)->rows());
        $this->assertSame([['id' => '13']], Database::sql('SELECT * FROM retained', index: 0)->rows());
        $this->assertSame([['index' => '1', 'failed' => '0'], ['index' => '2', 'failed' => '0']],
            Database::sql('SELECT * FROM migration ORDER BY `index`', index: 0)->rows());
        $this->assertSame('original-marker', DatabaseMarker::current()->marker);
        $this->assertSame(self::CONTROL_INDEX, Database::getCurrentIndex());
    }

    /**
     * @param int $index Destination owning this account
     * @param string $privileges Direct schema privileges
     */
    private function grant(int $index, string $privileges): void
    {
        Database::sql('GRANT ' . $privileges . ' ON ' . $this->grantSchema($index) . '.* TO ?@?', [$this->users[$index], '%']);
    }

    /**
     * @param int $index Prepared physical destination
     * @return string Quoted literal database pattern, including grant-specific wildcard escapes
     */
    private function grantSchema(int $index): string
    {
        return self::identifier(str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $this->databases[$index]));
    }

    /**
     * @param int $index Prepared physical destination
     * @param string $owner Existing base table
     */
    private function createTrigger(int $index, string $owner): void
    {
        $schema = self::identifier($this->databases[$index]) . '.';
        Database::sql('CREATE TRIGGER ' . $schema . 'retired_trigger BEFORE INSERT ON ' . $schema
            . self::identifier($owner) . ' FOR EACH ROW SET NEW.id=NEW.id');
    }

    /**
     * @param int $index Fixture connection
     * @param string $database Physical schema
     * @param string $user Actual account
     * @param string $password Fixture credential
     */
    private function connect(int $index, string $database, string $user, string $password): void
    {
        Database::close($index);
        Database::configure($index, $this->catalogHost, $user, $password, $database, $this->base->port, $this->base->charset);
        Database::connect($index);
        Database::useConnection($index);
    }

    /**
     * @param string $name Server schema or table name
     * @return string Quoted identifier, including embedded backticks
     */
    private static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
