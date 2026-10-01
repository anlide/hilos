<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ProfileEmailChangeNewConfirmActionDTO - DTO for step 4 of the profile email change (HIL-299, HIL-1137).
 *
 * Carries the code the new address received and nothing else. The new address and the proof of
 * the current one are the session's record (HIL-1182): the handler reads both there, re-checks
 * the address, spends this code, then the current address's, and only then moves the account.
 * The code is trimmed here.
 */
final class ProfileEmailChangeNewConfirmActionDTO extends ActionPayloadDTO
{
    public const string CODE = 'code';

    public const array SECRET_FIELDS = [self::CODE];

    /**
     * Creates a new-address confirm DTO.
     *
     * @param string $code Code the new address received (trimmed)
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
        return HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Confirm DTO instance
     * @throws InvalidFormatException When the code is absent or not a string
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
