<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Pages\DTO\UsersPageResponseSignalData;
use Demo\Chat\Pages\Hilos\Users\UsersPage as HilosUsersPage;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for the users-table page subscription snapshots.
 */
final class UsersPagesSubscribeSnapshotTest extends IntegrationTestCase
{
    /**
     * Verifies subscribing the admin users page queues its page_response snapshot.
     *
     * @throws HilosException When test setup or the subscription snapshot fails
     */
    public function testAdminUsersSubscribeQueuesSnapshotForSubscriber(): void
    {
        $this->assertSubscribeQueuesUsersSnapshot(
            new AdminUsersPage(new ChatAgent()),
            PageConstants::ADMIN_USERS,
        );
    }

    /**
     * Verifies subscribing the Hilos users page queues its page_response snapshot.
     *
     * @throws HilosException When test setup or the subscription snapshot fails
     */
    public function testHilosUsersSubscribeQueuesSnapshotForSubscriber(): void
    {
        $this->assertSubscribeQueuesUsersSnapshot(
            new HilosUsersPage(new DemoHilosAgent()),
            PageConstants::HILOS_USERS,
        );
    }

    /**
     * Subscribes the page and asserts the queued page_response users snapshot.
     *
     * @param AbstractPage $page Users-table page under test
     * @param string $expectedPageKey Page key the signal data must echo
     * @throws HilosException When test setup or the subscription snapshot fails
     */
    private function assertSubscribeQueuesUsersSnapshot(AbstractPage $page, string $expectedPageKey): void
    {
        Hilos::initSignalRouter(new ChatSignalRouter());

        $userA = Hilos::$db->users->actions->register(RandomHelper::hex(16));
        $userB = Hilos::$db->users->actions->register(RandomHelper::hex(16));

        $page->onSubscribe('snapshot-ak', new PageRouteParams([]));

        $pageResponse = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            if ($signal->signalName->getName() === ChatSignalConstants::PAGE_RESPONSE) {
                $pageResponse = $signal;
            }
        }

        $this->assertNotNull($pageResponse, 'page_response signal was not queued');
        $this->assertInstanceOf(WebSocketSignalData::class, $pageResponse->data);
        $this->assertSame('snapshot-ak', $pageResponse->data->targetAcceptKey);
        $this->assertInstanceOf(UsersPageResponseSignalData::class, $pageResponse->data->data);
        $this->assertSame($expectedPageKey, $pageResponse->data->data->pageKey);

        $rowsByKey = [];
        foreach ($pageResponse->data->data->toArray()['payload']['tables']['users']['rows'] as $row) {
            $this->assertArrayNotHasKey($row['rowKey'], $rowsByKey, 'duplicate rowKey in users snapshot');
            $rowsByKey[$row['rowKey']] = $row['slots']['user'];
        }

        $this->assertSame(['id' => (int)$userA->id, 'name' => $userA->name], $rowsByKey[(int)$userA->id]);
        $this->assertSame(['id' => (int)$userB->id, 'name' => $userB->name], $rowsByKey[(int)$userB->id]);
    }
}
