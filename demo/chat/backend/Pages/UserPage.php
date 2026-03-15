<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;

/**
 * UserPage - User page handler.
 *
 * Handles subscription, unsubscription, and actions for the user page.
 */
final class UserPage extends AbstractChatPage
{
    public const string PAGE = PageConstants::USER;

    /**
     * Handle page-specific subscription logic.
     *
     * @param string $acceptKey Accept key
     * @param array<string, string> $params Route params from page subscription (e.g. ['id' => userId])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_USER,
            $acceptKey,
            new ChatEventSignalDTO(new EntitiesChangesDTO()),
        );
    }
}
