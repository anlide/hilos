<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * RegistrationPasskeyOptionsActionDTO - DTO for asking the options of a passkey that starts an account (HIL-1104).
 *
 * The first submit of the guest's passkey door. One field, the identifier as it stands in the
 * surface's field, carried VERBATIM like the lookup's ({@see DetectIdentifierActionDTO}): the
 * server classifies and normalizes it itself, so the browser never has to agree with the
 * server about what the canonical form of a number is.
 */
final class RegistrationPasskeyOptionsActionDTO extends ActionPayloadDTO
{
    /**
     * Creates a registration passkey options DTO.
     *
     * @param string $identifier Identifier as typed, an email address or a phone number
     */
    public function __construct(
        public readonly string $identifier,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Registration passkey options DTO instance
     * @throws InvalidFormatException When the identifier is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            identifier: self::requireString($data, 'identifier'),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{identifier: string} Registration passkey options payload
     */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
        ];
    }
}
