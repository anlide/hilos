<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Runtime\View\Actions\Collection\RegistrationWaitersActions;

/**
 * Read-only wrapper over one session parked on a registration code step (HIL-415).
 *
 * What the converge broadcast reads off a waiter: where to send the step change,
 * and which session to sign in when the step is done. The row carries no write API
 * of its own - a waiter is parked once and then released, both of which are
 * collection-level acts on {@see RegistrationWaitersActions}.
 *
 * @extends RtItem<StateRegistrationWaiter>
 *
 * @property-read string $acceptKey Accept key of the waiting connection
 * @property-read string $identifier Identifier whose confirmation is awaited
 * @property-read string $sessionToken Session token to sign in on confirmation
 */
final class RegistrationWaiter extends RtItem
{
    /**
     * @param StateRegistrationWaiter $state Backing runtime state
     */
    public function __construct(StateRegistrationWaiter &$state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string Property value
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): string
    {
        return match ($name) {
            StateRegistrationWaiter::acceptKey => $this->_state->acceptKey,
            StateRegistrationWaiter::identifier => $this->_state->identifier,
            StateRegistrationWaiter::sessionToken => $this->_state->sessionToken,
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
