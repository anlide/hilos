<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Domain\Event\Events;

use Demo\WebSocketTest\Domain\Event\AbstractChatEvent;
use Demo\WebSocketTest\Utils\Constants\ChatEventType;

/**
 * UserRenamedEvent - Event when user renames themselves
 *
 * Represents the event when a user changes their display name in the chat.
 */
class UserRenamedEvent extends AbstractChatEvent
{
    /**
     * Constructor
     *
     * @param string $clientId Client ID of the user who renamed
     * @param string $oldName Previous name
     * @param string $newName New name
     * @param int|null $timestamp Optional timestamp (defaults to current time)
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $oldName,
        private readonly string $newName,
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
        return ChatEventType::USER_RENAMED;
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
     * Get previous name
     *
     * @return string Old name
     */
    public function getOldName(): string
    {
        return $this->oldName;
    }

    /**
     * Get new name
     *
     * @return string New name
     */
    public function getNewName(): string
    {
        return $this->newName;
    }

    /**
     * Get event-specific data
     *
     * @return array Event data with clientId, oldName, and newName
     */
    protected function getEventData(): array
    {
        return [
            'clientId' => $this->clientId,
            'oldName' => $this->oldName,
            'newName' => $this->newName,
        ];
    }
}

