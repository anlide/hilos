<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Pages;

use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\Core\Page\AbstractChatPage;
use Demo\WebSocketTest\Database\Idea\User as IdeaUser;

/**
 * UserPage - User page handler
 *
 * Handles subscription, unsubscription, and actions for the user page.
 */
class UserPage extends AbstractChatPage
{
    /**
     * Get page name
     *
     * @return string Page name
     */
    public function getPageName(): string
    {
        return PageConstants::USER;
    }

    /**
     * Handle page-specific subscription logic
     *
     * @param string $acceptKey Accept key
     * @param IdeaUser $user User idea
     */
    protected function handleSubscribe(string $acceptKey, IdeaUser $user): void
    {
        // TODO: Implement user page subscription logic
    }

    /**
     * Handle page-specific unsubscription logic
     *
     * @param string $acceptKey Accept key
     * @param int $userId User id
     */
    protected function handleUnsubscribe(string $acceptKey, int $userId): void
    {
        // TODO: Implement user page unsubscribe logic
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
        // TODO: Implement user page action logic
    }
}
