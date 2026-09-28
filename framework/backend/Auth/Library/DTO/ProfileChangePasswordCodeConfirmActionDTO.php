<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * Checks the password-change code without spending it (HIL-300).
 * The code is carried into the final submit and spent only after password policy accepts it.
 */
final class ProfileChangePasswordCodeConfirmActionDTO extends ActionPayloadDTO
{
    public const string CODE = 'code';

    public const array SECRET_FIELDS = [self::CODE];

    /**
     * Creates a password-change code confirmation.
     *
     * @param string $code Submitted verification code (trimmed)
     */
    public function __construct(
        public readonly string $code,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM;
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
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{code: string} Confirm payload
     */
    public function toArray(): array
    {
        return [
            self::CODE => $this->code,
        ];
    }

    /**
     * Check if the payload is valid (a non-empty code).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->code !== '';
    }
}
