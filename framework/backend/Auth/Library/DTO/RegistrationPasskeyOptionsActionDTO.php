<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * RegistrationPasskeyOptionsActionDTO - DTO for asking the options of a passkey that starts an account (HIL-1104).
 *
 * An absent identifier asks for an account without an address. A present identifier is
 * carried verbatim, like the lookup's ({@see DetectIdentifierActionDTO}), and requires this
 * browser's proven reservation on that address.
 */
final class RegistrationPasskeyOptionsActionDTO extends ActionPayloadDTO
{
    /**
     * Creates a registration passkey options DTO.
     *
     * @param ?string $identifier Address as typed, or null for an account without an address
     */
    public function __construct(
        public readonly ?string $identifier,
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
     * @throws InvalidFormatException When a present identifier is neither a string nor null
     */
    public static function fromArray(array $data): static
    {
        return new static(
            identifier: self::optionalString($data, 'identifier'),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{identifier: ?string} Registration passkey options payload
     */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
        ];
    }
}
