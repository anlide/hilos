<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ModeratorAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Page\PageRouteParams;

/**
 * ModeratorPage - Moderator page handler.
 *
 * Handles subscription, unsubscription, and actions for the moderator page.
 */
final class ModeratorPage extends AbstractChatPage
{
    public const string PAGE = PageConstants::MODERATOR;

    /**
     * Narrows the page agent to the moderator worker that serves moderation state.
     *
     * @return ModeratorAgent Moderator worker bound to this moderator page
     */
    protected function getChatAgent(): ModeratorAgent
    {
        assert($this->agent instanceof ModeratorAgent);

        return $this->agent;
    }

    /**
     * Handle page-specific subscription logic.
     *
     * @param string $acceptKey Accept key
     * @param PageRouteParams $params Route params from page subscription (unused for moderator page)
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_MODERATOR,
            $acceptKey,
            new ChatEventSignalDTO(new EntitiesChangesDTO()),
        );
    }
}
