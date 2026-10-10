<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Registration\RegistrationThemePick;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * CompleteRegistrationPasswordlessActionDTO - DTO for the second ending of the password screen (HIL-1008).
 *
 * The screen that asks a proved registration for its first password offers a way past
 * it: create the account now and sign in by a mailed link instead. That is this action,
 * and it is a separate one rather than an optional password on
 * {@see CompleteRegistrationActionDTO} - an omittable field would be a flag in the
 * payload of a public, anonymous-reachable door, which this tree does not do (HIL-576).
 *
 * Carries only the guest's theme pick, written into the account when this submit creates one
 * (HIL-1427). It carries no address, for the reason the complete-with-a-password payload carries no
 * address: which registration is being finished is read from the proved hold of THIS
 * session on the server, and a payload is not entitled to name somebody else's.
 */
final class CompleteRegistrationPasswordlessActionDTO extends ActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    /** @param ?string $themePick Guest browser's choice, or null when they never picked */
    public function __construct(public readonly ?string $themePick = null)
    {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSWORDLESS;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Complete-registration-passwordless DTO instance
     * @throws InvalidFormatException When the submitted theme choice has the wrong type
     * @throws ValidationException When the theme choice is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            themePick: RegistrationThemePick::readPayload(self::optionalString($data, RegistrationThemePick::PAYLOAD_KEY)),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{themePick: ?string} Completion payload
     */
    public function toArray(): array
    {
        return [RegistrationThemePick::PAYLOAD_KEY => $this->themePick];
    }
}
