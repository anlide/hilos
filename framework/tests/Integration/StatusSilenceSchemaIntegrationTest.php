<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusSilencePeriod;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Schema\EntitySchemaAudit;
use PHPUnit\Framework\Attributes\DataProvider;

/** Applies and reverses each generated pair on real storage, outside any rollback scope. */
final class StatusSilenceSchemaIntegrationTest extends FrameworkIntegrationTestCase
{
    /** @return iterable<string, array{string}> All six actual project tracks */
    public static function projects(): iterable
    {
        foreach (['chat', 'tasks', 'polls', 'binance-btc-tracker', 'ecommerce-shop', 'online-testing'] as $project) {
            yield $project => [$project];
        }
    }

    /** @param string $project Demo receiving the introducing schema unit */
    #[DataProvider('projects')]
    public function testGeneratedPairVerifiesAppliesSignedMomentsAndRollsBack(string $project): void
    {
        $root = dirname(__DIR__, 3) . '/demo/' . $project . '/backend/Database/Migration/Schema/';
        $matches = glob($root . '*_framework_install_status_silence_period.sql');
        self::assertCount(1, $matches);
        $up = file_get_contents($matches[0]);
        $down = file_get_contents(substr($matches[0], 0, -4) . '_down.sql');
        $catalog = new FrameworkMigrationCatalog(new FrameworkSchemaCatalog());
        $origin = $catalog->verifyPair($up, $down);
        self::assertSame(FrameworkMigrationOrigin::INSTALL, $origin->kind);
        self::assertSame(['status_silence_period'], $origin->units);
        self::assertCount(1, $origin->parts);
        self::assertSame([], $catalog->missingParts(['status_silence_period'], [$origin]));
        try {
            Database::sqlRun($up);
            self::assertSame([], EntitySchemaAudit::audit([StatusSilencePeriod::class]));
            $columns = Database::sql('SHOW COLUMNS FROM `hilos_status_silence_period`')->rows();
            foreach ($columns as $column) {
                if (in_array($column['Field'], ['started_at', 'ended_at'], true)) {
                    self::assertMatchesRegularExpression('/^bigint(?:\([0-9]+\))?$/D', $column['Type']);
                    self::assertNull($column['Default']);
                    self::assertSame('NO', $column['Null']);
                }
            }
            Database::sql('INSERT INTO `hilos_status_silence_period` (`public_id`, `started_at`, `ended_at`) VALUES (?, ?, ?)',
                [str_repeat('a', 32), -2000, -1000]);
            $stored = Database::sql('SELECT * FROM `hilos_status_silence_period`')->rows();
            self::assertSame(-2000, (int)$stored[0]['started_at']);
            self::assertSame(-1000, (int)$stored[0]['ended_at']);
            foreach ([[-1000, -1000], [0, -1]] as [$start, $end]) {
                try {
                    Database::sql('INSERT INTO `hilos_status_silence_period` (`public_id`, `started_at`, `ended_at`) VALUES (?, ?, ?)',
                        [str_repeat('b', 32), $start, $end]);
                    self::fail('The database must refuse an empty or reversed gap');
                } catch (DatabaseException) {
                    self::assertSame($stored, Database::sql('SELECT * FROM `hilos_status_silence_period`')->rows());
                }
            }
            Database::sqlRun($down);
            self::assertSame([], Database::sql("SHOW TABLES LIKE 'hilos_status_silence_period'")->rows());
        } finally {
            Database::sqlRun('DROP TABLE IF EXISTS `hilos_status_silence_period`');
        }
    }
}
