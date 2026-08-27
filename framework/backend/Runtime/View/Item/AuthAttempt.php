<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\AuthAttempt as StateAuthAttempt;
use Hilos\Runtime\View\Actions\Item\AuthAttemptActions;

/**
 * Read-only wrapper over the window counter of one throttle key (HIL-420).
 *
 * What the guard reads on the hot path: {@see blockedUntil} answers the fast path
 * on its own - a key blocked in this worker's replica is refused without asking the
 * agent - and the counter fields are what the agent's ladder arithmetic works from.
 *
 * @extends RtItem<StateAuthAttempt>
 *
 * @property-read string $scope Throttle scope, `ip` or `session`
 * @property-read string $identity Client IP or sha256 of the session token
 * @property-read string $action Throttled action name
 * @property-read int $count Attempts counted since the window opened
 * @property-read float $windowStartedAt Unix seconds the current window opened; 0.0 when none is open
 * @property-read int $level Escalation level reached so far
 * @property-read ?float $blockedUntil Unix seconds the block lifts, or null when not blocked
 * @property-read AuthAttemptActions $actions Actions for write operations
 */
final class AuthAttempt extends RtItem
{
    /**
     * @param StateAuthAttempt $state Backing runtime state
     */
    public function __construct(StateAuthAttempt $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|int|float|AuthAttemptActions|null Property value
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): string|int|float|AuthAttemptActions|null
    {
        return match ($name) {
            StateAuthAttempt::scope => $this->_state->scope,
            StateAuthAttempt::identity => $this->_state->identity,
            StateAuthAttempt::action => $this->_state->action,
            StateAuthAttempt::count => $this->_state->count,
            StateAuthAttempt::windowStartedAt => $this->_state->windowStartedAt,
            StateAuthAttempt::level => $this->_state->level,
            StateAuthAttempt::blockedUntil => $this->_state->blockedUntil,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
