<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\AdminUser\AdminUserTableRow;
use Demo\Chat\Tables\HilosUser\HilosUserTableRow;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\WebSocketSignalData;
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

    public function testAdminUsersBrowserSnapshotUsesFullAnchorRowsAndHidesAcceptKey(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        $firstUser = Hilos::$db->users->actions->register(RandomHelper::hex(16));
        $secondUser = Hilos::$db->users->actions->register(RandomHelper::hex(16));

        try {
            Hilos::$rt->connections->actions->register('admin-browser-ak-1', $firstUser->id);
            Hilos::initSignalRouter(new ChatSignalRouter());

            Hilos::$browser->subscribeSnapshot(
                PageConstants::ADMIN_USERS,
                'admin-listener-ak',
                new PageRouteParams([]),
            );

            $signal = Hilos::$sr->getNextQueuedSignal();
            $this->assertNotNull($signal);
            $this->assertSame(ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_USERS, $signal->signalName->getName());
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $this->assertSame('admin-listener-ak', $signal->data->targetAcceptKey);
            $this->assertInstanceOf(BrowserPageSignalData::class, $signal->data->data);

            $payload = $signal->data->data->toArray();
            $rows = $payload[BrowserPageSignalData::tables]['adminUsers'][BrowserPageSignalData::rows];
            $snapshot = Hilos::$table->adminUsers->getFullSnapshot()->toArray();

            $this->assertCount(count($snapshot[TableConstants::RESULT_KEY_ROWS]), $rows);
            $this->assertNotNull($this->findBrowserRowByUserId($rows, $firstUser->id));
            $this->assertNotNull($this->findBrowserRowByUserId($rows, $secondUser->id));

            $firstBrowserRow = $this->findBrowserRowByUserId($rows, $firstUser->id);
            $this->assertIsArray($firstBrowserRow);
            $userSource = $firstBrowserRow[BrowserPageSignalData::sources][ChatDbContext::users] ?? null;
            $this->assertIsArray($userSource);
            $this->assertSame($firstUser->id, $userSource[ObjectUser::id] ?? null);
            $this->assertSame($firstUser->name, $userSource[ObjectUser::name] ?? null);
            $this->assertArrayNotHasKey(ObjectUser::sessionToken, $userSource);
            $this->assertArrayNotHasKey(AdminUserTableRow::presence, $userSource);
            $this->assertArrayNotHasKey(AdminUserTableRow::onlineSessionCount, $userSource);

            $connection = $firstBrowserRow[BrowserPageSignalData::sources][ChatRtContext::connections] ?? null;
            $this->assertIsArray($connection);
            $this->assertArrayHasKey('userId', $connection);
            $this->assertArrayNotHasKey('acceptKey', $connection);
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

    /**
     * Finds a browser row by user id.
     *
     * @param list<array<string, mixed>> $rows Browser rows
     * @param int $userId User id
     * @return ?array<string, mixed> Matching browser row, or null
     */
    private function findBrowserRowByUserId(array $rows, int $userId): ?array
    {
        foreach ($rows as $row) {
            $user = $row[BrowserPageSignalData::sources][ChatDbContext::users] ?? null;
            if (is_array($user) && ($user[ObjectUser::id] ?? null) === $userId) {
                return $row;
            }
        }

        return null;
    }
}
