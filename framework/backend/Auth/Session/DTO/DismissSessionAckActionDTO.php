<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DismissSessionAckActionDTO - payload for dismissing a session's success ack (HIL-422).
 *
 * What the Continue button on the finished-flow panel sends. It carries no payload for the
 * same reason sign-out does not: the session is taken from the acting connection, and there
 * is only ever one ack on it, so naming which one to dismiss would only give a stale client
 * a way to clear a newer announcement.
 *
 * Owned by {@see AbstractSessionsLibraryAgent} through AGENT_ACTIONS rather than by a page:
 * the panel belongs to the sign-in surface, which is drawn over whatever page the person is
 * on, and the ack it clears stands on the session (HIL-710).
 */
final class DismissSessionAckActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_DISMISS_SESSION_ACK;
    }

    /**
     * Create from array (no payload fields).
     *
     * @param array<string, mixed> $data Payload data (ignored)
     * @return static Dismiss DTO instance
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
