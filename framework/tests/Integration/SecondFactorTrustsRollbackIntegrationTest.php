<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;

/** Exercises the unchanged action's own transaction; persisted expectations bypass its ORM readers. */
#[IntegrationTest]
final class SecondFactorTrustsRollbackIntegrationTest extends FrameworkRollbackIntegrationTestCase implements BreaksOrmState
{
    /** Case data is inserted only after the rollback scope starts. Moments are fixed epoch milliseconds. */
    protected function setUpInTransaction(): void
    {
        Database::sqlRun("INSERT INTO hilos_user (id, name) VALUES (1562, 'Rollback example')");
        Database::sqlRun(
            'INSERT INTO hilos_second_factor_trust (id, session_id, user_id, created_at, granted_at, trusted_until) VALUES '
            . '(1, 101, 1562, 1767225600000, 1767225600000, 1796083200000), '
            . '(2, 102, 1562, 1767225600000, 1767225600000, 1770076800000), '
            . '(3, 103, 1562, 1767225600000, 1767225600000, 1769903999000), '
            . '(4, 104, 1562, 1769904001000, 1769904001000, 1796083200000)',
        );
        Database::sqlRun('INSERT INTO hilos_second_factor_trust_restriction (id, saved_at, term_days) VALUES (1, 1769904000000, 7)');
        foreach ([1, 2, 3, 4] as $id) {
            self::assertArrayHasKey($id, Hilos::$db->secondFactorTrusts);
            Hilos::$db->secondFactorTrusts[$id]->trustedUntil;
        }

        // Deliberately bypass the loaded row so BreaksOrmState has work to do after rollback.
        Database::sqlRun('UPDATE hilos_second_factor_trust SET trusted_until = 1793491200000 WHERE id = 1');
        self::assertSame(1796083200000, Hilos::$db->secondFactorTrusts[1]->trustedUntil);
    }

    /** Only the old live long trust is cut to the saved term; applying it again changes nothing. */
    public function testApplySavedRestrictionsWithItsOwnTransaction(): void
    {
        $expected = [
            ['id' => '1', 'created_at' => 1767225600000, 'trusted_until' => 1770508800000],
            ['id' => '2', 'created_at' => 1767225600000, 'trusted_until' => 1770076800000],
            ['id' => '3', 'created_at' => 1767225600000, 'trusted_until' => 1769903999000],
            ['id' => '4', 'created_at' => 1769904001000, 'trusted_until' => 1796083200000],
        ];

        Hilos::$db->secondFactorTrusts->actions->applySavedRestrictions(1562);
        self::assertSame($expected, self::trustRows());

        Hilos::$db->secondFactorTrusts->actions->applySavedRestrictions(1562);
        self::assertSame($expected, self::trustRows());
    }

    /**
     * @return list<array{id: string, created_at: int, trusted_until: int}> Stored trusts, moments as epoch milliseconds
     */
    private static function trustRows(): array
    {
        $rows = [];
        foreach (Database::sql(
            'SELECT id, created_at, trusted_until FROM hilos_second_factor_trust ORDER BY id',
        )->rows() as $row) {
            $rows[] = [
                'id' => $row['id'],
                'created_at' => (int)$row['created_at'],
                'trusted_until' => (int)$row['trusted_until'],
            ];
        }

        return $rows;
    }
}
