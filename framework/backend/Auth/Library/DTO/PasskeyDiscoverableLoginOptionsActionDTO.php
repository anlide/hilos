<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * PasskeyDiscoverableLoginOptionsActionDTO - DTO for requesting usernameless WebAuthn login options (HIL-400).
 *
 * Carries no fields: a discoverable (resident-key) login names no account. The
 * handler answers on the PASSKEY_OPTIONS signal (ceremony=LOGIN) with an empty
 * allowCredentials list and a signed challenge token — the resident credential the
 * OS picker returns resolves the account on confirm. Public (anonymous-reachable);
 * an empty allowCredentials is identical for everyone, so there is nothing to
 * enumerate and no user resolution here.
 */
final class PasskeyDiscoverableLoginOptionsActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data (ignored; no fields)
     * @return static Options request DTO instance
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
