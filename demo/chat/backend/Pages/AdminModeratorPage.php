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
 * AdminModeratorPage - Admin moderator prompt pieces page handler
 *
 * Handles subscription, unsubscription, and actions for the admin moderator page.
 * Subscription response includes the moderatorPromptPieces table.
 */
class AdminModeratorPage extends AbstractChatPage
{
    /**
     * Get page name
     *
     * @return string Page name
     */
    public function getPageName(): string
    {
        return PageConstants::ADMIN_MODERATOR;
    }

    /**
     * Handle page-specific subscription logic
     *
     * Returns moderatorPromptPieces table data (full snapshot) in the subscription response.
     *
     * @param string $acceptKey Accept key
     */
    public function onSubscribe(string $acceptKey): void
    {
        $tablesPayload = TablePayloadBuilder::buildFull([DbChatContext::moderatorPromptPieces]);
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_MODERATOR,
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
        // TODO: Implement admin moderator page unsubscribe logic
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
