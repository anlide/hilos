<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Page;

use Demo\WebSocketTest\Database\Idea\User as IdeaUser;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\SignalRouter;

/**
 * AbstractChatPage - Abstract base class for chat page handlers
 *
 * Provides base implementation for chat-specific page logic.
 * Child classes must implement page-specific subscribe, unsubscribe, and action handlers.
 */
abstract class AbstractChatPage extends AbstractPage
{
    /** @var ChatPageContextInterface Chat agent context for accessing agent methods */
    protected ChatPageContextInterface $chatContext;

    /**
     * Constructor
     *
     * @param SignalRouter $signalRouter Signal router instance
     * @param ChatPageContextInterface $chatContext Chat agent context
     */
    public function __construct(SignalRouter $signalRouter, ChatPageContextInterface $chatContext)
    {
        parent::__construct($signalRouter);
        $this->chatContext = $chatContext;
    }

    /**
     * Handle page subscription
     *
     * @param string $acceptKey WebSocket accept key
     * @param IdeaUser $user User idea
     * @return void
     */
    public function onSubscribe(string $acceptKey, mixed $user): void
    {
        if (!($user instanceof IdeaUser)) {
            throw new \InvalidArgumentException('Expected IdeaUser instance');
        }

        $this->handleSubscribe($acceptKey, $user);
    }

    /**
     * Handle page-specific subscription logic
     *
     * @param string $acceptKey WebSocket accept key
     * @param IdeaUser $user User idea
     * @return void
     */
    abstract protected function handleSubscribe(string $acceptKey, IdeaUser $user): void;

    /**
     * Handle page-specific unsubscription logic
     *
     * @param string $acceptKey WebSocket accept key
     * @param int $userId User ID
     * @return void
     */
    abstract protected function handleUnsubscribe(string $acceptKey, int $userId): void;

    /**
     * Handle page unsubscription
     *
     * @param string $acceptKey WebSocket accept key
     * @param int $userId User ID
     * @return void
     */
    public function onUnsubscribe(string $acceptKey, int $userId): void
    {
        $this->handleUnsubscribe($acceptKey, $userId);
    }

    /**
     * Handle page-specific action logic
     *
     * @param string $acceptKey WebSocket accept key
     * @param int $userId User ID
     * @param string $action Action name
     * @param string $payload Action payload (raw string, usually JSON)
     * @return void
     */
    abstract protected function handleAction(string $acceptKey, int $userId, string $action, string $payload): void;

    /**
     * Handle action signal
     *
     * @param string $acceptKey WebSocket accept key
     * @param int $userId User ID
     * @param string $action Action name
     * @param string $payload Action payload (raw string, usually JSON)
     * @return void
     */
    public function onAction(string $acceptKey, int $userId, string $action, string $payload): void
    {
        $this->handleAction($acceptKey, $userId, $action, $payload);
    }
}
