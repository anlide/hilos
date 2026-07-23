<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Profile;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * ConfirmAddPasswordActionDTO - DTO for the profile add-password confirm payload (HIL-406).
 *
 * Step 2 of the add-a-password wizard: an authenticated submit that carries the
 * email, the delivered code, and the new password. The email is trimmed here and
 * re-lowercased by the handler so it matches the issued challenge identifier; the
 * code is trimmed so surrounding whitespace never fails an otherwise valid code.
 * The new password is not trimmed (leading/trailing whitespace is significant). The
 * owning user is read from the session, never carried here.
 */
final class ConfirmAddPasswordActionDTO extends ChatActionPayloadDTO
{
    public const string EMAIL = 'email';
    public const string CODE = 'code';
    public const string NEW_PASSWORD = 'newPassword';

    /**
     * Creates an add-password confirm DTO.
     *
     * @param string $email Submitted email address (trimmed)
     * @param string $code Submitted verification code (trimmed)
     * @param string $newPassword New password to set (untrimmed)
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
        return ChatSignalConstants::ADD_PASSWORD_CONFIRM;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Confirm DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            email: is_string($data[self::EMAIL] ?? null) ? trim($data[self::EMAIL]) : '',
            code: is_string($data[self::CODE] ?? null) ? trim($data[self::CODE]) : '',
            newPassword: is_string($data[self::NEW_PASSWORD] ?? null) ? $data[self::NEW_PASSWORD] : '',
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
            self::EMAIL => $this->email,
            self::CODE => $this->code,
            self::NEW_PASSWORD => $this->newPassword,
        ];
    }

    /**
     * Check if the payload is valid (a non-empty email, code, and new password).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->email !== '' && $this->code !== '' && $this->newPassword !== '';
    }
}
