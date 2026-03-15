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
 * AdminPage - Admin page handler.
 *
 * Handles subscription, unsubscription, and actions for the admin page.
 */
final class AdminPage extends AbstractChatPage
{
    /**
     * Get page name.
     *
     * @return string Page name
     */
    public function getPageName(): string
    {
        return PageConstants::ADMIN;
    }

    /**
     * Handle page-specific subscription logic.
     *
     * @param string $acceptKey Accept key
     * @param array<string, string> $params Route params from page subscription (unused for admin page)
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN,
            $acceptKey,
            new ChatEventSignalDTO(new EntitiesChangesDTO()),
        );
    }

    /**
     * Handle page-specific unsubscription logic.
     *
     * @param string $acceptKey Accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        // TODO: Implement admin page unsubscribe logic
    }

    /**
     * Handle page-specific action logic.
     *
     * @param string $acceptKey Accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        // TODO: Implement admin page action logic
    }
}
