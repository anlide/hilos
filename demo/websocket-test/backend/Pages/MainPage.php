<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Pages;

use Demo\WebSocketTest\Constants\ChatSignalConstants;
use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\Core\Page\AbstractChatPage;
use Hilos\Logging\Logger\Logger;

/**
 * MainPage - Main chat page handler
 *
 * Handles subscription, unsubscription, and actions for the main chat page.
 */
class MainPage extends AbstractChatPage
{
    /**
     * Get page name
     *
     * @return string Page name
     */
    public function getPageName(): string
    {
        return PageConstants::MAIN;
    }

    /**
     * Handle page-specific subscription logic
     *
     * @param string $acceptKey Accept key
     */
    public function onSubscribe(string $acceptKey): void
    {
        // nothing special on subscribe
    }

    /**
     * Handle page-specific unsubscription logic
     *
     * @param string $acceptKey Accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        // nothing special on unsubscribe
    }

    /**
     * Handle page-specific action logic
     *
     * @param string $acceptKey Accept key
     * @param string $action Action name
     * @param string $payload Action payload
     */
    public function onAction(string $acceptKey, string $action, string $payload): void
    {
        if ($action === ChatSignalConstants::MESSAGE) {
            // TODO: handle add message action
        } else {
            Logger::logAgentError('MainPage', "Unknown action: {$action}");
        }
    }
}
