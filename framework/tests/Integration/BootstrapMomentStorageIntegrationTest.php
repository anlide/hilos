<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\AdminViewModeLatchTable;
use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseMarker;
use Hilos\Database\DatabaseMarkerRow;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\MigrationClaim;
use Hilos\Database\MigrationClaimHolder;
use Hilos\Database\MigrationSqlReader;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Real bootstrap DDL and delivered parts, in a disposable schema outside rollback scopes. */
final class BootstrapMomentStorageIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string CHANGE = '20261109000848893922_HIL-1918_bootstrap_moments';
    private const array COLUMNS = [
        'migration_claim' => 'claimed_at',
        'admin_view_mode_latch' => 'closed_at',
        'database_marker' => 'written_at',
    ];

    private string $database;
    private string $root;
    private array $previousMigration = [];

    /** Opens a disposable schema and delivers only the three production change pairs. */
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['migrationListPath', 'migrationName', 'initialized'] as $property) {
            $this->previousMigration[$property] = new ReflectionProperty(Migration::class, $property)->getValue();
        }
        new ReflectionProperty(Migration::class, 'initialized')->setValue(null, []);
        $this->database = 'hilos-bootstrap-moments-' . bin2hex(random_bytes(6));
        $this->root = sys_get_temp_dir() . '/' . $this->database;
        mkdir($this->root . '/Schema', 0700, true);
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX,
            $config->host, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
            $config->database, $config->port, $config->charset);
        Database::connect();
        Database::sql('CREATE DATABASE `' . $this->database . '`');
        Database::close();
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX,
            $config->host, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
            $this->database, $config->port, $config->charset);
        Database::connect();
        Migration::setMigrationListPath($this->root);
        Migration::setMigrationName('Schema');
        $catalog = new FrameworkMigrationCatalog(new FrameworkSchemaCatalog());
        $copied = 0;
        foreach (glob(dirname(__DIR__, 3) . '/demo/chat/backend/Database/Migration/Schema/*.sql') as $file) {
            if (!str_contains(file_get_contents($file), self::CHANGE)) {
                continue;
            }
            copy($file, $this->root . '/Schema/' . basename($file));
            $copied++;
        }
        $this->assertSame(6, $copied, 'All three generated up/down pairs must be delivered');
        foreach (glob($this->root . '/Schema/*.sql') as $file) {
            if (!str_ends_with($file, '_down.sql')) {
                $catalog->verifyPair(file_get_contents($file), file_get_contents(substr($file, 0, -4) . '_down.sql'));
            }
        }
        Migration::initialize();
    }

    /** Drops owned resources and restores the invocation's migration configuration. */
    protected function tearDown(): void
    {
        try {
            Database::sql('DROP DATABASE IF EXISTS `' . $this->database . '`');
            foreach (glob($this->root . '/Schema/*') as $file) {
                unlink($file);
            }
            rmdir($this->root . '/Schema');
            rmdir($this->root);
        } finally {
            foreach ($this->previousMigration as $property => $value) {
                new ReflectionProperty(Migration::class, $property)->setValue(null, $value);
            }
            parent::tearDown();
        }
    }

    public function testFreshBootstrapHasExactSignedBigintWithoutSqlWriters(): void
    {
        $this->assertPhysicalMoments();
    }

    public function testDeliveredUpMigratesLegacyRowsWhileTheRolloutClaimStaysOccupied(): void
    {
        $this->legacySchema();
        Database::sql("INSERT INTO hilos_migration_claim VALUES(1,'bootstrap-owner','2040-01-01 00:00:00')");
        Database::sql("INSERT INTO hilos_admin_view_mode_latch VALUES(1,'prod','original-node',2208988800)");
        Database::sql("INSERT INTO hilos_database_marker VALUES(1,?,'original-node','1969-12-31 23:59:59')", [str_repeat('a', 32)]);
        $this->assertSame(3, Migration::migrateUp(holder: new MigrationClaimHolder('bootstrap-owner', true),
            afterRollout: function (): void {
                $this->assertSame('bootstrap-owner', MigrationClaim::current()?->holder);
                $this->assertPhysicalMoments();
                $this->assertSame(2208988800000, AdminViewModeLatchTable::read()['closedAt']);
                $this->assertEquals(new DatabaseMarkerRow(str_repeat('a', 32), 'original-node', -1000), DatabaseMarker::current());
            }));
        $this->assertNull(MigrationClaim::current());
        $this->assertSame(0, Migration::migrateUp());
        $this->assertSame(2208988800000, AdminViewModeLatchTable::read()['closedAt']);
        $this->assertSame(-1000, DatabaseMarker::current()?->writtenAt);
    }

    public function testDeliveredUpOnFreshSchemaPreservesExactMillisecondsAndRepeats(): void
    {
        Database::sql("INSERT INTO hilos_admin_view_mode_latch VALUES(1,'prod','original-node',-1)");
        DatabaseMarker::put(new DatabaseMarkerRow(str_repeat('b', 32), 'original-node', 2208988800456));
        $this->assertSame(3, Migration::migrateUp());
        $this->assertSame(0, Migration::migrateUp());
        $this->assertSame(-1, AdminViewModeLatchTable::read()['closedAt']);
        $this->assertSame(2208988800456, DatabaseMarker::current()?->writtenAt);
        $this->assertPhysicalMoments();
    }

    public function testFailureAfterDeliveredUpReleasesClaimAndDoesNotScaleAgain(): void
    {
        $this->legacySchema();
        Database::sql("INSERT INTO hilos_admin_view_mode_latch VALUES(1,'prod','original-node',1700000000)");
        try {
            Migration::migrateUp(afterRollout: static function (): void {
                throw new DatabaseException('Injected post-rollout failure');
            });
            $this->fail('The post-rollout failure must propagate');
        } catch (DatabaseException $failure) {
            $this->assertSame('Schema rollout completion failed: Injected post-rollout failure', $failure->getMessage());
        }
        $this->assertNull(MigrationClaim::current());
        $this->assertSame(0, Migration::migrateUp());
        $this->assertSame(1700000000000, AdminViewModeLatchTable::read()['closedAt']);
    }

    public function testDownAndUpExplicitlyLowerPrecisionAndPreserveTheClaim(): void
    {
        Database::sql("INSERT INTO hilos_migration_claim VALUES(1,'held',-1)");
        Database::sql("INSERT INTO hilos_admin_view_mode_latch VALUES(1,'prod','node',2208988800456)");
        DatabaseMarker::put(new DatabaseMarkerRow(str_repeat('c', 32), 'node', 2208988800456));
        foreach (array_keys(self::COLUMNS) as $unit) {
            $this->part($unit, true);
        }
        $this->assertSame('held', MigrationClaim::current()?->holder);
        $this->assertSame(-1000, MigrationClaim::current()?->claimedAt);
        foreach (array_keys(self::COLUMNS) as $unit) {
            $this->part($unit);
        }
        $this->assertSame('held', MigrationClaim::current()?->holder);
        $this->assertSame(-1000, MigrationClaim::current()?->claimedAt);
        $this->assertSame(2208988800000, AdminViewModeLatchTable::read()['closedAt']);
        $this->assertSame(2208988800000, DatabaseMarker::current()?->writtenAt);
        $this->assertPhysicalMoments();
    }

    /** @param string $unit Bootstrap unit whose legacy range cannot hold its source value */
    #[DataProvider('unrepresentableUnits')]
    public function testDownRefusesBeforeReplacingAnUnrepresentableSource(string $unit): void
    {
        $column = self::COLUMNS[$unit];
        $table = 'hilos_' . $unit;
        $values = match ($unit) {
            'migration_claim' => "1,'held',9223372036854775807",
            'admin_view_mode_latch' => "1,'prod','node',-1",
            'database_marker' => "1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','node',9223372036854775807",
        };
        Database::sql('INSERT INTO `' . $table . '` VALUES(' . $values . ')');
        $before = Database::sql('SELECT * FROM `' . $table . '`')->rows();
        try {
            $this->part($unit, true);
            $this->fail('A value outside the legacy range must refuse DOWN');
        } catch (DatabaseException $failure) {
            $this->assertStringContainsString('cannot be represented', $failure->getMessage());
        }
        $this->assertSame($before, Database::sql('SELECT * FROM `' . $table . '`')->rows());
        $this->assertPhysicalMoments();
        $this->assertSame([], Database::sql('SHOW COLUMNS FROM `' . $table . '` LIKE ?', ['__hil1918_' . $column])->rows());
    }

    /** @return list<array{string}> The three independent legacy ranges */
    public static function unrepresentableUnits(): array
    {
        return [['migration_claim'], ['admin_view_mode_latch'], ['database_marker']];
    }

    private function assertPhysicalMoments(): void
    {
        foreach (self::COLUMNS as $unit => $column) {
            $row = Database::sql('SHOW COLUMNS FROM `hilos_' . $unit . '` LIKE ?', [$column])->row();
            $this->assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/', $row['Type']);
            $this->assertSame('NO', $row['Null']);
            $this->assertNull($row['Default']);
            $this->assertSame('', $row['Extra']);
        }
    }

    private function legacySchema(): void
    {
        Database::sql('ALTER TABLE hilos_migration_claim MODIFY claimed_at DATETIME NOT NULL');
        Database::sql('ALTER TABLE hilos_admin_view_mode_latch MODIFY closed_at INT UNSIGNED NOT NULL');
        Database::sql('ALTER TABLE hilos_database_marker MODIFY written_at DATETIME NOT NULL');
    }

    /**
     * @param string $unit Bootstrap schema unit
     * @param bool $down Whether to execute its legacy direction
     */
    private function part(string $unit, bool $down = false): void
    {
        $part = new FrameworkMigrationCatalog(new FrameworkSchemaCatalog())->part(self::CHANGE, $unit);
        foreach (MigrationSqlReader::statements($down ? $part->downSql : $part->upSql) as $statement) {
            Database::sql($statement);
        }
    }
}
