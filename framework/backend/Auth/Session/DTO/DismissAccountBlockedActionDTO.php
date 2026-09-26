<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * DismissAccountBlockedActionDTO - payload of the Sign out button on the "Access closed" card (HIL-289).
 *
 * It carries no payload for the reason the ack dismissal does not: the session is taken from the
 * acting connection, and there is only ever one blocked account on it, so naming which one to
 * forget would only give a stale client a way to clear a newer card.
 *
 * Owned by {@see AbstractSessionsLibraryAgent} through AGENT_ACTIONS rather than by a page: the
 * card stands in the shell over whatever page the person is on, and the mark it clears stands on
 * the session.
 */
final class DismissAccountBlockedActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_DISMISS_ACCOUNT_BLOCKED;
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
