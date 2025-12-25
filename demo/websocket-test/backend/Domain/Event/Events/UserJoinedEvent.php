<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Domain\Event\Events;

use Demo\WebSocketTest\Constants\ChatEventType;
use Demo\WebSocketTest\Domain\Event\AbstractChatEvent;

/**
 * UserJoinedEvent - Event when user joins the chat
 *
 * Represents the event when a user subscribes to the chat page.
 */
class UserJoinedEvent extends AbstractChatEvent
{
    /**
     * Constructor
     *
     * @param int $userId User ID of the user who joined
     * @param int|null $timestamp Optional timestamp (defaults to current time)
     */
    public function __construct(
        private readonly int $userId,
        ?int $timestamp = null
    ) {
        parent::__construct($timestamp);
    }

    /**
     * Get event type
     *
     * @return ChatEventType Event type enum value
     */
    public function getType(): ChatEventType
    {
        return ChatEventType::USER_JOINED;
    }

    /**
     * Get user ID associated with event
     *
     * @return int User ID
     */
    public function getUserId(): int
    {
        return $this->userId;
    }

    /**
     * Get event-specific data
     *
     * @return array Event data with userId
     */
    protected function getEventData(): array
    {
        return [
            'userId' => $this->userId,
        ];
    }
}
