<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * CancelRegistrationActionDTO - DTO for the way off a code screen (HIL-486, renamed HIL-829).
 *
 * Carries no fields, and that is the security of it: which registration is being
 * canceled is read from the SESSION on the server, so a client can only ever drop
 * its own. The identifier is not accepted from the browser because a stranger naming
 * somebody else's address would otherwise reach into their flow - and since the hold
 * IS released now, naming one would hand a stranger the address itself.
 */
final class CancelRegistrationActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_CANCEL_REGISTRATION;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data (ignored; no fields)
     * @return static Cancel-registration DTO instance
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
