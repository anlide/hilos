<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Page;

use Hilos\Core\Page\AbstractPage;

/**
 * AbstractChatPage - Abstract base class for chat page handlers
 *
 * Provides base implementation for chat-specific page logic.
 * Child classes must implement page-specific subscribe, unsubscribe, and action handlers.
 */
abstract class AbstractChatPage extends AbstractPage
{
}
