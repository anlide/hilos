<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Pages;

use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\Core\Page\AbstractChatPage;
use Demo\WebSocketTest\Database\Idea\User as IdeaUser;

/**
 * ModeratorPage - Moderator page handler
 *
 * Handles subscription, unsubscription, and actions for the moderator page.
 */
class ModeratorPage extends AbstractChatPage
{
    /**
     * Get page name
     *
     * @return string Page name
     */
    public function getPageName(): string
    {
        return PageConstants::MODERATOR;
    }

    /**
     * Handle page-specific subscription logic
     *
     * @param string $acceptKey Accept key
     * @param IdeaUser $user User idea
     */
    protected function handleSubscribe(string $acceptKey, IdeaUser $user): void
    {
        // TODO: Implement moderator page subscription logic
    }

    /**
     * Handle page-specific unsubscription logic
     *
     * @param string $acceptKey Accept key
     * @param int $userId User id
     */
    protected function handleUnsubscribe(string $acceptKey, int $userId): void
    {
        // TODO: Implement moderator page unsubscribe logic
    }

    /**
     * Handle page-specific action logic
     *
     * @param string $acceptKey Accept key
     * @param int $userId User id
     * @param string $action Action name
     * @param string $payload Action payload
     */
    protected function handleAction(string $acceptKey, int $userId, string $action, string $payload): void
    {
        // TODO: Implement moderator page action logic
    }
}
