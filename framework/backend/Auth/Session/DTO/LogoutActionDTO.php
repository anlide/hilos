<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * LogoutActionDTO - payload for the shell sign-out action (HIL-710).
 *
 * Carries nothing, and that is the security of it: the session being signed out is read
 * from the acting connection on the server, so a client can only ever end its own.
 *
 * Owned by {@see AbstractSessionsLibraryAgent} through AGENT_ACTIONS rather than by a page:
 * the control sits in the app shell, which is drawn over whatever page the person is on.
 * It became framework-owned with the sessions themselves - a project keeping an action of
 * its own would be naming a door it no longer holds.
 */
final class LogoutActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_LOGOUT;
    }

    /**
     * Create from array (no payload fields).
     *
     * @param array<string, mixed> $data Payload data (ignored)
     * @return static Logout DTO instance
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
