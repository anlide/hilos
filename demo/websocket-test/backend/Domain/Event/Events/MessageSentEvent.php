<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Domain\Event\Events;

use Demo\WebSocketTest\Constants\ChatEventType;
use Demo\WebSocketTest\Domain\Event\AbstractChatEvent;

/**
 * MessageSentEvent - Event when user sends a message
 *
 * Represents the event when a user sends a message in the chat.
 */
class MessageSentEvent extends AbstractChatEvent
{
    /**
     * Constructor
     *
     * @param string $clientId Client ID of the user who sent the message
     * @param string $message Message text
     * @param int|null $timestamp Optional timestamp (defaults to current time)
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $message,
        ?int $timestamp = null,
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
        return ChatEventType::MESSAGE_SENT;
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
     * Get message text
     *
     * @return string Message content
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get event-specific data
     *
     * @return array Event data with clientId and message
     */
    protected function getEventData(): array
    {
        return [
            'clientId' => $this->clientId,
            'message' => $this->message,
        ];
    }
}
