<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * RegisterActionDTO - DTO for the registration action payload: an address and nothing else.
 *
 * Public (anonymous-reachable) register submit. The email is trimmed here and
 * lowercased by the handler before the reservation write.
 *
 * The password left this payload with HIL-825. It is asked for after the code, on the
 * screen that creates the account ({@see CompleteRegistrationActionDTO}), so a
 * registration nobody finished never carries a credential for an account that does not
 * exist. There is no password confirmation field either, and there never was: the
 * redesigned surface (HIL-412) has one password input, and a second one that has to
 * match it is a check the frontend could never make meaningful on a field the person
 * cannot see twice. The mistake it was meant to catch is answered by recovery, which
 * the surface offers on the same screen.
 */
final class RegisterActionDTO extends ActionPayloadDTO
{
    /**
     * Creates register action DTO.
     *
     * @param string $email Submitted account email (trimmed)
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
        return HilosSignalConstants::HILOS_REGISTER;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Register DTO instance
     * @throws InvalidFormatException When the email is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            email: trim(self::requireString($data, 'email')),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{email: string} Register payload
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
        ];
    }
}
