<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\DTO\ChatEventSignalDTO;
use Demo\Chat\Hilos;
use Hilos\Core\Table\TableActionHandler;
use Hilos\Core\Table\TablePayloadBuilder;
use Hilos\DTO\Action\ActionPayloadDTO;
use Hilos\DTO\EntitiesChangesDTO;
use Hilos\DTO\Table\TableDataDTO;
use Hilos\DTO\Table\TablesPayloadDTO;
use Hilos\Utils\Logger;

/**
 * AdminUsersPage - Admin users page handler
 *
 * Handles subscription, unsubscription, and actions for the admin users page.
 * Subscription response includes the users table (Hilos::$table->users).
 */
class AdminUsersPage extends AbstractChatPage
{
    /**
     * Get page name
     *
     * @return string Page name
     */
    public function getPageName(): string
    {
        return PageConstants::ADMIN_USERS;
    }

    /**
     * Handle page-specific subscription logic
     *
     * Returns users table data (full snapshot) in the subscription response.
     *
     * @param string $acceptKey Accept key
     */
    public function onSubscribe(string $acceptKey): void
    {
        $tablesPayload = TablePayloadBuilder::buildFull([Hilos::users]);
        Logger::logAgentInfo('chat', json_encode($tablesPayload->toArray()));

        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_USERS,
            $acceptKey,
            new ChatEventSignalDTO(new EntitiesChangesDTO(), $tablesPayload),
        );
    }

    /**
     * Handle page-specific unsubscription logic
     *
     * @param string $acceptKey Accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        // TODO: Implement admin users page unsubscribe logic
    }

    /**
     * Handle page-specific action logic
     *
     * Delegates table actions (load_page, refresh_snapshot) to TableActionHandler.
     *
     * @param string $acceptKey Accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        $result = TableActionHandler::handle($action, $dto);
        if ($result === null) {
            return;
        }

        $tablesPayload = $result instanceof TableDataDTO
            ? new TablesPayloadDTO(tables: [$result->key => $result])
            : $result;

        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::TABLE_UPDATE,
            $acceptKey,
            new ChatEventSignalDTO(new EntitiesChangesDTO(), $tablesPayload),
        );
    }
}
