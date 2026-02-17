<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;

/**
 * AdminModeratorPage - Admin moderator page handler
 *
 * Handles subscription, unsubscription, and actions for the admin moderator page.
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
     * @param string $acceptKey Accept key
     */
    public function onSubscribe(string $acceptKey): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN_MODERATOR,
            $acceptKey,
            new ChatEventSignalDTO(new EntitiesChangesDTO()),
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
     * @param string $acceptKey Accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        // TODO: Implement admin moderator page action logic
    }
}
