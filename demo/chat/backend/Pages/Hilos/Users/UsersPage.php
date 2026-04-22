<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Pages\Users\AbstractHilosUsersPage;
use Hilos\Core\Page\PageRouteParams;

/**
 * UsersPage - Hilos users list page implementation for demo.
 *
 * Sends the same users table snapshot as {@see AdminUsersPage} via WebSocket.
 */
final class UsersPage extends AbstractHilosUsersPage
{
    /**
     * Sends the initial users table snapshot to a Hilos users-list subscriber.
     *
     * @param string $acceptKey WebSocket accept key for the subscribing client
     * @param PageRouteParams $params Route params from page subscription
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USERS,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(),
                [TableChatContext::users => Hilos::$table->users->get()],
            ),
        );
    }
}
