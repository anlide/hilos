<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\TableChatContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Pages\Users\AbstractHilosUsersPage;

/**
 * UsersPage - Hilos users list page implementation for demo.
 *
 * Sends the same users table snapshot as {@see \Demo\Chat\Pages\AdminUsersPage} via WebSocket.
 */
final class UsersPage extends AbstractHilosUsersPage
{
    /**
     * {@inheritDoc}
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $result = Hilos::$table->users->get();

        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_USERS,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(),
                [TableChatContext::users => $result],
            ),
        );
    }
}
