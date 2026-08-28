<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ImpersonateStopActionDTO - payload for the shell impersonation-stop action (HIL-729).
 *
 * Carries nothing, and that is the security of it: the session being reverted is read from
 * the acting connection on the server, so a client can only ever end its own takeover. The
 * administrator to go back to comes off the session's impersonator marker.
 *
 * Owned by {@see AbstractSessionsLibraryAgent} through AGENT_ACTIONS, beside
 * {@see LogoutActionDTO} and for the same two reasons: the control sits in the app shell of
 * every project rather than on a page of one, and what it writes is a session.
 */
final class ImpersonateStopActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_IMPERSONATE_STOP;
    }

    /**
     * Create from array (no payload fields).
     *
     * @param array<string, mixed> $data Payload data (ignored)
     * @return static Impersonate-stop DTO instance
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
