<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Page\Pages;

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
     * @param string $clientId Client ID
     * @param IdeaUser $user User idea
     */
    protected function handleSubscribe(string $clientId, IdeaUser $user): void
    {
        // TODO: Implement user page subscription logic
    }

    /**
     * Handle page-specific unsubscription logic
     *
     * @param string $clientId Client id
     * @param int $userId User id
     */
    protected function handleUnsubscribe(string $clientId, int $userId): void
    {
        // TODO: Implement user page unsubscribe logic
    }

    /**
     * Handle page-specific action logic
     *
     * @param string $clientId Client id
     * @param int $userId User id
     * @param string $action Action name
     * @param string $payload Action payload
     */
    protected function handleAction(string $clientId, int $userId, string $action, string $payload): void
    {
        // TODO: Implement user page action logic
    }
}
