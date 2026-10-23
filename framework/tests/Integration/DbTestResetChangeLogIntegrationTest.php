<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\DbTestResetCommand;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\ChangeLogPartitions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseBinding;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Migration;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Seed;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use mysqli;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Runs the offline reset on its own pair, never on the suite's primary database. */
final class DbTestResetChangeLogIntegrationTest extends TestCase
{
    private const array JOURNAL_TABLES = [
        'hilos_change_log', 'hilos_change_log_change', 'hilos_change_log_field',
        'hilos_change_log_receipt', 'hilos_change_log_table', 'hilos_change_log_value',
    ];

    private EnvAccessor $previousEnv;
    private string $previousAppClass;
    /** @var array<int, DatabaseConnectionConfig> Connections to restore */
    private array $previousConfigurations;
    /** @var array<string, DatabaseBinding> Logical project registrations to restore */
    private array $previousDatabaseBindings;
    private ?int $previousTransactionIndex;
    /** @var list<int> Connections open on entry */
    private array $previousConnected;
    private int $previousIndex;
    /** @var array<string, mixed> Migration settings to restore */
    private array $previousMigration;
    private ?string $previousSeedPath;
    private mysqli $server;
    private string $primaryName;
    private string $journalName;
    private string $temporaryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousAppClass = Hilos::appClass();
        $this->previousConfigurations = new ReflectionProperty(Database::class, 'configurations')->getValue();
        $this->previousDatabaseBindings = new ReflectionProperty(Database::class, 'databaseBindings')->getValue();
        $this->previousTransactionIndex = new ReflectionProperty(Database::class, 'transactionIndex')->getValue();
        $this->previousConnected = array_values(array_filter(Database::getConfiguredIndices(), Database::isConnected(...)));
        $this->previousIndex = Database::getCurrentIndex();
        $this->previousMigration = [];
        foreach (['migrationListPath', 'migrationName', 'initialized'] as $property) {
            $this->previousMigration[$property] = new ReflectionProperty(Migration::class, $property)->getValue();
        }
        $this->previousSeedPath = Seed::getSeedPath();
        $this->primaryName = 'hilos-reset-' . bin2hex(random_bytes(6));
        $this->journalName = ChangeLogDatabase::name($this->primaryName);
        $this->temporaryPath = sys_get_temp_dir() . '/' . $this->primaryName;
        mkdir($this->temporaryPath . '/Schema', 0777, true);
        mkdir($this->temporaryPath . '/seeds');
        file_put_contents($this->temporaryPath . '/Schema/001_fixture.sql',
            'CREATE TABLE `reset_fixture` (`id` INT NOT NULL PRIMARY KEY);');
        copy(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_change_log.sql',
            $this->temporaryPath . '/Schema/002_journal.sql');
        file_put_contents($this->temporaryPath . '/seeds/001_fixture.sql', 'INSERT INTO `reset_fixture` VALUES (1);');
        $this->server = new mysqli(
            Hilos::$env[EnvConstants::DB_HOST]->string(),
            'root',
            Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
            '',
            Hilos::$env[EnvConstants::DB_PORT]->int(),
        );
        foreach (Database::getConfiguredIndices() as $index) {
            Database::close($index);
            Schema::reset($index);
        }
        new ReflectionProperty(Database::class, 'configurations')->setValue(null, []);
        new ReflectionProperty(Database::class, 'databaseBindings')->setValue(null, []);
        new ReflectionProperty(Database::class, 'transactionIndex')->setValue(null, null);
        new ReflectionProperty(Database::class, 'currentIndex')->setValue(null, DatabaseConnectionDefaults::PRIMARY_INDEX);
        Hilos::$env = new ResetChangeLogEnv($this->previousEnv, $this->primaryName);
        Migration::setMigrationListPath($this->temporaryPath);
        Migration::setMigrationName('Schema');
        Seed::setSeedPath($this->temporaryPath . '/seeds');
    }

    protected function tearDown(): void
    {
        try {
            foreach (Database::getConfiguredIndices() as $index) {
                Database::close($index);
                Schema::reset($index);
            }
            if (isset($this->server)) {
                $this->server->query('DROP DATABASE IF EXISTS ' . ChangeLogDatabase::identifier($this->primaryName));
                $this->server->query('DROP DATABASE IF EXISTS ' . ChangeLogDatabase::identifier($this->journalName));
                $this->server->close();
            }
            foreach (glob($this->temporaryPath . '/Schema/*') as $file) {
                unlink($file);
            }
            unlink($this->temporaryPath . '/seeds/001_fixture.sql');
            rmdir($this->temporaryPath . '/Schema');
            rmdir($this->temporaryPath . '/seeds');
            rmdir($this->temporaryPath);
        } finally {
            Hilos::$env = $this->previousEnv;
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
            foreach ($this->previousMigration as $property => $value) {
                new ReflectionProperty(Migration::class, $property)->setValue(null, $value);
            }
            new ReflectionProperty(Seed::class, 'seedPath')->setValue(null, $this->previousSeedPath);
            new ReflectionProperty(Database::class, 'configurations')->setValue(null, $this->previousConfigurations);
            new ReflectionProperty(Database::class, 'databaseBindings')->setValue(null, $this->previousDatabaseBindings);
            new ReflectionProperty(Database::class, 'transactionIndex')->setValue(null, $this->previousTransactionIndex);
            foreach ($this->previousConnected as $index) {
                Database::connect($index);
            }
            new ReflectionProperty(Database::class, 'currentIndex')->setValue(null, $this->previousIndex);
            parent::tearDown();
        }
    }

    public function testRepeatedFeatureResetClearsBothDatabasesAndPreparesPartitions(): void
    {
        $this->resetWith(ResetChangeLogEnabledHilos::class);
        $this->assertPreparedPair();
        Database::sqlRun('INSERT INTO `reset_fixture` VALUES (2)');
        $journal = ChangeLogDatabase::identifier($this->journalName);
        Database::sqlRun("INSERT INTO {$journal}.`hilos_change_log_table` (`name`) VALUES ('old-row')");
        Database::sqlRun("INSERT INTO {$journal}.`hilos_change_log_receipt` (`created_at`, `channel`, `action`) "
            . "VALUES (UTC_TIMESTAMP(6), 'agent', 'old-operation')");
        Database::sqlRun("CREATE TABLE {$journal}.`stale_fixture` (`id` INT)");
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);

        $this->resetWith(ResetChangeLogEnabledHilos::class);
        $this->assertPreparedPair();
        Database::sql('SELECT `id` FROM `reset_fixture` ORDER BY `id`');
        self::assertSame([1], array_map(intval(...), array_column(Database::rows(), 'id')));
        foreach (self::JOURNAL_TABLES as $table) {
            Database::sql("SELECT COUNT(*) AS n FROM {$journal}.`{$table}`");
            self::assertSame(0, (int)Database::field('n'));
        }
    }

    public function testPrimaryOnlyResetLeavesSecondaryDatabaseAndConnectionUntouched(): void
    {
        unlink($this->temporaryPath . '/Schema/002_journal.sql');
        $journal = ChangeLogDatabase::identifier($this->journalName);
        $this->server->query('CREATE DATABASE ' . $journal . ' ' . DatabaseConnectionDefaults::createDatabaseCharsetClause());
        $this->server->query("CREATE TABLE {$journal}.`sentinel` (`id` INT NOT NULL)");
        $this->server->query("INSERT INTO {$journal}.`sentinel` VALUES (73)");
        Database::configure(ChangeLogDatabase::CONNECTION_INDEX,
            Hilos::$env[EnvConstants::DB_HOST]->string(), 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
            $this->journalName, Hilos::$env[EnvConstants::DB_PORT]->int(), DatabaseConnectionDefaults::CHARSET);
        Database::connect(ChangeLogDatabase::CONNECTION_INDEX);
        $configuration = Database::getConnectionConfig(ChangeLogDatabase::CONNECTION_INDEX);
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);

        $this->resetWith(ResetChangeLogDisabledHilos::class);
        self::assertSame($configuration, Database::getConnectionConfig(ChangeLogDatabase::CONNECTION_INDEX));
        self::assertTrue(Database::isConnected(ChangeLogDatabase::CONNECTION_INDEX));
        self::assertSame([['id' => '73']], $this->server->query("SELECT `id` FROM {$journal}.`sentinel`")->fetch_all(MYSQLI_ASSOC));
        self::assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
    }

    /** @param class-string<Hilos> $facade Feature declaration for this CLI invocation */
    private function resetWith(string $facade): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $facade);
        // Each real CLI invocation begins with an empty migration initialization cache.
        new ReflectionProperty(Migration::class, 'initialized')->setValue(null, []);
        ob_start();
        try {
            self::assertSame(ExitCode::SUCCESS, (new DbTestResetCommand())->execute([], []));
        } finally {
            ob_end_clean();
        }
    }

    private function assertPreparedPair(): void
    {
        self::assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
        self::assertSame($this->primaryName, Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX)->database);
        self::assertSame($this->journalName, Database::getConnectionConfig(ChangeLogDatabase::CONNECTION_INDEX)->database);
        Database::sql('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$this->journalName]);
        self::assertSame(self::JOURNAL_TABLES, array_column(Database::rows(), 'name'));
        foreach (ChangeLogPartitions::TABLES as $table) {
            Database::sql('SELECT PARTITION_NAME AS name FROM information_schema.PARTITIONS '
                . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY PARTITION_ORDINAL_POSITION', [$this->journalName, $table]);
            $partitions = array_column(Database::rows(), 'name');
            self::assertCount(26, $partitions);
            self::assertSame('p' . gmdate('Ym'), $partitions[0]);
            self::assertSame('p_future', $partitions[25]);
        }
    }
}

