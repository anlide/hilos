<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Domain\Event\Events;

use Demo\WebSocketTest\Domain\Event\AbstractChatEvent;
use Demo\WebSocketTest\Utils\Constants\ChatEventType;

/**
 * ChatClearedEvent - Event when chat history is cleared
 *
 * Represents the event when chat history is cleaned up.
 * This event has no additional data.
 */
class ChatClearedEvent extends AbstractChatEvent
{
    /**
     * Get event type
     *
     * @return ChatEventType Event type enum value
     */
    public function getType(): ChatEventType
    {
        return ChatEventType::CHAT_CLEARED;
    }

    /**
     * Get event-specific data
     *
     * @return array Empty array (no additional data)
     */
    protected function getEventData(): array
    {
        return [];
    }
}

