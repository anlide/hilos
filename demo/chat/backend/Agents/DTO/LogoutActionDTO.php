<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * LogoutActionDTO - payload for the shell logout client action.
 *
 * Logout is page-independent and carries no payload: the acting session is taken
 * from the connection, and the effect is always "revert this session to anonymous".
 * Owned by {@see \Demo\Chat\Agents\ChatAgent} through AGENT_ACTIONS, not a page.
 */
final class LogoutActionDTO extends ChatActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::LOGOUT;
    }

    /**
     * Create from array (no payload fields).
     *
     * @param array<string, mixed> $data Payload data (ignored)
     * @return static Logout DTO instance
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
