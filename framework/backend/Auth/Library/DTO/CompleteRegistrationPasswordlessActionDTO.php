<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
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
 * Carries no fields, for the reason the complete-with-a-password payload carries no
 * address: which registration is being finished is read from the proved hold of THIS
 * session on the server, and a payload is not entitled to name somebody else's.
 */
final class CompleteRegistrationPasswordlessActionDTO extends ActionPayloadDTO
{
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
     * @param array<string, mixed> $data Payload data (ignored; no fields)
     * @return static Complete-registration-passwordless DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, mixed> Empty payload
     */
    public function toArray(): array
    {
        return [];
    }
}
