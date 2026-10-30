<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusIncident;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Object\Collection\StatusIncidents;
use Hilos\Database\Object\Collection\StatusMaintenancePeriods;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\Schema;
use Hilos\Status\DTO\StatusHistoryQuery;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real DDL and failed reads deliberately run outside a rollback scope. */
final class StatusHistorySchemaIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Removes fixture tables after successful and failed assertions alike. */
    protected function tearDown(): void
    {
        try {
            // One statement per call: a batch whose later results are still unread when the case closes
            // its link keeps running on the server and can drop the table the next case just created.
            foreach (['hilos_status_incident', 'hilos_status_maintenance_period', 'hilos_status_work'] as $table) {
                Database::sqlRun('DROP TABLE IF EXISTS `' . $table . '`');
            }
        } finally {
            Schema::reset();
            parent::tearDown();
        }
    }

    /** @return iterable<string, array{string}> All generated project migration tracks */
    public static function projects(): iterable
    {
        foreach (['chat', 'tasks', 'polls', 'binance-btc-tracker', 'ecommerce-shop', 'online-testing'] as $project) {
            yield $project => [$project];
        }
    }

    /** @param string $project Demo whose old install and new immutable change pair are exercised */
    #[DataProvider('projects')]
    public function testGeneratedUpgradeAddsOnlyTheOrderedIndexAndDownPreservesRows(string $project): void
    {
        $root = dirname(__DIR__, 3) . '/demo/' . $project . '/backend/Database/Migration/Schema/';
        $catalog = new FrameworkMigrationCatalog(new FrameworkSchemaCatalog());
        $catalog->verifyCurrentStubs(['status_incident']);
        $install = glob($root . '*_framework_install_status_incident.sql');
        self::assertCount(1, $install);
        $installUp = file_get_contents($install[0]);
        $installDown = file_get_contents(substr($install[0], 0, -4) . '_down.sql');
        $installOrigin = $catalog->verifyPair($installUp, $installDown);
        Database::sqlRun($installUp);
        $before = $this->indexes();
        self::assertNotContains('idx_status_incident_detected', array_column($before, 'INDEX_NAME'));
        Database::sql('INSERT INTO `hilos_status_incident` '
            . '(`id`, `component_key`, `component_name`, `severity`, `detected_at`, `publish_at`, `resolved_at`, `outcome`) '
            . 'VALUES (2, ?, ?, ?, 1000, 1500, 2000, ?)', ['retired', 'Saved component', 'partial_outage', 'resolved']);
        $saved = Database::sql('SELECT * FROM `hilos_status_incident`')->rows();

        $matches = [];
        foreach (glob($root . '*_framework_change_*.sql') as $path) {
            if (str_ends_with($path, '_down.sql')) {
                continue;
            }
            $up = file_get_contents($path);
            $down = file_get_contents(substr($path, 0, -4) . '_down.sql');
            $origin = $catalog->verifyPair($up, $down);
            if ($origin->units === ['status_incident']) {
                $matches[] = [$up, $down, $origin];
            }
        }
        self::assertCount(1, $matches);
        [$up, $down, $origin] = $matches[0];
        self::assertSame(FrameworkMigrationOrigin::CHANGE, $origin->kind);
        self::assertCount(1, $origin->parts);
        self::assertStringContainsString('_HIL-1693_status_history_order', $origin->parts[0]->changeId);
        self::assertCount(2, $catalog->coveredParts([$installOrigin, $origin]));
        Database::sqlRun($up);
        self::assertSame([], EntitySchemaAudit::audit([StatusIncident::class]));
        $ordered = array_values(array_filter($this->indexes(), static fn(array $row): bool =>
            $row['INDEX_NAME'] === 'idx_status_incident_detected'));
        self::assertSame(['detected_at', 'id'], array_column($ordered, 'COLUMN_NAME'));
        self::assertSame(['D', 'D'], array_column($ordered, 'COLLATION'));
        self::assertSame($saved, Database::sql('SELECT * FROM `hilos_status_incident`')->rows());
        Database::sqlRun($down);
        self::assertSame($before, $this->indexes());
        self::assertSame($saved, Database::sql('SELECT * FROM `hilos_status_incident`')->rows());
    }

    /** The current stub supports a fresh installation with the same Entity index directions. */
    public function testFreshStubMatchesEntity(): void
    {
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_status_incident.sql'));
        self::assertSame([], EntitySchemaAudit::audit([StatusIncident::class]));
    }

    /** Missing storage is a failure on every read path, including aggregates and historical key facets. */
    public function testDatabaseErrorsNeverBecomeEmptyHistoryOrZeroCounts(): void
    {
        foreach (['hilos_status_incident', 'hilos_status_work', 'hilos_status_maintenance_period'] as $table) {
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . '.sql'));
        }
        Schema::reset();
        Schema::initialize();
        $sources = [StatusIncidents::initDB(Objects::LAZY_STRATEGY_KEY), StatusMaintenancePeriods::initDB(Objects::LAZY_STRATEGY_KEY)];
        Database::sqlRun('DROP TABLE `hilos_status_incident`; DROP TABLE `hilos_status_maintenance_period`');
        foreach ($sources as $source) {
            foreach (['historyPortion', 'historyCount', 'completedCount', 'historyComponentKeys'] as $method) {
                try {
                    if ($method === 'historyPortion' || $method === 'historyCount') {
                        $source->$method(new StatusHistoryQuery());
                    } else {
                        $source->$method();
                    }
                    self::fail($method . ' must propagate the missing-table error');
                } catch (DatabaseException $failure) {
                    self::assertNotSame('', $failure->getMessage());
                }
            }
        }
    }

    /** @return list<array<string, mixed>> Stable index shape, excluding mutable engine statistics */
    private function indexes(): array
    {
        return Database::sql('SELECT INDEX_NAME, COLUMN_NAME, COLLATION, NON_UNIQUE FROM information_schema.STATISTICS '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hilos_status_incident' ORDER BY INDEX_NAME, SEQ_IN_INDEX")->rows();
    }
}
