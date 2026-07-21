<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\DTO;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * ImpersonateStopActionDTO - payload for the shell impersonation-stop client action.
 *
 * Stop is page-independent (the banner control lives in the app shell and the
 * effective user is now the impersonated target, so no page is guaranteed) and
 * carries no payload: the acting session is taken from the connection, and the
 * admin to restore comes from the session's impersonator marker. Owned by
 * {@see \Demo\Chat\Agents\ChatAgent} through AGENT_ACTIONS, calque of
 * {@see LogoutActionDTO}.
 */
final class ImpersonateStopActionDTO extends ChatActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::IMPERSONATE_STOP;
    }

    /**
     * Create from array (no payload fields).
     *
     * @param array<string, mixed> $data Payload data (ignored)
     * @return static Impersonate-stop DTO instance
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