/** Env values for the fixture's isolated pair; the suite's connection is only read. */
final class ResetChangeLogEnv extends EnvAccessor
{
    public function __construct(private readonly EnvAccessor $source, private readonly string $database)
    {
        parent::__construct();
    }

    /**
     * @param string $key Env key
     * @return mixed Fixture override or the suite value
     */
    public function effectiveValueFor(string $key): mixed
    {
        return match ($key) {
            EnvConstants::APP_ENV->name => 'test',
            EnvConstants::DB_DATABASE->name => $this->database,
            EnvConstants::DB_USERNAME->name => 'root',
            EnvConstants::DB_PASSWORD->name => $this->source[EnvConstants::DB_ROOT_PASSWORD]->string(),
            default => $this->source->effectiveValueFor($key),
        };
    }
}

class ResetChangeLogEnabledHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::CHANGE_LOG];

    /** @return HilosDbContext Unused facade data context */
    protected static function createDb(): HilosDbContext
    {
        return new ResetChangeLogDbContext();
    }
}

final class ResetChangeLogDisabledHilos extends ResetChangeLogEnabledHilos
{
    protected const array FEATURES = [];
}

final class ResetChangeLogDbContext extends HilosDbContext
{
    /** The reset command does not initialize the facade's data context. */
    public function configure(): void
    {
    }
}
