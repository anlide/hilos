<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\Dump;
use Hilos\Backup\FullRestore\MigrationHistory;
use Hilos\Backup\FullRestore\MigrationHistoryState;
use Hilos\Backup\FullRestore\MigrationOperationKind;
use Hilos\Backup\FullRestore\MigrationRow;
use Hilos\Backup\FullRestore\PreparedTarget;
use Hilos\Backup\FullRestoreMigrationEvidence;
use Hilos\Constants\EnvConstants;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use ReflectionProperty;

/**
 * Infrastructure DDL uses an isolated database pair, outside the rollback harness.
 * The fixture creates and removes its own schemas; read-only preparation must create no tables.
 * Source snapshots and connection settings are local to each case, with no runtime readers.
 */
final class MigrationReadOnlyIntegrationTest extends FrameworkIntegrationTestCase
{
    private string $database;
    private string $journal;
    private string $root;
    private DatabaseConnectionConfig $base;
    private array $previousMigration;

    /** Creates only empty schemas, without calling a migrator or application initializer. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->base = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $this->previousMigration = [];
        foreach (['migrationListPath', 'migrationName', 'initialized'] as $property) {
            $this->previousMigration[$property] = new ReflectionProperty(Migration::class, $property)->getValue();
        }
        new ReflectionProperty(Migration::class, 'initialized')->setValue(null, []);
        $this->database = 'hilos-read-track-' . bin2hex(random_bytes(6));
        $this->journal = ChangeLogDatabase::name($this->database);
        $this->root = sys_get_temp_dir() . '/' . $this->database;
        FsPath::ensureDirectory($this->root . '/Schema', 0700);
        Migration::setMigrationListPath($this->root);
        Migration::setMigrationName('Schema');
        $this->connect(DatabaseConnectionDefaults::PRIMARY_INDEX, $this->base->database);
        Database::sql('CREATE DATABASE ' . ChangeLogDatabase::identifier($this->database));
        Database::sql('CREATE DATABASE ' . ChangeLogDatabase::identifier($this->journal));
        $this->connect(DatabaseConnectionDefaults::PRIMARY_INDEX, $this->database);
        $this->connect(ChangeLogDatabase::CONNECTION_INDEX, $this->journal);
    }

    /** Removes owned schemas and sources and restores the migrator's process-local settings. */
    protected function tearDown(): void
    {
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::sql('DROP DATABASE IF EXISTS ' . ChangeLogDatabase::identifier($this->journal));
            Database::sql('DROP DATABASE IF EXISTS ' . ChangeLogDatabase::identifier($this->database));
            foreach (FsPath::entries($this->root . '/Schema') as $file) {
                FsPath::delete($this->root . '/Schema/' . $file);
            }
            FsPath::removeDirectory($this->root . '/Schema');
            FsPath::removeDirectory($this->root);
        } finally {
            foreach ($this->previousMigration as $property => $value) {
                new ReflectionProperty(Migration::class, $property)->setValue(null, $value);
            }
            parent::tearDown();
        }
    }

    /** Neither absence, empty history nor actual rows may cause any bootstrap DDL. */
    public function testReadHistoryDistinguishesAbsentEmptyAndRecordedAndRestoresTheCaller(): void
    {
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        $this->assertNull(Migration::readHistory(DatabaseConnectionDefaults::PRIMARY_INDEX));
        $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        $this->assertSame([], $this->tables($this->database));
        $this->assertSame([], Migration::appliedIndices());
        Database::sql('CREATE TABLE ' . ChangeLogDatabase::identifier($this->database) . '.`migration` (`index` INT PRIMARY KEY, `failed` TINYINT)');
        $this->assertSame([], Migration::readHistory(DatabaseConnectionDefaults::PRIMARY_INDEX)->rows);
        $this->assertNull(Migration::readHistory(DatabaseConnectionDefaults::PRIMARY_INDEX)->level());
        Database::sql('INSERT INTO ' . ChangeLogDatabase::identifier($this->database) . '.`migration` VALUES (0,0),(4,1),(7,0),(9,1)');
        $history = Migration::readHistory(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $this->assertSame([0, 4, 7, 9], $history->recordedIndices());
        $this->assertSame([0, 7], Migration::appliedIndices());
        $this->assertSame([4, 9], $history->failedIndices());
        $this->assertSame(7, $history->level());
        $this->assertSame(['migration'], $this->tables($this->database));
        $this->assertSame([], $this->tables($this->journal));
        $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
    }

    /** A genuine read error or unconfigured index is not mistaken for absence. */
    public function testReadErrorsRestoreTheCallerAndDoNotInitializeMissingColumns(): void
    {
        Database::sql('CREATE TABLE `migration` (`index` INT PRIMARY KEY)');
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        foreach ([DatabaseConnectionDefaults::PRIMARY_INDEX, 99] as $index) {
            try {
                Migration::readHistory($index);
                $this->fail('An unreadable history must refuse');
            } catch (DatabaseException) {
                $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
            }
        }
        $this->assertSame(['migration'], $this->tables($this->database));
        $this->assertSame([], $this->tables($this->journal));
    }

    /** Actual execution consumes the shared DELIMITER/substitution units and never migrates journal separately. */
    public function testMigrateUpExecutesTheSameReadOnlyQueue(): void
    {
        FsPath::write($this->root . '/Schema/001_pair.sql', <<<'SQL'
CREATE TABLE `payload` (`id` INT PRIMARY KEY);
CREATE TABLE {{change_log_database}}.`payload` (`id` INT PRIMARY KEY);
DELIMITER $$
CREATE PROCEDURE `write_pair`()
BEGIN
INSERT INTO `payload` VALUES (5);
INSERT INTO {{change_log_database}}.`payload` VALUES (7);
END$$
DELIMITER ;
CALL `write_pair`();
DROP PROCEDURE `write_pair`;
SQL);
        $track = Migration::readUpTrack();
        $this->assertCount(5, $track->files[0]->statements);
        $this->assertStringContainsString('`' . $this->journal . '`.`payload`', $track->files[0]->statements[1]);
        $this->assertSame([], $this->tables($this->database));
        $this->assertSame([], $this->tables($this->journal));
        $this->assertSame(1, Migration::migrateUp());
        $this->assertSame(5, (int)Database::sql('SELECT id FROM payload')->field('id'));
        $this->assertSame(7, (int)Database::sql('SELECT id FROM ' . ChangeLogDatabase::identifier($this->journal) . '.payload')->field('id'));
        $this->assertSame(['payload'], $this->tables($this->journal));
        $this->assertSame([1], Migration::appliedIndices());
        $track->assertUnchanged();
    }

    /** End-to-end fact preparation uses only primary rows for journal and notices history changes. */
    public function testEvidenceUsesThePrimaryJournalHistoryAndRechecksRows(): void
    {
        FsPath::write($this->root . '/Schema/001_old.sql', 'SELECT 1;');
        FsPath::write($this->root . '/Schema/002_later.sql', 'CREATE TABLE {{change_log_database}}.`later` (`id` INT);');
        Database::sql('CREATE TABLE `migration` (`index` INT PRIMARY KEY, `failed` TINYINT NOT NULL)');
        Database::sql('INSERT INTO migration VALUES (1,0),(2,0)');
        $primary = $this->target(DatabaseConnectionDefaults::PRIMARY_INDEX, 1);
        $journal = $this->target(ChangeLogDatabase::CONNECTION_INDEX, null);
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        $evidence = FullRestoreMigrationEvidence::prepare([$primary, $journal]);
        $facts = $evidence->targets[ChangeLogDatabase::CONNECTION_INDEX];
        $this->assertSame($primary, $facts->binding->source);
        $this->assertSame(1, $facts->facts->requireCleanupLevel());
        $this->assertSame([1, 2], $facts->facts->current->successfulIndices());
        $this->assertCount(1, $facts->pending);
        $this->assertSame(2, $facts->pending[0]->file->index);
        $this->assertSame(MigrationOperationKind::CREATE_TABLE, $facts->pending[0]->operation->kind);
        $this->assertSame($this->journal, $facts->pending[0]->operation->table->database);
        $this->assertSame([], $this->tables($this->journal));
        $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        $evidence->assertUnchanged();
        Database::sql('UPDATE ' . ChangeLogDatabase::identifier($this->database) . '.migration SET failed=1 WHERE `index`=2');
        try {
            $evidence->assertUnchanged();
            $this->fail('A changed successful CREATE row must invalidate the evidence');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertStringContainsString('live migration history changed', $failure->getMessage());
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        }
    }

    /** A legacy FULL keeps its history level unknown and its source effects ordered, without a fabricated zero. */
    public function testAbsentArchiveHistoryRemainsUnknownAndDoesNotRefusePreparation(): void
    {
        FsPath::write($this->root . '/Schema/001_old.sql', 'SELECT 1;');
        $evidence = FullRestoreMigrationEvidence::prepare([$this->target(DatabaseConnectionDefaults::PRIMARY_INDEX, null)]);
        $this->assertNull($evidence->targets[DatabaseConnectionDefaults::PRIMARY_INDEX]->facts->archiveLevel);
        $this->assertCount(1, $evidence->targets[DatabaseConnectionDefaults::PRIMARY_INDEX]->pending);
        $this->assertSame(1, $evidence->targets[DatabaseConnectionDefaults::PRIMARY_INDEX]->pending[0]->file->index);
        $this->assertSame(0, $evidence->targets[DatabaseConnectionDefaults::PRIMARY_INDEX]->pending[0]->statementIndex);
        $this->assertSame(MigrationOperationKind::DATA, $evidence->targets[DatabaseConnectionDefaults::PRIMARY_INDEX]->pending[0]->operation->kind);
        $this->assertSame('SELECT 1', $evidence->targets[DatabaseConnectionDefaults::PRIMARY_INDEX]->pending[0]->operation->sql);
        $this->assertSame([], $this->tables($this->database));
        $evidence->assertUnchanged();
    }

    /**
     * @param int $index Fixture connection index
     * @param string $database Existing schema to select
     */
    private function connect(int $index, string $database): void
    {
        Database::close($index);
        Database::configure($index, $this->base->host, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
            $database, $this->base->port, $this->base->charset);
        Database::connect($index);
    }

    /**
     * @param string $database Schema to observe independently from the migrator
     * @return list<string> All physical table names
     */
    private function tables(string $database): array
    {
        return array_column(Database::sql(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$database],
        )->rows(), 'TABLE_NAME');
    }

    /**
     * @param int $index Configured destination
     * @param ?int $level Explicit archive row and sidecar level, or an absent archive history
     * @return PreparedTarget Bound destination with its own archive history
     */
    private function target(int $index, ?int $level): PreparedTarget
    {
        $config = Database::getConnectionConfig($index);
        $address = Database::sql('SELECT @@hostname, @@port, @@server_id, DATABASE()', index: $index)->row();
        return new PreparedTarget(
            new BackupConnectionMeta($index, 'archive-name', $level), $config, json_encode(array_values($address)),
            'unused-source', 'unused-digest', 'unused-staged', 'unused-digest',
            new Dump([], new MigrationHistory(
                $level === null ? MigrationHistoryState::ABSENT : MigrationHistoryState::RECORDED,
                $level === null ? [] : [new MigrationRow($level, false)],
            )),
        );
    }
}
