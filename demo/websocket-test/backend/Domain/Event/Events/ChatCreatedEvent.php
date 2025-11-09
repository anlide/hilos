<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Domain\Event\Events;

use Demo\WebSocketTest\Domain\Event\AbstractChatEvent;
use Demo\WebSocketTest\Utils\Constants\ChatEventType;

/**
 * ChatCreatedEvent - Event when chat is created/started
 *
 * Represents the event when chat system is initialized.
 * This event has no additional data.
 */
class ChatCreatedEvent extends AbstractChatEvent
{
    /**
     * Get event type
     *
     * @return ChatEventType Event type enum value
     */
    public function getType(): ChatEventType
    {
        return ChatEventType::CHAT_CREATED;
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

