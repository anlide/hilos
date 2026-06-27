<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\AdminUser\AdminUserTableRow;
use Demo\Chat\Tables\HilosUser\HilosUserTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for user browser representation.
 */
final class UserBrowserRepresentationTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    public function testUserTableRowsIncludeRuntimeOnlineSessionCount(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        $user = Hilos::$db->users->actions->register(RandomHelper::hex(16));

        try {
            Hilos::$rt->connections->actions->register('test-accept-key-1', $user->id);
            Hilos::$rt->connections->actions->register('test-accept-key-2', $user->id);

            $hilosRow = Hilos::$table->hilosUsers->rowFromUser($user)->toArray();
            $this->assertSame(2, $hilosRow[HilosUserTableRow::onlineSessionCount]);
            $this->assertSame('online', $hilosRow[HilosUserTableRow::presence]);

            $adminRow = Hilos::$table->adminUsers->rowFromUser($user)->toArray();
            $this->assertSame(2, $adminRow[AdminUserTableRow::onlineSessionCount]);
            $this->assertSame('online', $adminRow[AdminUserTableRow::presence]);

            $hilosSnapshot = Hilos::$table->hilosUsers->getFullSnapshot()->toArray();
            $hilosSnapshotRow = $this->findRowByUserId($hilosSnapshot[TableConstants::RESULT_KEY_ROWS], $user->id);
            $this->assertSame(2, $hilosSnapshotRow[HilosUserTableRow::onlineSessionCount]);

            $adminSnapshot = Hilos::$table->adminUsers->getFullSnapshot()->toArray();
            $adminSnapshotRow = $this->findRowByUserId($adminSnapshot[TableConstants::RESULT_KEY_ROWS], $user->id);
            $this->assertSame(2, $adminSnapshotRow[AdminUserTableRow::onlineSessionCount]);
        } finally {
            Hilos::$rt->connections->actions->clear();
        }
    }

    /**
     * Finds a table row by user id.
     *
     * @param list<array<string, mixed>> $rows Table rows
     * @param int $userId User id
     * @return array<string, mixed> Matching row
     */
    private function findRowByUserId(array $rows, int $userId): array
    {
        foreach ($rows as $row) {
            if (($row[ObjectUser::id] ?? null) === $userId) {
                return $row;
            }
        }

        self::fail("User row #{$userId} not found");
    }
}
