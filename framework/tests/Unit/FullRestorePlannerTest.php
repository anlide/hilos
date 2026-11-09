<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupConnectionMeta;
use Hilos\Backup\Exception\RestoreFailedException;
use Hilos\Backup\FullRestore\DependencyProof;
use Hilos\Backup\FullRestore\Dump;
use Hilos\Backup\FullRestore\LiveObject;
use Hilos\Backup\FullRestore\MigrationBinding;
use Hilos\Backup\FullRestore\MigrationFacts;
use Hilos\Backup\FullRestore\MigrationHistory;
use Hilos\Backup\FullRestore\MigrationHistoryState;
use Hilos\Backup\FullRestore\MigrationOperationReader;
use Hilos\Backup\FullRestore\MigrationRow;
use Hilos\Backup\FullRestore\MigrationStep;
use Hilos\Backup\FullRestore\MigrationTargetEvidence;
use Hilos\Backup\FullRestore\PreparedTarget;
use Hilos\Backup\FullRestore\SchemaMetadata;
use Hilos\Backup\FullRestore\ServerMetadata;
use Hilos\Backup\FullRestore\StructureDefaults;
use Hilos\Backup\FullRestore\TableStructure;
use Hilos\Backup\FullRestore\TableName;
use Hilos\Backup\FullRestorePlanner;
use Hilos\Backup\FullRestoreSchemaSnapshot;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\MigrationHistory as DatabaseMigrationHistory;
use Hilos\Database\MigrationRecord;
use Hilos\Database\MigrationUpFile;
use Hilos\Database\MigrationUpTrack;
use Hilos\Fs\FsPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Pure planning fixtures; actual privilege and metadata visibility is covered by integration tests. */
final class FullRestorePlannerTest extends TestCase
{
    /** MariaDB's physical declaration of the production analytics JSON parent. */
    private const string JSON_PARENT = <<<'SQL'
CREATE TABLE `hilos_analytics_payload_json` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sha1_hash` binary(20) NOT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload_json`)),
  `created_ts` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ha_payload_hash` (`sha1_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    /** The production cron signal's payload FK, independent of its other analytics parents. */
    private const string JSON_CHILD = <<<'SQL'
