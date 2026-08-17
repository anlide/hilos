<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Constants\HilosSignalConstants;

/**
 * AbandonRegistrationActionDTO - DTO for "not that address?" on a code screen (HIL-486).
 *
 * Carries no fields, and that is the security of it: which registration is being
 * abandoned is read from the SESSION on the server, so a client can only ever drop
 * its own. The identifier is not accepted from the browser for the same reason the
 * hold is not released here at all - a stranger naming somebody else's address
 * would otherwise reach into their flow.
 */
final class AbandonRegistrationActionDTO extends ChatActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_ABANDON_REGISTRATION;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data (ignored; no fields)
     * @return static Abandon-registration DTO instance
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
