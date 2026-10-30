<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Database\Entity\Item\StatusMaintenancePeriod;
use Hilos\Database\Entity\Item\StatusWork;
use Hilos\Database\Entity\Item\StatusWorkComponent;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Schema\EntitySchemaAudit;
use PHPUnit\Framework\Attributes\DataProvider;

/** Exercises all generated pairs on real test storage; DDL stays outside rollback scopes. */
final class StatusWorksSchemaIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @return iterable<string, array{string}> All six generated project tracks */
    public static function projects(): iterable
    {
        foreach (['chat', 'tasks', 'polls', 'binance-btc-tracker', 'ecommerce-shop', 'online-testing'] as $project) {
            yield $project => [$project];
        }
    }

    /** @param string $project Demo receiving the three introducing units */
    #[DataProvider('projects')]
    public function testGeneratedPairsApplyAuditTheirForeignKeysAndRollBack(string $project): void
    {
        $root = dirname(__DIR__, 3) . '/demo/' . $project . '/backend/Database/Migration/Schema/';
        $catalog = new FrameworkMigrationCatalog(new FrameworkSchemaCatalog());
        $paths = [];
        try {
            foreach (['status_work', 'status_work_component', 'status_maintenance_period'] as $unit) {
                $matches = glob($root . '*_framework_install_' . $unit . '.sql');
                self::assertCount(1, $matches);
                $up = file_get_contents($matches[0]);
                $down = file_get_contents(substr($matches[0], 0, -4) . '_down.sql');
                $origin = $catalog->verifyPair($up, $down);
                self::assertSame(FrameworkMigrationOrigin::INSTALL, $origin->kind);
                self::assertSame([$unit], $origin->units);
                self::assertCount(1, $origin->parts);
                self::assertSame($unit, $origin->parts[0]->unit);
                $paths[] = $matches[0];
                Database::sqlRun($up);
            }
            self::assertSame([], EntitySchemaAudit::audit([StatusWork::class, StatusWorkComponent::class, StatusMaintenancePeriod::class]));
            Database::sql('INSERT INTO `hilos_status_work` (`public_id`, `title`, `starts_at`, `ends_at`, `created_at`, `updated_at`, `version`) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)', [str_repeat('a', 32), 'Work', 10000, 20000, 1000, 1000, 1]);
            $workId = Database::lastInsertId();
            Database::sql('INSERT INTO `hilos_status_work_component` (`work_id`, `component_key`) VALUES (?, ?)', [$workId, 'api']);
            Database::sql('INSERT INTO `hilos_status_maintenance_period` '
                . '(`public_id`, `work_id`, `work_public_id`, `announcement_known`, `title`, '
                . '`component_keys_json`, `started_at`, `last_observed_at`) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [str_repeat('b', 32), $workId, str_repeat('a', 32), true, 'Observed', '["api"]', 11000, 12000]);
            Database::sql('DELETE FROM `hilos_status_work` WHERE `id` = ?', [$workId]);
            self::assertSame([], Database::sql('SELECT * FROM `hilos_status_work_component`')->rows());
            $period = Database::sql('SELECT * FROM `hilos_status_maintenance_period`')->first()->first();
            self::assertNull($period['work_id']);
            self::assertSame(str_repeat('a', 32), $period['work_public_id']);
            self::assertSame('["api"]', $period['component_keys_json']);
            foreach (array_reverse($paths) as $path) {
                Database::sqlRun(file_get_contents(substr($path, 0, -4) . '_down.sql'));
            }
            self::assertSame([], Database::sql("SHOW TABLES LIKE 'hilos_status_work%'")->rows());
            self::assertSame([], Database::sql("SHOW TABLES LIKE 'hilos_status_maintenance_period'")->rows());
        } finally {
            // One statement per call: a batch whose later results are still unread when the case closes
            // its link keeps running on the server and can drop the table the next data set just created.
            foreach (['hilos_status_maintenance_period', 'hilos_status_work_component', 'hilos_status_work'] as $table) {
                Database::sqlRun('DROP TABLE IF EXISTS `' . $table . '`');
            }
        }
    }
}
