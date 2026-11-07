<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;

/** A raw SQL identity reuse that breaks loaded ORM memory and asks for a cold reset. */
#[IntegrationTest]
final class StatusWorksOrmStateIntegrationTest extends StatusWorksStorageIntegrationTestCase implements BreaksOrmState
{
    /** Simulates deletion and an integer id reused after restore without adopting the replacement work. */
    public function testHistoricalBridgeRefusesReusedIntegerIdentityAndDetachedSnapshotSurvivesDelete(): void
    {
        $work = Hilos::$db->statusWorks->actions->create('Same title', null, 10000, 20000, 1000, ['api']);
        $period = Hilos::$db->statusMaintenancePeriods->actions->create($work->id, $work->publicId, true, 'Same title', ['api'], 11000);
        self::assertSame($work->id, $period->work->id);
        Database::sql('DELETE FROM `hilos_status_work` WHERE `id` = ?', [$work->id]);
        self::assertSame([], $this->storedComponents($work->id));
        $row = $this->storedPeriod($period->id);
        self::assertNull($row['work_id']);
        self::assertSame($work->publicId, $row['work_public_id']);
        self::assertSame('["api"]', $row['component_keys_json']);
        Database::sql('INSERT INTO `hilos_status_work` (`id`, `public_id`, `title`, `starts_at`, `ends_at`, `created_at`, `updated_at`, `version`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$work->id, str_repeat('b', 32), 'Same title', 10000, 20000, 1000, 1000, 1]);
        Database::sql('UPDATE `hilos_status_maintenance_period` SET `work_id` = ? WHERE `id` = ?', [$work->id, $period->id]);
        $previous = Hilos::$db;
        Hilos::$db = new StatusWorksStorageDbContext();
        Hilos::$db->configure();
        try {
            self::assertNull(Hilos::$db->statusMaintenancePeriods[$period->id]->work);
            self::assertSame($work->publicId, Hilos::$db->statusMaintenancePeriods[$period->id]->workPublicId);
            self::assertSame(['api'], Hilos::$db->statusMaintenancePeriods[$period->id]->componentKeys);
        } finally {
            Hilos::$db = $previous;
        }
    }
}
