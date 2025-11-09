<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Domain\Event;

use Demo\WebSocketTest\Constants\ChatEventType;

/**
 * ChatEventInterface - Interface for all chat events
 *
 * Defines contract for chat event objects. All events must implement this interface
 * to ensure consistent structure and behavior.
 */
interface ChatEventInterface
{
    /**
     * Get event type
     *
     * @return ChatEventType Event type enum value
     */
    public function getType(): ChatEventType;

    /**
     * Get event timestamp
     *
     * @return int Unix timestamp when event occurred
     */
    public function getTimestamp(): int;

    /**
     * Get client ID associated with event (if applicable)
     *
     * @return string|null Client ID or null if event has no client
     */
    public function getClientId(): ?string;

    /**
     * Convert event to array representation
     *
     * @return array Event data as array
     */
    public function toArray(): array;
}
