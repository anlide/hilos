<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Domain\Event;

/**
 * AbstractChatEvent - Abstract base class for all chat events
 *
 * Provides common functionality for chat events:
 * - Timestamp management
 * - Base implementation of common methods
 * - Type safety through abstract getType() method
 */
abstract class AbstractChatEvent implements ChatEventInterface
{
    /** @var int Event timestamp */
    protected int $timestamp;

    /**
     * Constructor
     *
     * @param int|null $timestamp Optional timestamp (defaults to current time)
     */
    public function __construct(?int $timestamp = null)
    {
        $this->timestamp = $timestamp ?? time();
    }

    /**
     * Get event timestamp
     *
     * @return int Unix timestamp when event occurred
     */
    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * Get client ID associated with event (if applicable)
     *
     * Default implementation returns null. Override in child classes that have client ID.
     *
     * @return string|null Client ID or null if event has no client
     */
    public function getClientId(): ?string
    {
        return null;
    }

    /**
     * Convert event to array representation
     *
     * @return array Event data as array with 'type', 'timestamp', and 'data' keys
     */
    public function toArray(): array
    {
        return [
            'type' => $this->getType()->value,
            'timestamp' => $this->timestamp,
            'data' => $this->getEventData(),
        ];
    }

    /**
     * Get event-specific data
     *
     * Child classes must implement this to provide their specific data.
     *
     * @return array Event-specific data
     */
    abstract protected function getEventData(): array;
}

