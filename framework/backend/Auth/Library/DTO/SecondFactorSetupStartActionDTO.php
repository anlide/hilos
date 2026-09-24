<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * SecondFactorSetupStartActionDTO - DTO for asking a fresh secret on the enrolment an administrator requires (HIL-494).
 *
 * Carries no fields: whose enrolment it is comes off the session's wait, never off the
 * browser. Asking again replaces the secret shown before.
 */
final class SecondFactorSetupStartActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_START;
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
