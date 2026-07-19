<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * RegisterActionDTO - DTO for the email+password registration action payload.
 *
 * Public (anonymous-reachable) register submit. The email is trimmed here and
 * lowercased by the handler before the identity write; the password and its
 * confirmation are passed through verbatim so leading/trailing characters stay
 * significant and the equality check compares exactly what the user typed.
 */
final class RegisterActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates register action DTO.
     *
     * @param string $email Submitted account email (trimmed)
     * @param string $password Submitted plaintext password
     * @param string $confirmPassword Submitted plaintext password confirmation
     */
    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly string $confirmPassword,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::REGISTER;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Register DTO instance
     */
    public static function fromArray(array $data): static
    {
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        $confirmPassword = $data['confirmPassword'] ?? null;

        return new static(
            email: is_string($email) ? trim($email) : '',
            password: is_string($password) ? $password : '',
            confirmPassword: is_string($confirmPassword) ? $confirmPassword : '',
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{email: string, password: string, confirmPassword: string} Register payload
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'password' => $this->password,
            'confirmPassword' => $this->confirmPassword,
        ];
    }
}
