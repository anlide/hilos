<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Agent\Hilos\AbstractHilosAgent;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;

/**
 * Base class for framework-level Hilos admin page handlers.
 *
 * Projects extend these pages to bind framework admin routes to their own page
 * catalog, agents, and browser setup.
 *
 * @property AbstractHilosAgent $agent Framework admin agent for page operations
 */
abstract class AbstractHilosPage extends AbstractPage
{
    /**
     * The framework admin surface is closed by default: every hilos page
     * requires the admin privilege unless it explicitly declares PUBLIC or
     * AUTHENTICATED ({@see PageAccessLevel}). Enforced by {@see PageAccessGate}.
     */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    /**
     * Reads the session behind a subscribing or acting connection in the form the freeze row compares.
     *
     * Null means the registry, connection or session is absent; the protected-mode row refuses
     * that value as an identity. The token itself stays inside the session source.
     *
     * @param string $acceptKey Accept key of the connection to read
     * @return ?string Hash of that connection's session token, or null when it carries no session
     */
    protected static function subscriberSessionTokenHash(string $acceptKey): ?string
    {
        $sessionToken = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey)?->sessionToken;

        return $sessionToken === null || $sessionToken === ''
            ? null
            : StateProtectedModeRuntime::hashSessionToken($sessionToken);
    }
}
