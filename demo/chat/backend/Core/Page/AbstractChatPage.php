<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Page;

use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalRouter;

/**
 * AbstractChatPage - Abstract base class for chat page handlers
 *
 * Provides base implementation for chat-specific page logic.
 * Child classes must implement page-specific subscribe, unsubscribe, and action handlers.
 *
 * @property ChatPageAgentInterface $agent
 */
abstract class AbstractChatPage extends AbstractPage
{
    /** @var ChatPageAgentInterface Agent instance (typed for chat) */
    protected PageAgentInterface $agent;

    /**
     * Constructor
     *
     * @param SignalRouter $signalRouter Signal router instance
     * @param ChatPageAgentInterface $agent Chat agent instance
     */
    public function __construct(SignalRouter $signalRouter, ChatPageAgentInterface $agent)
    {
        parent::__construct($signalRouter, $agent);
    }

    /**
     * Get typed chat agent
     *
     * @return ChatPageAgentInterface
     */
    protected function getChatAgent(): ChatPageAgentInterface
    {
        /** @var ChatPageAgentInterface */
        return $this->agent;
    }
}
