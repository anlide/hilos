<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ProfileAddPasswordRequestActionDTO - DTO for the profile add-password request payload (HIL-406, HIL-1137).
 *
 * Step 1 of adding a password, reached only when the signed-in person has no
 * confirmed email: an authenticated submit that asks for a one-time code to be sent
 * to the email the person wants to prove and key the new password on. The email is
 * trimmed here and lowercased/validated by the handler before the code is issued;
 * the owning user is read from the session, never carried here.
 */
final class ProfileAddPasswordRequestActionDTO extends ActionPayloadDTO
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
        return HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Request DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            email: trim(self::requireString($data, self::EMAIL)),
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
