<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Profile;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * SetPasswordActionDTO - DTO for the profile set-password action payload (HIL-402).
 *
 * Carries the new password and, for a change (the user already has a password),
 * the current password re-auth. `currentPassword` is empty on the add flow (a
 * proven email is the authority, so there is nothing to re-verify); the server
 * decides change vs add from the user's identities, never from this flag.
 */
final class SetPasswordActionDTO extends ChatActionPayloadDTO
{
    public const string CURRENT_PASSWORD = 'currentPassword';
    public const string NEW_PASSWORD = 'newPassword';

    /**
     * Creates the set-password action DTO.
     *
     * @param string $currentPassword Current password for a change, or '' on the add flow
     * @param string $newPassword New password to set
     */
    public function __construct(
        public readonly string $currentPassword,
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
        return ChatSignalConstants::SET_PASSWORD;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            currentPassword: self::requireString($data, self::CURRENT_PASSWORD),
            newPassword: self::requireString($data, self::NEW_PASSWORD),
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, string> Data with currentPassword and newPassword keys
     */
    public function toArray(): array
    {
        return [
            self::CURRENT_PASSWORD => $this->currentPassword,
            self::NEW_PASSWORD => $this->newPassword,
        ];
    }

    /**
     * Check if the payload is valid (a non-empty new password).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->newPassword !== '';
    }
}
