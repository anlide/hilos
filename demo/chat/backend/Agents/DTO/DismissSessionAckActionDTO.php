<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\DTO;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * DismissSessionAckActionDTO - payload for dismissing a session's success ack (HIL-422).
 *
 * What the Continue button on the finished-flow panel sends. It carries no payload
 * for the same reason logout does not: the session is taken from the acting
 * connection, and there is only ever one ack on it, so naming which one to dismiss
 * would only give a stale client a way to clear a newer announcement.
 *
 * Owned by {@see ChatAgent} through AGENT_ACTIONS rather than by a page: the panel
 * belongs to the auth surface, which is drawn over whatever page the person is on.
 */
final class DismissSessionAckActionDTO extends ChatActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::DISMISS_SESSION_ACK;
    }

    /**
     * Create from array (no payload fields).
     *
     * @param array<string, mixed> $data Payload data (ignored)
     * @return static Dismiss DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, mixed> Empty payload
     */
    public function toArray(): array
    {
        return [];
    }
}
