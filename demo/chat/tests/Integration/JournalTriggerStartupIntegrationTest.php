<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Backup\Anonymization\LiveTableSchema;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalTriggerColumnTypes;
use Hilos\Database\ChangeLog\ChangeLogPartitions;
use Hilos\Database\ChangeLog\JournalTriggerFile;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerGenerator;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\ChangeLog\JournalTriggerRenderer;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\MigrationClaim;
use Hilos\Database\MigrationClaimHolder;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\JournalColumnMode;
use Hilos\Database\Schema\JournalColumnPlacement;
use Hilos\Hilos as HilosFacade;
use mysqli;
use PHPUnit\Framework\Attributes\DataProvider;

/** Exercises complete preflight and reconciliation against the live MariaDB catalog. */
final class JournalTriggerStartupIntegrationTest extends IntegrationTestCase
{
    private const string PROBE_TABLE = 'hilos_trigger_startup_probe';

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_field', 'hilos_change_log_table',
    ];

    private string $path;

    private ?DbContext $originalContext;

    private ?int $fixtureMigration = null;

    /** @var array<string, int> Journal row ids before the case */
    private array $journalBaseline = [];

    /** @var list<JournalTriggerFile> */
    private array $plan;

    /** @var list<array<string, mixed>> Original trigger metadata restored after each case */
    private array $original;

    /** Prepares isolated source files and remembers the database's original trigger set. */
    protected function setUp(): void
    {
        parent::setUp();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        $this->path = sys_get_temp_dir() . '/hilos-trigger-startup-' . getmypid();
        mkdir($this->path);
        JournalTriggerFiles::setPath($this->path);
        $this->plan = JournalTriggerGenerator::plan();
        JournalTriggerFiles::write($this->plan);
        $this->original = self::catalog();
        $this->originalContext = HilosFacade::$db;
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sql('SELECT COALESCE(MAX(`id`), 0) AS `id` FROM ' . $database . '.`' . $table . '`');
            $this->journalBaseline[$table] = (int)Database::field('id');
        }
        self::dropTriggers();
    }

    /** Restores the original catalog so another integration class sees the same stand. */
    protected function tearDown(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        self::dropTriggers();
        Database::sql('DROP TABLE IF EXISTS `' . self::PROBE_TABLE . '`');
        if ($this->fixtureMigration !== null) {
            Database::sql('DELETE FROM `migration` WHERE `index` = ?', [$this->fixtureMigration]);
        }
        HilosFacade::$db = $this->originalContext;
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sql('DELETE FROM ' . $database . '.`' . $table . '` WHERE `id` > ?', [$this->journalBaseline[$table]]);
        }
        foreach ($this->original as $row) {
            Database::sql('CREATE TRIGGER `' . $row['TRIGGER_NAME'] . '` ' . $row['ACTION_TIMING']
                . ' ' . $row['EVENT_MANIPULATION'] . ' ON `' . $row['EVENT_OBJECT_TABLE']
                . '` FOR EACH ROW ' . $row['ACTION_STATEMENT']);
        }
        foreach (glob($this->path . '/Schema/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->path . '/Schema')) {
            rmdir($this->path . '/Schema');
        }
        foreach (glob($this->path . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->path);
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        parent::tearDown();
    }

    public function testInstallsProjectFilesAndRepeatsWithoutAnyTriggerDdl(): void
    {
        // Keep the committed files, including their older headers, under the actual preflight.
        foreach (glob(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers/*.sql') ?: [] as $file) {
            copy($file, $this->path . '/' . basename($file));
        }
        JournalTriggerInstaller::apply();
        $this->assertCount(12, self::catalog());
        $before = self::ddlCounts();
        JournalTriggerInstaller::apply();
        $this->assertSame($before, self::ddlCounts(), 'A repeated start must execute no trigger DDL');
        $this->assertCanonical();
    }

    public function testStartupCompletionKeepsOneClaimThroughPartitionsAndTriggerInstallation(): void
    {
        $holder = new MigrationClaimHolder('journal-startup-test', false);
        $calls = 0;
        $this->assertSame(0, Migration::migrateUp(holder: $holder, afterRollout: function () use ($holder, &$calls): void {
            $calls++;
            $claim = MigrationClaim::current();
            $this->assertSame($holder->name, $claim?->holder);
            ChangeLogPartitions::ensureUnderClaim();
            JournalTriggerInstaller::apply();
            $this->assertEquals($claim, MigrationClaim::current(), 'The original claim must survive both steps');
        }));
        $this->assertSame(1, $calls);
        $this->assertNull(MigrationClaim::current());
        $this->assertCanonical();
    }

    public function testRepairsOnlyTheChangedTriggerAndRestoresTheCallersConnection(): void
    {
        JournalTriggerInstaller::apply();
        $file = $this->plan[0];
        Database::sql('DROP TRIGGER `' . $file->name . '`');
        Database::sql(str_replace('UTC_TIMESTAMP(6)', 'NOW(6)', $file->sql(ChangeLogDatabase::configuredName())));
        $before = self::ddlCounts();
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        JournalTriggerInstaller::apply();
        $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $after = self::ddlCounts();
        $this->assertSame($before['Com_create_trigger'] + 1, $after['Com_create_trigger']);
        $this->assertSame($before['Com_drop_trigger'] + 1, $after['Com_drop_trigger']);
        $this->assertCanonical();
    }

    #[DataProvider('fileRefusals')]
    public function testInvalidFilesRefuseBeforeRepairingAnyLiveTrigger(string $kind): void
    {
        JournalTriggerInstaller::apply();
        $first = $this->plan[0];
        Database::sql('DROP TRIGGER `' . $first->name . '`');
        Database::sql(str_replace('UTC_TIMESTAMP(6)', 'NOW(6)', $first->sql(ChangeLogDatabase::configuredName())));
        $last = $this->plan[array_key_last($this->plan)];
        $filename = $this->path . '/' . $last->name . '.sql';
        $named = $last->name;
        if ($kind === 'missing') {
            unlink($filename);
        } elseif ($kind === 'extra') {
            $named = 'custom.sql';
            file_put_contents($this->path . '/' . $named, 'SELECT 1;');
        } elseif ($kind === 'future') {
            file_put_contents($filename, new JournalTriggerFile($last->name, $last->body, 999999, false)->content());
        } else {
            file_put_contents($filename, str_replace('UTC_TIMESTAMP(6)', 'NOW(6)', $last->content()));
        }
        $before = self::ddlCounts();
        $catalog = self::catalog();
        try {
            JournalTriggerInstaller::apply();
            $this->fail('A bad source must refuse before the first DROP/CREATE');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString($named, $e->getMessage());
        }
        $this->assertSame($before, self::ddlCounts());
        $this->assertSame($catalog, self::catalog());
    }

    /** @return iterable<string, array{string}> Full-preflight failures */
    public static function fileRefusals(): iterable
    {
        foreach (['missing', 'extra', 'future', 'body'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function testForeignTriggersAreNamedAndLeftUntouchedBeforeAnyDdl(): void
    {
        Database::sql('CREATE TRIGGER `foreign_one` AFTER INSERT ON `hilos_setting` FOR EACH ROW SET @foreign_one = 1');
        Database::sql('CREATE TRIGGER `foreign_two` AFTER DELETE ON `hilos_setting` FOR EACH ROW SET @foreign_two = 1');
        $before = self::ddlCounts();
        try {
            JournalTriggerInstaller::apply();
            $this->fail('Every trigger without a file must refuse startup');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('foreign_one', $e->getMessage());
            $this->assertStringContainsString('foreign_two', $e->getMessage());
        }
        $this->assertSame($before, self::ddlCounts());
        $this->assertCount(2, self::catalog());
    }

    public function testTheInventoryDoesNotAdoptTriggersFromTheJournalDatabase(): void
    {
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql('CREATE TRIGGER ' . $database . '.`journal_database_probe` AFTER INSERT ON '
            . $database . '.`hilos_change_log_receipt` FOR EACH ROW SET @journal_database_probe = 1');
        try {
            JournalTriggerInstaller::apply();
            $this->assertCanonical();
            Database::sql('SELECT COUNT(*) AS `count` FROM INFORMATION_SCHEMA.TRIGGERS'
                . ' WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ?',
                [ChangeLogDatabase::configuredName(), 'journal_database_probe']);
            $this->assertSame(1, (int)Database::field('count'));
        } finally {
            Database::sql('DROP TRIGGER ' . $database . '.`journal_database_probe`');
        }
    }

    public function testTombstonesRemoveObsoleteTriggersAndThenRepeatWithoutDdl(): void
    {
        $files = JournalTriggerRenderer::tombstones('retired', Migration::getCurrentIndex());
        JournalTriggerFiles::write($files);
        Database::sql('CREATE TRIGGER `' . $files[0]->name . '` AFTER INSERT ON `hilos_setting` FOR EACH ROW SET @retired = 1');
        JournalTriggerInstaller::apply();
        $this->assertCanonical();
        $before = self::ddlCounts();
        JournalTriggerInstaller::apply();
        $this->assertSame($before, self::ddlCounts());
    }

    public function testSqlFailureAfterSomeCreatesLeavesASetThatTheNextStartCompletes(): void
    {
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $blocker = new mysqli($primary->host, $primary->user, $primary->password, $primary->database, $primary->port);
        Database::sql('SELECT @@SESSION.lock_wait_timeout AS `timeout`');
        $timeout = (int)Database::field('timeout');
        Database::sql('SET SESSION lock_wait_timeout = 1');
        try {
            // Preflight reads metadata, but CREATE TRIGGER needs an exclusive metadata lock
            // on this later table. Earlier tables finish their DDL before that lock times out.
            $blocker->query('LOCK TABLES `hilos_setting` READ');
            try {
                JournalTriggerInstaller::apply();
                $this->fail('The held table must refuse its trigger DDL');
            } catch (DatabaseException $e) {
                $this->assertStringContainsString('hilos_cl_hilos_setting_after_delete', $e->getMessage());
            }
        } finally {
            $blocker->query('UNLOCK TABLES');
            $blocker->close();
            Database::sql('SET SESSION lock_wait_timeout = ' . $timeout);
        }
        $this->assertCount(6, self::catalog(), 'The two earlier tables must already have all three triggers');
        $before = self::ddlCounts();
        JournalTriggerInstaller::apply();
        $after = self::ddlCounts();
        $this->assertSame($before['Com_drop_trigger'], $after['Com_drop_trigger']);
        $this->assertSame($before['Com_create_trigger'] + 6, $after['Com_create_trigger']);
        $this->assertCanonical();
    }

    #[DataProvider('incompatibleDdl')]
    public function testMigrationJournalsDmlBeforeIncompatibleDdlAndInstallsTheNewFiles(bool $rename): void
    {
        $this->prepareProbe();
        $table = self::PROBE_TABLE;
        $columns = ['id' => false, 'value' => false];
        if ($rename) {
            $columns['replacement'] = true;
        }
        $placements = ['id' => new JournalColumnPlacement(JournalColumnMode::RECORD_KEY)];
        foreach (array_keys($columns) as $column) {
            $placements[$column] ??= new JournalColumnPlacement(JournalColumnMode::VALUE);
        }
        // Files for the post-migration schema are shipped before the rollout begins.
        JournalTriggerFiles::write(JournalTriggerRenderer::render(
            new LiveTableSchema($table, $columns, array_fill_keys(array_keys($columns), 'int'), [],
                ['id'], ['PRIMARY' => ['id']], []),
            $placements,
            new JournalTriggerColumnTypes([]),
            $this->fixtureMigration,
        ));
        $ddl = $rename ? 'RENAME COLUMN `obsolete` TO `replacement`' : 'DROP COLUMN `obsolete`';
        $this->writeProbeMigration("UPDATE `{$table}` SET `value` = 11, `obsolete` = 21 WHERE `id` = 1;\n"
            . "ALTER TABLE `{$table}` {$ddl};\n");
        $this->mountProbe($rename ? JournalStartupRenamedEntity::class : JournalStartupEntity::class);
        $this->assertSame(1, Migration::migrateUp(afterRollout: function (): void {
            $this->assertNotNull(MigrationClaim::current());
            ChangeLogPartitions::ensureUnderClaim();
            JournalTriggerInstaller::apply();
        }));
        $this->assertNull(MigrationClaim::current());
        $assignments = ['`value` = 12'];
        if ($rename) {
            $assignments[] = '`replacement` = 22';
        }
        Database::sql('UPDATE `' . $table . '` SET ' . implode(', ', $assignments) . ' WHERE `id` = 1');
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sql('SELECT l.`mutation_type` FROM ' . $database . '.`hilos_change_log` l JOIN '
            . $database . '.`hilos_change_log_table` t ON t.`id` = l.`table_id` WHERE t.`name` = ? ORDER BY l.`id`', [$table]);
        $this->assertSame(['create', 'update', 'update'], array_column(Database::rows(), 'mutation_type'));
        Database::sql('SELECT f.`name`, c.`old_value`, c.`new_value` FROM ' . $database . '.`hilos_change_log_change` c JOIN '
            . $database . '.`hilos_change_log_field` f ON f.`id` = c.`field_id` JOIN '
            . $database . '.`hilos_change_log_table` t ON t.`id` = f.`table_id` WHERE t.`name` = ? ORDER BY c.`id`', [$table]);
        $changes = Database::rows();
        $this->assertContains(['name' => 'obsolete', 'old_value' => '20', 'new_value' => '21'], $changes);
        $this->assertContains(['name' => 'value', 'old_value' => '11', 'new_value' => '12'], $changes);
        if ($rename) {
            $this->assertContains(['name' => 'replacement', 'old_value' => '21', 'new_value' => '22'], $changes);
        }
        $before = self::ddlCounts();
        JournalTriggerInstaller::apply();
        $this->assertSame($before, self::ddlCounts());
    }

    /** @return iterable<string, array{bool}> Two incompatible column changes */
    public static function incompatibleDdl(): iterable
    {
        yield 'drop' => [false];
        yield 'rename' => [true];
    }

    public function testDmlAfterAnIncompatibleDdlFailsTheMigrationWithoutDisablingTheJournal(): void
    {
        $this->prepareProbe();
        $table = self::PROBE_TABLE;
        $this->writeProbeMigration("ALTER TABLE `{$table}` DROP COLUMN `obsolete`;\n"
            . "UPDATE `{$table}` SET `value` = 12 WHERE `id` = 1;\n");
        $before = self::ddlCounts();
        $calls = 0;
        try {
            Migration::migrateUp(afterRollout: static function () use (&$calls): void {
                $calls++;
            });
            $this->fail('The old trigger must refuse a write referring to its deleted column');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('Migration ' . $this->fixtureMigration . ' failed', $e->getMessage());
            $this->assertStringContainsString('obsolete', $e->getMessage());
        }
        $this->assertSame(0, $calls);
        $this->assertNull(MigrationClaim::current());
        $this->assertSame($before, self::ddlCounts());
        Database::sql('SELECT `failed` FROM `migration` WHERE `index` = ?', [$this->fixtureMigration]);
        $this->assertSame(1, (int)Database::field('failed'));
        Database::sql('SELECT `value` FROM `' . $table . '` WHERE `id` = 1');
        $this->assertSame(10, (int)Database::field('value'));
    }

    private function prepareProbe(): void
    {
        $this->fixtureMigration = Migration::getCurrentIndex() + 1;
        Database::sql('CREATE TABLE `' . self::PROBE_TABLE . '` '
            . '(`id` INT NOT NULL PRIMARY KEY, `value` INT NOT NULL, `obsolete` INT NULL) ENGINE=InnoDB');
        $this->mountProbe(JournalStartupOldEntity::class);
        JournalTriggerFiles::write(JournalTriggerGenerator::plan());
        JournalTriggerInstaller::apply();
        Database::sql('INSERT INTO `' . self::PROBE_TABLE . '` (`id`, `value`, `obsolete`) VALUES (1, 10, 20)');
    }

    /** @param class-string<Entity> $entityClass Fixture Entity matching the schema after migration */
    private function mountProbe(string $entityClass): void
    {
        $context = new JournalStartupDbContext();
        $context->entities = $this->originalContext->getMountedEntities();
        $context->entities['startupProbe'] = $entityClass;
        HilosFacade::$db = $context;
    }

    /** @param string $sql Fixture migration SQL */
    private function writeProbeMigration(string $sql): void
    {
        mkdir($this->path . '/Schema');
        file_put_contents($this->path . '/Schema/' . $this->fixtureMigration . '_probe.sql', $sql);
        Migration::setMigrationListPath($this->path);
    }

    private function assertCanonical(): void
    {
        $rows = array_column(self::catalog(), null, 'TRIGGER_NAME');
        $this->assertCount(count($this->plan), $rows);
        foreach ($this->plan as $file) {
            $this->assertTrue($file->matches($rows[$file->name], ChangeLogDatabase::configuredName()), $file->name);
        }
    }

    /** @return list<array<string, mixed>> Primary database trigger metadata */
    private static function catalog(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT, DEFINER'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        return Database::rows();
    }

    private static function dropTriggers(): void
    {
        foreach (self::catalog() as $row) {
            Database::sql('DROP TRIGGER `' . $row['TRIGGER_NAME'] . '`');
        }
    }

    /** @return array<string, int> Session trigger DDL counters, independent of timestamps */
    private static function ddlCounts(): array
    {
        Database::sql("SHOW SESSION STATUS WHERE Variable_name IN ('Com_create_trigger', 'Com_drop_trigger')");
        return array_map(intval(...), array_column(Database::rows(), 'Value', 'Variable_name'));
    }
}

/** Supplies the fixture's mounted metadata without replacing any production Entity chain. */
final class JournalStartupDbContext extends HilosDbContext
{
    /** @var array<string, class-string<Entity>> Mounted fixture and project Entity map */
    public array $entities = [];

    /** @return array<string, class-string<Entity>> Fixture's current schema declarations */
    public function getMountedEntities(): array
    {
        return $this->entities;
    }
}

class JournalStartupEntity extends Entity
{
    public const string _table = 'hilos_trigger_startup_probe';
    public const bool _journaled = true;
    public const string _primary = 'id';
    public const array _columns = ['id', 'value'];
    public const array _types = ['id' => PhpType::INTEGER->value, 'value' => PhpType::INTEGER->value];
    public const array _pii = [];
    public const array _piiNotPersonal = ['id', 'value'];

    public int $id;
    public int $value;
}

final class JournalStartupOldEntity extends JournalStartupEntity
{
    public const array _columns = ['id', 'value', 'obsolete'];
    public const array _types = [...parent::_types, 'obsolete' => PhpType::INTEGER->value];
    public const array _piiNotPersonal = ['id', 'value', 'obsolete'];

    public ?int $obsolete = null;
}

final class JournalStartupRenamedEntity extends JournalStartupEntity
{
    public const array _columns = ['id', 'value', 'replacement'];
    public const array _types = [...parent::_types, 'replacement' => PhpType::INTEGER->value];
    public const array _piiNotPersonal = ['id', 'value', 'replacement'];

    public ?int $replacement = null;
}
