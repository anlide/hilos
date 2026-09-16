<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * BrowserEraseActionDTO - payload for the erase on the privacy page (HIL-839).
 *
 * Carries nothing, exactly as {@see LogoutActionDTO} carries nothing and for the same
 * reason: the session being ended is read from the acting connection on the server, so a
 * client can only ever erase its own browser.
 *
 * Owned by {@see AbstractSessionsLibraryAgent} through AGENT_ACTIONS rather than by a page:
 * the control stands on /privacy, which is one of the public pages the framework owns the
 * behavior of, and what it ends is the session - which is the library's.
 */
final class BrowserEraseActionDTO extends ActionPayloadDTO
{
    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_BROWSER_ERASE;
    }

    /**
     * Create from array (no payload fields).
     *
     * @param array<string, mixed> $data Payload data (ignored)
     * @return static Browser-erase DTO instance
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
