<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ProfileSecondFactorResetRequestActionDTO - DTO for asking the delayed removal of the second factor from the profile (HIL-494).
 *
 * Carries no fields: whose factor it is comes off the session. It exists for the person on a
 * trusted browser, whom the sign-in never asks for a code and so never offers the way to ask.
 */
final class ProfileSecondFactorResetRequestActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data (ignored; no fields)
     * @return static DTO instance
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
