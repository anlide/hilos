<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * CancelSecondFactorActionDTO - DTO for going back from a second-factor screen to sign in another way (HIL-494).
 *
 * Carries no fields: which wait is let go is read from the SESSION on the server, so a
 * browser can only ever drop its own.
 */
final class CancelSecondFactorActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_CANCEL_SECOND_FACTOR;
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
