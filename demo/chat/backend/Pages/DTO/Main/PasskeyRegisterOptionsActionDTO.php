<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * PasskeyRegisterOptionsActionDTO - DTO for requesting WebAuthn registration options (HIL-284).
 *
 * Carries no fields: the passkey is registered for the signed-in user, resolved
 * from the session on the server, never from the client. The action is
 * authenticated (a guest cannot reach it). The handler answers on the
 * PASSKEY_OPTIONS signal with the publicKey creation options and a signed
 * challenge token to hand back on confirm.
 */
final class PasskeyRegisterOptionsActionDTO extends ChatActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::PASSKEY_REGISTER_OPTIONS;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data (ignored; no fields)
     * @return static Options request DTO instance
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
