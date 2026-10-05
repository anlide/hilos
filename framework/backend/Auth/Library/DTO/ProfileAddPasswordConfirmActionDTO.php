<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ProfileAddPasswordConfirmActionDTO - DTO for the profile add-password confirm payload (HIL-406, HIL-1137).
 *
 * Step 2 of adding a password: an authenticated submit carrying the delivered code
 * and new password. The email comes from the session's profile flow; the code is
 * trimmed so surrounding whitespace never fails an otherwise valid code. The new
 * password is not trimmed (leading/trailing whitespace is significant).
 */
final class ProfileAddPasswordConfirmActionDTO extends ActionPayloadDTO
{
    public const string CODE = 'code';
    public const string NEW_PASSWORD = 'newPassword';

    public const array SECRET_FIELDS = [self::CODE, self::NEW_PASSWORD];

    /**
     * Creates an add-password confirm DTO.
     *
     * @param string $code Submitted verification code (trimmed)
     * @param string $newPassword New password to set (untrimmed)
     */
    public function __construct(
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
        return HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Confirm DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            code: trim(self::requireString($data, self::CODE)),
            newPassword: self::requireString($data, self::NEW_PASSWORD),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{code: string, newPassword: string} Confirm payload
     */
    public function toArray(): array
    {
        return [
            self::CODE => $this->code,
            self::NEW_PASSWORD => $this->newPassword,
        ];
    }

    /**
     * Check if the payload is valid (a non-empty code and password).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->code !== '' && $this->newPassword !== '';
    }
}
