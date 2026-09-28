<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Adds the first password to an account with a confirmed email address (HIL-300). */
final class ProfileSetPasswordActionDTO extends ActionPayloadDTO
{
    public const string NEW_PASSWORD = 'newPassword';

    public const array SECRET_FIELDS = [self::NEW_PASSWORD];

    /**
     * Creates the set-password action DTO.
     *
     * @param string $newPassword New password to set
     */
    public function __construct(
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
        return HilosSignalConstants::PROFILE_SET_PASSWORD;
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
            newPassword: self::requireString($data, self::NEW_PASSWORD),
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, string> Data with the newPassword key
     */
    public function toArray(): array
    {
        return [
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
