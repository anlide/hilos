<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * LoginActionDTO - DTO for the email+password login action payload.
 *
 * Public (anonymous-reachable) login submit. The email is trimmed here and
 * lowercased by the handler before the identity lookup; the password is passed
 * through verbatim so leading/trailing characters stay significant.
 */
final class LoginActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates login action DTO.
     *
     * @param string $email Submitted account email (trimmed)
     * @param string $password Submitted plaintext password
     */
    public function __construct(
        public readonly string $email,
        public readonly string $password,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::LOGIN;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Login DTO instance
     */
    public static function fromArray(array $data): static
    {
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;

        return new static(
            email: is_string($email) ? trim($email) : '',
            password: is_string($password) ? $password : '',
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{email: string, password: string} Login payload
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'password' => $this->password,
        ];
    }
}