CREATE TABLE `hilos_analytics_agent_cron_signal` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payload_json_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ha_agent_cron_signal_payload` (`payload_json_id`),
  CONSTRAINT `fk_ha_agent_cron_signal_payload` FOREIGN KEY (`payload_json_id`) REFERENCES `hilos_analytics_payload_json` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    private string $root;
    private array $configuration;
    private StructureDefaults $defaults;

    /** Preserves the registry; these configured targets are never connected. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration = new ReflectionProperty(Database::class, 'configurations')->getValue();
        $this->root = sys_get_temp_dir() . '/hilos-restore-planner-' . bin2hex(random_bytes(6));
        FsPath::ensureDirectory($this->root, 0700);
        $this->defaults = new StructureDefaults('InnoDB', 'utf8mb4', 'utf8mb4_general_ci', ['utf8mb4' => 'utf8mb4_general_ci']);
    }

    /** Removes only this case's source files and restores the registry. */
    protected function tearDown(): void
    {
        foreach (FsPath::entries($this->root) as $file) {
            FsPath::delete($this->root . '/' . $file);
        }
        FsPath::removeDirectory($this->root);
        new ReflectionProperty(Database::class, 'configurations')->setValue(null, $this->configuration);
        parent::tearDown();
    }

    /** Applied ALTER changes the current proof; an unapplied ALTER belongs only to future replay. */
    public function testCreateAlterAndFutureAlterAreSeparateProofs(): void
    {
        $cleanup = $this->plan([
            2 => 'CREATE TABLE later (id INT PRIMARY KEY)',
            3 => "ALTER TABLE later ADD label VARCHAR(30) NOT NULL DEFAULT 'kept'",
            4 => 'ALTER TABLE later ADD future_only BIGINT',
        ], [
            'later' => "CREATE TABLE later (id INT(11) NOT NULL, label VARCHAR(30) NOT NULL DEFAULT 'kept', PRIMARY KEY(id)) ENGINE=InnoDB",
            'independent' => 'CREATE TABLE independent (id INT)',
        ], applied: [1, 2, 3]);
        $this->assertSame(['later'], array_column($cleanup, 'table'));
        $this->assertSame([DatabaseConnectionDefaults::PRIMARY_INDEX], array_column($cleanup, 'index'));
    }

    /** Conditional CREATE never permits keeping rows from the old target. */
    public function testIfNotExistsStillRequiresCleanupAndNullSidecarIsAllowed(): void
    {
        $cleanup = $this->plan([2 => 'CREATE TABLE IF NOT EXISTS later (id INT)'], ['later' => 'CREATE TABLE later (id INT DEFAULT NULL)']);
        $this->assertSame(['later'], array_column($cleanup, 'table'));
    }

    /** A successful live INSERT does not establish that its source exists after cleanup and import. */
    public function testDmlBeforeItsSourceCreateRefuses(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('absent');
        $this->plan([2 => "INSERT INTO carried(id) SELECT id FROM later;\nCREATE TABLE IF NOT EXISTS later(id INT)"],
            ['later' => 'CREATE TABLE later(id INT)'], archive: ['carried' => 'CREATE TABLE carried(id INT)']);
    }

    /** @param string $sql Direct read or write of rows whose provenance is unknown */
    #[DataProvider('retainedDataOperations')]
    public function testDmlCannotUseRetainedTargetOnlyRows(string $sql): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('unproven target-only');
        $this->plan([2 => $sql], ['independent' => 'CREATE TABLE independent(id INT)'],
            archive: ['carried' => 'CREATE TABLE carried(id INT)']);
    }

    /** @return iterable<string, array{string}> Ordinary direct references, including qualified aliases and joins */
    public static function retainedDataOperations(): iterable
    {
        yield 'insert source' => ['INSERT INTO carried(id) SELECT i.id FROM target.independent AS i'];
        yield 'update target' => ['UPDATE independent SET id = 5'];
        yield 'delete target' => ['DELETE FROM independent WHERE id = 5'];
        yield 'replace target' => ['REPLACE INTO independent(id) VALUES(5)'];
        yield 'select source' => ['SELECT i.id FROM `target`.`independent` i'];
        yield 'joined update' => ['UPDATE carried c JOIN independent i ON c.id = i.id SET c.id = i.id'];
        yield 'joined delete' => ['DELETE c FROM carried c LEFT JOIN independent i ON c.id = i.id WHERE i.id IS NULL'];
    }

    /** The physical alias cannot make a retained conditional DROP look absent to the planner. */
    public function testLegacyConditionalDropOfRetainedTableRefuses(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => 'DROP TABLE IF EXISTS `#mysql50#independent`'],
            ['independent' => 'CREATE TABLE independent(id INT)'],
            archive: ['carried' => 'CREATE TABLE carried(id INT)']);
    }

    /** CREATE precedes DML; literal, comment and column names never become table references. */
    public function testDmlAfterCreateUsesOnlyAvailableArchiveAndEarlierUpTables(): void
    {
        $this->assertSame(['later'], array_column($this->plan([
            2 => "CREATE TABLE IF NOT EXISTS later(id INT);\n"
                . "INSERT INTO later(id) SELECT c.id AS `FROM` FROM carried c;\n"
                . "UPDATE later l JOIN carried c ON l.id = c.id SET l.id = c.id;\n"
                . "DELETE l FROM later l LEFT JOIN carried c ON l.id = c.id WHERE c.id IS NULL;\n"
                . "SELECT 'FROM independent' AS later, c.`FROM` FROM carried c /* JOIN independent */",
        ], ['later' => 'CREATE TABLE later(id INT)'], archive: ['carried' => 'CREATE TABLE carried(id INT, `FROM` INT)']), 'table'));
    }

    /** @param string $sql Direct access outside admitted, non-bootstrap archive or earlier-UP tables */
    #[DataProvider('unavailableDataOperations')]
    public function testUnprovenDataOperationsRefuseEvenWithoutCleanup(string $sql): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => $sql], [], archive: ['carried' => 'CREATE TABLE carried(id INT)']);
    }

    /** @return iterable<string, array{string}> Missing, external, protected and opaque requirements */
    public static function unavailableDataOperations(): iterable
    {
        yield 'absent source' => ['INSERT INTO carried(id) SELECT id FROM absent'];
        yield 'external schema' => ['UPDATE outside.carried SET id=1'];
        yield 'bootstrap marker' => ['DELETE FROM hilos_database_marker'];
        yield 'nested source' => ['SELECT (SELECT id FROM absent)'];
        yield 'unknown operation' => ['TRUNCATE TABLE carried'];
        yield 'unconditional trigger drop' => ['DROP TRIGGER absent'];
    }

    /**
     * @param string $sql Future execution unit, not a reconstructed live-history effect
     * @param string $protected Table whose existing rows must survive
     * @param bool $cleanup Whether an unrelated proven table also needs cleanup
     * @param ?int $level Known archive boundary, or the legacy unknown-level path
     */
    #[DataProvider('indirectReplayCalls')]
    public function testFutureCallsCannotBypassPreservedRows(string $sql, string $protected, bool $cleanup, ?int $level): void
    {
        $live = [$protected => 'CREATE TABLE ' . $protected . '(id INT)'];
        $source = $sql;
        if ($cleanup) {
            $live['later'] = 'CREATE TABLE later(id INT)';
            $source = "CREATE TABLE later(id INT);\n" . $source;
        }
        $routine = ['ROUTINE_SCHEMA' => 'target', 'ROUTINE_NAME' => 'touch_kept', 'SQL_MODE' => '',
            'SQL_DATA_ACCESS' => 'NO SQL', ServerMetadata::CREATE_SQL => 'CREATE FUNCTION touch_kept() RETURNS INT NO SQL BEGIN DELETE FROM '
                . $protected . '; RETURN 1; END'];
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('touch_kept');
        $this->plan([2 => $source], $live, archive: ['carried' => 'CREATE TABLE carried(id INT)'],
            level: $level, routines: [$routine]);
    }

    /** @return iterable<string, array{string, string, bool, ?int}> Retained and bootstrap rows, with and without cleanup */
    public static function indirectReplayCalls(): iterable
    {
        yield 'empty cleanup' => ['SELECT touch_kept()', 'independent', false, 1];
        yield 'unrelated cleanup' => ['SELECT touch_kept()', 'independent', true, 1];
        yield 'bootstrap marker' => ['SELECT touch_kept()', 'hilos_database_marker', false, 1];
        yield 'nested qualified' => ['DO COALESCE(`target`.`touch_kept`(),0)', 'independent', false, 1];
        yield 'legacy archive' => ['SELECT touch_kept()', 'independent', false, null];
        yield 'insert value' => ['INSERT INTO carried(id) VALUES(touch_kept())', 'independent', false, 1];
        yield 'insert select' => ['INSERT INTO carried(id) SELECT touch_kept() FROM carried', 'independent', false, 1];
        yield 'upsert' => ['INSERT INTO carried(id) VALUES(1) ON DUPLICATE KEY UPDATE id=touch_kept()', 'independent', false, 1];
        yield 'update assignment' => ['UPDATE carried SET id=touch_kept()', 'independent', false, 1];
        yield 'join condition' => ['SELECT c.id FROM carried c JOIN carried d ON touch_kept()=c.id', 'independent', false, 1];
        yield 'delete condition' => ['DELETE FROM carried WHERE id=touch_kept()', 'independent', false, 1];
        yield 'group expression' => ['SELECT id FROM carried GROUP BY touch_kept()', 'independent', false, 1];
        yield 'having expression' => ['SELECT id FROM carried HAVING touch_kept()=1', 'independent', false, 1];
        yield 'order expression' => ['SELECT id FROM carried ORDER BY touch_kept()', 'independent', false, 1];
    }

    /** Opaque native functions are not absent effects, even when no table is being dropped. */
    public function testFutureNativeCallRefusesWithoutCleanup(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('native_touch');
        $this->plan([2 => 'SELECT COALESCE(native_touch(),0)'], ['independent' => 'CREATE TABLE independent(id INT)'],
            functions: [['name' => 'native_touch']]);
    }

    /** A filtered routine/UDF catalog cannot prove a scalar call is a builtin. */
    public function testFutureCallRequiresCompleteCatalogWithoutCleanup(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('visibility');
        $this->plan([2 => 'SELECT hidden_touch()'], [], visible: false);
    }

    /** A qualified call cannot be treated as a builtin merely because the routine is missing from metadata. */
    public function testFutureUnresolvedQualifiedCallRefuses(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => 'SELECT outside.hidden_touch()'], []);
    }

    /**
     * Catalog absence requires a proven identifier comparison, including potential collation aliases.
     *
     * @param string $sql Call spelling whose native identity cannot be established
     * @param array $routines Visible stored functions in the execution schema
     * @param array $functions Visible opaque native functions
     */
    #[DataProvider('ambiguousCallNames')]
    public function testFutureCallNameUncertaintyRefuses(string $sql, array $routines, array $functions): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => $sql], [], routines: $routines, functions: $functions);
    }

    /** @return iterable<string, array{string, array, array}> Non-ASCII or quoted names cannot use an ASCII case comparison as absence evidence */
    public static function ambiguousCallNames(): iterable
    {
        yield 'non-ASCII caller' => ['SELECT TÖUCH()', [['ROUTINE_SCHEMA' => 'target', 'ROUTINE_NAME' => 'töuch']], []];
        yield 'stored collation alias' => ['SELECT touch()', [['ROUTINE_SCHEMA' => 'target', 'ROUTINE_NAME' => 'tóuch']], []];
        yield 'native collation alias' => ['SELECT touch()', [], [['name' => 'tóuch']]];
        yield 'quoted spelling' => ['SELECT `touch kept`()', [], []];
    }

    /** An indirect routine chain is refused at its entry, without relying on declared data access. */
    public function testFutureNestedRoutineBodyIsUnsupported(): void
    {
        $routines = [
            ['ROUTINE_SCHEMA' => 'target', 'ROUTINE_NAME' => 'wrapper', 'SQL_MODE' => '', 'SQL_DATA_ACCESS' => 'READS SQL DATA',
                ServerMetadata::CREATE_SQL => 'CREATE FUNCTION wrapper() RETURNS INT READS SQL DATA RETURN outside.touch_kept()'],
            ['ROUTINE_SCHEMA' => 'outside', 'ROUTINE_NAME' => 'touch_kept', 'SQL_MODE' => '',
                ServerMetadata::CREATE_SQL => 'CREATE FUNCTION touch_kept() RETURNS INT BEGIN DELETE FROM target.independent; RETURN 1; END'],
        ];
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('wrapper');
        $this->plan([2 => 'SELECT wrapper()'], ['independent' => 'CREATE TABLE independent(id INT)'], routines: $routines);
    }

    /** Missing routine text cannot stand in for an empty body. */
    public function testFutureHiddenRoutineBodyRefuses(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('hidden_touch');
        $this->plan([2 => 'SELECT hidden_touch()'], [], routines: [
            ['ROUTINE_SCHEMA' => 'target', 'ROUTINE_NAME' => 'hidden_touch', ServerMetadata::CREATE_SQL => null],
        ]);
    }

    /** ANSI_QUOTES can turn a string token into an actual stored function identifier. */
    public function testFutureQuoteModeCannotHideACallWithoutCleanup(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('opaque');
        $this->plan([2 => 'SELECT "touch_kept"()'], [], sqlMode: 'ANSI_QUOTES', routines: [
            ['ROUTINE_SCHEMA' => 'target', 'ROUTINE_NAME' => 'touch_kept', ServerMetadata::CREATE_SQL => null],
        ]);
    }

    /** An archived execution unit is not a future call, even if its routine no longer has a visible body. */
    public function testHistoricalCallsAreNotRecheckedAsFutureReplay(): void
    {
        $this->assertSame([], $this->plan([1 => 'SELECT old_touch()', 2 => 'SELECT 1'],
            ['independent' => 'CREATE TABLE independent(id INT)'], level: 2, applied: [1, 2], routines: [
                ['ROUTINE_SCHEMA' => 'target', 'ROUTINE_NAME' => 'old_touch', ServerMetadata::CREATE_SQL => null],
            ]));
    }

    /** Every pending call needs proof, including a file not yet recorded in the live target. */
    public function testUnappliedFutureFunctionStillNeedsProof(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('future_touch');
        $this->plan([2 => 'SELECT FUTURE_TOUCH()'], [], applied: [1], routines: [
            ['ROUTINE_SCHEMA' => 'target', 'ROUTINE_NAME' => 'future_touch', ServerMetadata::CREATE_SQL => null],
        ]);
    }

    /** A legacy level cannot hide a future callable-catalog mutation from the captured catalog. */
    public function testUnknownLevelCannotIntroduceAnUnprovenRoutine(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('Opaque UP');
        $this->plan([2 => 'CREATE FUNCTION touch_kept() RETURNS INT RETURN 1'], [], level: null);
    }

    /** Literal/comment/column spellings are not calls, and builtins keep ordinary scalar SQL available. */
    public function testFutureBuiltinExpressionsDoNotCallNamesInTextOrColumns(): void
    {
        $routine = ['ROUTINE_SCHEMA' => 'target', 'ROUTINE_NAME' => 'touch_kept', 'SQL_MODE' => '',
            ServerMetadata::CREATE_SQL => null];
        $this->assertSame([], $this->plan([2 => "SELECT 'touch_kept()', COALESCE(ABS(-1),0), c.touch_kept FROM carried c /* touch_kept() */;\n"
            . 'INSERT INTO carried(touch_kept) VALUES(ABS(-2))'],
            ['independent' => 'CREATE TABLE independent(id INT)'], archive: ['carried' => 'CREATE TABLE carried(touch_kept INT)'],
            routines: [$routine, ['ROUTINE_SCHEMA' => 'target', 'ROUTINE_NAME' => 'carried', ServerMetadata::CREATE_SQL => null]]));
    }

    /** Parenthesized SQL operators are not function calls and need no callable catalog. */
    public function testOrdinaryPredicatesDoNotRequireRoutineVisibility(): void
    {
        $this->assertSame([], $this->plan([2 => "SELECT id FROM carried WHERE NOT (id IN (1,2)) AND (id=3 OR (id=4));\n"
            . 'SELECT CASE WHEN (id=1) THEN (1) ELSE (2) END FROM carried'], [],
            archive: ['carried' => 'CREATE TABLE carried(id INT)'], visible: false));
    }

    /**
     * @param string $table Bootstrap table that survives the import
     * @param bool $archived Whether the archive also names the excluded marker table
     * @param ?int $level Known or unknown archive history
     */
    #[DataProvider('retainedBootstrapTables')]
    public function testRetainedBootstrapAlterationRefusesAutomaticTriggerEffects(string $table, bool $archived, ?int $level): void
    {
        $create = $table === 'hilos_database_marker'
            ? 'CREATE TABLE hilos_database_marker(id TINYINT UNSIGNED PRIMARY KEY, marker CHAR(32) NOT NULL,'
                . ' written_by VARCHAR(255) NOT NULL, written_at DATETIME NOT NULL)'
            : 'CREATE TABLE ' . $table . '(id INT)';
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('bootstrap UPDATE effects');
        $this->plan([2 => 'ALTER TABLE ' . $table . ' ADD own_column INT'], [$table => $create],
            archive: $archived ? [$table => $create] : [], applied: [1], level: $level, visible: false, triggerVisible: true,
            triggers: [['TRIGGER_SCHEMA' => 'target', 'TRIGGER_NAME' => 'automatic_effect',
                'EVENT_OBJECT_SCHEMA' => 'target', 'EVENT_OBJECT_TABLE' => $table]]);
    }

    /** @return iterable<string, array{string, bool, ?int}> Every canonical bootstrap owner, including the marker exclusion */
    public static function retainedBootstrapTables(): iterable
    {
        foreach ([1, null] as $level) {
            $history = $level === null ? 'unknown' : 'known';
            yield $history . ' marker absent from archive' => ['hilos_database_marker', false, $level];
            yield $history . ' marker excluded from import' => ['hilos_database_marker', true, $level];
            yield $history . ' claim absent from archive' => ['hilos_migration_claim', false, $level];
            yield $history . ' latch absent from archive' => ['hilos_admin_view_mode_latch', false, $level];
        }
    }

    /**
     * @param string $table Retained bootstrap owner
     * @param string $column Any auto-written owner column, including its moment column
     * @param bool $archived Whether a conditional owner or excluded marker declaration is archived
     * @param ?int $level Known or unknown archive history
     * @param string $extra Physical ON UPDATE metadata, independent of identifier and keyword case
     */
    #[DataProvider('retainedBootstrapAutomaticUpdates')]
    public function testRetainedBootstrapColumnAutomaticUpdateRefuses(
        string $table,
        string $column,
        bool $archived,
        ?int $level,
        string $extra,
    ): void {
        $create = $table === 'hilos_database_marker'
            ? 'CREATE TABLE hilos_database_marker(id TINYINT UNSIGNED PRIMARY KEY, marker CHAR(32) NOT NULL,'
                . ' written_by VARCHAR(255) NOT NULL, written_at DATETIME NOT NULL)'
            : 'CREATE TABLE IF NOT EXISTS ' . $table . '(id INT)';
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('bootstrap column ' . $column . ' has automatic ON UPDATE effects');
        $this->plan([2 => 'ALTER TABLE ' . $table . ' ADD own_column INT'],
            [$table => 'CREATE TABLE ' . $table . '(id INT)'],
            archive: $archived ? [$table => $create] : [], level: $level, visible: false, triggerVisible: true, columns: [
                ['TABLE_SCHEMA' => 'target', 'TABLE_NAME' => $table, 'COLUMN_NAME' => $column, 'EXTRA' => $extra],
            ]);
    }

    /** @return iterable<string, array{string, string, bool, ?int, string}> Every retained mapping, without staging or visible dependencies */
    public static function retainedBootstrapAutomaticUpdates(): iterable
    {
        foreach (['hilos_migration_claim' => 'claimed_at', 'hilos_admin_view_mode_latch' => 'closed_at',
            'hilos_database_marker' => 'written_at'] as $table => $moment) {
            foreach ([false, true] as $archived) {
                foreach ([1, null] as $level) {
                    foreach (['audit_stamp' => 'on update current_timestamp()', $moment => 'ON UPDATE CURRENT_TIMESTAMP(6)',
                        'Other_Clock' => "DEFAULT_GENERATED oN\tUpDaTe CURRENT_TIMESTAMP"] as $column => $extra) {
                        $case = $table . '.' . $column . ($archived ? ' archived' : ' absent') . ($level === null ? ' unknown' : ' known');
                        yield $case => [$table, $column, $archived, $level, $extra];
                    }
                }
            }
        }
    }

    /**
     * @param string $table Bootstrap owner whose real migration is still pending on the target
     * @param bool $archived Whether the excluded marker is also archived
     * @param ?int $level Known or unknown archive history
     */
    #[DataProvider('retainedBootstrapTables')]
    public function testPendingBootstrapColumnAutomaticUpdateRefuses(string $table, bool $archived, ?int $level): void
    {
        $create = $table === 'hilos_database_marker'
            ? 'CREATE TABLE hilos_database_marker(id TINYINT UNSIGNED PRIMARY KEY, marker CHAR(32) NOT NULL,'
                . ' written_by VARCHAR(255) NOT NULL, written_at DATETIME NOT NULL)'
            : 'CREATE TABLE ' . $table . '(id INT)';
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('bootstrap column modified has automatic ON UPDATE effects');
        $this->plan([2 => 'ALTER TABLE ' . $table . ' ADD own_column INT'], [$table => $create],
            archive: $archived ? [$table => $create] : [], applied: [1], level: $level, columns: [
                ['TABLE_SCHEMA' => 'target', 'TABLE_NAME' => $table, 'COLUMN_NAME' => 'modified', 'EXTRA' => 'on update current_timestamp()'],
            ]);
    }

    /** DEFAULT CURRENT_TIMESTAMP writes only on INSERT, and auto-writers on another physical owner are irrelevant. */
    public function testBootstrapAutomaticUpdateChecksExactOwnerAndAllowsInsertDefaults(): void
    {
        $this->assertSame([], $this->plan([2 => 'ALTER TABLE hilos_database_marker ADD own_column INT'],
            ['hilos_database_marker' => 'CREATE TABLE hilos_database_marker(id INT)'], visible: false, triggerVisible: true, columns: [
                ['TABLE_SCHEMA' => 'target', 'TABLE_NAME' => 'hilos_database_marker', 'COLUMN_NAME' => 'audit_stamp',
                    'COLUMN_DEFAULT' => 'current_timestamp()', 'EXTRA' => 'DEFAULT_GENERATED'],
                ['TABLE_SCHEMA' => 'target', 'TABLE_NAME' => 'hilos_database_marker', 'COLUMN_NAME' => 'another_clock',
                    'COLUMN_DEFAULT' => 'current_timestamp()', 'EXTRA' => ''],
                ['TABLE_SCHEMA' => 'target', 'TABLE_NAME' => 'independent', 'COLUMN_NAME' => 'audit_stamp',
                    'EXTRA' => 'on update current_timestamp()'],
                ['TABLE_SCHEMA' => 'elsewhere', 'TABLE_NAME' => 'hilos_database_marker', 'COLUMN_NAME' => 'audit_stamp',
                    'EXTRA' => 'on update current_timestamp()'],
            ]));
    }

    /** Unconditional claim/latch replacement also removes their automatic column writers. */
    public function testImportedBootstrapOwnersDoNotRetainTheirAutomaticColumnWriters(): void
    {
        foreach (['hilos_migration_claim', 'hilos_admin_view_mode_latch'] as $table) {
            $create = 'CREATE TABLE ' . $table . '(id INT)';
            $this->assertSame([], $this->plan([2 => 'ALTER TABLE ' . $table . ' ADD own_column INT'], [$table => $create],
                archive: [$table => $create], columns: [
                    ['TABLE_SCHEMA' => 'target', 'TABLE_NAME' => $table, 'COLUMN_NAME' => 'audit_stamp', 'EXTRA' => 'on update current_timestamp()'],
                ]));
        }
    }

    /**
     * @param string $table Retained bootstrap owner
     * @param string $column Reserved staging column that the canonical UPDATE would reuse
     * @param bool $archived Whether an excluded marker or conditional owner declaration is present
     * @param ?int $level Known or unknown archive history
     */
    #[DataProvider('retainedBootstrapStagingColumns')]
    public function testRetainedBootstrapStagingRefusesWithoutAnyVisibleDependency(
        string $table,
        string $column,
        bool $archived,
        ?int $level,
    ): void {
        $create = $table === 'hilos_database_marker'
            ? 'CREATE TABLE hilos_database_marker(id TINYINT UNSIGNED PRIMARY KEY, marker CHAR(32) NOT NULL,'
                . ' written_by VARCHAR(255) NOT NULL, written_at DATETIME NOT NULL)'
            : 'CREATE TABLE IF NOT EXISTS ' . $table . '(id INT)';
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('existing bootstrap staging column ' . strtolower($column));
        $this->plan([2 => 'ALTER TABLE ' . $table . ' ADD own_column INT'],
            [$table => 'CREATE TABLE ' . $table . '(id INT, ' . $column . ' BIGINT)'],
            archive: $archived ? [$table => $create] : [], applied: [1], level: $level, columns: [
                ['TABLE_SCHEMA' => 'target', 'TABLE_NAME' => $table, 'COLUMN_NAME' => $column],
            ]);
    }

    /** @return iterable<string, array{string, string, bool, ?int}> Exact owner/column pairs with every retained import shape */
    public static function retainedBootstrapStagingColumns(): iterable
    {
        foreach ([1, null] as $level) {
            foreach ([false, true] as $archived) {
                $case = ($level === null ? 'unknown' : 'known') . ($archived ? ' archive declaration' : ' absent from archive');
                yield 'claim ' . $case => ['hilos_migration_claim', '__hil1918_claimed_at', $archived, $level];
                yield 'latch ' . $case => ['hilos_admin_view_mode_latch', '__hil1918_closed_at', $archived, $level];
                yield 'marker ' . $case => ['hilos_database_marker', '__hil1918_written_at', $archived, $level];
            }
        }
        yield 'case-insensitive claim column' => ['hilos_migration_claim', '__HIL1918_CLAIMED_AT', false, 1];
        yield 'case-insensitive latch column' => ['hilos_admin_view_mode_latch', '__HIL1918_CLOSED_AT', false, 1];
        yield 'case-insensitive marker column' => ['hilos_database_marker', '__HIL1918_WRITTEN_AT', false, 1];
    }

    /** A staging spelling on another physical owner or the wrong staging name does not belong to this UPDATE. */
    public function testBootstrapStagingChecksTheExactOwnerAndColumn(): void
    {
        $this->assertSame([], $this->plan([2 => 'ALTER TABLE hilos_database_marker ADD own_column INT'],
            ['hilos_database_marker' => 'CREATE TABLE hilos_database_marker(id INT)'], columns: [
                ['TABLE_SCHEMA' => 'target', 'TABLE_NAME' => 'hilos_database_marker', 'COLUMN_NAME' => '__hil1918_claimed_at', 'EXTRA' => ''],
                ['TABLE_SCHEMA' => 'target', 'TABLE_NAME' => 'independent', 'COLUMN_NAME' => '__hil1918_written_at'],
                ['TABLE_SCHEMA' => 'elsewhere', 'TABLE_NAME' => 'hilos_database_marker', 'COLUMN_NAME' => '__hil1918_written_at'],
            ]));
    }

    /** Unconditional import replaces claim/latch staging along with the rest of their old table. */
    public function testImportedBootstrapOwnersDoNotRetainTheirStaging(): void
    {
        foreach (['hilos_migration_claim' => '__hil1918_claimed_at', 'hilos_admin_view_mode_latch' => '__hil1918_closed_at']
            as $table => $column) {
            $this->assertSame([], $this->plan([2 => 'ALTER TABLE ' . $table . ' ADD own_column INT'],
                [$table => 'CREATE TABLE ' . $table . '(id INT, ' . $column . ' BIGINT)'],
                archive: [$table => 'CREATE TABLE ' . $table . '(id INT)'], columns: [
                    ['TABLE_SCHEMA' => 'target', 'TABLE_NAME' => $table, 'COLUMN_NAME' => $column],
                ]));
        }
    }

    /** @param bool $staging Whether the destination with pending bootstrap UP has its own staging column */
    #[DataProvider('destinationStaging')]
    public function testBootstrapStagingUsesItsOwnConnectionSnapshot(bool $staging): void
    {
        $path = $this->root . '/002_marker.sql';
        FsPath::write($path, "ALTER TABLE hilos_database_marker ADD own_column INT;\n");
        $file = MigrationUpFile::read(2, $path);
        $track = new MigrationUpTrack($this->root, 'Schema', ['002_marker.sql'], [$file]);
        $evidence = [];
        $schemas = [];
        $servers = [];
        foreach ([DatabaseConnectionDefaults::PRIMARY_INDEX => 2, 2 => 1] as $index => $level) {
            Database::configure($index, 'unused', 'unused', '', 'target');
            $dump = new Dump([], new MigrationHistory(MigrationHistoryState::RECORDED, [new MigrationRow($level, false)]));
            $target = new PreparedTarget(new BackupConnectionMeta($index, 'source', null), Database::getConnectionConfig($index),
                json_encode(['server-' . $index, DatabaseConnectionDefaults::PORT, 1, 'target']), 'source', 'digest', 'staged', 'digest', $dump);
            $facts = MigrationFacts::read($dump->migrationHistory,
                new DatabaseMigrationHistory([new MigrationRecord($level, false)]), $track, null);
            $pending = $level === 1 ? [new MigrationStep($file, 0, MigrationOperationReader::read($file->statements[0], 'target')[0])] : [];
            $evidence[$index] = new MigrationTargetEvidence(MigrationBinding::resolve($target, [$target]), $facts, $pending);
            $columns = $level === 2 || $staging
                ? [['TABLE_SCHEMA' => 'target', 'TABLE_NAME' => 'hilos_database_marker', 'COLUMN_NAME' => '__hil1918_written_at']]
                : [];
            $schemas[$index] = new SchemaMetadata('server-' . $index, [], [['lower_case_table_names' => 0]], $columns, [], $this->defaults,
                completeTriggerVisibility: true);
            $servers['server-' . $index] = new ServerMetadata(true, [], [], [], [], [], []);
        }
        if ($staging) {
            $this->expectException(RestoreFailedException::class);
            $this->expectExceptionMessage('connection 2: Object target.hilos_database_marker: existing bootstrap staging column');
        }
        $this->assertSame([], FullRestorePlanner::prove($evidence, $track, new FullRestoreSchemaSnapshot($schemas, $servers)));
    }

    /** @return list<array{bool}> A same-named table on the primary never supplies another server's column evidence */
    public static function destinationStaging(): array
    {
        return [[false], [true]];
    }

    /** @param bool $automaticUpdate Whether the pending destination has its own auto-writer */
    #[DataProvider('destinationAutomaticUpdates')]
    public function testBootstrapAutomaticUpdateUsesItsOwnConnectionSnapshot(bool $automaticUpdate): void
    {
        $path = $this->root . '/002_marker.sql';
        FsPath::write($path, "ALTER TABLE hilos_database_marker ADD own_column INT;\n");
        $file = MigrationUpFile::read(2, $path);
        $track = new MigrationUpTrack($this->root, 'Schema', ['002_marker.sql'], [$file]);
        $evidence = [];
        $schemas = [];
        $servers = [];
        foreach ([DatabaseConnectionDefaults::PRIMARY_INDEX => 2, 2 => 1] as $index => $level) {
            Database::configure($index, 'unused', 'unused', '', 'target');
            $dump = new Dump([], new MigrationHistory(MigrationHistoryState::RECORDED, [new MigrationRow($level, false)]));
            $target = new PreparedTarget(new BackupConnectionMeta($index, 'source', null), Database::getConnectionConfig($index),
                json_encode(['server-' . $index, DatabaseConnectionDefaults::PORT, 1, 'target']), 'source', 'digest', 'staged', 'digest', $dump);
            $facts = MigrationFacts::read($dump->migrationHistory,
                new DatabaseMigrationHistory([new MigrationRecord($level, false)]), $track, null);
            $pending = $level === 1 ? [new MigrationStep($file, 0, MigrationOperationReader::read($file->statements[0], 'target')[0])] : [];
            $evidence[$index] = new MigrationTargetEvidence(MigrationBinding::resolve($target, [$target]), $facts, $pending);
            $columns = $level === 2 || $automaticUpdate
                ? [['TABLE_SCHEMA' => 'target', 'TABLE_NAME' => 'hilos_database_marker', 'COLUMN_NAME' => 'audit_stamp',
                    'EXTRA' => 'on update current_timestamp()']]
                : [];
            $schemas[$index] = new SchemaMetadata('server-' . $index, [], [['lower_case_table_names' => 0]], $columns, [], $this->defaults,
                completeTriggerVisibility: true);
            $servers['server-' . $index] = new ServerMetadata(true, [], [], [], [], [], []);
        }
        if ($automaticUpdate) {
            $this->expectException(RestoreFailedException::class);
            $this->expectExceptionMessage('connection 2: Object target.hilos_database_marker: bootstrap column audit_stamp');
        }
        $this->assertSame([], FullRestorePlanner::prove($evidence, $track, new FullRestoreSchemaSnapshot($schemas, $servers)));
    }

    /** @return list<array{bool}> The same schema/table on a separate server cannot lend its column evidence */
    public static function destinationAutomaticUpdates(): array
    {
        return [[false], [true]];
    }

    /** A filtered empty schema catalog cannot establish safety, even without cleanup or visible triggers. */
    public function testRetainedBootstrapAlterationRequiresCompleteTriggerVisibility(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('bootstrap trigger visibility');
        $this->plan([2 => 'ALTER TABLE hilos_database_marker ADD own_column INT'],
            ['hilos_database_marker' => 'CREATE TABLE hilos_database_marker(id INT)'], visible: false);
    }

    /** Local schema visibility suffices; an unrelated trigger gives no automatic effects on this owner. */
    public function testRetainedBootstrapAlterationNeedsNoGlobalCatalogGrant(): void
    {
        $this->assertSame([], $this->plan([2 => 'ALTER TABLE hilos_database_marker ADD own_column INT'],
            ['hilos_database_marker' => 'CREATE TABLE hilos_database_marker(id INT)'], visible: false, triggerVisible: true,
            triggers: [['TRIGGER_SCHEMA' => 'target', 'TRIGGER_NAME' => 'unrelated',
                'EVENT_OBJECT_SCHEMA' => 'target', 'EVENT_OBJECT_TABLE' => 'independent']]));
    }

    /** Non-marker bootstrap tables present in the archive lose their old triggers during import. */
    public function testImportedBootstrapOwnersDoNotRetainTheirTriggers(): void
    {
        foreach (['hilos_migration_claim', 'hilos_admin_view_mode_latch'] as $table) {
            $create = 'CREATE TABLE ' . $table . '(id INT)';
            $this->assertSame([], $this->plan([2 => 'ALTER TABLE ' . $table . ' ADD own_column INT'], [$table => $create],
                archive: [$table => $create], visible: false, triggerVisible: false,
                triggers: [['TRIGGER_SCHEMA' => 'target', 'TRIGGER_NAME' => 'old_trigger',
                    'EVENT_OBJECT_SCHEMA' => 'target', 'EVENT_OBJECT_TABLE' => $table]]));
        }
    }

    /** @param string $table Bootstrap owner that a conditional CREATE can leave in place */
    #[DataProvider('conditionalBootstrapOwners')]
    public function testConditionalBootstrapCreateDoesNotProveTriggerRemoval(string $table): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('bootstrap UPDATE effects');
        $this->plan([2 => 'ALTER TABLE ' . $table . ' ADD own_column INT'], [$table => 'CREATE TABLE ' . $table . '(id INT)'],
            archive: [$table => 'CREATE TABLE IF NOT EXISTS ' . $table . '(id INT)'], visible: false, triggerVisible: true,
            triggers: [['TRIGGER_SCHEMA' => 'target', 'TRIGGER_NAME' => 'surviving_trigger',
                'EVENT_OBJECT_SCHEMA' => 'target', 'EVENT_OBJECT_TABLE' => $table]]);
    }

    /** @return list<array{string}> Non-marker owners whose import must establish replacement before ignoring old triggers */
    public static function conditionalBootstrapOwners(): array
    {
        return [['hilos_migration_claim'], ['hilos_admin_view_mode_latch']];
    }

    /** A trigger belonging to archive data is already removed by its table import. */
    public function testConditionalTriggerDropOnArchiveOwnerIsAdmitted(): void
    {
        $this->assertSame([], $this->plan([2 => 'DROP TRIGGER IF EXISTS target.carried_trigger'], [],
            archive: ['carried' => 'CREATE TABLE carried(id INT)'], visible: false, triggerVisible: true,
            triggers: [['TRIGGER_SCHEMA' => 'target', 'TRIGGER_NAME' => 'carried_trigger',
                'EVENT_OBJECT_SCHEMA' => 'target', 'EVENT_OBJECT_TABLE' => 'carried']]));
    }

    /** Schema-wide trigger visibility proves absence without granting server-wide cleanup authority. */
    public function testSchemaTriggerProofAdmitsAnAbsentConditionalDrop(): void
    {
        $this->assertSame([], $this->plan([2 => 'DROP TRIGGER IF EXISTS missing'], [], visible: false, triggerVisible: true));
    }

    /** A schema trigger grant never replaces global visibility for target-only table cleanup. */
    public function testSchemaTriggerProofDoesNotAuthorizeCleanup(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('server-wide dependency visibility');
        $this->plan([2 => 'CREATE TABLE later(id INT)'], ['later' => 'CREATE TABLE later(id INT)'],
            visible: false, triggerVisible: true);
    }

    /** Missing trigger metadata cannot prove absence even when cleanup is empty. */
    public function testConditionalTriggerDropRequiresCompleteVisibility(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('visibility');
        $this->plan([2 => 'DROP TRIGGER IF EXISTS missing'], [], visible: false);
    }

    /** A missing conditional trigger is also safe on the legacy path without cleanup authority. */
    public function testUnknownLevelKeepsAnIndependentConditionalTriggerDrop(): void
    {
        $this->assertSame([], $this->plan([2 => 'DROP TRIGGER IF EXISTS missing'],
            ['independent' => 'CREATE TABLE independent(id INT)'], level: null));
    }

    /** A legacy archive level cannot hide direct data access to preserved rows. */
    public function testUnknownLevelDmlStillRefusesRetainedData(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => 'UPDATE independent SET id=1'], ['independent' => 'CREATE TABLE independent(id INT)'], level: null);
    }

    /** The unchanged Chat UP110 drops absent service triggers, an archive table, then old settings. */
    public function testOrdinaryChatModerationDropIsAdmitted(): void
    {
        $sql = FsPath::read(dirname(__DIR__, 3) . '/demo/chat/backend/Database/Migration/Schema/110_drop_chat_moderation.sql');
        $this->assertSame([], $this->plan([110 => $sql], [], archive: [
            'moderator_prompt_piece' => 'CREATE TABLE moderator_prompt_piece(id INT)',
            'hilos_setting' => 'CREATE TABLE hilos_setting(`key` VARCHAR(128))',
        ], level: 109, applied: [109, 110], visible: false, triggerVisible: true));
    }

    /** Exact trigger proof still protects owners and restricts the DROP to conditional prepared addresses. */
    #[DataProvider('unavailableTriggerDrops')]
    public function testSchemaTriggerProofKeepsOwnershipRefusals(string $sql, string $owner): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => $sql], ['independent' => 'CREATE TABLE independent(id INT)'],
            visible: false, triggerVisible: true,
            triggers: [['TRIGGER_SCHEMA' => 'target', 'TRIGGER_NAME' => 't',
                'EVENT_OBJECT_SCHEMA' => 'target', 'EVENT_OBJECT_TABLE' => $owner]]);
    }

    /** @return iterable<string, array{string, string}> Unsupported addresses and live owners */
    public static function unavailableTriggerDrops(): iterable
    {
        yield 'retained owner' => ['DROP TRIGGER IF EXISTS target.t', 'independent'];
        yield 'bootstrap owner' => ['DROP TRIGGER IF EXISTS target.t', 'migration'];
        yield 'unknown owner' => ['DROP TRIGGER IF EXISTS target.t', 'unproven'];
        yield 'outside destination' => ['DROP TRIGGER IF EXISTS outside.t', 'independent'];
        yield 'unconditional' => ['DROP TRIGGER target.t', 'independent'];
    }

    /** Dropping a trigger belonging to preserved rows must never bypass the table ownership proof. */
    public function testDropTriggerOnRetainedTableRefuses(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('unproven target-only');
        $this->plan([2 => 'DROP TRIGGER IF EXISTS target.retained_trigger'], ['independent' => 'CREATE TABLE independent(id INT)'],
            triggers: [['TRIGGER_SCHEMA' => 'target', 'TRIGGER_NAME' => 'retained_trigger',
                'EVENT_OBJECT_SCHEMA' => 'target', 'EVENT_OBJECT_TABLE' => 'independent']]);
    }

    /** Actual FULL rows ahead of the UP snapshot refuse even without cleanup or a sidecar level. */
    public function testActualArchiveAheadOfCodeRefusesWithoutCleanup(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('connection 0: archive at migration 3, code expects 2 (1 ahead); there is no downgrade path');
        $this->plan([1 => 'SELECT 1', 2 => 'SELECT 2'], [], level: 3, applied: [1, 2]);
    }

    /** No UP files means unknown code, while equal and older actual archive levels remain admissible. */
    public function testActualArchiveCompatibilityRetainsUnknownEqualAndOlderLevels(): void
    {
        $this->assertSame([], $this->plan([], [], level: 3));
        $this->assertSame([], $this->plan([1 => 'SELECT 1', 2 => 'SELECT 2'], [], level: 2, applied: [1, 2]));
        $this->assertSame([], $this->plan([1 => 'SELECT 1', 2 => 'SELECT 2'], [], level: 1, applied: [1, 2]));
    }

    /** A truncated declaration cannot prove a cleanup lineage, even when its parsed shape matches. */
    #[DataProvider('incompleteDeclarationModes')]
    public function testModesHidingPhysicalPropertiesRefuse(string $mode): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('SHOW CREATE');
        $this->plan([2 => 'CREATE TABLE later(id INT PRIMARY KEY)'],
            ['later' => 'CREATE TABLE later(id INT NOT NULL, PRIMARY KEY(id))'], sqlMode: $mode);
    }

    /** @return iterable<string, array{string}> Explicit suppression and non-native emulation modes */
    public static function incompleteDeclarationModes(): iterable
    {
        foreach (['NO_FIELD_OPTIONS', 'NO_TABLE_OPTIONS', 'NO_KEY_OPTIONS', 'ANSI', 'DB2', 'POSTGRESQL', 'MSSQL', 'MAXDB', 'ORACLE'] as $mode) {
            yield $mode => [$mode];
        }
        yield 'combined' => ['STRICT_TRANS_TABLES,NO_FIELD_OPTIONS,NO_ENGINE_SUBSTITUTION'];
    }

    /** Case-insensitive physical names must never let IF NOT EXISTS preserve an old target's rows. */
    #[DataProvider('unsupportedCaseModes')]
    public function testCaseInsensitiveOrUnprovenPhysicalIdentityRefuses(?int $mode): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('lower_case_table_names');
        $this->plan([2 => 'CREATE TABLE IF NOT EXISTS Later(id INT)'], ['later' => 'CREATE TABLE later(id INT)'], caseMode: $mode);
    }

    /** @return iterable<string, array{?int}> Both folding modes and unavailable evidence */
    public static function unsupportedCaseModes(): iterable
    {
        yield 'stored lowercase' => [1];
        yield 'compared lowercase' => [2];
        yield 'missing' => [null];
        yield 'unknown' => [3];
    }

    /** On mode zero distinct-case tables are independent physical objects. */
    public function testCaseSensitiveIdentityDoesNotMergeDistinctCaseTables(): void
    {
        $this->assertSame(['Later'], array_column($this->plan([2 => 'CREATE TABLE IF NOT EXISTS Later(id INT)'], [
            'Later' => 'CREATE TABLE Later(id INT)', 'later' => 'CREATE TABLE later(id BIGINT)',
        ]), 'table'));
    }

    /** ALTER ordering, primary keys, signedness, NULL/default and table properties are material. */
    #[DataProvider('structureMismatches')]
    public function testStructureMismatchRefuses(string $live): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => "CREATE TABLE later (id INT UNSIGNED PRIMARY KEY, value VARCHAR(20) NOT NULL DEFAULT 'a')"], ['later' => $live]);
    }

    /** @return iterable<string, array{string}> Essential structural differences */
    public static function structureMismatches(): iterable
    {
        foreach ([
            'type' => "id BIGINT UNSIGNED PRIMARY KEY, value VARCHAR(20) NOT NULL DEFAULT 'a'",
            'signed' => "id INT PRIMARY KEY, value VARCHAR(20) NOT NULL DEFAULT 'a'",
            'null' => "id INT UNSIGNED PRIMARY KEY, value VARCHAR(20) DEFAULT 'a'",
            'default' => "id INT UNSIGNED PRIMARY KEY, value VARCHAR(20) NOT NULL DEFAULT 'b'",
            'key' => "id INT UNSIGNED NOT NULL, value VARCHAR(20) NOT NULL DEFAULT 'a'",
            'order' => "value VARCHAR(20) NOT NULL DEFAULT 'a', id INT UNSIGNED PRIMARY KEY",
        ] as $case => $columns) {
            yield $case => ['CREATE TABLE later (' . $columns . ')'];
        }
        yield 'engine' => ["CREATE TABLE later (id INT UNSIGNED PRIMARY KEY, value VARCHAR(20) NOT NULL DEFAULT 'a') ENGINE=MyISAM"];
        yield 'collation' => ["CREATE TABLE later (id INT UNSIGNED PRIMARY KEY, value VARCHAR(20) NOT NULL DEFAULT 'a') COLLATE=utf8mb4_bin"];
        yield 'row format' => ["CREATE TABLE later (id INT UNSIGNED PRIMARY KEY, value VARCHAR(20) NOT NULL DEFAULT 'a') ROW_FORMAT=COMPACT"];
    }

    /** Target history needs the exact successful CREATE row, not just a higher boundary. */
    public function testUnappliedCreateDoesNotAuthorizeCleanup(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => 'CREATE TABLE later (id INT)'], ['later' => 'CREATE TABLE later (id INT)'], applied: [1]);
    }

    /** The exact successful CREATE must execute again, rather than become a skipped conditional declaration. */
    public function testFutureSkippedCreateCannotClaimRecreation(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => 'CREATE TABLE later(id INT)', 3 => 'CREATE TABLE IF NOT EXISTS later(id INT)'],
            ['later' => 'CREATE TABLE later(id INT)'], applied: [3]);
    }

    /** SHOW CREATE can omit an inherited row format; physical metadata must still match. */
    public function testPhysicalRowFormatIsPartOfProvenance(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => 'CREATE TABLE later(id INT)'], ['later' => 'CREATE TABLE later(id INT)'], rowFormat: 'Compact');
    }

    /** Failed history and sidecar disagreement are independent refusals. */
    public function testFailedAndContradictoryHistoryRefuse(): void
    {
        foreach ([true, false] as $failed) {
            try {
                $this->plan([2 => 'CREATE TABLE later (id INT)'], ['later' => 'CREATE TABLE later (id INT)'],
                    sidecar: $failed ? null : 0, failed: $failed);
                $this->fail('History cannot prove cleanup');
            } catch (RestoreFailedException $failure) {
                $this->assertFalse($failure->databaseTouched());
            }
        }
    }

    /** Unknown legacy levels keep the compatible independent path and refuse overlapping late objects. */
    public function testUnknownLevelIsNeverZero(): void
    {
        $this->assertSame([], $this->plan([2 => 'CREATE TABLE later (id INT)'], ['independent' => 'CREATE TABLE independent (id INT)'], level: null));
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => 'CREATE TABLE later (id INT)'], ['later' => 'CREATE TABLE later (id INT)'], level: null);
    }

    /** A CREATE cannot be repaired by deleting data that came from the archive. */
    public function testArchiveCreateCollisionRefuses(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => 'CREATE TABLE carried (id INT)'], [], archive: ['carried' => 'CREATE TABLE carried (id INT)']);
    }

    /** A conditional CREATE may retain archive rows; it must never turn them into cleanup candidates. */
    public function testIfNotExistsPreservesArchiveLineage(): void
    {
        $this->assertSame([], $this->plan([2 => 'CREATE TABLE IF NOT EXISTS carried(id INT)'],
            ['carried' => 'CREATE TABLE carried(id INT)'], archive: ['carried' => 'CREATE TABLE carried(id INT)']));
    }

    /** A normal UP drop does not grant cleanup rights over any other object. */
    public function testOrdinaryArchiveDropPreservesIndependentAndBootstrapObjects(): void
    {
        $live = ['independent' => 'CREATE TABLE independent (id INT)'];
        foreach (['migration', 'hilos_migration_claim', 'hilos_admin_view_mode_latch', 'hilos_database_marker'] as $table) {
            $live[$table] = 'CREATE TABLE ' . $table . ' (id INT)';
        }
        $this->assertSame([], $this->plan([2 => 'DROP TABLE carried'], $live, archive: ['carried' => 'CREATE TABLE carried (id INT)']));
    }

    /** An ordinary archive DROP still validates the unrelated JSON parent's full FK structure, without cleanup. */
    public function testArchiveJsonParentRemainsProvableDuringOrdinaryDrop(): void
    {
        $this->assertSame([], $this->plan([2 => 'DROP TABLE moderator_prompt_piece'], [], archive: [
            'moderator_prompt_piece' => 'CREATE TABLE moderator_prompt_piece(id INT PRIMARY KEY)',
            'hilos_analytics_payload_json' => self::JSON_PARENT,
            'hilos_analytics_agent_cron_signal' => self::JSON_CHILD,
        ]));
    }

    /**
     * @param ?string $parent Archived parent, or its proven absence
     * @param string $child Archived child FK
     * @param string $reason Specific missing FK fact, beyond parsing CHECK
     */
    #[DataProvider('invalidJsonParentReferences')]
    public function testJsonCheckNeverRelaxesArchiveForeignKeyProof(?string $parent, string $child, string $reason): void
    {
        $archive = ['moderator_prompt_piece' => 'CREATE TABLE moderator_prompt_piece(id INT PRIMARY KEY)',
            'hilos_analytics_agent_cron_signal' => $child];
        if ($parent !== null) {
            $archive['hilos_analytics_payload_json'] = $parent;
        }
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage($reason);
        $this->plan([2 => 'DROP TABLE moderator_prompt_piece'], [], archive: $archive);
    }

    /** @return iterable<string, array{?string, string, string}> Each FK requirement remains independently necessary */
    public static function invalidJsonParentReferences(): iterable
    {
        yield 'absent parent' => [null, self::JSON_CHILD, 'archive references absent target.hilos_analytics_payload_json'];
        yield 'absent parent column' => [str_replace('`id`', '`other_id`', self::JSON_PARENT), self::JSON_CHILD, 'parent columns'];
        yield 'signed parent' => [str_replace('bigint(20) unsigned NOT NULL AUTO_INCREMENT', 'bigint(20) NOT NULL AUTO_INCREMENT',
            self::JSON_PARENT), self::JSON_CHILD, 'column types or collations'];
        yield 'parent type' => [str_replace('bigint(20)', 'int(11)', self::JSON_PARENT), self::JSON_CHILD, 'column types or collations'];
        yield 'missing parent index' => [str_replace("  PRIMARY KEY (`id`),\n", '', self::JSON_PARENT), self::JSON_CHILD, 'supporting index'];
        yield 'non-btree parent index' => [str_replace('PRIMARY KEY (`id`)', 'PRIMARY KEY USING HASH (`id`)', self::JSON_PARENT),
            self::JSON_CHILD, 'supporting index'];
        yield 'parent engine' => [str_replace('ENGINE=InnoDB', 'ENGINE=MyISAM', self::JSON_PARENT), self::JSON_CHILD, 'parent columns'];
        yield 'outside schema' => [self::JSON_PARENT,
            str_replace('REFERENCES `hilos_analytics_payload_json`', 'REFERENCES outside.`hilos_analytics_payload_json`', self::JSON_CHILD),
            'archive references absent outside.hilos_analytics_payload_json'];
        $textParent = str_replace('`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT',
            '`id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL', self::JSON_PARENT);
        yield 'parent collation' => [$textParent,
            str_replace('`payload_json_id` bigint(20) unsigned', '`payload_json_id` varchar(20) COLLATE utf8mb4_general_ci', self::JSON_CHILD),
            'column types or collations'];
        yield 'parent charset' => [$textParent,
            str_replace('`payload_json_id` bigint(20) unsigned', '`payload_json_id` varchar(20) CHARACTER SET ascii', self::JSON_CHILD),
            'column types or collations'];
    }

    /** @param array<int, string> $source Authored JSON in either CREATE or a later ADD */
    #[DataProvider('authoredJsonLineages')]
    public function testMariaDbJsonAliasProvesCleanupAgainstPhysicalLongtext(array $source): void
    {
        $cleanup = $this->plan($source, ['hilos_analytics_payload_json' => self::JSON_PARENT], mariaDbJsonAlias: true);
        $this->assertSame(['hilos_analytics_payload_json'], array_column($cleanup, 'table'));
        $this->assertSame([DatabaseConnectionDefaults::PRIMARY_INDEX], array_column($cleanup, 'index'));
    }

    /** @return iterable<string, array{array<int, string>}> Both entry points use the same proven column normalization */
    public static function authoredJsonLineages(): iterable
    {
        yield 'create' => [[2 => str_replace(
            'longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload_json`))', 'JSON NOT NULL',
            self::JSON_PARENT,
        )]];
        yield 'alter add' => [[
            2 => str_replace(
                "  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload_json`)),\n",
                '', self::JSON_PARENT,
            ),
            3 => 'ALTER TABLE hilos_analytics_payload_json ADD payload_json JSON NOT NULL AFTER sha1_hash',
        ]];
    }

    /** MySQL native JSON and unknown family evidence cannot authorize alias cleanup. */
    public function testJsonLineageWithoutMariaDbEvidenceRefuses(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->expectExceptionMessage('proven MariaDB alias');
        $this->plan([2 => 'CREATE TABLE later (payload_json JSON)'],
            ['later' => 'CREATE TABLE later (payload_json LONGTEXT COLLATE utf8mb4_bin CHECK (json_valid(payload_json)))']);
    }

    /**
     * A missing, additional or changed CHECK cannot turn the target's rows into proven cleanup.
     *
     * @param string $source Proven source CREATE
     * @param string $live Actual target definition
     * @param bool $mariaDbJsonAlias Explicit family evidence for authored JSON fixtures
     */
    #[DataProvider('jsonCheckMismatches')]
    public function testJsonCheckIsSignificantForCleanup(string $source, string $live, bool $mariaDbJsonAlias = false): void
    {
        $this->expectException(RestoreFailedException::class);
        if ($mariaDbJsonAlias) {
            $this->expectExceptionMessage('live structure differs');
        }
        $this->plan([2 => $source], ['hilos_analytics_payload_json' => $live], mariaDbJsonAlias: $mariaDbJsonAlias);
    }

    /** @return iterable<string, array> Constraint and collation changes never disappear from provenance */
    public static function jsonCheckMismatches(): iterable
    {
        $missing = str_replace(' CHECK (json_valid(`payload_json`))', '', self::JSON_PARENT);
        yield 'missing live check' => [self::JSON_PARENT, $missing];
        yield 'additional live check' => [$missing, self::JSON_PARENT];
        yield 'changed expression' => [self::JSON_PARENT, str_replace('json_valid(`payload_json`)',
            'json_valid(`payload_json`) OR 1', self::JSON_PARENT)];
        $alias = str_replace('longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload_json`))',
            'JSON NOT NULL', self::JSON_PARENT);
        yield 'alias missing live check' => [$alias, $missing, true];
        yield 'alias wrong live collation' => [$alias, str_replace('COLLATE utf8mb4_bin', 'COLLATE utf8mb4_general_ci',
            self::JSON_PARENT), true];
    }

    /** A successful RENAME keeps the original CREATE provenance, while future UP still starts at the old name. */
    public function testRenamePreservesCreatedLineage(): void
    {
        $this->assertSame(['renamed'], array_column($this->plan([
            2 => 'CREATE TABLE later (id INT)', 3 => 'RENAME TABLE later TO renamed',
        ], ['renamed' => 'CREATE TABLE renamed (id INT)']), 'table'));
    }

    /** A renamed archive table still carries archived data and never becomes a cleanup candidate. */
    public function testRenamedArchiveLineageCannotBeDeleted(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => 'RENAME TABLE carried TO renamed'], ['renamed' => 'CREATE TABLE renamed (id INT)'],
            archive: ['carried' => 'CREATE TABLE carried (id INT)']);
    }

    /** The future queue must not leave a dangling FK by dropping its parent first. */
    public function testFutureDropCannotBreakAnArchiveReference(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => 'DROP TABLE parent'], [], archive: [
            'parent' => 'CREATE TABLE parent (id INT PRIMARY KEY)',
            'child' => 'CREATE TABLE child (id INT, parent INT, CONSTRAINT fk FOREIGN KEY(parent) REFERENCES parent(id))',
        ]);
    }

    /** Cycles refuse and even permitting FK actions impose a child-before-parent order. */
    public function testForeignKeysOrderChildrenBeforeParentsAndCyclesRefuse(): void
    {
        $sql = [2 => 'CREATE TABLE parent (id INT PRIMARY KEY)',
            3 => 'CREATE TABLE child (id INT PRIMARY KEY, CONSTRAINT child_fk FOREIGN KEY(id) REFERENCES parent(id) ON DELETE CASCADE)'];
        $live = ['parent' => $sql[2], 'child' => $sql[3]];
        $fk = $this->fk('child', 'parent', 'CASCADE');
        $this->assertSame(['child', 'parent'], array_column($this->plan($sql, $live, foreignKeys: [$fk]), 'table'));
        $sql[4] = 'ALTER TABLE parent ADD CONSTRAINT parent_fk FOREIGN KEY(id) REFERENCES child(id)';
        $live['parent'] = 'CREATE TABLE parent (id INT PRIMARY KEY, CONSTRAINT parent_fk FOREIGN KEY(id) REFERENCES child(id))';
        $this->expectException(RestoreFailedException::class);
        $this->plan($sql, $live, foreignKeys: [$fk, $this->fk('parent', 'child', 'RESTRICT')]);
    }

    /** External incoming links forbid cleanup independently of ON DELETE behavior. */
    #[DataProvider('fkRules')]
    public function testIncomingPreservedOrExternalFkRefuses(string $rule): void
    {
        $fk = $this->fk('preserved', 'later', $rule);
        $fk['TABLE_SCHEMA'] = 'outside';
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => 'CREATE TABLE later (id INT)'], ['later' => 'CREATE TABLE later (id INT)'], foreignKeys: [$fk]);
    }

    /** @return iterable<array{string}> Restricting and permitting rules */
    public static function fkRules(): iterable
    {
        yield ['RESTRICT'];
        yield ['CASCADE'];
        yield ['SET NULL'];
    }

    /** A filtered catalog is never evidence that no incoming link exists. */
    public function testInsufficientVisibilityRefuses(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => 'CREATE TABLE later (id INT)'], ['later' => 'CREATE TABLE later (id INT)'], visible: false);
    }

    /** Views, triggers and opaque operations cannot silently lose dependencies. */
    #[DataProvider('opaqueDependencies')]
    public function testDependentOrOpaqueObjectsRefuse(array $views, array $triggers, string $sql): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => $sql], ['later' => 'CREATE TABLE later (id INT)'], views: $views, triggers: $triggers);
    }

    /** @return iterable<string, array{array, array, string}> Dependent and opaque definitions */
    public static function opaqueDependencies(): iterable
    {
        yield 'view' => [[['TABLE_SCHEMA' => 'outside', 'TABLE_NAME' => 'v', 'VIEW_DEFINITION' => 'select id from target.later']],
            [], 'CREATE TABLE later(id INT)'];
        yield 'hidden view' => [[['TABLE_SCHEMA' => 'outside', 'TABLE_NAME' => 'v', 'VIEW_DEFINITION' => null]], [], 'CREATE TABLE later(id INT)'];
        yield 'trigger' => [[], [['EVENT_OBJECT_SCHEMA' => 'target', 'EVENT_OBJECT_TABLE' => 'later', 'TRIGGER_NAME' => 't']],
            'CREATE TABLE later(id INT)'];
        yield 'opaque UP' => [[], [], 'CREATE TABLE later LIKE old'];
    }

    /** Named keys and FK actions are structural, not just a set of column names. */
    public function testForeignKeyAndIndexShapesAreCompared(): void
    {
        $expected = TableStructure::read('CREATE TABLE t (id INT PRIMARY KEY, owner INT, '
            . 'CONSTRAINT fk FOREIGN KEY(owner) REFERENCES p(id) ON DELETE CASCADE)', 'target', $this->defaults);
        $actual = TableStructure::read('CREATE TABLE t (id INT NOT NULL, owner INT DEFAULT NULL, PRIMARY KEY(id), KEY fk(owner),'
            . ' CONSTRAINT fk FOREIGN KEY(owner) REFERENCES p(id) ON DELETE CASCADE)', 'target', $this->defaults);
        $this->assertSame($expected->fingerprint(), $actual->fingerprint());
        $different = TableStructure::read('CREATE TABLE t (id INT PRIMARY KEY, owner INT, '
            . 'CONSTRAINT fk FOREIGN KEY(owner) REFERENCES p(id) ON DELETE SET NULL)', 'target', $this->defaults);
        $this->assertNotSame($expected->fingerprint(), $different->fingerprint());
    }

    /**
     * @param int $primaryLevel Actual shared archive history
     * @param int $journalLevel Journal-local rows, which never define the source level
     * @param bool $ahead Whether the primary archive exceeds the shared UP track
     */
    #[DataProvider('sharedArchiveLevels')]
    public function testPrimaryJournalQueueIsAppliedOnceByPhysicalSchema(int $primaryLevel, int $journalLevel, bool $ahead): void
    {
        $primaryIndex = DatabaseConnectionDefaults::PRIMARY_INDEX;
        $journalIndex = ChangeLogDatabase::CONNECTION_INDEX;
        $targets = [];
        foreach ([$primaryIndex => 'target', $journalIndex => 'target-change-log'] as $index => $database) {
            Database::configure($index, 'unused', 'unused', '', $database);
            $dump = new Dump([], new MigrationHistory(MigrationHistoryState::RECORDED,
                [new MigrationRow($index === $primaryIndex ? $primaryLevel : $journalLevel, false)]));
            $targets[$index] = new PreparedTarget(new BackupConnectionMeta($index, 'source', null), Database::getConnectionConfig($index),
                json_encode(['server', DatabaseConnectionDefaults::PORT, 1, $database]), 'source', 'digest', 'staged', 'digest', $dump);
        }
        $path = $this->root . '/002_pair.sql';
        FsPath::write($path, "CREATE TABLE target.same_name (id INT);\nCREATE TABLE {{change_log_database}}.same_name (id BIGINT);\n"
            . "INSERT INTO {{change_log_database}}.same_name(id) SELECT p.id FROM target.same_name p;\n");
        $file = MigrationUpFile::read(2, $path);
        $track = new MigrationUpTrack($this->root, 'Schema', ['002_pair.sql'], [$file]);
        $pending = [];
        foreach ($file->statements as $position => $sql) {
            $pending[] = new MigrationStep($file, $position, MigrationOperationReader::read($sql, 'target')[0]);
        }
        $evidence = [];
        $schemas = [];
        foreach ($targets as $index => $target) {
            $facts = MigrationFacts::read($targets[$primaryIndex]->dump->migrationHistory,
                new DatabaseMigrationHistory([new MigrationRecord(1, false), new MigrationRecord(2, false)]), $track, null);
            $evidence[$index] = new MigrationTargetEvidence(MigrationBinding::resolve($target, array_values($targets)), $facts, $pending);
            $schemas[$index] = new SchemaMetadata('server', ['same_name' => new LiveObject('BASE TABLE',
                $index === $primaryIndex ? 'CREATE TABLE same_name (id INT)' : 'CREATE TABLE same_name (id BIGINT)')],
                [['lower_case_table_names' => 0]], [], [], $this->defaults);
        }
        $snapshot = new FullRestoreSchemaSnapshot($schemas, ['server' => new ServerMetadata(true, [], [], [], [], [], [])]);
        if ($ahead) {
            $this->expectException(RestoreFailedException::class);
            $this->expectExceptionMessage('connection 0: archive at migration 3, code expects 2');
        }
        $cleanup = FullRestorePlanner::prove($evidence, $track, $snapshot);
        $this->assertSame([$primaryIndex, $journalIndex], array_column($cleanup, 'index'));
        $this->assertSame(['same_name', 'same_name'], array_column($cleanup, 'table'));
    }

    /** @return iterable<string, array{int, int, bool}> Primary defines the actual level for both destinations */
    public static function sharedArchiveLevels(): iterable
    {
        yield 'known primary' => [1, 1, false];
        yield 'primary ahead' => [3, 1, true];
        yield 'journal rows are not the source' => [1, 3, false];
    }

    /**
     * @param string $expression Scalar expression in a qualified journal INSERT
     * @param string $routineSchema Physical schema containing the same-named stored function
     * @param string $routineName Stored name, possibly a builtin homonym in another schema
     * @param bool $refused Whether the actual execution source can reach that stored function
     */
    #[DataProvider('sharedSourceCalls')]
    public function testJournalDmlCallsUseThePrimaryExecutionSchema(
        string $expression,
        string $routineSchema,
        string $routineName,
        bool $refused,
    ): void {
        $targets = [];
        foreach ([DatabaseConnectionDefaults::PRIMARY_INDEX => 'target', ChangeLogDatabase::CONNECTION_INDEX => 'target-change-log']
            as $index => $database) {
            Database::configure($index, 'unused', 'unused', '', $database);
            $dump = new Dump(['carried' => MigrationOperationReader::read('CREATE TABLE carried(id INT)', $database)[0]->definition],
                new MigrationHistory(MigrationHistoryState::RECORDED, [new MigrationRow(1, false)]));
            $targets[$index] = new PreparedTarget(new BackupConnectionMeta($index, 'source', null), Database::getConnectionConfig($index),
                json_encode(['server', DatabaseConnectionDefaults::PORT, 1, $database]), 'source', 'digest', 'staged', 'digest', $dump);
        }
        $path = $this->root . '/002_journal_call.sql';
        FsPath::write($path, 'INSERT INTO {{change_log_database}}.carried(id) VALUES(' . $expression . ");\n");
        $file = MigrationUpFile::read(2, $path);
        $track = new MigrationUpTrack($this->root, 'Schema', ['002_journal_call.sql'], [$file]);
        $step = new MigrationStep($file, 0, MigrationOperationReader::read($file->statements[0], 'target')[0]);
        $evidence = [];
        $schemas = [];
        foreach ($targets as $index => $target) {
            $facts = MigrationFacts::read($targets[DatabaseConnectionDefaults::PRIMARY_INDEX]->dump->migrationHistory,
                new DatabaseMigrationHistory([new MigrationRecord(1, false), new MigrationRecord(2, false)]), $track, null);
            $evidence[$index] = new MigrationTargetEvidence(MigrationBinding::resolve($target, array_values($targets)), $facts, [$step]);
            $schemas[$index] = new SchemaMetadata('server', ['independent' => new LiveObject('BASE TABLE',
                'CREATE TABLE independent(id INT)')], [['lower_case_table_names' => 0]], [], [], $this->defaults);
        }
        $routine = ['ROUTINE_SCHEMA' => $routineSchema, 'ROUTINE_NAME' => $routineName, ServerMetadata::CREATE_SQL => null];
        $snapshot = new FullRestoreSchemaSnapshot($schemas, ['server' => new ServerMetadata(true, [], [], [], [], [$routine], [])]);
        if ($refused) {
            $this->expectException(RestoreFailedException::class);
            $this->expectExceptionMessage($routineName);
        }
        $this->assertSame([], FullRestorePlanner::prove($evidence, $track, $snapshot));
    }

    /** @return iterable<string, array{string, string, string, bool}> Destination qualification never changes the source's default schema */
    public static function sharedSourceCalls(): iterable
    {
        yield 'primary function in journal write' => ['touch_kept()', 'target', 'touch_kept', true];
        yield 'qualified journal function' => ['`target-change-log`.touch_kept()', 'target-change-log', 'touch_kept', true];
        yield 'builtin with journal homonym' => ['ABS(-2)', 'target-change-log', 'ABS', false];
    }

    /** A same-named referenced table on another server is not an incoming dependency of the cleanup target. */
    public function testPhysicalServersDoNotShareSchemaOrDependencyNames(): void
    {
        $path = $this->root . '/002_later.sql';
        FsPath::write($path, "CREATE TABLE later(id INT);\n");
        $file = MigrationUpFile::read(2, $path);
        $track = new MigrationUpTrack($this->root, 'Schema', ['002_later.sql'], [$file]);
        $targets = [];
        $live = new LiveObject('BASE TABLE', 'CREATE TABLE later(id INT)');
        foreach ([DatabaseConnectionDefaults::PRIMARY_INDEX => 1, 2 => 2] as $index => $level) {
            Database::configure($index, 'unused', 'unused', '', 'target');
            $dump = new Dump($level === 1 ? [] : ['later' => MigrationOperationReader::read($live->createSql, 'target')[0]->definition],
                new MigrationHistory(MigrationHistoryState::RECORDED, [new MigrationRow($level, false)]));
            $targets[$index] = new PreparedTarget(new BackupConnectionMeta($index, 'source', null), Database::getConnectionConfig($index),
                json_encode(['server-' . $index, DatabaseConnectionDefaults::PORT, 1, 'target']), 'source', 'digest', 'staged', 'digest', $dump);
        }
        $evidence = [];
        $schemas = [];
        foreach ($targets as $index => $target) {
            $facts = MigrationFacts::read($target->dump->migrationHistory,
                new DatabaseMigrationHistory([new MigrationRecord(1, false), new MigrationRecord(2, false)]), $track, null);
            $pending = $facts->archiveLevel === 1 ? [new MigrationStep($file, 0, MigrationOperationReader::read($live->createSql, 'target')[0])] : [];
            $evidence[$index] = new MigrationTargetEvidence(MigrationBinding::resolve($target, array_values($targets)), $facts, $pending);
            $schemas[$index] = new SchemaMetadata('server-' . $index, ['later' => $live], [['lower_case_table_names' => 0]], [], [], $this->defaults);
        }
        $snapshot = new FullRestoreSchemaSnapshot($schemas, [
            'server-0' => new ServerMetadata(true, [], [], [], [], [], []),
            'server-2' => new ServerMetadata(true, [], [$this->fk('preserved', 'later', 'CASCADE')], [], [], [], []),
        ]);
        $this->assertSame([DatabaseConnectionDefaults::PRIMARY_INDEX],
            array_column(FullRestorePlanner::prove($evidence, $track, $snapshot), 'index'));
    }

    /** A view's indirect table dependency through a stored function must be traced. */
    public function testTransitiveStoredFunctionDependencyIsTraced(): void
    {
        $routine = ['ROUTINE_SCHEMA' => 'outside', 'ROUTINE_NAME' => 'lookup', 'SQL_MODE' => '',
            ServerMetadata::CREATE_SQL => 'CREATE FUNCTION lookup() RETURNS INT RETURN (SELECT id FROM target.later)'];
        $proof = new DependencyProof([new TableName('target', 'later')], [$routine]);
        $proof->assertIndependent('SELECT 1', '', 'independent view');
        $this->expectException(RestoreFailedException::class);
        $proof->assertIndependent('SELECT outside.lookup()', '', 'dependent view');
    }

    /** An uncalled parameterized procedure has no fixed binding; executing its dynamic body needs proof. */
    public function testUncalledDynamicProcedureIsSeparateFromItsInvocation(): void
    {
        $proof = new DependencyProof([new TableName('target', 'later')], []);
        $sql = 'CREATE PROCEDURE helper() BEGIN PREPARE s FROM @sql; EXECUTE s; END';
        $proof->assertIndependent($sql, '', 'stored helper', false);
        $this->expectException(RestoreFailedException::class);
        $proof->assertIndependent($sql, '', 'reachable helper');
    }

    /** Native implementations are not SQL routines and cannot establish a readable dependency proof. */
    public function testOpaqueNativeFunctionDependencyRefuses(): void
    {
        $proof = new DependencyProof([new TableName('target', 'later')], [], ['native_lookup']);
        $this->expectException(RestoreFailedException::class);
        $proof->assertIndependent('SELECT native_lookup()', '', 'view using native code');
    }

    /** Dependency definitions use the same in-memory refusal boundary as migration UP. */
    public function testDependencyProofRejectsLegacyPhysicalIdentifiers(): void
    {
        $proof = new DependencyProof([], []);

        $this->expectException(RestoreFailedException::class);
        $proof->assertIndependent('SELECT id FROM `#mysql50#independent`', '', 'legacy dependency');
    }

    /** Direct structure parsing refuses legacy identifiers without a destination server context. */
    public function testStructureTokensRejectLegacyPhysicalIdentifiers(): void
    {
        $this->expectException(RestoreFailedException::class);
        TableStructure::tokens('CREATE TABLE `#mysql50#ordinary` (id INT)');
    }

    /** Column placement and ordinary CHANGE/MODIFY/DROP replay preserve a semantic structure, not raw CREATE text. */
    public function testAlterReplaysColumnsAndIndexChanges(): void
    {
        $structure = TableStructure::read('CREATE TABLE t (id INT PRIMARY KEY, old_name INT, removed INT)', 'target', $this->defaults);
        $structure->alter(MigrationOperationReader::read('ALTER TABLE t ADD first_column VARCHAR(8) FIRST, '
            . 'CHANGE old_name new_name BIGINT AFTER id, MODIFY removed BIGINT, DROP COLUMN removed, '
            . 'ADD KEY ix(new_name), RENAME INDEX ix TO renamed_ix', 'target')[0]);
        $expected = TableStructure::read('CREATE TABLE t (first_column VARCHAR(8), id INT PRIMARY KEY, new_name BIGINT, KEY renamed_ix(new_name))',
            'target', $this->defaults);
        $this->assertSame($expected->fingerprint(), $structure->fingerprint());
    }

    /** A duplicate live history row is contradictory evidence even when both rows claim success. */
    public function testDuplicateLiveRowsRefuse(): void
    {
        $this->expectException(RestoreFailedException::class);
        $this->plan([2 => 'CREATE TABLE later(id INT)'], ['later' => 'CREATE TABLE later(id INT)'], applied: [1, 2, 2]);
    }

    /**
     * @param array<int, string> $sql Migration source text split by the shared execution-unit reader
     * @param array<string, string> $live Realistic SHOW CREATE definitions
     * @param array<string, string> $archive Dump CREATE declarations
     * @param ?list<int> $applied Exact successful live rows; null means all fixture files
     * @param ?int $level Actual archive level; null is a legacy archive
     * @param ?int $sidecar Independent optional sidecar level
     * @param bool $failed Whether an additional live failed row exists
     * @param list<array<string, mixed>> $foreignKeys Physical FK metadata
     * @param bool $visible Full visibility verdict
     * @param list<array<string, mixed>> $views Physical view metadata
     * @param list<array<string, mixed>> $triggers Physical trigger metadata
     * @param ?string $rowFormat Physical row format, which SHOW CREATE may omit
     * @param string $sqlMode Actual metadata session mode
     * @param ?int $caseMode Physical identifier mode, or missing metadata
     * @param list<array<string, mixed>> $routines Physical stored routine catalog, including hidden declarations
     * @param list<array<string, mixed>> $functions Opaque native UDF catalog
     * @param ?bool $triggerVisible Schema trigger visibility; defaults to the fixture's global verdict
     * @param bool $mariaDbJsonAlias Explicit destination evidence for alias-specific cases only
     * @param list<array<string, mixed>> $columns Schema-local physical column snapshot
     * @return array Proven cleanup
     */
    private function plan(
        array $sql,
        array $live,
        array $archive = [],
        ?array $applied = null,
        ?int $level = 1,
        ?int $sidecar = null,
        bool $failed = false,
        array $foreignKeys = [],
        bool $visible = true,
        array $views = [],
        array $triggers = [],
        ?string $rowFormat = null,
        string $sqlMode = '',
        ?int $caseMode = 0,
        array $routines = [],
        array $functions = [],
        ?bool $triggerVisible = null,
        bool $mariaDbJsonAlias = false,
        array $columns = [],
    ): array {
        $index = DatabaseConnectionDefaults::PRIMARY_INDEX;
        Database::configure($index, 'unused', 'unused', '', 'target');
        $objects = [];
        foreach ($archive as $name => $create) {
            $objects[$name] = MigrationOperationReader::read($create, 'target')[0]->definition;
        }
        $dump = new Dump($objects, new MigrationHistory($level === null ? MigrationHistoryState::ABSENT : MigrationHistoryState::RECORDED,
            $level === null ? [] : [new MigrationRow($level, false)]));
        $target = new PreparedTarget(new BackupConnectionMeta($index, 'source', $sidecar), Database::getConnectionConfig($index),
            json_encode(['server', DatabaseConnectionDefaults::PORT, 1, 'target']), 'source', 'digest', 'staged', 'digest', $dump);
        $files = [];
        $pending = [];
        foreach ($sql as $number => $statement) {
            $path = $this->root . '/' . $number . '.sql';
            FsPath::write($path, $statement . ";\n");
            $file = MigrationUpFile::read($number, $path);
            $files[] = $file;
            foreach ($file->statements as $position => $unit) {
                foreach (MigrationOperationReader::read($unit, 'target') as $operation) {
                    $pending[] = new MigrationStep($file, $position, $operation);
                }
            }
        }
        $track = new MigrationUpTrack($this->root, 'Schema', array_map('basename', array_column($files, 'path')), $files);
        $rows = array_map(static fn (int $number): MigrationRecord => new MigrationRecord($number, false), $applied ?? [1, ...array_keys($sql)]);
        if ($failed) {
            $rows[] = new MigrationRecord(99, true);
        }
        $facts = MigrationFacts::read($dump->migrationHistory, new DatabaseMigrationHistory($rows), $track, $sidecar);
        $pending = array_values(array_filter($pending,
            static fn (MigrationStep $step): bool => $level === null || $step->file->index > $level));
        $evidence = [$index => new MigrationTargetEvidence(MigrationBinding::resolve($target, [$target]), $facts, $pending)];
        $metadata = [];
        foreach ($live as $name => $create) {
            $metadata[$name] = new LiveObject('BASE TABLE', $create, $rowFormat);
        }
        foreach ($views as &$view) {
            $view[ServerMetadata::CREATE_SQL] = $view['VIEW_DEFINITION'];
        }
        unset($view);
        $defaults = new StructureDefaults('InnoDB', 'utf8mb4', 'utf8mb4_general_ci',
            ['utf8mb4' => 'utf8mb4_general_ci', 'ascii' => 'ascii_general_ci'], $sqlMode,
            charsetByCollation: ['utf8mb4_bin' => 'utf8mb4', 'utf8mb4_0900_ai_ci' => 'utf8mb4'], mariaDbJsonAlias: $mariaDbJsonAlias);
        $snapshot = new FullRestoreSchemaSnapshot([$index => new SchemaMetadata('server', $metadata,
            $caseMode === null ? [] : [['lower_case_table_names' => $caseMode]], $columns, [], $defaults,
            completeTriggerVisibility: $triggerVisible ?? $visible, triggers: $triggers)],
            ['server' => new ServerMetadata($visible, [], $foreignKeys, $views, $triggers, $routines, [], $functions)]);
        return FullRestorePlanner::prove($evidence, $track, $snapshot);
    }

    /**
     * @param string $child Child table in the target schema
     * @param string $parent Referenced cleanup candidate
     * @param string $rule ON DELETE action
     * @return array<string, string> Physical dependency row
     */
    private function fk(string $child, string $parent, string $rule): array
    {
        return ['TABLE_SCHEMA' => 'target', 'TABLE_NAME' => $child, 'CONSTRAINT_NAME' => 'fk_' . $child,
            'REFERENCED_TABLE_SCHEMA' => 'target', 'REFERENCED_TABLE_NAME' => $parent, 'DELETE_RULE' => $rule];
    }
}
