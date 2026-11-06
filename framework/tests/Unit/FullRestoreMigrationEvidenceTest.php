<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\Dump;
use Hilos\Backup\FullRestore\MigrationBinding;
use Hilos\Backup\FullRestore\MigrationFacts;
use Hilos\Backup\FullRestore\MigrationHistory;
use Hilos\Backup\FullRestore\MigrationHistoryState;
use Hilos\Backup\FullRestore\MigrationOperationKind;
use Hilos\Backup\FullRestore\MigrationOperationReader;
use Hilos\Backup\FullRestore\MigrationRow;
use Hilos\Backup\FullRestore\PreparedTarget;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\MigrationNumberTakenTwiceException;
use Hilos\Database\Exception\MigrationSkippedBelowLevelException;
use Hilos\Database\Migration;
use Hilos\Database\MigrationHistory as DatabaseMigrationHistory;
use Hilos\Database\MigrationRecord;
use Hilos\Database\MigrationSqlReader;
use Hilos\Database\MigrationUpTrack;
use Hilos\Fs\FsPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Real source files and pure history/DDL facts; no database or trial DDL. */
final class FullRestoreMigrationEvidenceTest extends TestCase
{
    private string $root;
    private array $oldConfiguration;
    private int $oldIndex;
    private ?string $oldPath;
    private string $oldName;

