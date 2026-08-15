<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\OAuthPendingLogin;
use OutOfBoundsException;

/**
 * OAuthPendingLogins - in-flight OAuth login ops keyed by accept key (HIL-281).
 *
 * Framework-owned state collection; the project registers it and the callback
 * action records ops into it, while the framework OAuth async agent observes and
 * drains it across ticks.
 *
 * @extends RtStates<OAuthPendingLogin>
 */
final class OAuthPendingLogins extends RtStates
{
    public const string STATE_CLASS = OAuthPendingLogin::class;

    /**
     * @param ?string $acceptKey Initiating accept key, or null for a missing optional key
     * @return ?OAuthPendingLogin Pending-login op, or null when missing
     */
    public function get(?string $acceptKey): ?OAuthPendingLogin
    {
        /** @var ?OAuthPendingLogin $state */
        $state = parent::get($acceptKey);

        return $state;
    }

    /**
     * Array access is for required ops; use `get()` when absence is valid.
     *
     * @param mixed $offset Initiating accept key
     * @return OAuthPendingLogin Pending-login op
     * @throws OutOfBoundsException When no state is stored under the key
     */
    public function offsetGet(mixed $offset): OAuthPendingLogin
    {
        if ($offset === null) {
            throw new OutOfBoundsException('OAuth pending login not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("OAuth pending login not found: {$offset}");
    }
}
