<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * PasskeyLoginOptionsActionDTO - DTO for requesting WebAuthn login options (HIL-284).
 *
 * Public (anonymous-reachable) username-first login start: the client names the
 * account email; the handler resolves the user's passkey credentials into
 * allowCredentials (or a dummy set for an unknown email, anti-enumeration) and
 * answers on the PASSKEY_OPTIONS signal with a signed challenge token. The email is
 * trimmed here and lowercased by the handler.
 */
final class PasskeyLoginOptionsActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates a passkey login options request DTO.
     *
     * @param string $email Submitted account email (trimmed)
     */
    public function __construct(
        public readonly string $email,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::PASSKEY_LOGIN_OPTIONS;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Options request DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            email: trim(self::requireString($data, 'email')),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{email: string} Options request payload
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
        ];
    }
}