    /** Saves the process-local registry and uses only this case's source directory. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->oldConfiguration = new ReflectionProperty(Database::class, 'configurations')->getValue();
        $this->oldIndex = Database::getCurrentIndex();
        $this->oldPath = new ReflectionProperty(Migration::class, 'migrationListPath')->getValue();
        $this->oldName = new ReflectionProperty(Migration::class, 'migrationName')->getValue();
        $this->root = sys_get_temp_dir() . '/hilos-full-track-' . bin2hex(random_bytes(8));
        FsPath::ensureDirectory($this->root . '/Schema', 0700);
        Migration::setMigrationListPath($this->root);
        Migration::setMigrationName('Schema');
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX, 'unused', 'unused', '', 'restore-target');
    }

    /** Removes temporary sources and restores the registry even after a refusal. */
    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->root . '/Schema'), ['.', '..']) as $file) {
            FsPath::delete($this->root . '/Schema/' . $file);
        }
        rmdir($this->root . '/Schema');
        rmdir($this->root);
        new ReflectionProperty(Database::class, 'configurations')->setValue(null, $this->oldConfiguration);
        new ReflectionProperty(Database::class, 'currentIndex')->setValue(null, $this->oldIndex);
        new ReflectionProperty(Migration::class, 'migrationListPath')->setValue(null, $this->oldPath);
        Migration::setMigrationName($this->oldName);
        parent::tearDown();
    }

    /** The exact archive rows win over a sidecar, and null sidecars do not hide successful evidence. */
    public function testArchiveLevelIsActualSuccessfulHistory(): void
    {
        $track = $this->track(['001_a.sql', '002_b.sql', '003_c.sql']);
        $history = new MigrationHistory(MigrationHistoryState::RECORDED, [new MigrationRow(1, false), new MigrationRow(2, false)]);
        $current = new DatabaseMigrationHistory([new MigrationRecord(1, false), new MigrationRecord(2, false), new MigrationRecord(3, false)]);

        $facts = MigrationFacts::read($history, $current, $track, null);
        $this->assertSame(2, $facts->archiveLevel);
        $this->assertSame(2, $facts->requireCleanupLevel());
        $this->assertSame([1, 2, 3], $facts->current->successfulIndices());

        $mismatch = MigrationFacts::read($history, $current, $track, 1);
        $this->assertSame(2, $mismatch->archiveLevel);
        $this->expectException(RestoreFailedException::class);
        $mismatch->requireCleanupLevel();
    }

    /** Absence and emptiness do not mint level zero, while an explicit successful zero stays zero. */
    public function testUnknownAndEmptyHistoriesRemainDistinctWithoutRejectingLegacyFacts(): void
    {
        foreach ([MigrationHistoryState::ABSENT, MigrationHistoryState::EMPTY] as $state) {
            $facts = MigrationFacts::read(new MigrationHistory($state, []), null, $this->track([]), 0);
            $this->assertSame($state, $facts->archive->state);
            $this->assertNull($facts->archiveLevel);
            try {
                $facts->requireCleanupLevel();
                $this->fail('Unknown history cannot authorize cleanup');
            } catch (RestoreFailedException $failure) {
                $this->assertFalse($failure->databaseTouched());
            }
        }
        $facts = MigrationFacts::read(
            new MigrationHistory(MigrationHistoryState::RECORDED, [new MigrationRow(0, false)]),
            new DatabaseMigrationHistory([new MigrationRecord(0, false)]), $this->track([]), null,
        );
        $this->assertSame(0, $facts->requireCleanupLevel());
    }

    /** A higher successful row does not hide any failed row in either history. */
    public function testAllFailedRowsRemainVisibleAndRefuseCleanup(): void
    {
        $facts = MigrationFacts::read(
            new MigrationHistory(MigrationHistoryState::RECORDED, [new MigrationRow(1, true), new MigrationRow(3, false)]),
            new DatabaseMigrationHistory([new MigrationRecord(3, false), new MigrationRecord(4, true)]),
            $this->track(['001_a.sql', '003_c.sql', '004_d.sql']), null,
        );
        $this->assertSame(3, $facts->archiveLevel);
        $this->assertSame([1], $facts->archiveFailed);
        $this->assertSame([4], $facts->current->failedIndices());
        $this->expectException(RestoreFailedException::class);
        $facts->requireCleanupLevel();
    }

    /** The primary sidecar must agree too when the destination is the journal. */
    public function testJournalCannotIgnoreAContradictoryPrimarySidecar(): void
    {
        $facts = MigrationFacts::read(
            new MigrationHistory(MigrationHistoryState::RECORDED, [new MigrationRow(3, false)]),
            new DatabaseMigrationHistory([new MigrationRecord(3, false)]), $this->track([]), null, 2,
        );
        $this->expectException(RestoreFailedException::class);
        $facts->requireCleanupLevel();
    }

    /** Archive and live holes use the same strict lower bound as normal startup. */
    public function testTrackCheckRetainsItsLowestRowSemantics(): void
    {
        $track = $this->track(['001_a.sql', '002_b.sql', '003_c.sql', '004_d.sql']);
        $facts = MigrationFacts::read(
            new MigrationHistory(MigrationHistoryState::RECORDED, [new MigrationRow(3, false)]),
            new DatabaseMigrationHistory([new MigrationRecord(3, false), new MigrationRecord(4, false)]), $track, null,
        );
        $this->assertSame(3, $facts->requireCleanupLevel());
        $this->expectException(MigrationSkippedBelowLevelException::class);
        MigrationFacts::read(
            new MigrationHistory(MigrationHistoryState::RECORDED, [new MigrationRow(1, false), new MigrationRow(3, false)]),
            null, $track, null,
        );
    }

    /** The live rows are checked independently from an intact archive history. */
    public function testLiveHoleIsRefused(): void
    {
        $this->expectException(MigrationSkippedBelowLevelException::class);
        MigrationFacts::read(
            new MigrationHistory(MigrationHistoryState::RECORDED, [new MigrationRow(1, false)]),
            new DatabaseMigrationHistory([new MigrationRecord(1, false), new MigrationRecord(3, false)]),
            $this->track(['001_a.sql', '002_b.sql', '003_c.sql']), null,
        );
    }

    /** Duplicated row numbers are not an ambiguous successful level. */
    public function testContradictoryArchiveRowsAreRefused(): void
    {
        $this->expectException(RestoreFailedException::class);
        MigrationFacts::read(
            new MigrationHistory(MigrationHistoryState::RECORDED, [new MigrationRow(3, false), new MigrationRow(3, true)]),
            null, $this->track([]), null,
        );
    }

    /** UP selection and file digests include every numeric file, with original SQL-reader semantics. */
    public function testSharedReaderRetainsDelimiterBoundariesAndJournalSubstitution(): void
    {
        $sql = <<<'SQL'
-- CREATE TABLE fake (id INT);
SELECT 'first; second'; SELECT 2;
DELIMITER $$
CREATE PROCEDURE p()
BEGIN
SELECT 1;
END$$
DELIMITER ;
CREATE TABLE {{change_log_database}}.`later` (`id` INT);
SELECT 'unterminated by delimiter'
SQL;
        FsPath::write($this->root . '/Schema/010_last.sql', $sql);
        FsPath::write($this->root . '/Schema/002_earlier.sql', "SELECT 2;\n");
        $track = Migration::readUpTrack();
        $this->assertSame([2, 10], array_map(static fn ($file): int => $file->index, $track->files));
        $this->assertSame([10], array_map(static fn ($file): int => $file->index, $track->after(2)));
        $this->assertSame([
            "SELECT 'first; second'; SELECT 2",
            "CREATE PROCEDURE p()\nBEGIN\nSELECT 1;\nEND",
            'CREATE TABLE `restore-target-change-log`.`later` (`id` INT)',
            "SELECT 'unterminated by delimiter'",
        ], $track->files[1]->statements);
        $this->assertSame(MigrationSqlReader::statements($sql), $track->files[1]->statements);
        $this->assertSame(hash('sha256', $sql), $track->files[1]->digest);
        $track->assertUnchanged();
        FsPath::write($this->root . '/Schema/010_last.sql', $sql . "\nSELECT 3;\n");
        $this->expectException(DatabaseException::class);
        $track->assertUnchanged();
    }

    /** Directory membership is evidence too, even when the added file would precede the archive level. */
    public function testSnapshotRefusesANewLowerFile(): void
    {
        FsPath::write($this->root . '/Schema/010_last.sql', 'SELECT 1;');
        $track = Migration::readUpTrack();
        FsPath::write($this->root . '/Schema/002_added.sql', 'SELECT 2;');
        $this->expectException(DatabaseException::class);
        $track->assertUnchanged();
    }

    /** Both directional collisions are refused before a source can establish provenance. */
    public function testReadOnlySourceQueueRefusesDuplicateUpNumbers(): void
    {
        FsPath::write($this->root . '/Schema/001_a.sql', 'SELECT 1;');
        FsPath::write($this->root . '/Schema/001_b.sql', 'SELECT 2;');
        $this->expectException(MigrationNumberTakenTwiceException::class);
        Migration::readUpTrack();
    }

    /** IF NOT EXISTS still describes a table that the post-import queue must recreate. */
    public function testDirectCreateCarriesPhysicalIdentityDefinitionsAndReferences(): void
    {
        $operations = MigrationOperationReader::read(
            '/* fake CREATE TABLE nope (id INT); */ CREATE TABLE IF NOT EXISTS `journal`.`real``table` '
            . "(`id` INT PRIMARY KEY, `parent` INT, `text` VARCHAR(50) DEFAULT 'CREATE TABLE fake (id INT)',"
            . ' CONSTRAINT `fk` FOREIGN KEY (`parent`) REFERENCES `data`.`parent` (`id`)) ENGINE=InnoDB', 'primary',
        );
        $operation = $operations[0];
        $this->assertCount(1, $operations);
        $this->assertSame(MigrationOperationKind::CREATE_TABLE, $operation->kind);
        $this->assertTrue($operation->isProvenanceSource());
        $this->assertTrue($operation->ifNotExists);
        $this->assertSame('journal', $operation->table->database);
        $this->assertSame('real`table', $operation->table->table);
        $this->assertSame(['id', 'parent', 'text'], $operation->definition->columns);
        $this->assertSame('data', $operation->references[0]->database);
        $this->assertSame('parent', $operation->references[0]->table);
    }

    /** Operations are ordered across execution units and inside a multi-table DROP or RENAME. */
    public function testAlterDropAndRenameKeepOrderAndPhysicalNames(): void
    {
        $alter = MigrationOperationReader::read(
            'ALTER TABLE `later` ADD COLUMN `parent` INT AFTER `id`, '
            . 'ADD CONSTRAINT `fk` FOREIGN KEY (`parent`) REFERENCES `elsewhere`.`parent` (`id`), '
            . 'MODIFY COLUMN `parent` BIGINT, CHANGE COLUMN `parent` `owner` BIGINT, '
            . 'DROP FOREIGN KEY `fk`, RENAME COLUMN `owner` TO `parent`', 'primary',
        )[0];
        $this->assertSame(MigrationOperationKind::ALTER_TABLE, $alter->kind);
        $this->assertCount(6, $alter->clauses);
        $this->assertSame('elsewhere', $alter->references[0]->database);
        $rename = MigrationOperationReader::read('RENAME TABLE later TO next, next TO `other`.`final`', 'primary');
        $this->assertSame(['later', 'next'], array_map(static fn ($operation): string => $operation->table->table, $rename));
        $this->assertSame('other', $rename[1]->renamedTo->database);
        $this->assertSame('final', $rename[1]->renamedTo->table);
        $this->assertSame(MigrationOperationKind::RENAME_TABLE, $rename[0]->kind);
        $this->assertSame('renamed', MigrationOperationReader::read('ALTER TABLE later RENAME TO renamed', 'primary')[0]->renamedTo->table);
        $drop = MigrationOperationReader::read('DROP TABLE IF EXISTS `other`.`final`, `archive_table`', 'primary');
        $this->assertSame(['final', 'archive_table'], array_map(static fn ($operation): string => $operation->table->table, $drop));
        $this->assertSame(MigrationOperationKind::DROP_TABLE, $drop[0]->kind);
    }

    /**
     * Legacy physical names are opaque in every quoted identifier position.
     *
     * @param string $sql One migration execution unit
     */
    #[DataProvider('legacyPhysicalIdentifiers')]
    public function testMigrationOperationReaderRejectsLegacyPhysicalIdentifiers(string $sql): void
    {
        $operations = MigrationOperationReader::read($sql, 'primary');

        $this->assertCount(1, $operations);
        $this->assertSame(MigrationOperationKind::UNKNOWN, $operations[0]->kind);
    }

    /** @return iterable<string, array{string}> Quoted legacy aliases from DDL, DML and references */
    public static function legacyPhysicalIdentifiers(): iterable
    {
        yield 'conditional table drop' => ['DROP TABLE IF EXISTS `#mysql50#independent`'];
        yield 'qualified schema' => ['CREATE TABLE child(id INT, FOREIGN KEY(id) REFERENCES `#mysql50#archive`.parent(id))'];
        yield 'qualified table' => ['CREATE TABLE child(id INT, FOREIGN KEY(id) REFERENCES archive.`#mysql50#parent`(id))'];
        yield 'table declaration' => ['CREATE TABLE `#mysql50#ordinary`(id INT)'];
        yield 'column declaration' => ['CREATE TABLE ordinary (`#mysql50#column` INT)'];
        yield 'DML target' => ['UPDATE `#mysql50#independent` SET id=1'];
        yield 'table rename' => ['RENAME TABLE independent TO `#mysql50#renamed`'];
        yield 'column rename' => ['ALTER TABLE ordinary RENAME COLUMN id TO `#mysql50#renamed`'];
        yield 'trigger identifier' => ['DROP TRIGGER IF EXISTS `#mysql50#trigger`'];
    }

    /** Prefix text in a SQL string or comment is not an identifier. */
    public function testMigrationOperationReaderKeepsLegacyPrefixInStringsAndComments(): void
    {
        foreach (["SELECT '#mysql50#independent'", 'SELECT 1 /* #mysql50#independent */'] as $sql) {
            $operation = MigrationOperationReader::read($sql, 'primary')[0];

            $this->assertSame(MigrationOperationKind::DATA, $operation->kind);
            $this->assertSame([], $operation->references);
        }
    }

    /** Opaque SQL cannot authorize a cleanup, even when it contains convincing CREATE text. */
    #[DataProvider('opaqueSql')]
    public function testOpaqueDdlNeverEstablishesACreate(string $sql): void
    {
        $operations = MigrationOperationReader::read($sql, 'primary');
        $this->assertCount(1, $operations);
        $this->assertSame(MigrationOperationKind::UNKNOWN, $operations[0]->kind);
        $this->assertFalse($operations[0]->isProvenanceSource());
        $this->assertNull($operations[0]->definition);
    }

    /** @return iterable<string, array{string}> Unsupported execution forms */
    public static function opaqueSql(): iterable
    {
        foreach ([
            'CREATE TABLE later LIKE original',
            'CREATE TABLE later AS SELECT * FROM original',
            'CREATE TABLE later (id INT) AS SELECT 1',
            "PREPARE stmt FROM 'CREATE TABLE later (id INT)'",
            'EXECUTE stmt',
            'CALL build_tables()',
            'CREATE PROCEDURE p() BEGIN CREATE TABLE later (id INT); END',
            'CREATE TABLE later (id INT); DROP TABLE later',
            '/*!80000 CREATE TABLE later (id INT) */',
            'ALTER TABLE later ADD PARTITION (PARTITION p VALUES LESS THAN (5))',
            'CREATE TABLE later (id INT) unsupported_option=1',
            "SELECT 'incomplete quote",
            "SET SQL_MODE='ANSI_QUOTES'",
            'SET FOREIGN_KEY_CHECKS=0',
            'USE another_schema',
            "SELECT 'escaped \\' quote; CREATE TABLE hidden (id INT) -- '\n",
        ] as $sql) {
            yield $sql => [$sql];
        }
    }

    /** Data and ordinary comments do not become object declarations. */
    public function testDataAndCommentsAreKnownNonDdl(): void
    {
        foreach ([
            ["SELECT 'CREATE TABLE fake (id INT);'", MigrationOperationKind::DATA],
            ["INSERT INTO notes VALUES ('/* CREATE TABLE fake (id INT) */')", MigrationOperationKind::DATA],
            ['/* CREATE TABLE fake (id INT) */ -- DROP TABLE real', MigrationOperationKind::OTHER],
        ] as [$sql, $kind]) {
            $operation = MigrationOperationReader::read($sql, 'primary')[0];
            $this->assertSame($kind, $operation->kind);
            $this->assertFalse($operation->isProvenanceSource());
        }
    }

    /** Bootstrap names belong to initialization, whatever CREATE a file happens to contain. */
    public function testBootstrapTablesNeverBecomeProvenanceCandidates(): void
    {
        foreach (['migration', 'hilos_migration_claim', 'hilos_admin_view_mode_latch', 'hilos_database_marker'] as $table) {
            $operation = MigrationOperationReader::read('CREATE TABLE `' . $table . '` (id INT)', 'primary')[0];
            $this->assertFalse($operation->isProvenanceSource());
        }
    }

    /** Journal identity requires its derived name and same physical server, not index 1 alone. */
    public function testJournalBindsToThePrimarySourceAndExecutionSchema(): void
    {
        $primary = $this->target(DatabaseConnectionDefaults::PRIMARY_INDEX, 'restore-target');
        $journal = $this->target(ChangeLogDatabase::CONNECTION_INDEX, 'restore-target-change-log');
        $this->assertSame($primary, MigrationBinding::resolve($journal, [$journal, $primary])->source);
        $ordinary = $this->target(ChangeLogDatabase::CONNECTION_INDEX, 'ordinary-secondary');
        $this->assertSame($ordinary, MigrationBinding::resolve($ordinary, [$ordinary, $primary])->source);
    }

    /** Correct schema names on different servers do not describe the primary track's journal. */
    public function testJournalOnAnotherPhysicalServerIsRefused(): void
    {
        $primary = $this->target(DatabaseConnectionDefaults::PRIMARY_INDEX, 'restore-target');
        $journal = $this->target(ChangeLogDatabase::CONNECTION_INDEX, 'restore-target-change-log', 'other-server');
        $this->expectException(RestoreFailedException::class);
        MigrationBinding::resolve($journal, [$primary, $journal]);
    }

    /**
     * @param list<string> $names Track file names for pure history checks
     * @return MigrationUpTrack Pure source facts without reading files
     */
    private function track(array $names): MigrationUpTrack
    {
        return new MigrationUpTrack($this->root . '/Schema', 'Schema', $names, []);
    }

    /**
     * @param int $index Configured index
     * @param string $database Physical database
     * @param string $server Server-reported hostname
     * @return PreparedTarget Input binding without opening a database
     */
    private function target(int $index, string $database, string $server = 'server'): PreparedTarget
    {
        Database::configure($index, 'unused', 'unused', '', $database);
        return new PreparedTarget(
            new BackupConnectionMeta($index, 'archive-name', null), Database::getConnectionConfig($index),
            json_encode([$server, DatabaseConnectionDefaults::PORT, 1, $database]),
            'unused-source', 'unused-digest', 'unused-staged', 'unused-digest',
            new Dump([], new MigrationHistory(MigrationHistoryState::ABSENT, [])),
        );
    }
}
