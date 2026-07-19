<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * ConfirmPasswordResetActionDTO - DTO for submitting a password-reset code.
 *
 * Public (anonymous-reachable) submit that carries the emailed code and the new
 * password. The email is trimmed here and lowercased by the handler; the code
 * and new password are passed through verbatim so leading/trailing characters
 * stay significant.
 */
final class ConfirmPasswordResetActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates a password-reset confirmation DTO.
     *
     * @param string $email Submitted account email (trimmed)
     * @param string $code Submitted verification code
     * @param string $newPassword Submitted new plaintext password
     */
    public function __construct(
        public readonly string $email,
        public readonly string $code,
        public readonly string $newPassword,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::CONFIRM_PASSWORD_RESET;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Confirm DTO instance
     */
    public static function fromArray(array $data): static
    {
        $email = $data['email'] ?? null;
        $code = $data['code'] ?? null;
        $newPassword = $data['newPassword'] ?? null;

        return new static(
            email: is_string($email) ? trim($email) : '',
            code: is_string($code) ? trim($code) : '',
            newPassword: is_string($newPassword) ? $newPassword : '',
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{email: string, code: string, newPassword: string} Confirm payload
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'code' => $this->code,
            'newPassword' => $this->newPassword,
        ];
    }
}
