<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\DbChatContext;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Table\TablePayloadBuilder;

/**
 * AdminBotsPage - Admin bots page handler
 *
 * Handles subscription, unsubscription, and actions for the admin bots page.
 * Subscription response includes the bots table (Hilos::$table->bots).
 */
class AdminBotsPage extends AbstractChatPage
{
    /**
     * Get page name
     *
     * @return string Page name
     */
    public function getPageName(): string
    {
        return PageConstants::ADMIN_BOTS;
    }

    /**
     * Handle page-specific subscription logic
     *
     * Returns bots table data (full snapshot) in the subscription response.
     *
     * @param string $acceptKey Accept key
     */
    public function onSubscribe(string $acceptKey): void
    {
        $tablesPayload = TablePayloadBuilder::buildFull([DbChatContext::bots]);
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_BOTS,
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
        // TODO: Implement admin bots page unsubscribe logic
    }

    /**
     * Handle page-specific action logic
     *
     * Table actions (load_page, refresh_snapshot) are routed to AdminUsersPage via ActionRouteConfig.
     *
     * @param string $acceptKey Accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        // Table actions routed to AdminUsersPage
    }
}
