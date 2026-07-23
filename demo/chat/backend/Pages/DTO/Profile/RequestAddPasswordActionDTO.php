<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Profile;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * RequestAddPasswordActionDTO - DTO for the profile add-password request payload (HIL-406).
 *
 * Step 1 of the add-a-password wizard, reached only when the signed-in user has no
 * verified email: an authenticated submit that asks for a one-time code to be sent
 * to the email the user wants to prove and key the new password on. The email is
 * trimmed here and lowercased/validated by the handler before the code is issued;
 * the owning user is read from the session, never carried here.
 */
final class RequestAddPasswordActionDTO extends ChatActionPayloadDTO
{
    public const string EMAIL = 'email';

    /**
     * Creates an add-password request DTO.
     *
     * @param string $email Submitted email address (trimmed)
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
        return ChatSignalConstants::ADD_PASSWORD_REQUEST;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Request DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            email: is_string($data[self::EMAIL] ?? null) ? trim($data[self::EMAIL]) : '',
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{email: string} Request payload
     */
    public function toArray(): array
    {
        return [
            self::EMAIL => $this->email,
        ];
    }

    /**
     * Check if the payload is valid (a non-empty email).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->email !== '';
    }
}
