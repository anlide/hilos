<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * RequestPasswordResetActionDTO - DTO for a password-reset request payload.
 *
 * Public (anonymous-reachable) submit that asks for a recovery code to be sent
 * to an email. The email is trimmed here and lowercased by the handler before
 * the identity lookup; the response is always the same generic success, so this
 * payload never reveals whether the address has an account.
 */
final class RequestPasswordResetActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates a password-reset request DTO.
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
        return ChatSignalConstants::REQUEST_PASSWORD_RESET;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Request DTO instance
     */
    public static function fromArray(array $data): static
    {
        $email = $data['email'] ?? null;

        return new static(
            email: is_string($email) ? trim($email) : '',
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
            'email' => $this->email,
        ];
    }
}
