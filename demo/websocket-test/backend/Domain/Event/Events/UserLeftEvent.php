<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Domain\Event\Events;

use Demo\WebSocketTest\Constants\ChatEventType;
use Demo\WebSocketTest\Domain\Event\AbstractChatEvent;

/**
 * UserLeftEvent - Event when user leaves the chat
 *
 * Represents the event when a user unsubscribes from the chat page.
 */
class UserLeftEvent extends AbstractChatEvent
{
    /**
     * Constructor
     *
     * @param string $clientId Client ID of the user who left
     * @param int|null $timestamp Optional timestamp (defaults to current time)
     */
    public function __construct(
        private readonly string $clientId,
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
        return ChatEventType::USER_LEFT;
    }

    /**
     * Get client ID associated with event
     *
     * @return string Client ID
     */
    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    /**
     * Get event-specific data
     *
     * @return array Event data with clientId
     */
    protected function getEventData(): array
    {
        return [
            'clientId' => $this->clientId,
        ];
    }
}
