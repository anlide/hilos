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
 * BotPage - Bot page handler
 *
 * Handles subscription, unsubscription, and actions for the bot page.
 */
class BotPage extends AbstractChatPage
{
    /**
     * Get page name
     *
     * @return string Page name
     */
    public function getPageName(): string
    {
        return PageConstants::BOT;
    }

    /**
     * Handle page-specific subscription logic
     *
     * @param string $acceptKey Accept key
     */
    public function onSubscribe(string $acceptKey): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_BOT,
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
        // TODO: Implement bot page unsubscribe logic
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
        // TODO: Implement bot page action logic
    }
}
