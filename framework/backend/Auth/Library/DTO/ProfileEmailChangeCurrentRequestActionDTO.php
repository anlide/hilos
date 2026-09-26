<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ProfileEmailChangeCurrentRequestActionDTO - DTO for step 1 of the profile email change (HIL-299, HIL-1137).
 *
 * Carries nothing, and that is the point: the code goes to the address the account holds,
 * which the handler reads from the acting session's user. A client naming the address
 * would be naming where the proof of ownership is sent.
 */
final class ProfileEmailChangeCurrentRequestActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST;
    }

    /**
     * Create from array (no payload fields).
     *
     * @param array<string, mixed> $data Payload data (ignored)
     * @return static Request DTO instance
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
