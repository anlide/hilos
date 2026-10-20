<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;

/** Exercises the unchanged action's own transaction; persisted expectations bypass its ORM readers. */
#[IntegrationTest]
#[BreaksOrmState]
final class SecondFactorTrustsRollbackIntegrationTest extends FrameworkRollbackIntegrationTestCase
{
    /** Case data is inserted only after the rollback scope starts. All SQL datetimes are fixed UTC. */
    protected function setUpInTransaction(): void
    {
        Database::sqlRun("INSERT INTO hilos_user (id, name) VALUES (1562, 'Rollback example')");
        Database::sqlRun(
            'INSERT INTO hilos_second_factor_trust (id, session_id, user_id, created_at, trusted_until) VALUES '
            . "(1, 101, 1562, '2026-01-01 00:00:00', '2026-12-01 00:00:00'), "
            . "(2, 102, 1562, '2026-01-01 00:00:00', '2026-02-03 00:00:00'), "
            . "(3, 103, 1562, '2026-01-01 00:00:00', '2026-01-31 23:59:59'), "
            . "(4, 104, 1562, '2026-02-01 00:00:01', '2026-12-01 00:00:00')",
        );
        foreach ([1, 2, 3, 4] as $id) {
            self::assertArrayHasKey($id, Hilos::$db->secondFactorTrusts);
            Hilos::$db->secondFactorTrusts[$id]->trustedUntil;
        }

        // Deliberately bypass the loaded row so BreaksOrmState has work to do after rollback.
        Database::sqlRun("UPDATE hilos_second_factor_trust SET trusted_until = '2026-11-01 00:00:00' WHERE id = 1");
        self::assertSame('2026-12-01 00:00:00', Hilos::$db->secondFactorTrusts[1]->trustedUntil);
    }

    /** Only the old live long trust is capped; a retry with a later limit lengthens nothing. */
    public function testCapLiveUntilWithItsOwnTransaction(): void
    {
        $savedAtSql = '2026-02-01 00:00:00';
        $limitSql = '2026-02-08 00:00:00';
        $expected = [
            ['id' => '1', 'created_at' => '2026-01-01 00:00:00', 'trusted_until' => '2026-02-08 00:00:00'],
            ['id' => '2', 'created_at' => '2026-01-01 00:00:00', 'trusted_until' => '2026-02-03 00:00:00'],
            ['id' => '3', 'created_at' => '2026-01-01 00:00:00', 'trusted_until' => '2026-01-31 23:59:59'],
            ['id' => '4', 'created_at' => '2026-02-01 00:00:01', 'trusted_until' => '2026-12-01 00:00:00'],
        ];

        Hilos::$db->secondFactorTrusts->actions->capLiveUntil($limitSql, $savedAtSql);
        self::assertSame($expected, Database::sql(
            'SELECT id, created_at, trusted_until FROM hilos_second_factor_trust ORDER BY id',
        )->rows());

        Hilos::$db->secondFactorTrusts->actions->capLiveUntil('2026-03-01 00:00:00', $savedAtSql);
        self::assertSame($expected, Database::sql(
            'SELECT id, created_at, trusted_until FROM hilos_second_factor_trust ORDER BY id',
        )->rows());
    }
}
