<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\Dump;
use Hilos\Backup\FullRestore\DumpObjectType;
use Hilos\Backup\FullRestore\DumpServerVersion;
use Hilos\Backup\FullRestore\DumpSqlLexer;
use Hilos\Backup\FullRestore\MigrationHistoryState;
use Hilos\Backup\FullRestoreDumpReader;
use Hilos\Fs\FsPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Dump admission and primary staging only; live planning and FK sorting have separate coverage. */
final class FullRestorePlanTest extends TestCase
{
    /** @var list<string> Temporary source/staging files to remove after each case */
    private array $paths = [];

    /** Removes only this case's temporary files. */
    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            FsPath::delete($path);
        }
        parent::tearDown();
    }

    /** Quoted data, identifiers and ordinary comments cannot introduce another object. */
    public function testObjectDeclarationsRespectQuotesCommentsAndStatementBoundaries(): void
    {
        $sql = <<<'SQL'
-- CREATE TABLE `comment_fake` (`id` int);
# DROP TABLE `hash_fake`;
/* CREATE TABLE `block_fake` (`id` int); */
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40101 CREATE */ TABLE `quoted``table` (`id` int, `text` longtext COMMENT 'REFERENCES `fake` (`id`)');
INSERT INTO `quoted``table` VALUES
(1, 'first; CREATE TABLE `data_fake` (`id` int); \'still quoted\''),
(2, 'doubled ''quote'';\nDROP TABLE `another_fake`;'),
(3, "double \"quote\"; CREATE VIEW `view_fake` AS SELECT 1"),
(4, 'a multiline
CREATE TABLE `line_fake` (`id` int);
value');
SQL;
        $dump = $this->read($this->source($sql));

        $this->assertSame(['quoted`table'], array_keys($dump->objects));
        $this->assertSame(DumpObjectType::TABLE, $dump->objects['quoted`table']->type);
        $this->assertSame(['id', 'text'], $dump->objects['quoted`table']->columns);
        $this->assertSame([], $dump->objects['quoted`table']->foreignKeys);
        $this->assertSame(MigrationHistoryState::ABSENT, $dump->migrationHistory->state);
        $this->assertSame([], $dump->migrationHistory->rows);
    }

    /** MariaDB's real mysqldump saves, suppresses and restores diagnostic verbosity around the dump. */
    public function testMariaDbNoteVerbosityWrapperKeepsTheDumpRestorable(): void
    {
        $sql = "/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;\n"
            . "CREATE TABLE `probe` (`id` int);\nINSERT INTO `probe` VALUES (1);\n"
            . "/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;\n";
        $source = $this->source($sql);
        $dump = $this->stagePrimary($source, $source . '.staging');

        $this->assertSame(['probe'], array_keys($dump->objects));
        $this->assertSame(self::completed($sql), FsPath::read($source . '.staging'));
    }

    /** Escape and comment delimiters may straddle a read buffer without exposing quoted SQL as a declaration. */
    public function testQuoteEscapeAndCommentBoundariesAcrossInputChunks(): void
    {
        $opening = "SET SQL_MODE='';\nCREATE TABLE `notes` (`text` longtext);\nINSERT INTO `notes` VALUES ('";
        $sql = $opening . str_repeat('x', DumpSqlLexer::CHUNK_BYTES - strlen($opening) - 1)
            . "\\'; CREATE TABLE `fake` (`id` int);');";
        $sql .= str_repeat(' ', DumpSqlLexer::CHUNK_BYTES - strlen($sql) % DumpSqlLexer::CHUNK_BYTES - 1)
            . "/* CREATE TABLE `comment_fake` (`id` int); */\n";
        $source = $this->source($sql);

        $dump = $this->stagePrimary($source, $source . '.staging');

        $this->assertSame(['notes'], array_keys($dump->objects));
        $this->assertSame(self::completed($sql), FsPath::read($source . '.staging'));
    }

    /** Literal data supports dump spelling for exponents, binary strings, bit fields and nulls. */
    public function testDumpLiteralFormsKeepTheirOriginalBytes(): void
    {
        $sql = <<<'SQL'
SET SQL_MODE='';
CREATE TABLE `values_probe` (`number` double, `data` blob, `flag` bit(4));
INSERT INTO `values_probe` VALUES (-1.25e-10,_binary 'a\0b',b'1010'),(1e+20,X'00ff',NULL),(0,0x12ff,b'0000');
SQL;
        $source = $this->source($sql);

        $this->stagePrimary($source, $source . '.staging');

        $this->assertSame(self::completed($sql), FsPath::read($source . '.staging'));
    }

    /** A table without INSERTs is readable empty history, with no invented level. */
    public function testEmptyMigrationHistoryIsDistinctFromAnAbsentTable(): void
    {
        $dump = $this->read($this->source(self::migrationDdl()));

        $this->assertSame(MigrationHistoryState::EMPTY, $dump->migrationHistory->state);
        $this->assertSame([], $dump->migrationHistory->rows);
        $this->assertArrayHasKey('migration', $dump->objects);
    }

    /** No successful row is fabricated when the table stores only failed migrations. */
    public function testFailedOnlyHistoryAndReversedStorageOrderRemainExplicit(): void
    {
        $dump = $this->read($this->source('CREATE TABLE `migration` (`failed` tinyint, `index` int);'
            . 'INSERT INTO `migration` VALUES (1,12);'));

        $this->assertSame(MigrationHistoryState::RECORDED, $dump->migrationHistory->state);
        $this->assertCount(1, $dump->migrationHistory->rows);
        $this->assertSame(12, $dump->migrationHistory->rows[0]->index);
        $this->assertTrue($dump->migrationHistory->rows[0]->failed);
    }

    /** Every successful and failed row survives, including an explicitly recorded zero. */
    public function testMigrationRowsRespectStorageAndExplicitColumnOrder(): void
    {
        $dump = $this->read($this->source(self::migrationDdl()
            . "INSERT INTO `migration` VALUES (0,0),(109,0);\n"
            . "INSERT INTO `migration` (`failed`,`index`) VALUES ('1','110');\n"));

        $this->assertSame(MigrationHistoryState::RECORDED, $dump->migrationHistory->state);
        $this->assertSame([0, 109, 110], array_map(static fn ($row): int => $row->index, $dump->migrationHistory->rows));
        $this->assertSame([false, false, true], array_map(static fn ($row): bool => $row->failed, $dump->migrationHistory->rows));
    }

    /** Composite and external references remain explicit for the subsequent planner. */
    public function testDdlAndForeignKeysAreReturnedWithoutResolvingOrSortingThem(): void
    {
        $ddl = <<<'SQL'
CREATE TABLE `child` (
 `id` int NOT NULL,
 `parent_id` int DEFAULT NULL,
 `parent_kind` int DEFAULT NULL,
 PRIMARY KEY (`id`),
 CONSTRAINT `fk``parent` FOREIGN KEY (`parent_id`,`parent_kind`)
   REFERENCES `external_db`.`parent` (`id`,`kind`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $dump = $this->read($this->source($ddl));

        $this->assertSame($ddl, $dump->objects['child']->createSql);
        $this->assertCount(1, $dump->objects['child']->foreignKeys);
        $fk = $dump->objects['child']->foreignKeys[0];
        $this->assertSame('fk`parent', $fk->name);
        $this->assertSame(['parent_id', 'parent_kind'], $fk->columns);
        $this->assertSame('external_db', $fk->referencedDatabase);
        $this->assertSame('parent', $fk->referencedTable);
        $this->assertSame(['id', 'kind'], $fk->referencedColumns);
    }

    /** The current framework's generated reference column remains readable without treating its expression as DDL. */
    public function testFrameworkGeneratedColumnsAreAdmitted(): void
    {
        $dump = $this->read($this->source(FsPath::read(
            dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_country_name.sql',
        )));

        $this->assertSame(['hilos_country_name'], array_keys($dump->objects));
        $this->assertContains('locale_slot', $dump->objects['hilos_country_name']->columns);
        $this->assertCount(3, $dump->objects['hilos_country_name']->foreignKeys);
    }

    /** Journal dumps carry range partitions in executable comments and no migration journal of their own. */
    public function testJournalPartitionDefinitionsAreAdmitted(): void
    {
        $sql = <<<'SQL'
/*M!999999\- enable the sandbox mode */
CREATE TABLE `journal_probe` (
 `id` bigint unsigned NOT NULL,
 `created_at` datetime(6) NOT NULL,
 `data` longtext CHECK (json_valid(`data`)),
 PRIMARY KEY (`id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
/*!50500 PARTITION BY RANGE COLUMNS (`created_at`)
(PARTITION `p_202601` VALUES LESS THAN ('2026-02-01') ENGINE=InnoDB,
 PARTITION `p_future` VALUES LESS THAN (MAXVALUE) ENGINE=InnoDB) */;
INSERT INTO `journal_probe` VALUES (1,'2026-01-01 00:00:00.000001','{"value":"marker"}');
SQL;
        $dump = $this->read($this->source($sql));

        $this->assertSame(['journal_probe'], array_keys($dump->objects));
        $this->assertSame(MigrationHistoryState::ABSENT, $dump->migrationHistory->state);
        $this->assertStringContainsString('PARTITION BY RANGE COLUMNS', $dump->objects['journal_probe']->createSql);
    }

    /** Both common mysqldump view-placeholder forms resolve to the final view declaration. */
    #[DataProvider('viewPlaceholders')]
    public function testVersionCommentViewDeclarationsHaveTheirOwnObjectType(string $placeholder): void
    {
        $sql = $placeholder . <<<'SQL'
/*!50001 DROP VIEW IF EXISTS `summary` */;
/*!50001 SET @saved_cs_client = @@character_set_client */;
/*!50001 SET @saved_cs_results = @@character_set_results */;
/*!50001 SET @saved_col_connection = @@collation_connection */;
/*!50001 SET character_set_client = utf8mb4 */;
/*!50001 SET character_set_results = utf8mb4 */;
/*!50001 SET collation_connection = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`owner`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `summary` AS SELECT 1 AS `id`, 'CREATE TABLE `fake` (`id` int)' AS `label` */;
/*!50001 SET character_set_client = @saved_cs_client */;
/*!50001 SET character_set_results = @saved_cs_results */;
/*!50001 SET collation_connection = @saved_col_connection */;
SQL;
        $dump = $this->read($this->source($sql));

        $this->assertSame(['summary'], array_keys($dump->objects));
        $this->assertSame(DumpObjectType::VIEW, $dump->objects['summary']->type);
        $this->assertStringContainsString('DEFINER=`owner`@`localhost`', $dump->objects['summary']->createSql);
        $this->assertSame([], $dump->objects['summary']->foreignKeys);
    }

    /**
     * @return iterable<string, array{string}> Temporary view and table-shaped placeholder forms
     */
    public static function viewPlaceholders(): iterable
    {
        yield 'temporary view' => ["DROP TABLE IF EXISTS `summary`;\n"
            . "/*!50001 DROP VIEW IF EXISTS `summary` */;\n/*!50001 CREATE VIEW `summary` AS SELECT 1 AS `id` */;\n"];
        yield 'temporary table' => ["DROP TABLE IF EXISTS `summary`;\nCREATE TABLE `summary` (`id` int);\n"
            . "DROP TABLE IF EXISTS `summary`;\n"];
    }

    /** Marker DDL/data, charset wrappers and data locks disappear while adjacent service tables survive byte for byte. */
    public function testStagingExcludesOnlyTheCompleteStandardMarkerBlock(): void
    {
        $before = "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"
            . self::migrationDdl() . 'INSERT INTO `migration` VALUES (109,0);';
        $after = "\nCREATE TABLE `hilos_migration_claim` (`id` int);\n"
            . "INSERT INTO `hilos_migration_claim` VALUES (1);\n"
            . "CREATE TABLE `hilos_admin_view_mode_latch` (`id` int);\n"
            . "INSERT INTO `hilos_admin_view_mode_latch` VALUES (1);\n"
            . "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n";
        $sql = $before . "\n" . self::markerBlock() . $after;
        $source = $this->source($sql);

        $dump = $this->stagePrimary($source, $source . '.staging');

        $this->assertSame(self::completed($before . $after), FsPath::read($source . '.staging'));
        $this->assertSame(self::completed($sql), FsPath::read($source));
        $this->assertSame(['migration', 'hilos_database_marker', 'hilos_migration_claim', 'hilos_admin_view_mode_latch'],
            array_keys($dump->objects));
        $this->assertSame(MigrationHistoryState::RECORDED, $dump->migrationHistory->state);
    }

    /** MariaDB's per-table transaction belongs to its data owner, including marker-only exclusion. */
    public function testStagingExcludesTheMarkersAutocommitWrapperAndKeepsOtherTransactions(): void
    {
        $before = self::migrationDdl() . "\nSET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;\n"
            . "INSERT INTO `migration` VALUES (109,0);\nCOMMIT;\nSET AUTOCOMMIT=@OLD_AUTOCOMMIT;";
        $marker = str_replace('LOCK TABLES `hilos_database_marker` WRITE;',
            "SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;\nLOCK TABLES `hilos_database_marker` WRITE;", self::markerBlock())
            . "\nCOMMIT;\nSET AUTOCOMMIT=@OLD_AUTOCOMMIT;";
        $source = $this->source($before . "\n" . $marker);

        $this->stagePrimary($source, $source . '.staging');

        $this->assertSame(self::completed($before), FsPath::read($source . '.staging'));
    }

    /** Old dumps carrying no database marker preserve every source byte in staging. */
    public function testStagingWithoutMarkerIsAnExactCopy(): void
    {
        $sql = self::migrationDdl() . "\n-- hilos_database_marker is only a comment\n"
            . "INSERT INTO `migration` VALUES (42,0);\nCREATE TABLE `notes` (`text` longtext);\n"
            . "INSERT INTO `notes` VALUES ('DROP TABLE `hilos_database_marker`; INSERT INTO `migration` VALUES (999,1);');\n"
            . "/* trailing comment */\n";
        $source = $this->source($sql);

        $this->stagePrimary($source, $source . '.staging');

        $this->assertSame(self::completed($sql), FsPath::read($source . '.staging'));
        $this->assertSame(self::completed($sql), FsPath::read($source));
    }

    /** Rejection at the very end cannot publish a partially filtered staging stream. */
    #[DataProvider('refusedFragments')]
    public function testUnsupportedOrContradictoryInputRefusesBeforeStaging(string $fragment): void
    {
        $sql = self::migrationDdl() . self::markerBlock() . "\n" . $fragment;
        $source = $this->source($sql);
        try {
            $this->stagePrimary($source, $source . '.staging');
            $this->fail('The unrecognized fragment must refuse the whole file');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertFileDoesNotExist($source . '.staging');
            $this->assertFileDoesNotExist($source . '.staging.preparing');
            $this->assertSame(self::completed($sql), FsPath::read($source));
        }
    }

    /**
     * @return iterable<string, array{string}> Syntax whose effect or migration history cannot be admitted
     */
    public static function refusedFragments(): iterable
    {
        yield 'duplicate history' => ['INSERT INTO `migration` VALUES (7,0),(7,1);'];
        yield 'duplicate successful history' => ['INSERT INTO `migration` VALUES (7,0),(7,0);'];
        yield 'unknown failed flag' => ['INSERT INTO `migration` VALUES (7,2);'];
        yield 'missing history field' => ['INSERT INTO `migration` (`index`) VALUES (7);'];
        yield 'null history field' => ['INSERT INTO `migration` VALUES (NULL,0);'];
        yield 'history expression' => ['INSERT INTO `migration` VALUES (1+1,0);'];
        yield 'overflow index' => ['INSERT INTO `migration` VALUES (999999999999999999999999,0);'];
        yield 'unreadable history' => ["INSERT INTO `migration` VALUES ('unknown',0);"];
        yield 'insert select' => ['INSERT INTO `migration` SELECT 7,0;'];
        yield 'history mutation' => ['UPDATE `migration` SET `failed`=0;'];
        yield 'marker update' => ["UPDATE `hilos_database_marker` SET `marker`='other';"];
        yield 'version marker update' => ["/*!50001 UPDATE `hilos_database_marker` SET `marker`='other' */;"];
        yield 'mariadb marker update' => ["/*M!100100 UPDATE `hilos_database_marker` SET `marker`='other' */;"];
        yield 'marker truncate' => ['TRUNCATE TABLE `hilos_database_marker`;'];
        yield 'marker delete' => ['DELETE FROM `hilos_database_marker`;'];
        yield 'marker replace' => ["REPLACE INTO `hilos_database_marker` VALUES (1,'x','n','2026-01-01 00:00:00');"];
        yield 'marker alter' => ['ALTER TABLE `hilos_database_marker` RENAME TO `other`;'];
        yield 'marker rename' => ['RENAME TABLE `other` TO `hilos_database_marker`;'];
        yield 'qualified marker drop' => ['DROP TABLE IF EXISTS `other`.`hilos_database_marker`;'];
        yield 'multiple target drop' => ['DROP TABLE IF EXISTS `other`, `hilos_database_marker`;'];
        yield 'dynamic SQL' => ["PREPARE s FROM 'DROP TABLE hilos_database_marker';"];
        yield 'stored procedure' => ['CALL change_marker();'];
        yield 'database drop' => ['DROP DATABASE `db`;'];
        yield 'unknown DDL' => ['CREATE TABLE `other` LIKE `migration`;'];
        yield 'create select' => ['CREATE TABLE `other` AS SELECT * FROM `migration`;'];
        yield 'unknown table option' => ['CREATE TABLE `other` (`id` int) MYSTERY=1;'];
        yield 'unknown column attribute' => ['CREATE TABLE `other` (`id` int MYSTERY);'];
        yield 'incomplete primary key' => ['CREATE TABLE `other` (`id` int, PRIMARY KEY);'];
        yield 'incomplete default' => ['CREATE TABLE `other` (`id` int DEFAULT);'];
        yield 'quoted type' => ["CREATE TABLE `other` (`id` 'int');"];
        yield 'unknown generated function' => ['CREATE TABLE `other` (`id` int AS (change_marker()));'];
        yield 'quoted generated function' => ['CREATE TABLE `other` (`id` int AS (`change_marker`()));'];
        yield 'qualified default function' => ['CREATE TABLE `other` (`id` int DEFAULT (other_db.COALESCE(1,2)));'];
        yield 'inline FK' => ['CREATE TABLE `other` (`id` int REFERENCES `migration` (`index`));'];
        yield 'incomplete FK' => ['CREATE TABLE `other` (`id` int, FOREIGN KEY (`id`) REFERENCES);'];
        yield 'incomplete CREATE' => ['CREATE TABLE `other` (`id` int;'];
        yield 'duplicate CREATE' => [self::migrationDdl()];
        yield 'dangling DROP' => ['DROP TABLE IF EXISTS `other`;'];
        yield 'no delimiter' => ['INSERT INTO `migration` VALUES (7,0)'];
        yield 'incomplete string' => ["INSERT INTO `migration` VALUES ('7,0);"];
        yield 'incomplete comment' => ['/* unfinished'];
        yield 'incomplete executable comment' => ['/*!40101 SET SQL_NOTES=0;'];
        yield 'SQL inside executable comment' => ['/*!40101 SET SQL_NOTES=0; DROP TABLE `hilos_database_marker` */;'];
        yield 'delimiter change' => ["DELIMITER ;;\n"];
        yield 'lexical SQL mode change' => ["SET SQL_MODE='NO_BACKSLASH_ESCAPES';"];
        yield 'unknown note verbosity expression' => ['SET NOTE_VERBOSITY=some_function();'];
        yield 'commit without table transaction' => ['COMMIT;'];
        yield 'autocommit without table owner' => ['SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;'];
        yield 'unclosed table transaction' => ['CREATE TABLE t (id INT); SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;'];
        yield 'cross-table transaction' => ['CREATE TABLE a (id INT); CREATE TABLE b (id INT);'
            . ' SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0; INSERT INTO a VALUES (1);'
            . ' COMMIT; SET AUTOCOMMIT=@OLD_AUTOCOMMIT;'];
        yield 'commit with suffix' => ['CREATE TABLE t (id INT); SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;'
            . ' COMMIT AND CHAIN; SET AUTOCOMMIT=@OLD_AUTOCOMMIT;'];
        yield 'quoted saved value is not a reference' => ["SET @saved_cs_client='@@character_set_client';"];
        yield 'charset save without owner' => ['SET @saved_cs_client=@@character_set_client;'];
        yield 'unknown target' => ['INSERT INTO `undeclared` VALUES (1);'];
        yield 'unknown data function' => ['CREATE TABLE `other` (`id` int); INSERT INTO `other` VALUES (change_marker());'];
        yield 'unknown data charset' => ["CREATE TABLE `other` (`text` longtext); INSERT INTO `other` VALUES (_unknown 'text');"];
        yield 'invalid bit literal' => ["CREATE TABLE `other` (`flag` bit(1)); INSERT INTO `other` VALUES (b'2');"];
        yield 'mixed marker lock' => ['LOCK TABLES `hilos_database_marker` WRITE, `migration` WRITE;'];
        yield 'unclosed data lock' => ['LOCK TABLES `migration` WRITE;'];
        yield 'unclosed key wrapper' => ['ALTER TABLE `migration` DISABLE KEYS;'];
    }

    /**
     * Conditional facts, inherited quote modes and physical aliases cannot enter an executable staging file.
     *
     * @param string $sql Complete execution-dependent fixture stream
     */
    #[DataProvider('unsafeAdmissionStreams')]
    public function testExecutionDependentInputRefusesWithoutPublishingStaging(string $sql): void
    {
        $source = $this->source($sql);
        try {
            $this->stagePrimary($source, $source . '.staging');
            $this->fail('Execution-dependent SQL must refuse before staging publication');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertFileDoesNotExist($source . '.staging');
            $this->assertFileDoesNotExist($source . '.staging.preparing');
            $this->assertSame(self::completed($sql), FsPath::read($source));
        }
    }

    /** @return iterable<string, array{string}> Complete streams whose apparent facts depend on execution context */
    public static function unsafeAdmissionStreams(): iterable
    {
        yield 'future migration row' => [self::migrationDdl() . '/*!999999 INSERT INTO migration VALUES (2,0) */;'];
        yield 'future table' => ['/*!999999 CREATE TABLE later(id INT) */;'];
        yield 'future MariaDB table' => ['/*M!999999 CREATE TABLE later(id INT) */;'];
        yield 'MySQL range skipped by MariaDB' => ['/*!80000 CREATE TABLE later(id INT) */;'];
        yield 'inherited backslash mode' => [<<<'SQL'
CREATE TABLE notes(text LONGTEXT);
INSERT INTO notes VALUES ('x\'); DROP TABLE hilos_database_marker; -- ');
SQL];
        yield 'inherited ANSI quotes' => ['CREATE TABLE notes(text LONGTEXT); INSERT INTO notes VALUES ("text");'];
        yield 'escaped DDL default' => ["CREATE TABLE notes(text VARCHAR(30) DEFAULT 'x\\n');"];
        yield 'system variable restores unknown mode' => ["SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='';"
            . ' SET @@SQL_MODE=@OLD_SQL_MODE; SET SQL_NOTES="0";'];
        yield 'ANSI quote mode' => ["SET SQL_MODE='ANSI_QUOTES'; CREATE TABLE notes(text LONGTEXT);"];
        yield 'Oracle quote mode' => ["SET SQL_MODE='ORACLE'; CREATE TABLE notes(text LONGTEXT);"];
        yield 'MSSQL quote mode' => ["SET SQL_MODE='MSSQL'; CREATE TABLE notes(text LONGTEXT);"];
        yield 'mixed global and marker setup' => [str_replace(
            '/*!40101 SET @saved_cs_client = @@character_set_client */;',
            "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO', @saved_cs_client=@@character_set_client;",
            self::markerBlock(),
        )];
        yield 'legacy marker table' => ['DROP TABLE IF EXISTS `#mysql50#hilos_database_marker`;'
            . ' CREATE TABLE `#mysql50#hilos_database_marker`(id INT);'];
        yield 'legacy table declaration' => ['CREATE TABLE `#mysql50#ordinary`(id INT);'];
        yield 'legacy referenced schema' => ['CREATE TABLE child(id INT, FOREIGN KEY(id) REFERENCES `#mysql50#archive`.parent(id));'];
        yield 'legacy referenced table' => ['CREATE TABLE child(id INT, FOREIGN KEY(id) REFERENCES archive.`#mysql50#parent`(id));'];
    }

    /**
     * @param string $serverInfo Destination family and version
     * @param string $comment SQL comment containing the declaration
     * @param bool $executes Whether that server is known to execute the declaration
     */
    #[DataProvider('executableCommentDestinations')]
    public function testExecutableCommentFactsAreBoundToTheImportServer(string $serverInfo, string $comment, bool $executes): void
    {
        $sql = $comment . ';';
        $source = $this->source($sql);
        if (!$executes) {
            $this->expectException(RestoreFailedException::class);
        }
        $dump = FullRestoreDumpReader::stagePrimary($source, $source . '.staging', new DumpServerVersion($serverInfo));

        $this->assertSame(['probe'], array_keys($dump->objects));
        $this->assertSame(self::completed($sql), FsPath::read($source . '.staging'));
    }

    /** @return iterable<string, array{string, string, bool}> Server rules for both executable comment families */
    public static function executableCommentDestinations(): iterable
    {
        yield 'unversioned common' => ['8.4.1', '/*! CREATE TABLE probe(id INT) */', true];
        yield 'matching MySQL version' => ['8.4.1', '/*!80401 CREATE TABLE probe(id INT) */', true];
        yield 'future MySQL version' => ['8.4.1', '/*!80402 CREATE TABLE probe(id INT) */', false];
        yield 'MariaDB family on MySQL' => ['8.4.1', '/*M! CREATE TABLE probe(id INT) */', false];
        yield 'MariaDB diagnostic version' => ['11.4.13-MariaDB', '/*M!100616 CREATE TABLE probe(id INT) */', true];
        yield 'MariaDB compatibility prefix' => ['5.5.5-11.4.13-MariaDB', '/*M!110413 CREATE TABLE probe(id INT) */', true];
        yield 'before MariaDB ignored interval' => ['11.4.13-MariaDB', '/*!50699 CREATE TABLE probe(id INT) */', true];
        yield 'start of MariaDB ignored interval' => ['11.4.13-MariaDB', '/*!50700 CREATE TABLE probe(id INT) */', false];
        yield 'end of MariaDB ignored interval' => ['11.4.13-MariaDB', '/*!99999 CREATE TABLE probe(id INT) */', false];
        yield 'after MariaDB ignored interval' => ['11.4.13-MariaDB', '/*!100000 CREATE TABLE probe(id INT) */', true];
        yield 'MariaDB family is not ignored' => ['11.4.13-MariaDB', '/*M!50700 CREATE TABLE probe(id INT) */', true];
    }

    /** A fresh retained SET can establish quote semantics again after the dump restores an unknown mode. */
    public function testRetainedModeSetupAppliesBeforeReadingTheNextStatement(): void
    {
        $sql = <<<'SQL'
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
SET SQL_MODE=@OLD_SQL_MODE;
SET SQL_MODE='';
CREATE TABLE notes(text LONGTEXT);
INSERT INTO notes VALUES ("double quotes"), ('backslash \' quote');
SQL;
        $source = $this->source($sql);
        $this->stagePrimary($source, $source . '.staging');

        $this->assertSame(self::completed($sql), FsPath::read($source . '.staging'));
    }

    /** Migration metadata cannot be inferred from a table with an unknown shape. */
    #[DataProvider('invalidServiceDeclarations')]
    public function testUnknownServiceTableDeclarationsRefuse(string $sql): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->read($this->source($sql));
    }

    /**
     * @return iterable<string, array{string}> Unsupported declarations of protected service objects
     */
    public static function invalidServiceDeclarations(): iterable
    {
        yield 'missing failed column' => ['CREATE TABLE `migration` (`index` int);'];
        yield 'extra migration column' => ['CREATE TABLE `migration` (`index` int, `failed` int, `extra` int);'];
        yield 'migration is view' => ['CREATE VIEW `migration` AS SELECT 1;'];
        yield 'unknown migration type' => ['CREATE TABLE `migration` (`index` text, `failed` int);'];
        yield 'ambiguous migration case' => ['CREATE TABLE `MIGRATION` (`index` int, `failed` tinyint);'];
        yield 'marker is view' => ['CREATE VIEW `hilos_database_marker` AS SELECT 1;'];
        yield 'unknown marker shape' => ['CREATE TABLE `hilos_database_marker` (`id` int);'];
    }

    /** A syntactically closed prefix cannot establish absence of objects or an empty migration history. */
    #[DataProvider('incompleteDumps')]
    public function testTruncationAtAStatementBoundaryCannotProveAbsence(string $sql): void
    {
        $source = $this->source($sql, false);
        try {
            $this->stagePrimary($source, $source . '.staging');
            $this->fail('An unfinished dump cannot establish table or history absence');
        } catch (RestoreFailedException $failure) {
            $this->assertFalse($failure->databaseTouched());
            $this->assertFileDoesNotExist($source . '.staging');
            $this->assertSame($sql, FsPath::read($source));
        }
    }

    /**
     * @return iterable<string, array{string}> Incomplete files and false completion comments
     */
    public static function incompleteDumps(): iterable
    {
        yield 'empty file' => [''];
        yield 'before data block' => [self::migrationDdl()];
        yield 'after a complete INSERT' => [self::migrationDdl() . 'INSERT INTO `migration` VALUES (12,0);'];
        yield 'completion inside block comment' => [self::migrationDdl() . "/*\n-- Dump completed on 2026-01-01 00:00:00\n*/"];
        yield 'completion inside string' => ["CREATE TABLE `notes` (`text` longtext);\n"
            . "INSERT INTO `notes` VALUES ('\n-- Dump completed on 2026-01-01 00:00:00\n');"];
        yield 'completion before SQL' => ["-- Dump completed on 2026-01-01 00:00:00\n" . self::migrationDdl()];
        yield 'completion before executable comment' => [self::completed(self::migrationDdl()) . '/*!40101 SET SQL_NOTES=1 */;'];
    }

    /** A complete empty connection proves table absence, without declaring level zero. */
    public function testCompleteEmptyConnectionHasAbsentHistory(): void
    {
        $dump = $this->read($this->source('-- Empty database'));

        $this->assertSame([], $dump->objects);
        $this->assertSame(MigrationHistoryState::ABSENT, $dump->migrationHistory->state);
        $this->assertSame([], $dump->migrationHistory->rows);
    }

    /** An empty source marker block is omitted as completely as a populated block. */
    public function testEmptyMarkerTableIsExcluded(): void
    {
        $marker = preg_replace('/^INSERT INTO `hilos_database_marker`[^\n]+\n/m', '', self::markerBlock());
        $sql = self::migrationDdl() . "\n" . $marker;
        $source = $this->source($sql);

        $this->stagePrimary($source, $source . '.staging');

        $this->assertSame(self::completed(self::migrationDdl()), FsPath::read($source . '.staging'));
    }

    /** Neither an existing destination nor the source itself may be replaced. */
    public function testStagingNeverOverwritesAnExistingFile(): void
    {
        $sql = self::migrationDdl();
        $source = $this->source($sql);
        foreach ([$source, $source . '.staging'] as $destination) {
            if ($destination !== $source) {
                FsPath::write($destination, 'existing destination');
            }
            try {
                $this->stagePrimary($source, $destination);
                $this->fail('Existing files must not be replaced');
            } catch (RestoreFailedException $failure) {
                $this->assertFalse($failure->databaseTouched());
                $this->assertSame(self::completed($sql), FsPath::read($source));
                $this->assertSame($destination === $source ? self::completed($sql) : 'existing destination', FsPath::read($destination));
            }
        }
    }

    /** A single data string much larger than the input buffer never becomes a PHP string in the reader. */
    public function testLargeSingleInsertIsReadAndStagedWithBoundedMemory(): void
    {
        $source = $this->source("CREATE TABLE `large_data` (`payload` longtext);\nINSERT INTO `large_data` VALUES ('", false);
        $chunk = str_repeat('x', 65536);
        for ($i = 0; $i < 128; $i++) {
            FsPath::append($source, $chunk);
        }
        FsPath::append($source, self::completed("; CREATE TABLE `fake` (`id` int);');\n"));
        $baseline = memory_get_usage();
        memory_reset_peak_usage();

        $dump = $this->stagePrimary($source, $source . '.staging');
        $peak = memory_get_peak_usage() - $baseline;

        $this->assertLessThan(4 * 1024 * 1024, $peak);
        $this->assertSame(['large_data'], array_keys($dump->objects));
        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $source . '.staging'));
    }

    /**
     * @param string $path Fixture dump on the representative MariaDB destination
     * @return Dump Admitted fixture metadata
     */
    private function read(string $path): Dump
    {
        return FullRestoreDumpReader::read($path, new DumpServerVersion('11.4.13-MariaDB'));
    }

    /**
     * @param string $path Fixture dump on the representative MariaDB destination
     * @param string $stagingPath Private fixture staging destination
     * @return Dump Admitted fixture metadata after exclusive staging publication
     */
    private function stagePrimary(string $path, string $stagingPath): Dump
    {
        return FullRestoreDumpReader::stagePrimary($path, $stagingPath, new DumpServerVersion('11.4.13-MariaDB'));
    }

    /**
     * @param string $sql SQL fixture written only to the system temporary directory
     * @param bool $complete Whether the fixture has reached mysqldump's completion line
     * @return string Source path with its related staging paths registered for cleanup
     */
    private function source(string $sql, bool $complete = true): string
    {
        $path = FsPath::createTempFile('hilos-full-restore-test-');
        $this->paths[] = $path;
        $this->paths[] = $path . '.staging';
        $this->paths[] = $path . '.preparing';
        $this->paths[] = $path . '.staging.preparing';
        FsPath::write($path, $complete ? self::completed($sql) : $sql);
        return $path;
    }

    /**
     * @param string $sql Complete SQL fixture before the dump trailer
     * @return string Completed mysqldump-shaped file
     */
    private static function completed(string $sql): string
    {
        return $sql . "\n-- Dump completed on 2026-01-01 00:00:00\n";
    }

    /**
     * @return string Current two-column migration journal in mysqldump form
     */
    private static function migrationDdl(): string
    {
        return <<<'SQL'
CREATE TABLE `migration` (
 `index` int(10) unsigned NOT NULL,
 `failed` tinyint(1) NOT NULL DEFAULT 1,
 PRIMARY KEY (`index`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    }

    /**
     * @return string Complete marker block including mysqldump's table-local wrappers
     */
    private static function markerBlock(): string
    {
        return <<<'SQL'
-- Table structure for table `hilos_database_marker`
DROP TABLE IF EXISTS `hilos_database_marker`;
/*!40101 SET @saved_cs_client = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hilos_database_marker` (
 `id` tinyint(3) unsigned NOT NULL,
 `marker` char(32) NOT NULL,
 `written_by` varchar(255) NOT NULL,
 `written_at` datetime NOT NULL,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
-- Dumping data for table `hilos_database_marker`
LOCK TABLES `hilos_database_marker` WRITE;
/*!40000 ALTER TABLE `hilos_database_marker` DISABLE KEYS */;
INSERT INTO `hilos_database_marker` VALUES (1,'0123456789abcdef0123456789abcdef','archive-node','2026-01-01 00:00:00');
/*!40000 ALTER TABLE `hilos_database_marker` ENABLE KEYS */;
UNLOCK TABLES;
SQL;
    }
}
