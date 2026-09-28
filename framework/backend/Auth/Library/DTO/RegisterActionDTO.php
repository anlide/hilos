<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Registration\RegistrationConsent;
use Hilos\Core\Exception\ValidationException;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * RegisterActionDTO - DTO for the registration action payload: an address and its accepted legal revisions.
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
     * @param ?array<string, string> $acceptedRevisions Accepted document-to-revision boundary map, or null for a repeat
     */
    public function __construct(
        public readonly string $email,
        public readonly ?array $acceptedRevisions = null,
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
     * @throws ValidationException When the accepted revision map has an unknown document or invalid revision id
     */
    public static function fromArray(array $data): static
    {
        return new static(
            email: trim(self::requireString($data, 'email')),
            acceptedRevisions: RegistrationConsent::readPayload(self::optionalArray($data, RegistrationConsent::PAYLOAD_KEY)),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, mixed> Action payload including the optional accepted revisions
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            RegistrationConsent::PAYLOAD_KEY => $this->acceptedRevisions,
        ];
    }
}
