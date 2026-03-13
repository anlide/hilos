<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Page;

use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAgentInterface;

/**
 * AbstractChatPage - Abstract base class for chat page handlers.
 *
 * Provides base implementation for chat-specific page logic.
 * Child classes must implement page-specific subscribe, unsubscribe, and action handlers.
 */
abstract class AbstractChatPage extends AbstractPage
{
    /**
     * Get chat agent (same as $this->agent, typed for convenience).
     *
     * @return PageAgentInterface Chat agent instance
     */
    protected function getChatAgent(): PageAgentInterface
    {
        return $this->agent;
    }
}
